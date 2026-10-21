<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\player\Player;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;

class Bee extends Animal {

    private int $angerTicks = 0;
    private ?Living $angerTarget = null;
    private bool $isAngry = false;
    private bool $hasStung = false;
    private int $deathAfterStingTicks = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:bee";
    }

    public function isFlying(): bool {
        return true;
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $name = strtolower($item->getName());
        return str_contains($name, "flower") ||
               str_contains($name, "tulip") ||
               str_contains($name, "orchid") ||
               str_contains($name, "allium") ||
               str_contains($name, "poppy") ||
               str_contains($name, "dandelion") ||
               str_contains($name, "daisy") ||
               str_contains($name, "rose") ||
               str_contains($name, "lilac") ||
               str_contains($name, "peony");
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->angerTicks = $nbt->getInt("AngerTicks", 0);
        $this->isAngry = $this->angerTicks > 0;
        $this->hasStung = $nbt->getByte("HasStung", 0) === 1;
        $this->deathAfterStingTicks = $nbt->getInt("DeathAfterSting", 0);
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("AngerTicks", $this->angerTicks);
        $nbt->setByte("HasStung", $this->hasStung ? 1 : 0);
        $nbt->setInt("DeathAfterSting", $this->deathAfterStingTicks);
        return $nbt;
    }

    public function setAngry(bool $angry): void {
        $this->isAngry = $angry;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::ANGRY, $angry);
        if (!$angry) {
            $this->angerTicks = 0;
            $this->angerTarget = null;
        }
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry);
    }

    public function isAngry(): bool {
        return $this->isAngry;
    }

    public function anger(?Living $target, bool $broadcast = true): void {
        if ($this->hasStung || $target === null || !$this->canUseAsTarget($target)) {
            return;
        }
        $this->setAngry(true);
        $this->angerTicks = 500;
        $this->angerTarget = $target;
        $this->setCombatTarget($target, 500);

        if ($broadcast) {
            foreach ($this->getNearbyLiving(20.0, 10.0) as $nearby) {
                if ($nearby instanceof self && !$nearby->hasStung) {
                    $nearby->anger($target, false);
                }
            }
        }
    }

    public function attack(EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Living) {
                $this->anger($damager);
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->isAngry()) {
            if ($this->angerTicks > 0) {
                $this->angerTicks--;
                if ($this->angerTarget !== null && (!$this->angerTarget->isAlive() || $this->angerTarget->isClosed() || $this->angerTarget->getWorld() !== $this->getWorld()
                    || ($this->angerTarget instanceof Player && ($this->angerTarget->isCreative() || !$this->angerTarget->isOnline())))) {
                    $this->setAngry(false);
                }
            } else {
                $this->setAngry(false);
            }
            $hasUpdate = true;
        }

        if ($this->hasStung && $this->deathAfterStingTicks > 0) {
            $this->deathAfterStingTicks--;
            if ($this->deathAfterStingTicks <= 0) {
                $this->kill();
            }
            $hasUpdate = true;
        }

        return $hasUpdate;
    }

    protected function calculateAI(): void {
        if ($this->isAngry() && $this->angerTarget !== null) {
            $player = $this->angerTarget;
            $this->targetPosition = clone $player->getLocation();

            $dist = $this->location->distanceSquared($player->getLocation());
            if ($dist < 2.0 && !$this->hasStung) {
                $ev = new \pocketmine\event\entity\EntityDamageByEntityEvent($this, $player, \pocketmine\event\entity\EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2.0);
                $player->attack($ev);
                if (!$ev->isCancelled()) {
                    $player->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(
                        \pocketmine\entity\effect\VanillaEffects::POISON(), 120, 0
                    ));
                    $this->hasStung = true;
                    $this->deathAfterStingTicks = mt_rand(200, 1200);
                }
                $this->setAngry(false);
            }
            return;
        }

        parent::calculateAI();
    }
}
