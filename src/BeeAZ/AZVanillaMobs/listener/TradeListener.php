<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\listener;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\overworld\Villager;
use BeeAZ\AZVanillaMobs\utils\TradeManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\UpdateTradePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeAutoStackRequestAction;
use pocketmine\scheduler\ClosureTask;
use pocketmine\entity\Location;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\particle\AngryVillagerParticle;

class TradeListener implements Listener {

    private Main $plugin;
    public static array $trading = [];
    public static array $tradeOffset = [];
    public static array $clientRecipeCount = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
        self::closeTrade($event->getPlayer());
        unset(self::$trading[$playerName]);
        unset(self::$tradeOffset[$playerName]);
        unset(self::$clientRecipeCount[$playerName]);
    }

    public function onPlayerInteractEntity(PlayerEntityInteractEvent $event): void {
        $player = $event->getPlayer();
        $entity = $event->getEntity();

        if ($entity instanceof Villager) {
            $event->cancel();

            if (!($entity instanceof \BeeAZ\AZVanillaMobs\entity\overworld\WanderingTrader)) {
                if ($entity->getProfession() === 0 || $entity->getProfession() === 14) {
                    $entity->playVillagerSound("mob.villager.no", 1.0, 1.0);
                    $entity->getWorld()->addParticle($entity->getLocation()->add(0, 1.5, 0), new AngryVillagerParticle());
                    return;
                }
            }

            self::openTrade($player, $entity);
        }
    }

    public static function openTrade(Player $player, Villager $villager): void {
        if (isset(self::$trading[$player->getName()])) {
            self::closeTrade($player);
        }

        self::$trading[$player->getName()] = $villager;

        $inv = $villager->getOrCreateTradeInventory();
        $inv->clearAll();

        $playerName = $player->getName();
        $recipes = $villager->getTradeRecipes();
        $recipesCount = count($recipes);

        $craftingManager = $player->getServer()->getCraftingManager();
        $craftingCount = count($craftingManager->getCraftingRecipeIndex());
        $furnaceCount = 0;
        foreach (\pocketmine\crafting\FurnaceType::cases() as $furnaceType) {
            $furnaceCount += count($craftingManager->getFurnaceRecipeManager($furnaceType)->getAll());
        }
        $initialOffset = $craftingCount + $furnaceCount + 1;

        self::$tradeOffset[$playerName] = $initialOffset;

        $invManager = $player->getNetworkSession()->getInvManager();
        if ($invManager !== null) {
            try {
                $reflection = new \ReflectionClass($invManager);

                $addComplexMethod = $reflection->getMethod("addComplex");
                $addComplexMethod->setAccessible(true);
                
                $addComplexMethod->invoke($invManager, [4 => 0, 5 => 1, 6 => 2, 7 => 3, 8 => 4], $inv);

                $associateMethod = $reflection->getMethod("associateIdWithInventory");
                $associateMethod->setAccessible(true);
                $associateMethod->invoke($invManager, 99, $inv);

                $invManager->syncContents($inv);
            } catch (\Throwable $t) {
            }
        }

        $openPk = ContainerOpenPacket::entityInv(99, WindowTypes::TRADING, $villager->getId());
        $player->getNetworkSession()->sendDataPacket($openPk);

        $offersNbt = TradeManager::buildOffersNbt($recipes, $player->getNetworkSession()->getTypeConverter());
        $pk = UpdateTradePacket::create(
            99, WindowTypes::TRADING, 0, $villager->getLevel() - 1, $villager->getId(), $player->getId(), $villager->getProfessionName(), true, false, $offersNbt
        );
        $player->getNetworkSession()->sendDataPacket($pk);

        $villager->playVillagerSound("mob.villager.haggle", 1.0, 1.0);
    }

    public static function refreshTrade(Player $player, Villager $villager): void {
        $playerName = $player->getName();
        $recipes = $villager->getTradeRecipes();
        $recipesCount = count($recipes);

        $craftingManager = $player->getServer()->getCraftingManager();
        $craftingCount = count($craftingManager->getCraftingRecipeIndex());
        $furnaceCount = 0;
        foreach (\pocketmine\crafting\FurnaceType::cases() as $furnaceType) {
            $furnaceCount += count($craftingManager->getFurnaceRecipeManager($furnaceType)->getAll());
        }
        $initialOffset = $craftingCount + $furnaceCount + 1;

        self::$tradeOffset[$playerName] = $initialOffset;

        $offersNbt = TradeManager::buildOffersNbt($recipes, $player->getNetworkSession()->getTypeConverter());
        $pk = UpdateTradePacket::create(
            99, WindowTypes::TRADING, 0, $villager->getLevel() - 1, $villager->getId(), $player->getId(), $villager->getProfessionName(), true, false, $offersNbt
        );
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public static function closeTrade(Player $player): void {
        $name = $player->getName();
        unset(self::$tradeOffset[$name]);
        if (isset(self::$trading[$name])) {
            $villager = self::$trading[$name];
            unset(self::$trading[$name]);

            $inv = $villager->getTradeInventory();
            if ($inv !== null) {
                for ($slot = 0; $slot <= 1; $slot++) {
                    $item = $inv->getItem($slot);
                    if (!$item->isNull()) {
                        $leftovers = $player->getInventory()->addItem($item);
                        foreach ($leftovers as $l) {
                            $player->getWorld()->dropItem($player->getLocation(), $l);
                        }
                        $inv->clear($slot);
                    }
                }
                $inv->clear(2);
                $inv->clear(3);
                $inv->clear(4);
            }

            $player->getNetworkSession()->sendDataPacket(ContainerClosePacket::create(99, WindowTypes::TRADING, true));

            $invManager = $player->getNetworkSession()->getInvManager();
            if ($invManager !== null) {
                try {
                    $reflection = new \ReflectionClass($invManager);
                    $removeMethod = $reflection->getMethod("remove");
                    $removeMethod->setAccessible(true);
                    $removeMethod->invoke($invManager, 99);
                } catch (\Throwable $t) {
                }
                $invManager->syncAll();
            }
        }
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();

        if ($packet instanceof PlayerAuthInputPacket) {
            $player = $event->getOrigin()->getPlayer();
            if ($player !== null && isset(self::$trading[$player->getName()])) {
                $villager = self::$trading[$player->getName()];
                if (!$villager->isAlive() || $player->getWorld() !== $villager->getWorld() || $player->getLocation()->distanceSquared($villager->getLocation()) > 64) {
                    self::closeTrade($player);
                }
            }
        } elseif ($packet instanceof ContainerClosePacket) {
            $player = $event->getOrigin()->getPlayer();
            if ($player !== null && isset(self::$trading[$player->getName()])) {
                if ($packet->windowId === 99) {
                    self::closeTrade($player);
                }
            }
        } elseif ($packet instanceof ItemStackRequestPacket) {
            $player = $event->getOrigin()->getPlayer();
            if ($player === null || !isset(self::$trading[$player->getName()])) return;

            $villager = self::$trading[$player->getName()];
            $tradeInv = $villager->getTradeInventory();
            if ($tradeInv === null) return;

            try {
                $reflectionPacket = new \ReflectionClass($packet);
                $requestsProp = $reflectionPacket->getProperty("requests");
                $requestsProp->setAccessible(true);
                $requests = $requestsProp->getValue($packet);

                $spoofedStackIds = [];

                foreach ($requests as $request) {
                    $reflectionRequest = new \ReflectionClass($request);
                    $actionsProp = $reflectionRequest->getProperty("actions");
                    $actionsProp->setAccessible(true);
                    $actions = $actionsProp->getValue($request);

                    foreach ($actions as $action) {
                        $actionRefl = new \ReflectionClass($action);
                        foreach (["source", "destination"] as $propName) {
                            if ($actionRefl->hasProperty($propName)) {
                                $prop = $actionRefl->getProperty($propName);
                                $prop->setAccessible(true);
                                $slotInfo = $prop->getValue($action);
                                if ($slotInfo instanceof ItemStackRequestSlotInfo) {
                                    $cid = $slotInfo->getContainerName()->getContainerId();
                                    $sid = $slotInfo->getSlotId();
                                    $stackId = $slotInfo->getStackId();

                                    $targetSlot = null;
                                    if ($cid === ContainerUIIds::TRADE_INGREDIENT1 || $cid === ContainerUIIds::TRADE2_INGREDIENT1) {
                                        if ($sid === 4) $targetSlot = 0;
                                        elseif ($sid === 5) $targetSlot = 1;
                                        elseif ($sid === 6) $targetSlot = 2;
                                        elseif ($sid === 7) $targetSlot = 3;
                                        elseif ($sid === 8) $targetSlot = 4;
                                        elseif ($sid !== 50) $targetSlot = 0; 
                                    } elseif ($cid === ContainerUIIds::TRADE_INGREDIENT2 || $cid === ContainerUIIds::TRADE2_INGREDIENT2) {
                                        $targetSlot = 1;
                                    } elseif ($cid === ContainerUIIds::CREATED_OUTPUT) {
                                        $targetSlot = 2;
                                    }

                                    if ($targetSlot !== null) {
                                        $spoofedStackIds[$targetSlot] = $stackId;
                                        self::setItemStackId($player, $tradeInv, $targetSlot, $stackId);
                                    }
                                }
                            }
                        }
                    }
                }

                $hasTrade = false;
                $rawRecipeId = null;
                $totalCount = 0;
                $multiplier = 1;

                foreach ($requests as $request) {
                    $reflectionRequest = new \ReflectionClass($request);
                    $actionsProp = $reflectionRequest->getProperty("actions");
                    $actionsProp->setAccessible(true);
                    $actions = $actionsProp->getValue($request);

                    $newActions = [];
                    $consumeIndex = 0;

                    foreach ($actions as $action) {
                        $className = get_class($action);
                        
                        if ($action instanceof \pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingConsumeInputStackRequestAction) {
                            $actionRefl = new \ReflectionClass($action);
                            $sourceProp = $actionRefl->getProperty("source");
                            $sourceProp->setAccessible(true);
                            $source = $sourceProp->getValue($action);
                            
                            $count = 1;
                            if (method_exists($action, "getCount")) {
                                $count = $action->getCount();
                            } elseif ($actionRefl->hasProperty("count")) {
                                $countProp = $actionRefl->getProperty("count");
                                $countProp->setAccessible(true);
                                $count = $countProp->getValue($action);
                            }
                            
                            
                            $fnClass = "pocketmine\\network\\mcpe\\protocol\\types\\inventory\\FullContainerName";
                            $cName = new $fnClass(ContainerUIIds::TRADE2_INGREDIENT1);
                            $infoClass = "pocketmine\\network\\mcpe\\protocol\\types\\inventory\\stackrequest\\ItemStackRequestSlotInfo";
                            $destSlotInfo = new $infoClass($cName, 7 + $consumeIndex, 0);
                            $consumeIndex++;

                            $placeActionClass = "pocketmine\\network\\mcpe\\protocol\\types\\inventory\\stackrequest\\PlaceStackRequestAction";
                            $newActions[] = new $placeActionClass($count, $source, $destSlotInfo);
                            continue;
                        }

                        if (str_contains($className, "Craft") || str_contains($className, "Crafting")) {
                            if ($action instanceof CraftRecipeStackRequestAction || $action instanceof CraftRecipeAutoStackRequestAction) {
                                $hasTrade = true;
                                $rawRecipeId = $action->getRecipeId();
                                if ($action instanceof CraftRecipeAutoStackRequestAction) {
                                    $multiplier = $action->getRepetitions();
                                }
                            }
                            continue;
                        }
                        if ($action instanceof \pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CreativeCreateStackRequestAction) {
                            return; 
                        }

                        
                        $actionRefl = new \ReflectionClass($action);
                        foreach (["source", "destination"] as $propName) {
                            if ($actionRefl->hasProperty($propName)) {
                                $prop = $actionRefl->getProperty($propName);
                                $prop->setAccessible(true);
                                $slotInfo = $prop->getValue($action);
                                if ($slotInfo instanceof ItemStackRequestSlotInfo) {
                                    $cName = $slotInfo->getContainerName();
                                    $cid = $cName->getContainerId();
                                    if ($cid === ContainerUIIds::CREATED_OUTPUT) {
                                        $cRefl = new \ReflectionClass($cName);
                                        $idProp = $cRefl->getProperty("containerId");
                                        $idProp->setAccessible(true);
                                        $idProp->setValue($cName, ContainerUIIds::TRADE2_INGREDIENT1);

                                        $slotInfoRefl = new \ReflectionClass($slotInfo);
                                        $slotIdProp = $slotInfoRefl->getProperty("slotId");
                                        $slotIdProp->setAccessible(true);
                                        $slotIdProp->setValue($slotInfo, 6);
                                        
                                        if ($propName === "source") {
                                            if (method_exists($action, "getCount")) {
                                                $totalCount += $action->getCount();
                                            } elseif ($actionRefl->hasProperty("count")) {
                                                $countProp = $actionRefl->getProperty("count");
                                                $countProp->setAccessible(true);
                                                $totalCount += $countProp->getValue($action);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $newActions[] = $action;
                    }
                    $actionsProp->setValue($request, $newActions);
                }

                if (!$hasTrade || $rawRecipeId === null) return;

                $recipes = $villager->getTradeRecipes();

                if (count($recipes) > 0) {
                    $inputA = $tradeInv->getItem(0);
                    $inputB = $tradeInv->getItem(1);
                    $recipeIndex = null;

                    // UpdateTradePacket advertises recipeNetworkId = 32000 + index.
                    // Use that authoritative ID first. The old crafting-recipe offset
                    // was ambiguous when several trader offers had the same emerald cost,
                    // and therefore selected the wrong output item.
                    $directIndex = $rawRecipeId - 32000;
                    if (isset($recipes[$directIndex]) && self::recipeMatchesIngredients($recipes[$directIndex], $inputA, $inputB)) {
                        $recipeIndex = $directIndex;
                    }

                    
                    $storedOffset = self::$tradeOffset[$player->getName()] ?? null;
                    if ($recipeIndex === null && $storedOffset !== null) {
                        $testIdx = $rawRecipeId - $storedOffset;
                        if (isset($recipes[$testIdx]) && self::recipeMatchesIngredients($recipes[$testIdx], $inputA, $inputB)) {
                            $recipeIndex = $testIdx;
                        }
                    }

                    
                    if ($recipeIndex === null) {
                        $matchingIndices = [];
                        foreach ($recipes as $idx => $recipe) {
                            if (self::recipeMatchesIngredients($recipe, $inputA, $inputB)) {
                                $matchingIndices[] = $idx;
                            }
                        }

                        if (count($matchingIndices) === 1) {
                            $recipeIndex = $matchingIndices[0];
                        } elseif (count($matchingIndices) > 1) {
                            
                            $cachedClientCount = self::$clientRecipeCount[$player->getName()] ?? null;
                            if ($cachedClientCount !== null) {
                                $testIdx = $rawRecipeId - $cachedClientCount;
                                if (in_array($testIdx, $matchingIndices, true)) {
                                    $recipeIndex = $testIdx;
                                }
                            }
                            if ($recipeIndex === null) {
                                $recipeIndex = $matchingIndices[0];
                            }
                        }
                    }

                    if ($recipeIndex !== null && isset($recipes[$recipeIndex])) {
                        
                        self::$clientRecipeCount[$player->getName()] = $rawRecipeId - $recipeIndex;

                        $recipe = $recipes[$recipeIndex];
                        \pocketmine\Server::getInstance()->getLogger()->debug("TradeListener: Recipe found dynamically. Index: $recipeIndex, rawRecipeId: $rawRecipeId, tier: {$recipe['tier']}, villagerLevel: {$villager->getLevel()}, uses: {$recipe['uses']}, maxUses: {$recipe['maxUses']}");
                        if ($recipe['tier'] <= $villager->getLevel() && $recipe['uses'] < $recipe['maxUses']) {
                            
                            $sellCount = $recipe['sell']->getCount();
                            if ($totalCount === 0) {
                                $totalCount = $sellCount * $multiplier;
                            }
                            
                            $sellItem = clone $recipe['sell'];
                            $sellItem->setCount($totalCount);
                            $tradeInv->setItem(2, $sellItem);
                            
                            \pocketmine\Server::getInstance()->getLogger()->debug("TradeListener: Set output item in slot 2. count: $totalCount");

                            
                            foreach ($spoofedStackIds as $slot => $stackId) {
                                self::setItemStackId($player, $tradeInv, $slot, $stackId);
                                \pocketmine\Server::getInstance()->getLogger()->debug("TradeListener: Re-applied stackId $stackId to slot $slot");
                            }

                            $this->plugin->getScheduler()->scheduleDelayedTask(
                                new ClosureTask(function () use ($player, $villager, $recipeIndex, $multiplier, $tradeInv): void {
                                    if (!$player->isOnline()) return;
                                    
                                    
                                    $tradeInv->clear(3);
                                    $tradeInv->clear(4);
                                    
                                    $recipes = $villager->getTradeRecipes();
                                    $recipe = $recipes[$recipeIndex] ?? null;
                                    if ($recipe === null) return;
                                    
                                    $recipes[$recipeIndex]['demand'] = ($recipes[$recipeIndex]['demand'] ?? 0) + $multiplier;
                                    $recipes[$recipeIndex]['uses'] += $multiplier;
                                    $villager->setTradeRecipes($recipes);
                                    
                                    $villager->updateRecipesPrice();
                                    
                                    $oldLevel = $villager->getLevel();
                                    $oldXp = $villager->getXp();
                                    $addedXp = $recipe['traderExp'] * $multiplier;
                                    $villager->addXp($addedXp);
                                    $newLevel = $villager->getLevel();
                                    $newXp = $villager->getXp();
                                    \pocketmine\Server::getInstance()->getLogger()->debug("TradeListener: Trade transaction completed. Recipe index: $recipeIndex, XP added: $addedXp, Old XP: $oldXp, New XP: $newXp, Old Level: $oldLevel, New Level: $newLevel");
                                    
                                    if ($newLevel === $oldLevel) {
                                        self::refreshTrade($player, $villager);
                                    }
                                    
                                    if ($recipe['rewardExp'] > 0) {
                                        $xpAmount = mt_rand(3, 6) * $multiplier;
                                        $location = Location::fromObject($villager->getPosition()->add(0, 0.5, 0), $villager->getWorld());
                                        $xpOrb = new ExperienceOrb($location, $xpAmount);
                                        $xpOrb->spawnToAll();
                                    }
                                    
                                    $villager->playVillagerSound("mob.villager.yes", 1.0, 1.0);
                                    $villager->getWorld()->addParticle($villager->getLocation()->add(0, 1.5, 0), new HappyVillagerParticle());
                                    
                                    $invManager = $player->getNetworkSession()->getInvManager();
                                    if ($invManager !== null) {
                                        $invManager->syncAll();
                                    }
                                }), 1
                            );
                        }
                    }
                }
            } catch (\Throwable $t) {
            }
        }
    }

    private static function setItemStackId(Player $player, \pocketmine\inventory\Inventory $inventory, int $slotId, int $stackId): void {
        $invManager = $player->getNetworkSession()->getInvManager();
        if ($invManager === null) return;
        
        try {
            $reflection = new \ReflectionClass($invManager);
            $inventoriesProp = $reflection->getProperty("inventories");
            $inventoriesProp->setAccessible(true);
            $inventories = $inventoriesProp->getValue($invManager);
            
            $objId = spl_object_id($inventory);
            if (isset($inventories[$objId])) {
                $entry = $inventories[$objId];
                
                $entryRefl = new \ReflectionClass($entry);
                $infosProp = $entryRefl->getProperty("itemStackInfos");
                $infosProp->setAccessible(true);
                $itemStackInfos = $infosProp->getValue($entry);
                
                $itemStackInfoClass = "pocketmine\\network\\mcpe\\ItemStackInfo";
                if (class_exists($itemStackInfoClass)) {
                    $newInfo = new $itemStackInfoClass(null, $stackId);
                    $itemStackInfos[$slotId] = $newInfo;
                    $infosProp->setValue($entry, $itemStackInfos);
                }
            }
        } catch (\Throwable $t) {
        }
    }

    private static function recipeMatchesIngredients(array $recipe, \pocketmine\item\Item $inputA, \pocketmine\item\Item $inputB): bool {
        $buyA = $recipe['buyA'];
        $buyB = $recipe['buyB'] ?? null;

        
        if (!$inputA->equals($buyA, true, false) || $inputA->getCount() < $buyA->getCount()) {
            return false;
        }

        
        if ($buyB === null || $buyB->isNull()) {
            if (!$inputB->isNull()) {
                return false;
            }
        } else {
            if (!$inputB->equals($buyB, true, false) || $inputB->getCount() < $buyB->getCount()) {
                return false;
            }
        }

        return true;
    }
}