<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\BaseMob;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;

class IronGolem extends BaseMob {

    private int $angerTicks = 0;
    private ?Player $angryTarget = null;
    private int $attackDelay = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:iron_golem";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(2.7, 1.4);
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->setMaxHealth(100);
        $this->setHealth(100);
    }

    public function getMovementSpeed(): float {
        return $this->targetEntity !== null ? 0.32 : 0.22;
    }

    public function attack(EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof \pocketmine\entity\Living
                && !($damager instanceof Creeper)
                && !($damager instanceof Player && $damager->isCreative())) {
                $this->targetEntity = $damager;
                $this->angryTarget = $damager instanceof Player ? $damager : null;
                $this->angerTicks = 600;
            }
        }
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool {
        $item = $player->getInventory()->getItemInHand();
        if ($this->getHealth() < $this->getMaxHealth()
            && $item->getTypeId() === \pocketmine\item\VanillaItems::IRON_INGOT()->getTypeId()) {
            $this->setHealth(min($this->getMaxHealth(), $this->getHealth() + 25));
            if (!$player->isCreative()) {
                $item->pop();
                $player->getInventory()->setItemInHand($item);
            }
            $this->getWorld()->addSound($this->location, new \pocketmine\world\sound\PopSound());
            return true;
        }
        return parent::onInteract($player, $clickPos);
    }

    protected function calculateAI(): void {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());
        $this->angerTicks = max(0, $this->angerTicks - $this->getAIUpdateInterval());

        $target = $this->getRevengeTarget();
        if ($target === null && $this->targetEntity !== null && $this->targetEntity->isAlive() && !$this->targetEntity->isClosed()) {
            $target = $this->targetEntity;
        }
        if ($target === null || $target instanceof Creeper) {
            $target = $this->findNearestLiving(
                fn(\pocketmine\entity\Living $entity): bool => $entity instanceof \BeeAZ\AZVanillaMobs\entity\Monster && !($entity instanceof Creeper),
                32.0,
                true
            );
        }

        $this->targetEntity = $target;
        if ($target !== null) {
            $this->targetPosition = clone $target->getLocation();
            if ($this->location->distanceSquared($target->getLocation()) < 4.5 && $this->attackDelay <= 0) {
                $damage = (float) mt_rand(7, 21);
                if ($this->performMeleeAttack($target, $damage, 4.5)) {
                    $motion = $target->getMotion();
                    $target->setMotion(new Vector3($motion->x, 0.65, $motion->z));
                    $this->attackDelay = 20;
                }
            }
            return;
        }

        if ($this->targetPosition === null || mt_rand(1, 100) <= 15) {
            $this->targetPosition = mt_rand(1, 100) <= 60 ? $this->chooseRandomDestination(8.0, 2.0) : null;
        }
    }

    public function getXpDropAmount(): int {
        return 0;
    }
}
