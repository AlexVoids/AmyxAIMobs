<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\the_end;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;

class Enderman extends Monster {

    private int $heldBlockStateId = 0;
    private ?Player $staringPlayer = null;
    private int $stareTicks = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:enderman";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(2.9, 0.6, 2.55);
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->setMaxHealth(40);
        $this->setHealth(40);

        $this->heldBlockStateId = $nbt->getInt("HeldBlockStateId", 0);
        if ($this->heldBlockStateId !== 0) {
            $networkId = \pocketmine\network\mcpe\convert\TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->heldBlockStateId);
            $this->getNetworkProperties()->setShort(EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID, $networkId);
        }
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("HeldBlockStateId", $this->heldBlockStateId);
        return $nbt;
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($currentTick % 5 === 0 && $this->getCombatTarget() === null) {
            $candidate = null;
            $bestDistance = 64.0 * 64.0;
            foreach ($this->getWorld()->getPlayers() as $player) {
                if ($player->isCreative() || $player->isSpectator() || !$player->isAlive()) {
                    continue;
                }
                $distance = $this->location->distanceSquared($player->getLocation());
                if ($distance < $bestDistance && $this->isPlayerLookingAtMe($player)) {
                    $candidate = $player;
                    $bestDistance = $distance;
                }
            }

            if ($candidate !== null && $candidate === $this->staringPlayer) {
                $this->stareTicks += 5;
            } elseif ($candidate !== null) {
                $this->staringPlayer = $candidate;
                $this->stareTicks = 5;
            } else {
                $this->staringPlayer = null;
                $this->stareTicks = 0;
            }

            if ($this->staringPlayer !== null && $this->stareTicks >= 5) {
                $this->setCombatTarget($this->staringPlayer, 500);
            }
        }

        $block = $this->getWorld()->getBlock($this->location);
        if ($block instanceof \pocketmine\block\Water) {
            $ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_CONTACT, 1.0);
            $this->attack($ev);
            $this->teleportRandomly();
            $hasUpdate = true;
        }

        $teleportInterval = $this->targetPosition !== null ? mt_rand(80, 160) : mt_rand(200, 400);
        if ($currentTick % $teleportInterval === 0) {
            $this->teleportRandomly();
            $hasUpdate = true;
        }

        if ($this->targetPosition === null) {
            if ($this->heldBlockStateId === 0) {
                if (mt_rand(1, 100) <= 2 && $currentTick % 100 === 0) {
                    $this->tryStealBlock();
                }
            } else {
                if (mt_rand(1, 100) <= 2 && $currentTick % 100 === 0) {
                    $this->tryPlaceBlock();
                }
            }
        }

        return $hasUpdate;
    }

    public function attack(EntityDamageEvent $source): void {
        if ($source->getCause() === EntityDamageEvent::CAUSE_PROJECTILE) {
            $source->cancel();
            $this->teleportRandomly();
            return;
        }

        parent::attack($source);

        if ($this->isAlive() && mt_rand(1, 100) <= 50) {
            $this->teleportRandomly();
        }
    }

    public function teleportRandomly(): bool {
        return $this->teleportNear($this->getLocation(), 15, 8);
    }

    public function teleportNear(Vector3 $target, int $horizontalRange = 5, int $verticalRange = 3): bool {
        $world = $this->getWorld();

        for ($i = 0; $i < 16; $i++) {
            $randX = floor($target->x) + mt_rand(-$horizontalRange, $horizontalRange) + 0.5;
            $randZ = floor($target->z) + mt_rand(-$horizontalRange, $horizontalRange) + 0.5;
            $randY = floor($target->y) + mt_rand(-$verticalRange, $verticalRange);

            $pos = new Vector3($randX, $randY, $randZ);
            $block = $world->getBlock($pos);
            $blockBelow = $world->getBlock($pos->subtract(0, 1, 0));
            $blockAbove = $world->getBlock($pos->add(0, 1, 0));
            $blockAbove2 = $world->getBlock($pos->add(0, 2, 0));

            if ($blockBelow->isSolid() && !$block->isSolid() && !$blockAbove->isSolid() && !$blockAbove2->isSolid() && !($block instanceof \pocketmine\block\Water)) {
                $world->addSound($this->location, new \pocketmine\world\sound\EndermanTeleportSound());
                $world->addParticle($this->location, new \pocketmine\world\particle\EndermanTeleportParticle());

                $this->teleport($pos);

                $world->addSound($pos, new \pocketmine\world\sound\EndermanTeleportSound());
                $world->addParticle($pos, new \pocketmine\world\particle\EndermanTeleportParticle());

                $this->targetPosition = null;
                return true;
            }
        }
        return false;
    }

    protected function calculateAI(): void {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());

        $target = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($target === null) {
            $target = $this->findNearestLiving(
                fn(\pocketmine\entity\Living $entity): bool => $entity instanceof Endermite,
                32.0,
                true
            );
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 500);
            $this->targetPosition = clone $target->getLocation();
            $this->setBehaviorSpeedMultiplier(1.5);
            $distance = $this->location->distanceSquared($target->getLocation());

            if ($distance < 3.2 && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($target, $this->getAttackDamage(), 3.2)) {
                    $this->attackDelay = 20;
                }
            } elseif ($distance > 144.0 && mt_rand(1, 100) <= 30) {
                $this->teleportNear($target->getLocation(), 4, 3);
            }
            return;
        }

        $this->clearCombatTarget();
        $this->setBehaviorSpeedMultiplier(1.0);
        if ($this->targetPosition === null || mt_rand(1, 100) <= 5) {
            $this->targetPosition = $this->chooseRandomDestination(14.0, 3.0);
        }
    }

    private function isPlayerLookingAtMe(Player $player): bool {
        $helmet = $player->getArmorInventory()->getHelmet();
        if (!$helmet->isNull() && str_contains(strtolower($helmet->getName()), 'pumpkin')) {
            return false;
        }
        if (!$this->canSeeEntity($player)) {
            return false;
        }

        $fromPlayer = $this->location->add(0, $this->getEyeHeight(), 0)
            ->subtractVector($player->getLocation()->add(0, $player->getEyeHeight(), 0));
        $distance = $fromPlayer->length();
        if ($distance <= 0.001) {
            return true;
        }
        $directionToEnderman = $fromPlayer->multiply(1.0 / $distance);
        $look = $player->getDirectionVector()->normalize();
        $dot = $look->dot($directionToEnderman);
        return $dot > 1.0 - (0.025 / max($distance, 1.0));
    }

    private function tryStealBlock(): void {
        $world = $this->getWorld();
        $pos = $this->location->floor();

        for ($x = -1; $x <= 1; $x++) {
            for ($y = -1; $y <= 2; $y++) {
                for ($z = -1; $z <= 1; $z++) {
                    $blockPos = $pos->add($x, $y, $z);
                    $block = $world->getBlock($blockPos);
                    $name = strtolower($block->getName());

                    $isStealable = false;
                    foreach (["grass", "dirt", "sand", "gravel", "clay", "pumpkin", "melon", "tnt", "flower", "dandelion", "poppy", "orchid", "allium", "tulip", "daisy", "rose"] as $term) {
                        if (str_contains($name, $term)) {
                            $isStealable = true;
                            break;
                        }
                    }

                    if ($isStealable) {
                        $this->heldBlockStateId = $block->getStateId();
                        $world->setBlock($blockPos, \pocketmine\block\VanillaBlocks::AIR());

                        $networkId = \pocketmine\network\mcpe\convert\TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->heldBlockStateId);
                        $this->getNetworkProperties()->setShort(EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID, $networkId);
                        return;
                    }
                }
            }
        }
    }

    private function tryPlaceBlock(): void {
        if ($this->heldBlockStateId === 0) return;

        $world = $this->getWorld();
        $pos = $this->location->floor();

        for ($x = -1; $x <= 1; $x++) {
            for ($z = -1; $z <= 1; $z++) {
                for ($y = -1; $y <= 1; $y++) {
                    $blockPos = $pos->add($x, $y, $z);
                    $block = $world->getBlock($blockPos);
                    $blockAbove = $world->getBlock($blockPos->add(0, 1, 0));

                    if ($block->isSolid() && $blockAbove->getTypeId() === \pocketmine\block\BlockTypeIds::AIR) {
                        $placePos = $blockPos->add(0, 1, 0);
                        $heldBlock = \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($this->heldBlockStateId);
                        $world->setBlock($placePos, $heldBlock);

                        $this->heldBlockStateId = 0;
                        $this->getNetworkProperties()->setShort(EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID, 0);
                        return;
                    }
                }
            }
        }
    }

    public function getDrops(): array {
        $drops = parent::getDrops();
        if ($this->heldBlockStateId !== 0) {
            $block = \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->fromStateId($this->heldBlockStateId);
            $drops[] = $block->asItem();
        }
        return $drops;
    }
}