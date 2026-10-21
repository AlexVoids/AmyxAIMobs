<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\entity\Location;

class Zombie extends Monster {

    private int $conversionTicks = 0;

    public static function getNetworkTypeId(): string {
        return "minecraft:zombie";
    }

    public function isUndead(): bool {
        return true;
    }

    public function onUpdate(int $currentTick): bool {
        $hasUpdate = parent::onUpdate($currentTick);
        if (!$this->isAlive() || $this->isClosed()) {
            return $hasUpdate;
        }

        if ($this->isInsideOfWater() || $this->isUnderwater()) {
            $this->conversionTicks++;
            if ($this->conversionTicks >= 600) { 
                $this->convertToDrowned();
                return false;
            }
        } else {
            $this->conversionTicks = 0;
        }

        return $hasUpdate;
    }

    private function convertToDrowned(): void {
        $drowned = new Drowned(Location::fromObject($this->location, $this->getWorld(), $this->location->yaw, $this->location->pitch));
        
        $drowned->setHealth(min($this->getHealth(), $drowned->getMaxHealth()));
        $drowned->setMaxHealth($this->getMaxHealth());
        
        if ($this->getNameTag() !== "") {
            $drowned->setNameTag($this->getNameTag());
            $drowned->setNameTagVisible($this->isNameTagVisible());
            $drowned->setNameTagAlwaysVisible($this->isNameTagAlwaysVisible());
        }

        
        $drowned->getArmorInventory()->setContents($this->getArmorInventory()->getContents());

        $this->close();
        $drowned->spawnToAll();
    }
}
