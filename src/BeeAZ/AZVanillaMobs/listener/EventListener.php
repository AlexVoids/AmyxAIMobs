<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\listener;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\overworld\Zombie;
use BeeAZ\AZVanillaMobs\entity\overworld\SnowGolem;
use BeeAZ\AZVanillaMobs\entity\overworld\IronGolem;
use BeeAZ\AZVanillaMobs\entity\overworld\Wolf;
use BeeAZ\AZVanillaMobs\entity\overworld\Bee;
use BeeAZ\AZVanillaMobs\entity\overworld\Creeper;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\player\Player;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\scheduler\Task;

class EventListener implements Listener
{
    public static array $rainingWorlds = [];
    private Main $plugin;

    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;
    }

    public static function isWorldRaining(World $world): bool
    {
        return isset(self::$rainingWorlds[$world->getFolderName()]);
    }

    public function onEntitySpawn(EntitySpawnEvent $event): void
    {
        $entity = $event->getEntity();
        if (get_class($entity) === 'pocketmine\entity\Zombie') {
            $location = $entity->getLocation();
            $newZombie = new Zombie($location);
            $newZombie->setHealth($entity->getHealth());
            $newZombie->setMaxHealth($entity->getMaxHealth());
            $entity->close();
            $newZombie->spawnToAll();
        }
    }

    public function onDataPacketSend(DataPacketSendEvent $event): void
    {
        foreach ($event->getPackets() as $packet) {
            if ($packet instanceof LevelEventPacket) {
                if ($packet->eventId === LevelEvent::START_RAIN || $packet->eventId === LevelEvent::START_THUNDER) {
                    foreach ($event->getTargets() as $target) {
                        $player = $target->getPlayer();
                        if ($player !== null) {
                            self::$rainingWorlds[$player->getWorld()->getFolderName()] = true;
                        }
                    }
                } elseif ($packet->eventId === LevelEvent::STOP_RAIN || $packet->eventId === LevelEvent::STOP_THUNDER) {
                    foreach ($event->getTargets() as $target) {
                        $player = $target->getPlayer();
                        if ($player !== null) {
                            unset(self::$rainingWorlds[$player->getWorld()->getFolderName()]);
                        }
                    }
                }
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $block = $event->getBlock();
        $blockName = strtolower($block->getName());

        if (str_contains($blockName, "beehive") || str_contains($blockName, "bee nest") || str_contains($blockName, "bee_nest") || str_contains($blockName, "tổ ong")) {
            $player = $event->getPlayer();
            if ($player->isCreative()) return;

            foreach ($block->getPosition()->getWorld()->getEntities() as $entity) {
                if ($entity instanceof Bee) {
                    if ($entity->getLocation()->distanceSquared($block->getPosition()) <= 256) {
                        $entity->anger($player);
                    }
                }
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void
    {
        if ($event->isCancelled()) return;
        $block = null;
        foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $b]) {
            $block = $b;
        }
        if ($block === null) return;

        $typeId = $block->getTypeId();

        if ($typeId === BlockTypeIds::CARVED_PUMPKIN || $typeId === BlockTypeIds::LIT_PUMPKIN || $typeId === BlockTypeIds::PUMPKIN) {
            $world = $block->getPosition()->getWorld();
            $pos = $block->getPosition();

            $below1 = $world->getBlock($pos->subtract(0, 1, 0));
            $below2 = $world->getBlock($pos->subtract(0, 2, 0));

            if ($below1->getTypeId() === BlockTypeIds::SNOW && $below2->getTypeId() === BlockTypeIds::SNOW) {
                $this->plugin->getScheduler()->scheduleDelayedTask(
                    new class($world, $pos) extends Task {
                        private $world;
                        private $pos;
                        public function __construct($world, $pos)
                        {
                            $this->world = $world;
                            $this->pos = $pos;
                        }
                        public function onRun(): void
                        {
                            $this->world->setBlock($this->pos, VanillaBlocks::AIR());
                            $this->world->setBlock($this->pos->subtract(0, 1, 0), VanillaBlocks::AIR());
                            $this->world->setBlock($this->pos->subtract(0, 2, 0), VanillaBlocks::AIR());
                        }
                    },
                    1
                );

                $location = new Location($pos->getX() + 0.5, $pos->getY() - 2, $pos->getZ() + 0.5, $world, (float) mt_rand(0, 360), 0.0);
                $golem = new SnowGolem($location);
                $golem->spawnToAll();
                return;
            }

            if ($below1->getTypeId() === BlockTypeIds::IRON && $below2->getTypeId() === BlockTypeIds::IRON) {
                $leftArm = $world->getBlock($pos->add(-1, -1, 0));
                $rightArm = $world->getBlock($pos->add(1, -1, 0));
                $isXAligned = ($leftArm->getTypeId() === BlockTypeIds::IRON && $rightArm->getTypeId() === BlockTypeIds::IRON);

                $frontArm = $world->getBlock($pos->add(0, -1, -1));
                $backArm = $world->getBlock($pos->add(0, -1, 1));
                $isZAligned = ($frontArm->getTypeId() === BlockTypeIds::IRON && $backArm->getTypeId() === BlockTypeIds::IRON);

                if ($isXAligned || $isZAligned) {
                    $this->plugin->getScheduler()->scheduleDelayedTask(
                        new class($world, $pos, $isXAligned) extends Task {
                            private $world;
                            private $pos;
                            private $isXAligned;
                            public function __construct($world, $pos, $isXAligned)
                            {
                                $this->world = $world;
                                $this->pos = $pos;
                                $this->isXAligned = $isXAligned;
                            }
                            public function onRun(): void
                            {
                                $this->world->setBlock($this->pos, VanillaBlocks::AIR());
                                $this->world->setBlock($this->pos->subtract(0, 1, 0), VanillaBlocks::AIR());
                                $this->world->setBlock($this->pos->subtract(0, 2, 0), VanillaBlocks::AIR());

                                if ($this->isXAligned) {
                                    $this->world->setBlock($this->pos->add(-1, -1, 0), VanillaBlocks::AIR());
                                    $this->world->setBlock($this->pos->add(1, -1, 0), VanillaBlocks::AIR());
                                } else {
                                    $this->world->setBlock($this->pos->add(0, -1, -1), VanillaBlocks::AIR());
                                    $this->world->setBlock($this->pos->add(0, -1, 1), VanillaBlocks::AIR());
                                }
                            }
                        },
                        1
                    );

                    $location = new Location($pos->getX() + 0.5, $pos->getY() - 2, $pos->getZ() + 0.5, $world, (float) mt_rand(0, 360), 0.0);
                    $golem = new IronGolem($location);
                    $golem->spawnToAll();
                }
            }
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void
    {
        if ($event->isCancelled()) return;
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            $entity = $event->getEntity();

            if($damager instanceof Creeper && $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION){
                $maxDamage = max(1.0, (float) $this->plugin->getConfig()->get("creeper-max-damage", 16.0));
                if($event->getBaseDamage() > $maxDamage){
                    $event->setBaseDamage($maxDamage);
                }
            }

            if ($entity instanceof Player && $damager instanceof Living) {
                $uuid = $entity->getUniqueId()->toString();
                foreach ($entity->getWorld()->getNearbyEntities($entity->getBoundingBox()->expandedCopy(16, 8, 16)) as $near) {
                    if ($near instanceof Wolf && $near->isTamed() && $near->getOwnerUuid() === $uuid && !$near->isSitting()) {
                        $near->setAngryTarget($damager);
                    }
                }
            }

            if ($damager instanceof Player && $entity instanceof Living) {
                if (!($entity instanceof Wolf && $entity->isTamed() && $entity->getOwnerUuid() === $damager->getUniqueId()->toString())) {
                    $uuid = $damager->getUniqueId()->toString();
                    foreach ($damager->getWorld()->getNearbyEntities($damager->getBoundingBox()->expandedCopy(16, 8, 16)) as $near) {
                        if ($near instanceof Wolf && $near->isTamed() && $near->getOwnerUuid() === $uuid && !$near->isSitting()) {
                            $near->setAngryTarget($entity);
                        }
                    }
                }
            }
        }
    }
}