<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

class TraderLlama extends Llama {
    public static function getNetworkTypeId(): string {
        return "minecraft:trader_llama";
    }

    public function isBreedingItem(\pocketmine\item\Item $item): bool {
        $hay = \pocketmine\item\StringToItemParser::getInstance()->parse("hay_block");
        return $hay !== null && $item->equals($hay, false, false);
    }
}
