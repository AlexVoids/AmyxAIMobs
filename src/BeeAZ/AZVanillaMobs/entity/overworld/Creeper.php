<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use pocketmine\world\Explosion;

class Creeper extends \BeeAZ\AZVanillaMobs\entity\Monster {
    private int $fuse = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:creeper";
    }

    protected function initEntity(\pocketmine\nbt\tag\CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->getNetworkProperties()->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::IGNITED, false);
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::IGNITED, $this->fuse > 0);
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        $threat = $this->findAvoidanceThreat();
        $target = $this->getCombatTarget();
        $shouldSwell = $threat === null
            && $target !== null
            && $this->location->distanceSquared($target->getLocation()) <= 7.84
            && $this->canSeeEntity($target);

        if ($shouldSwell) {
            if ($this->fuse === 0) {
                $this->getNetworkProperties()->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::IGNITED, true);
            }
            $this->fuse++;
            if ($this->fuse >= 30) {
                $explosion = new Explosion($this->getPosition(), 2.7, $this);

                // Main::onLoad() installs a compatibility alias required by
                // Essential's UpdateSubChunkBlocks packet. If the alias cannot
                // be installed, fall back to entity-only damage instead of
                // crashing the server.
                $plugin = \pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
                $allowBlockDamage = $plugin instanceof \BeeAZ\AZVanillaMobs\Main
                    && (bool) $plugin->getConfig()->get("explosion-block-damage", false)
                    && class_exists(\pocketmine\network\mcpe\protocol\types\ProtocolInfo::class);
                if ($allowBlockDamage) {
                    $explosion->explodeA();
                }
                $explosion->explodeB();
                $this->kill();
            }
        } elseif ($this->fuse > 0) {
            // Vanilla creepers defuse when the target escapes past roughly six blocks
            // or vision is lost.
            $this->fuse = max(0, $this->fuse - 2);
            if ($this->fuse === 0) {
                $this->getNetworkProperties()->setGenericFlag(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::IGNITED, false);
            }
        }

        return $hasUpdate || $this->fuse > 0;
    }

    protected function calculateAI(): void {
        parent::calculateAI();

        // Creepers should feel cautious rather than constantly sprinting around.
        // When idle they wander slowly and stay within a smaller area.
        if ($this->getCombatTarget() === null) {
            $this->setBehaviorSpeedMultiplier(0.55);
            if ($this->targetPosition !== null && $this->location->distanceSquared($this->targetPosition) > 30.0) {
                $this->targetPosition = $this->chooseRandomDestination(5.0, 2.0);
            }
        }
    }

    protected function getFollowRange(): float {
        return 16.0;
    }

    protected function getChaseSpeedMultiplier(): float {
        return 0.82;
    }

    protected function getMeleeReachSquared(): float {
        return 0.0;
    }
}
