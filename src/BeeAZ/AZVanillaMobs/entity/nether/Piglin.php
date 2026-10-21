<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\nether;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;

class Piglin extends Monster {
    private int $barterTicks = 0;
    private ?ItemEntity $targetGoldEntity = null;
    private ?Item $heldItem = null;

    protected function sendSpawnPacket(\pocketmine\player\Player $player): void {
        parent::sendSpawnPacket($player);
        $this->sendHeldItem($player, $this->heldItem ?? \pocketmine\item\VanillaItems::GOLDEN_SWORD());
    }

    private function equipItem(Item $item): void {
        $this->heldItem = clone $item;
        $this->broadcastHeldItem($this->heldItem);
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:piglin";
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if ($this->barterTicks > 0) {
            $this->barterTicks--;
            if ($this->barterTicks <= 0) {
                $this->barter();
                $this->equipItem(\pocketmine\item\VanillaItems::GOLDEN_SWORD());
            }
            $hasUpdate = true;
        }
        return $hasUpdate;
    }

    private function isWearingGoldArmor(Player $player): bool {
        $armorInventory = $player->getArmorInventory();
        foreach ($armorInventory->getContents() as $item) {
            $typeId = $item->getTypeId();
            if (
                $typeId === \pocketmine\item\VanillaItems::GOLDEN_HELMET()->getTypeId() ||
                $typeId === \pocketmine\item\VanillaItems::GOLDEN_CHESTPLATE()->getTypeId() ||
                $typeId === \pocketmine\item\VanillaItems::GOLDEN_LEGGINGS()->getTypeId() ||
                $typeId === \pocketmine\item\VanillaItems::GOLDEN_BOOTS()->getTypeId()
            ) {
                return true;
            }
        }
        return false;
    }

    protected function calculateAI(): void {
        if ($this->barterTicks > 0) {
            $this->targetPosition = null;
            return;
        }

        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());

        $threat = $this->findAvoidanceThreat();
        if ($threat !== null) {
            $this->clearCombatTarget();
            $this->targetGoldEntity = null;
            $this->targetPosition = $this->chooseEscapeDestination($threat, 9.0);
            $this->setBehaviorSpeedMultiplier(1.2);
            return;
        }

        $combatTarget = $this->getRevengeTarget() ?? $this->getCombatTarget();
        if ($combatTarget === null || !$this->isValidNaturalTarget($combatTarget)) {
            $combatTarget = $this->selectNaturalTarget();
        }

        if ($combatTarget !== null) {
            $this->targetGoldEntity = null;
            $this->setCombatTarget($combatTarget, 500);
            $this->targetPosition = clone $combatTarget->getLocation();
            if ($this->location->distanceSquared($combatTarget->getLocation()) < 2.8 && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($combatTarget, $this->getAttackDamage(), 2.8)) {
                    $this->attackDelay = 20;
                }
            }
            return;
        }

        $this->clearCombatTarget();

        // Gold admiration and barter pickup is only considered while calm.
        if ($this->targetGoldEntity !== null && !$this->targetGoldEntity->isClosed() && $this->targetGoldEntity->getWorld() === $this->getWorld()) {
            $dist = $this->location->distanceSquared($this->targetGoldEntity->getLocation());
            if ($dist < 1.8) {
                $item = $this->targetGoldEntity->getItem();
                if ($item->getCount() > 1) {
                    $this->targetGoldEntity->setStackSize($item->getCount() - 1);
                } else {
                    $this->targetGoldEntity->flagForDespawn();
                }
                $this->targetGoldEntity = null;
                $this->targetPosition = null;
                $this->equipItem(\pocketmine\item\VanillaItems::GOLD_INGOT());
                $this->barterTicks = 40;
                return;
            }
            $this->targetPosition = clone $this->targetGoldEntity->getLocation();
            return;
        }

        $this->targetGoldEntity = null;
        $nearestGold = null;
        $nearestGoldDist = 100.0;
        foreach ($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(10, 5, 10), $this) as $entity) {
            if ($entity instanceof ItemEntity && $entity->getItem()->getTypeId() === \pocketmine\item\VanillaItems::GOLD_INGOT()->getTypeId()) {
                $dist = $this->location->distanceSquared($entity->getLocation());
                if ($dist < $nearestGoldDist) {
                    $nearestGoldDist = $dist;
                    $nearestGold = $entity;
                }
            }
        }
        if ($nearestGold !== null) {
            $this->targetGoldEntity = $nearestGold;
            $this->targetPosition = clone $nearestGold->getLocation();
            return;
        }

        if ($this->targetPosition === null || mt_rand(1, 100) <= 6) {
            $this->targetPosition = $this->chooseRandomDestination(10.0, 2.0);
        }
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool {
        if ($this->barterTicks > 0) return false;

        $item = $player->getInventory()->getItemInHand();
        if ($item->getTypeId() === \pocketmine\item\VanillaItems::GOLD_INGOT()->getTypeId()) {
            $item->pop();
            $player->getInventory()->setItemInHand($item);

            $pk = new \pocketmine\network\mcpe\protocol\AnimatePacket();
            $pk->action = \pocketmine\network\mcpe\protocol\AnimatePacket::ACTION_SWING_ARM;
            $pk->actorRuntimeId = $this->getId();
            $this->getWorld()->broadcastPacketToViewers($this->getLocation(), $pk);

            $this->equipItem(\pocketmine\item\VanillaItems::GOLD_INGOT());
            $this->barterTicks = 40;
            return true;
        }
        return parent::onInteract($player, $clickPos);
    }

    private function barter(): void {
        $lootTable = [
            fn() => \pocketmine\item\VanillaItems::ENDER_PEARL()->setCount(mt_rand(2, 4)),
            fn() => \pocketmine\item\VanillaItems::FIRE_CHARGE()->setCount(mt_rand(1, 5)),
            fn() => \pocketmine\block\VanillaBlocks::OBSIDIAN()->asItem()->setCount(mt_rand(1, 1)),
            fn() => \pocketmine\item\VanillaItems::LEATHER()->setCount(mt_rand(2, 5)),
            fn() => \pocketmine\item\VanillaItems::IRON_INGOT()->setCount(mt_rand(2, 5)),
            fn() => \pocketmine\item\VanillaItems::GLOWSTONE_DUST()->setCount(mt_rand(2, 5)),
            fn() => \pocketmine\item\VanillaItems::STRING()->setCount(mt_rand(4, 12)),
            fn() => \pocketmine\item\VanillaItems::NETHER_QUARTZ()->setCount(mt_rand(4, 12)),
            fn() => \pocketmine\block\VanillaBlocks::GRAVEL()->asItem()->setCount(mt_rand(8, 16)),
        ];

        $randomLoot = $lootTable[array_rand($lootTable)]();
        $this->getWorld()->dropItem($this->getLocation(), $randomLoot);
        $this->getWorld()->addSound($this->getLocation(), new \pocketmine\world\sound\PopSound());
    }
}
