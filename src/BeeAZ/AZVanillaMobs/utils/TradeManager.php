<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\utils;

use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class TradeManager {

    public static function getItemNbt(Item $item, TypeConverter $converter): CompoundTag {
        $netItem = $converter->coreItemStackToNet($item);
        try {
            $itemName = $converter->getItemTypeDictionary()->fromIntId($netItem->getId());
        } catch (\Throwable $e) {
            $itemName = GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
        }

        $tag = CompoundTag::create()
            ->setByte("Count", $item->getCount())
            ->setShort("Damage", $netItem->getMeta())
            ->setString("Name", $itemName)
            ->setByte("WasPickedUp", 0);

        if ($item->hasNamedTag()) {
            $tag->setTag("tag", clone $item->getNamedTag());
        }
        return $tag;
    }

    public static function buildOffersNbt(array $recipes, TypeConverter $converter): CacheableNbt {
        $recipeList = new ListTag();

        foreach ($recipes as $idx => $recipeData) {
            $buyA = $recipeData['buyA'] ?? null;
            $buyB = $recipeData['buyB'] ?? null;
            $sell = $recipeData['sell'] ?? null;
            if (!$buyA instanceof Item || !$sell instanceof Item || $buyA->isNull() || $sell->isNull()) {
                continue;
            }

            $recipeTag = CompoundTag::create()
                ->setTag("buyA", self::getItemNbt($buyA, $converter))
                ->setInt("buyCountA", $buyA->getCount())
                ->setTag("sell", self::getItemNbt($sell, $converter))
                ->setInt("maxUses", $recipeData['maxUses'] ?? 12)
                ->setInt("uses", $recipeData['uses'] ?? 0)
                ->setByte("rewardExp", (int) ($recipeData['rewardExp'] ?? 1))
                ->setInt("traderExp", $recipeData['traderExp'] ?? 2)
                ->setInt("tier", max(0, ($recipeData['tier'] ?? 1) - 1))
                ->setFloat("priceMultiplierA", $recipeData['priceMultiplierA'] ?? 0.05)
                ->setFloat("priceMultiplierB", $recipeData['priceMultiplierB'] ?? 0.05)
                ->setInt("demand", $recipeData['demand'] ?? 0)
                ->setInt("recipeNetworkId", 32000 + (int) $idx);

            if ($buyB instanceof Item && !$buyB->isNull()) {
                $recipeTag->setTag("buyB", self::getItemNbt($buyB, $converter));
                $recipeTag->setInt("buyCountB", $buyB->getCount());
            }
            $recipeList->push($recipeTag);
        }

        return new CacheableNbt(CompoundTag::create()->setTag("Recipes", $recipeList));
    }
}
