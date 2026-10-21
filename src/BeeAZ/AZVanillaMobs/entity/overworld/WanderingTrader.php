<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\Location;

class WanderingTrader extends Villager {
    private bool $scheduledRemoval = false;
    public bool $spawnLlamas = false;

    public function getProfessionName(): string {
        return "Странствующий торговец";
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:wandering_trader";
    }
    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        // Wandering traders are disabled in this build. Existing entities are
        // left registered only to despawn cleanly when old chunks are loaded.
        $this->setNameTag("Странствующий торговец");
        $this->setNameTagVisible(false);
        $this->setNameTagAlwaysVisible(false);
        $this->spawnLlamas = false;
        $this->scheduledRemoval = true;
    }

    public function entityBaseTick(int $tickDiff = 1): bool {
        $hasUpdate = parent::entityBaseTick($tickDiff);
        if($this->scheduledRemoval && !$this->isFlaggedForDespawn()){
            $this->flagForDespawn();
        }
        return $hasUpdate;
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("WanderingTradeVersion", 3);
        $nbt->setInt("LlamasSpawned", 1);
        return $nbt;
    }
}
