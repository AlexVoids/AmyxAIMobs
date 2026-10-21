<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\utils;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/**
 * Registers visual-only vanilla items missing from some PocketMine forks.
 *
 * The server core is based on a modern protocol but exposes an incomplete
 * VanillaItems registry. Mapping these items by their Bedrock string IDs lets
 * each player's protocol converter decide how to represent them.
 */
final class CompatItemManager
{
    /** @var array<string, Item> */
    private static array $items = [];

    private function __construct() {}

    public static function bootstrap(): void
    {
        self::ensure("crossbow", "Crossbow");
        self::ensure("trident", "Trident");
        self::ensure("nautilus_shell", "Nautilus Shell");
    }

    public static function get(string $id): ?Item
    {
        $id = strtolower(str_replace("minecraft:", "", $id));
        $parsed = null;
        try {
            $parser = StringToItemParser::getInstance();
            $parsed = $parser->parse("minecraft:" . $id) ?? $parser->parse($id);
        } catch (\Throwable) {}
        if ($parsed !== null && !$parsed->isNull()) {
            return $parsed;
        }
        return isset(self::$items[$id]) ? clone self::$items[$id] : null;
    }

    private static function ensure(string $id, string $displayName): void
    {
        if (self::get($id) !== null) {
            return;
        }

        try {
            $item = new Item(new ItemIdentifier(ItemTypeIds::newId()), $displayName);
            GlobalItemDataHandlers::getSerializer()->map(
                $item,
                fn(): SavedItemData => new SavedItemData("minecraft:" . $id)
            );
            try {
                GlobalItemDataHandlers::getDeserializer()->map(
                    "minecraft:" . $id,
                    fn(): Item => clone $item
                );
            } catch (\Throwable) {
                // A fork may already have a deserializer but no parser entry.
            }

            $parser = StringToItemParser::getInstance();
            try {
                if (method_exists($parser, "override")) {
                    $parser->override($id, fn(): Item => clone $item);
                    $parser->override("minecraft:" . $id, fn(): Item => clone $item);
                } else {
                    $parser->register($id, fn(): Item => clone $item);
                }
            } catch (\Throwable) {}

            self::$items[$id] = $item;
        } catch (\Throwable) {
            // Held equipment is cosmetic. Unsupported items must never prevent
            // the plugin from starting.
        }
    }
}
