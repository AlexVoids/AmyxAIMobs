<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;

class Cow extends Animal {
    public int $milkingCooldown = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:cow";
    }

    protected function getBreedingItem(): \pocketmine\item\Item {
        return \pocketmine\item\VanillaItems::WHEAT();
    }

    public function onUpdate(int $currentTick): bool {
        if ($this->milkingCooldown > 0) {
            $this->milkingCooldown--;
        }
        return parent::onUpdate($currentTick);
    }

    public function canBeMilked(): bool {
        return $this->milkingCooldown <= 0;
    }

    public function setMilkingCooldown(int $ticks = 300): void {
        $this->milkingCooldown = $ticks;
    }
}
