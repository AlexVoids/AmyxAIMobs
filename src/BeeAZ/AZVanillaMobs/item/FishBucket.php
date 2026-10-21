<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\item;

use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\item\ItemUseResult;
use pocketmine\entity\Location;

class FishBucket extends Item {

    private string $entityClass;

    public function __construct(ItemIdentifier $identifier, string $name, string $entityClass) {
        parent::__construct($identifier, $name);
        $this->entityClass = $entityClass;
    }

    public function getMaxStackSize(): int {
        return 1;
    }

    public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems): ItemUseResult {
        if (!$blockReplace->canBeReplaced()) {
            return ItemUseResult::NONE;
        }

        $world = $player->getWorld();
        $pos = $blockReplace->getPosition();
        
        $location = Location::fromObject($pos->add(0.5, 0.0, 0.5), $world, mt_rand(0, 360), 0);
        $entity = new $this->entityClass($location);
        
        $world->setBlock($pos, \pocketmine\block\VanillaBlocks::WATER());
        $world->addSound($pos->add(0.5, 0.5, 0.5), \pocketmine\block\VanillaBlocks::WATER()->getBucketEmptySound());
        
        $entity->spawnToAll();
        
        $this->pop();
        $returnedItems[] = VanillaItems::BUCKET();
        return ItemUseResult::SUCCESS;
    }
}
