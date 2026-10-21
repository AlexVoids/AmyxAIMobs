<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\listener;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\overworld\WanderingTrader;
use BeeAZ\AZVanillaMobs\entity\overworld\TraderLlama;
use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\Server;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\block\Fence;
use pocketmine\item\StringToItemParser;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\scheduler\Task;
use Throwable;

class LeashListener implements Listener
{
    public static array $leashedEntities = [];
    public static array $fenceLeashedEntities = [];
    public static array $entityLeashedEntities = [];
    public static array $justTied = [];
    private Main $plugin;

    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;

        $this->plugin->getScheduler()->scheduleRepeatingTask(new class() extends Task {
            public function onRun(): void
            {
                LeashListener::tickLeashes();
            }
        }, 1);
    }

    public function onEntitySpawn(EntitySpawnEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof WanderingTrader && $entity->spawnLlamas) {
            $entity->spawnLlamas = false;
            $location = $entity->getLocation();
            $world = $entity->getWorld();
            for ($i = 0; $i < 2; $i++) {
                $offset = new Vector3(mt_rand(-2, 2) + 0.5, 0.0, mt_rand(-2, 2) + 0.5);
                $llamaPos = $location->add($offset->x, $offset->y, $offset->z);
                $llama = new TraderLlama(Location::fromObject($llamaPos, $world, mt_rand(0, 360), 0), CompoundTag::create()->setByte("NaturalSpawned", 1));
                $llama->spawnToAll();
                self::attachLeash($llama, $entity->getId());
                self::$entityLeashedEntities[$llama->getId()] = $entity->getId();
            }
        }
    }

    public static function removeKnotIfUnused(int $knotId, World $world): void
    {
        foreach (self::$fenceLeashedEntities as $data) {
            if ($data['knotId'] === $knotId) {
                return;
            }
        }
        $pk = RemoveActorPacket::create($knotId);
        foreach ($world->getPlayers() as $p) {
            $p->getNetworkSession()->sendDataPacket($pk);
        }
    }

    public static function tickLeashes(): void
    {
        foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
            $fencePos = $fenceData['pos'];
            $world = null;
            foreach (Server::getInstance()->getWorldManager()->getWorlds() as $w) {
                if ($w->getFolderName() === $fenceData['world']) {
                    $world = $w;
                    break;
                }
            }
            if ($world === null) {
                unset(self::$fenceLeashedEntities[$entityId]);
                continue;
            }
            $entity = $world->getEntity($entityId);
            if ($entity === null) {
                continue;
            }
            if (!$entity->isAlive() || $entity->isClosed()) {
                $knotId = $fenceData['knotId'];
                unset(self::$fenceLeashedEntities[$entityId]);
                self::removeKnotIfUnused($knotId, $world);
                continue;
            }
            
            $block = $world->getBlockAt((int)$fencePos->x, (int)$fencePos->y, (int)$fencePos->z);
            $blockName = strtolower($block->getName());
            $knotPos = new Vector3($fencePos->x + 0.5, $fencePos->y + 0.5, $fencePos->z + 0.5);
            $dist = $entity->getLocation()->distance($knotPos);

            if (!(str_contains($blockName, 'fence') || str_contains($blockName, 'wall')) || $dist > 12.0) {
                $knotId = $fenceData['knotId'];
                unset(self::$fenceLeashedEntities[$entityId]);
                self::detachLeash($entity);
                self::removeKnotIfUnused($knotId, $world);
                
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), $leadItem);
                }
            } elseif ($dist > 5.0) {
                $dir = $knotPos->subtractVector($entity->getLocation());
                $dir->y = 0;
                if ($dir->lengthSquared() > 0) {
                    $dir = $dir->normalize();
                }
                $entity->setMotion($dir->multiply(0.15));
            }
        }

        foreach (self::$leashedEntities as $entityId => $playerId) {
            $player = null;
            foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                if ($p->getId() === $playerId) {
                    $player = $p;
                    break;
                }
            }

            if ($player === null || !$player->isAlive()) {
                unset(self::$leashedEntities[$entityId]);
                foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
                    $entity = $world->getEntity($entityId);
                    if ($entity !== null) {
                        self::detachLeash($entity);
                        $leadItem = StringToItemParser::getInstance()->parse("lead");
                        if ($leadItem !== null) {
                            $entity->getWorld()->dropItem($entity->getLocation(), $leadItem);
                        }
                        break;
                    }
                }
                continue;
            }

            $entity = $player->getWorld()->getEntity($entityId);
            if ($entity === null || !$entity->isAlive() || $entity->isClosed() || $entity->getWorld() !== $player->getWorld()) {
                unset(self::$leashedEntities[$entityId]);
                continue;
            }

            $dist = $entity->getLocation()->distance($player->getLocation());
            if ($dist > 10.0) {
                unset(self::$leashedEntities[$entityId]);
                self::detachLeash($entity);
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), $leadItem);
                }
            }
        }

        foreach (self::$entityLeashedEntities as $entityId => $holderId) {
            $world = null;
            $entity = null;
            $holder = null;
            foreach (Server::getInstance()->getWorldManager()->getWorlds() as $w) {
                $e = $w->getEntity($entityId);
                if ($e !== null) {
                    $entity = $e;
                    $world = $w;
                    $holder = $w->getEntity($holderId);
                    break;
                }
            }
            if ($entity === null || !$entity->isAlive() || $entity->isClosed() || $holder === null || !$holder->isAlive() || $holder->isClosed()) {
                unset(self::$entityLeashedEntities[$entityId]);
                if ($entity !== null) {
                    self::detachLeash($entity);
                }
                continue;
            }
            $dist = $entity->getLocation()->distance($holder->getLocation());
            if ($dist > 10.0) {
                unset(self::$entityLeashedEntities[$entityId]);
                self::detachLeash($entity);
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), $leadItem);
                }
            } elseif ($dist > 3.0) {
                $dir = $holder->getLocation()->subtractVector($entity->getLocation());
                $dir->y = 0;
                if ($dir->lengthSquared() > 0) {
                    $dir = $dir->normalize();
                }
                $entity->setMotion($dir->multiply(0.15));
            }
        }
    }

    public static function detachLeash(Entity $entity): void
    {
        try {
            $entity->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::LEASHED, false);
            $entity->getNetworkProperties()->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, -1);
            $entity->despawnFromAll();
            $entity->spawnToAll();
        } catch (Throwable $e) {
        }
    }

    public static function attachLeash(Entity $entity, int $holderId): void
    {
        try {
            $entity->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::LEASHED, true);
            $entity->getNetworkProperties()->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, $holderId);
            $entity->despawnFromAll();
            $entity->spawnToAll();
        } catch (Throwable $e) {
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void
    {
        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;

        $player = $event->getPlayer();
        $block = $event->getBlock();
        $blockName = strtolower($block->getName());

        if (!(str_contains($blockName, 'fence') || str_contains($blockName, 'wall'))) return;

        $tick = Server::getInstance()->getTick();
        if (isset(self::$justTied[$player->getId()]) && ($tick - self::$justTied[$player->getId()]) < 5) {
            $event->cancel();
            return;
        }

        $playerLeashedIds = [];
        foreach (self::$leashedEntities as $entityId => $pid) {
            if ($pid === $player->getId()) {
                $playerLeashedIds[] = $entityId;
            }
        }

        $blockPos = $block->getPosition();

        if (empty($playerLeashedIds)) {
            $entitiesToTransfer = [];
            $knotIdToRemove = null;
            
            foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
                $pos = $fenceData['pos'];
                if ((int)$pos->x === $blockPos->getFloorX()
                    && (int)$pos->y === $blockPos->getFloorY()
                    && (int)$pos->z === $blockPos->getFloorZ()
                    && $fenceData['world'] === $player->getWorld()->getFolderName()
                ) {
                    $entitiesToTransfer[] = $entityId;
                    $knotIdToRemove = $fenceData['knotId'];
                }
            }

            if (!empty($entitiesToTransfer)) {
                $event->cancel();
                foreach ($entitiesToTransfer as $eId) {
                    unset(self::$fenceLeashedEntities[$eId]);
                    $entity = $player->getWorld()->getEntity($eId);
                    if ($entity !== null) {
                        self::$leashedEntities[$eId] = $player->getId();
                        self::attachLeash($entity, $player->getId());
                    }
                }
                if ($knotIdToRemove !== null) {
                    self::removeKnotIfUnused($knotIdToRemove, $player->getWorld());
                }
                self::$justTied[$player->getId()] = $tick;
            }
            return;
        }

        $event->cancel();
        $knotId = null;
        foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
            $pos = $fenceData['pos'];
            if ((int)$pos->x === $blockPos->getFloorX()
                && (int)$pos->y === $blockPos->getFloorY()
                && (int)$pos->z === $blockPos->getFloorZ()
                && $fenceData['world'] === $player->getWorld()->getFolderName()
            ) {
                $knotId = $fenceData['knotId'];
                break;
            }
        }

        if ($knotId === null) {
            $knotId = Entity::nextRuntimeId();
            $knotPos = new Vector3($blockPos->getFloorX() + 0.5, $blockPos->getFloorY() + 0.5, $blockPos->getFloorZ() + 0.5);

            $pk = AddActorPacket::create(
                $knotId,
                $knotId,
                "minecraft:leash_knot",
                $knotPos,
                null,
                0.0, 0.0, 0.0, 0.0,
                [],
                [EntityMetadataProperties::FLAGS => new LongMetadataProperty(0)],
                new PropertySyncData([], []),
                []
            );

            foreach ($player->getWorld()->getPlayers() as $p) {
                $p->getNetworkSession()->sendDataPacket($pk);
            }
        }

        self::$justTied[$player->getId()] = $tick;

        foreach ($playerLeashedIds as $entityId) {
            $entity = $player->getWorld()->getEntity($entityId);
            if ($entity !== null) {
                unset(self::$leashedEntities[$entityId]);
                unset(self::$entityLeashedEntities[$entityId]);
                self::$fenceLeashedEntities[$entityId] = [
                    'pos' => new Vector3($blockPos->getFloorX(), $blockPos->getFloorY(), $blockPos->getFloorZ()),
                    'world' => $player->getWorld()->getFolderName(),
                    'knotId' => $knotId
                ];
                self::attachLeash($entity, $knotId);
            }
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        self::sendKnotsToPlayer($event->getPlayer());
    }

    public function onEntityTeleport(EntityTeleportEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            self::sendKnotsToPlayer($entity);
        }
    }

    public static function sendKnotsToPlayer(Player $player): void
    {
        $sentKnots = [];
        foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
            if ($fenceData['world'] === $player->getWorld()->getFolderName()) {
                $knotId = $fenceData['knotId'];
                if (in_array($knotId, $sentKnots, true)) {
                    continue;
                }
                $sentKnots[] = $knotId;
                
                $blockPos = $fenceData['pos'];
                $knotPos = new Vector3($blockPos->x + 0.5, $blockPos->y + 0.5, $blockPos->z + 0.5);
                $pk = AddActorPacket::create(
                    $knotId,
                    $knotId,
                    "minecraft:leash_knot",
                    $knotPos,
                    null,
                    0.0, 0.0, 0.0, 0.0,
                    [],
                    [EntityMetadataProperties::FLAGS => new LongMetadataProperty(0)],
                    new PropertySyncData([], []),
                    []
                );
                $player->getNetworkSession()->sendDataPacket($pk);
            }
        }
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();

        if ($packet instanceof InventoryTransactionPacket) {
            $trData = $packet->trData;
            if ($trData instanceof UseItemOnEntityTransactionData) {
                $actionType = $trData->getActionType();
                if ($actionType === UseItemOnEntityTransactionData::ACTION_INTERACT || $actionType === UseItemOnEntityTransactionData::ACTION_ITEM_INTERACT) {
                    $player = $event->getOrigin()->getPlayer();
                    if ($player !== null) {
                        $tick = Server::getInstance()->getTick();
                        if (isset(self::$justTied[$player->getId()]) && ($tick - self::$justTied[$player->getId()]) < 5) {
                            return;
                        }

                        $targetRuntimeId = $trData->getActorRuntimeId();
                        $entitiesToTransfer = [];
                        $knotIdToRemove = null;
                        
                        foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
                            if ($fenceData['knotId'] === $targetRuntimeId) {
                                $entitiesToTransfer[] = $entityId;
                                $knotIdToRemove = $targetRuntimeId;
                            }
                        }
                        
                        if (!empty($entitiesToTransfer)) {
                            $event->cancel();
                            foreach ($entitiesToTransfer as $eId) {
                                unset(self::$fenceLeashedEntities[$eId]);
                                $entity = $player->getWorld()->getEntity($eId);
                                if ($entity !== null) {
                                    self::$leashedEntities[$eId] = $player->getId();
                                    self::attachLeash($entity, $player->getId());
                                }
                            }
                            
                            if ($knotIdToRemove !== null) {
                                self::removeKnotIfUnused($knotIdToRemove, $player->getWorld());
                            }
                            
                            self::$justTied[$player->getId()] = $tick;
                            return;
                        }
                        $entity = $player->getWorld()->getEntity($targetRuntimeId);
                        if ($entity !== null) {
                            if (isset(self::$leashedEntities[$entity->getId()])) {
                                $event->cancel();
                                unset(self::$leashedEntities[$entity->getId()]);
                                self::detachLeash($entity);
                                $leadItem = StringToItemParser::getInstance()->parse("lead");
                                if (!$player->isCreative() && $leadItem !== null) {
                                    $player->getInventory()->addItem(clone $leadItem);
                                }
                                self::$justTied[$player->getId()] = $tick;
                                return;
                            }

                            if (isset(self::$fenceLeashedEntities[$entity->getId()])) {
                                $event->cancel();
                                $fenceData = self::$fenceLeashedEntities[$entity->getId()];
                                $knotId = $fenceData['knotId'];
                                unset(self::$fenceLeashedEntities[$entity->getId()]);
                                self::detachLeash($entity);
                                self::removeKnotIfUnused($knotId, $player->getWorld());
                                
                                $leadItem = StringToItemParser::getInstance()->parse("lead");
                                if (!$player->isCreative() && $leadItem !== null) {
                                    $player->getInventory()->addItem(clone $leadItem);
                                }
                                self::$justTied[$player->getId()] = $tick;
                                return;
                            }

                            if ($entity instanceof Animal) {
                                $item = $player->getInventory()->getItemInHand();
                                $leadItem = StringToItemParser::getInstance()->parse("lead");

                                if ($leadItem !== null && $item->getTypeId() === $leadItem->getTypeId()) {
                                    $event->cancel();
                                    self::$leashedEntities[$entity->getId()] = $player->getId();
                                    self::attachLeash($entity, $player->getId());
                                    unset(self::$entityLeashedEntities[$entity->getId()]);

                                    if (!$player->isCreative()) {
                                        $item->setCount($item->getCount() - 1);
                                        $player->getInventory()->setItemInHand($item);
                                    }
                                    self::$justTied[$player->getId()] = $tick;
                                    return;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public static function registerFenceLeash(Entity $entity, Vector3 $fencePos, string $worldName): void
    {
        $knotId = Entity::nextRuntimeId();
        self::$fenceLeashedEntities[$entity->getId()] = [
            'pos' => $fencePos,
            'world' => $worldName,
            'knotId' => $knotId
        ];
        self::attachLeash($entity, $knotId);
    }

    public static function getLeashHolder(Entity $entity): ?Vector3
    {
        $entityId = $entity->getId();
        if (isset(self::$leashedEntities[$entityId])) {
            $playerId = self::$leashedEntities[$entityId];
            foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                if ($p->getId() === $playerId) {
                    return $p->getLocation();
                }
            }
        }
        if (isset(self::$fenceLeashedEntities[$entityId])) {
            $pos = self::$fenceLeashedEntities[$entityId]['pos'];
            return new Vector3($pos->x + 0.5, $pos->y + 0.5, $pos->z + 0.5);
        }
        if (isset(self::$entityLeashedEntities[$entityId])) {
            $holderId = self::$entityLeashedEntities[$entityId];
            foreach ($entity->getWorld()->getEntities() as $e) {
                if ($e->getId() === $holderId) {
                    return $e->getLocation();
                }
            }
        }
        return null;
    }

    public function onEntityDespawn(EntityDespawnEvent $event): void
    {
        $entity = $event->getEntity();
        $entityId = $entity->getId();
        $isDead = !$entity->isAlive();

        if (isset(self::$fenceLeashedEntities[$entityId])) {
            $fenceData = self::$fenceLeashedEntities[$entityId];
            $knotId = $fenceData['knotId'];
            unset(self::$fenceLeashedEntities[$entityId]);
            self::removeKnotIfUnused($knotId, $entity->getWorld());

            if ($isDead) {
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), clone $leadItem);
                }
            }
        }

        if (isset(self::$leashedEntities[$entityId])) {
            unset(self::$leashedEntities[$entityId]);
            if ($isDead) {
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), clone $leadItem);
                }
            }
        }

        if (isset(self::$entityLeashedEntities[$entityId])) {
            unset(self::$entityLeashedEntities[$entityId]);
            if ($isDead) {
                $leadItem = StringToItemParser::getInstance()->parse("lead");
                if ($leadItem !== null) {
                    $entity->getWorld()->dropItem($entity->getLocation(), clone $leadItem);
                }
            }
        }
    }

    public function onPlayerPostChunkSend(PlayerPostChunkSendEvent $event): void
    {
        $player = $event->getPlayer();
        $chunkX = $event->getChunkX();
        $chunkZ = $event->getChunkZ();

        $sentKnots = [];
        foreach (self::$fenceLeashedEntities as $entityId => $fenceData) {
            if ($fenceData['world'] === $player->getWorld()->getFolderName()) {
                $blockPos = $fenceData['pos'];
                if (((int)$blockPos->x >> 4) === $chunkX && ((int)$blockPos->z >> 4) === $chunkZ) {
                    $knotId = $fenceData['knotId'];
                    if (in_array($knotId, $sentKnots, true)) {
                        continue;
                    }
                    $sentKnots[] = $knotId;
                    
                    $knotPos = new Vector3($blockPos->x + 0.5, $blockPos->y + 0.5, $blockPos->z + 0.5);
                    $pk = AddActorPacket::create(
                        $knotId,
                        $knotId,
                        "minecraft:leash_knot",
                        $knotPos,
                        null,
                        0.0, 0.0, 0.0, 0.0,
                        [],
                        [EntityMetadataProperties::FLAGS => new LongMetadataProperty(0)],
                        new PropertySyncData([], []),
                        []
                    );
                    $player->getNetworkSession()->sendDataPacket($pk);
                }
            }
        }
    }
}