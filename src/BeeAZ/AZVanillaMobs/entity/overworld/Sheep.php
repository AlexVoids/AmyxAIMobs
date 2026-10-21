<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\Dye;
use pocketmine\world\sound\PopSound;
use pocketmine\world\particle\SmokeParticle;

class Sheep extends Animal {

    private int $woolColor = 0;
    private bool $isSheared = false;

    public static function getNetworkTypeId(): string {
        return "minecraft:sheep";
    }

    protected function getBreedingItem(): Item {
        return VanillaItems::WHEAT();
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);

        if ($nbt->getTag("WoolColor") !== null) {
            $this->woolColor = $nbt->getInt("WoolColor");
        } else {

            $r = mt_rand(1, 1000);
            if ($r <= 5) {
                $this->woolColor = 6;
            } elseif ($r <= 55) {
                $this->woolColor = 12;
            } elseif ($r <= 155) {
                $this->woolColor = 15;
            } elseif ($r <= 255) {
                $this->woolColor = 7;
            } elseif ($r <= 355) {
                $this->woolColor = 8;
            } else {
                $this->woolColor = 0;
            }
        }

        $this->isSheared = $nbt->getByte("IsSheared", 0) === 1;

        $this->getNetworkProperties()->setByte(EntityMetadataProperties::COLOR, $this->woolColor);
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SHEARED, $this->isSheared);
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("WoolColor", $this->woolColor);
        $nbt->setByte("IsSheared", $this->isSheared ? 1 : 0);
        return $nbt;
    }

    public function getWoolColor(): int {
        return $this->woolColor;
    }

    public function setWoolColor(int $color): void {
        $this->woolColor = $color;
        $this->getNetworkProperties()->setByte(EntityMetadataProperties::COLOR, $color);
    }

    public function isSheared(): bool {
        return $this->isSheared;
    }

    public function setSheared(bool $sheared): void {
        $this->isSheared = $sheared;
        $this->getNetworkProperties()->setGenericFlag(EntityMetadataFlags::SHEARED, $sheared);
    }

    protected function syncNetworkData(\pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setByte(EntityMetadataProperties::COLOR, $this->woolColor);
        $properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->isSheared);
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool {
        $item = $player->getInventory()->getItemInHand();

        if ($item->getTypeId() === VanillaItems::SHEARS()->getTypeId()) {
            if (!$this->isSheared) {
                $this->setSheared(true);

                $cases = DyeColor::cases();
                $dye = $cases[$this->woolColor] ?? DyeColor::WHITE();
                $woolItem = VanillaBlocks::WOOL()->setColor($dye)->asItem();
                $woolItem->setCount(mt_rand(1, 3));

                $this->getWorld()->dropItem($this->location, $woolItem);

                if (!$player->isCreative()) {
                    $item->setDamage($item->getDamage() + 1);
                    if ($item->getDamage() >= $item->getMaxDamage()) {
                        $player->getInventory()->setItemInHand(VanillaItems::AIR());
                    } else {
                        $player->getInventory()->setItemInHand($item);
                    }
                }

                $this->getWorld()->addSound($this->location, new PopSound());
                return true;
            }
        }

        if ($item instanceof Dye) {
            $cases = DyeColor::cases();
            $colorIndex = array_search($item->getColor(), $cases, true);
            if ($colorIndex !== false) {
                if ($this->woolColor !== $colorIndex) {
                    $this->setWoolColor($colorIndex);

                    if (!$player->isCreative()) {
                        $item->pop();
                        $player->getInventory()->setItemInHand($item);
                    }

                    $this->getWorld()->addSound($this->location, new PopSound());
                    return true;
                }
            }
        }

        return parent::onInteract($player, $clickPos);
    }

    protected function calculateAI(): void {
        parent::calculateAI();

        if ($this->isSheared && mt_rand(1, 100) <= 8) {
            $blockBelow = $this->getWorld()->getBlock($this->location->subtract(0, 0.5, 0));
            if ($blockBelow->getTypeId() === VanillaBlocks::GRASS()->getTypeId()) {
                $this->setSheared(false);
                $this->getWorld()->addSound($this->location, new PopSound());

                $this->getWorld()->setBlock($this->location->subtract(0, 0.5, 0), VanillaBlocks::DIRT());
            }
        }
    }
}
