<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Animal;
use BeeAZ\AZVanillaMobs\inventory\TradeInventory;
use BeeAZ\AZVanillaMobs\listener\TradeListener;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\particle\AngryVillagerParticle;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\math\Vector3;

class Villager extends Animal {

    private int $profession = 0; 
    private int $biome = 0;      
    private int $level = 1;      
    private int $xp = 0;         
    private ?Vector3 $jobSite = null;
    private ?TradeInventory $tradeInventory = null;
    protected ?array $tradeRecipes = null;
    private int $lastCheckTick = 0;
    private int $lastRestockTick = 0;
    private int $hostility = 0;

    public static array $claimedJobSites = []; 

    public static function getNetworkTypeId(): string {
        return "minecraft:villager_v2";
    }

    public function getOrCreateTradeInventory(): TradeInventory {
        if ($this->tradeInventory === null) {
            $this->tradeInventory = new TradeInventory();
        }
        return $this->tradeInventory;
    }

    public function getTradeInventory(): ?TradeInventory {
        return $this->tradeInventory;
    }

    public function getProfession(): int {
        return $this->profession;
    }

    public function setProfession(int $profession): void {
        $this->profession = $profession;
        $this->updateNetworkProperties();
    }

    public function getProfessionName(): string {
        return match ($this->profession) {
            1 => "Фермер",
            2 => "Рыбак",
            3 => "Пастух",
            4 => "Лучник",
            5 => "Библиотекарь",
            6 => "Картограф",
            7 => "Священник",
            8 => "Бронник",
            9 => "Мясник",
            10 => "Кожевник",
            11 => "Инструментальщик",
            12 => "Оружейник",
            13 => "Каменщик",
            14 => "Безработный",
            99 => "Странствующий торговец",
            default => "Житель",
        };
    }

    public function getLevel(): int {
        return $this->level;
    }

    public function setLevel(int $level): void {
        $this->level = $level;
        $this->updateNetworkProperties();
    }

    public function getXp(): int {
        return $this->xp;
    }

    public function addXp(int $amount): void {
        if ($this->profession === 0 || $this->profession === 14) return;
        $this->xp += $amount;
        $this->updateNetworkProperties();

        
        $thresholds = [1 => 10, 2 => 70, 3 => 150, 4 => 250];
        if (isset($thresholds[$this->level]) && $this->xp >= $thresholds[$this->level]) {
            $this->level++;
            $this->updateNetworkProperties();
            $this->playVillagerSound("mob.villager.yes", 1.0, 1.2);
            $this->getWorld()->addParticle($this->getLocation()->add(0, 1.5, 0), new HappyVillagerParticle());

            foreach (TradeListener::$trading as $name => $v) {
                if ($v->getId() === $this->getId()) {
                    $player = \pocketmine\Server::getInstance()->getPlayerExact($name);
                    if ($player !== null) {
                        TradeListener::refreshTrade($player, $this);
                    }
                }
            }
        }
    }

    public function getJobSite(): ?Vector3 {
        return $this->jobSite;
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->hostility = $nbt->getInt("Hostility", 0);

        if ($this instanceof WanderingTrader) {
            $this->profession = 99;
        } elseif ($nbt->getTag("Profession") !== null) {
            $this->profession = $nbt->getInt("Profession");
        } else {
            $this->profession = mt_rand(1, 10) === 1 ? 14 : 0;
        }

        if ($nbt->getTag("Biome") !== null) {
            $this->biome = $nbt->getInt("Biome");
        } else {
            $pos = $this->getPosition();
            $biome = $this->getWorld()->getBiome((int)$pos->x, (int)$pos->y, (int)$pos->z);
            $biomeName = strtolower($biome->getName());
            $markVariant = 0;
            if (str_contains($biomeName, "desert")) {
                $markVariant = 1;
            } elseif (str_contains($biomeName, "jungle")) {
                $markVariant = 2;
            } elseif (str_contains($biomeName, "savanna")) {
                $markVariant = 3;
            } elseif (str_contains($biomeName, "swamp")) {
                $markVariant = 4;
            } elseif (str_contains($biomeName, "taiga") || str_contains($biomeName, "cold")) {
                $markVariant = 5;
            } elseif (str_contains($biomeName, "snow") || str_contains($biomeName, "ice") || str_contains($biomeName, "frozen")) {
                $markVariant = 6;
            }
            $this->biome = $markVariant;
        }

        if ($nbt->getTag("TradeLevel") !== null) {
            $this->level = $nbt->getInt("TradeLevel");
        } else {
            $this->level = 1;
        }

        if ($nbt->getTag("TradeXP") !== null) {
            $this->xp = $nbt->getInt("TradeXP");
        } else {
            $this->xp = 0;
        }

        if ($nbt->getTag("JobSiteX") !== null && $nbt->getTag("JobSiteY") !== null && $nbt->getTag("JobSiteZ") !== null) {
            $this->jobSite = new Vector3(
                $nbt->getInt("JobSiteX"),
                $nbt->getInt("JobSiteY"),
                $nbt->getInt("JobSiteZ")
            );
            $key = $this->getWorld()->getFolderName() . ":" . $this->jobSite->x . ":" . $this->jobSite->y . ":" . $this->jobSite->z;
            self::$claimedJobSites[$key] = $this->getId();
        }

        if ($nbt->getTag("Recipes") instanceof ListTag) {
            $this->loadRecipesFromNbt($nbt->getListTag("Recipes"));
        } else {
            $this->generateRecipes();
        }

        $this->updateNetworkProperties();
    }

    public function saveNBT(): CompoundTag {
        $nbt = parent::saveNBT();
        $nbt->setInt("Profession", $this->profession);
        $nbt->setInt("Biome", $this->biome);
        $nbt->setInt("Hostility", $this->hostility);
        $nbt->setInt("TradeLevel", $this->level);
        $nbt->setInt("TradeXP", $this->xp);

        if ($this->jobSite !== null) {
            $nbt->setInt("JobSiteX", (int)$this->jobSite->x);
            $nbt->setInt("JobSiteY", (int)$this->jobSite->y);
            $nbt->setInt("JobSiteZ", (int)$this->jobSite->z);
        }

        $nbt->setTag("Recipes", $this->saveRecipesToNbt());
        return $nbt;
    }

    public function updateNetworkProperties(): void {
        $props = $this->getNetworkProperties();
        $props->setInt(EntityMetadataProperties::VARIANT, $this->profession);
        $props->setInt(EntityMetadataProperties::MARK_VARIANT, $this->biome);
        $props->setInt(EntityMetadataProperties::TRADE_TIER, $this->level - 1);
        $props->setInt(EntityMetadataProperties::MAX_TRADE_TIER, 5);
        $props->setInt(EntityMetadataProperties::TRADE_XP, $this->xp);
        $this->sendData($this->getViewers());
    }

    public function playVillagerSound(string $soundName, float $volume = 1.0, float $pitch = 1.0): void {
        $pos = $this->getPosition();
        $pk = PlaySoundPacket::create($soundName, $pos->x, $pos->y, $pos->z, $volume, $pitch, null);
        foreach ($this->getWorld()->getPlayers() as $player) {
            if ($player->getLocation()->distanceSquared($pos) <= 256) {
                $player->getNetworkSession()->sendDataPacket($pk);
            }
        }
    }

    public function onUpdate(int $currentTick): bool {
        foreach (TradeListener::$trading as $villager) {
            if ($villager->getId() === $this->getId()) {
                $this->targetPosition = null;
                $this->motion->x = 0.0;
                $this->motion->z = 0.0;
                break;
            }
        }

        $alive = parent::onUpdate($currentTick);
        if (!$alive) return false;

        if ($currentTick - $this->lastCheckTick >= 100) {
            $this->lastCheckTick = $currentTick;
            $this->checkJobSiteSystem();
        }

        return true;
    }

    private function checkJobSiteSystem(): void {
        // Wandering traders never claim village workstations. The inherited
        // system previously changed profession 99 into a normal villager
        // profession, which also changed the title and trade table.
        if ($this instanceof WanderingTrader) {
            return;
        }
        $world = $this->getWorld();
        $pos = $this->getPosition();

        if ($this->profession === 14) return;

        
        if ($this->jobSite === null) {
            $startX = (int)floor($pos->x) - 4;
            $endX = (int)floor($pos->x) + 4;
            $startY = (int)floor($pos->y) - 2;
            $endY = (int)floor($pos->y) + 2;
            $startZ = (int)floor($pos->z) - 4;
            $endZ = (int)floor($pos->z) + 4;

            for ($x = $startX; $x <= $endX; $x++) {
                for ($y = $startY; $y <= $endY; $y++) {
                    for ($z = $startZ; $z <= $endZ; $z++) {
                        $block = $world->getBlockAt($x, $y, $z);
                        $prof = self::getProfessionFromBlock($block);
                        if ($prof !== null) {
                            if ($this->xp > 0 && $prof !== $this->profession) {
                                continue; 
                            }

                            $key = $world->getFolderName() . ":" . $x . ":" . $y . ":" . $z;
                            if (!isset(self::$claimedJobSites[$key])) {
                                $this->jobSite = new Vector3($x, $y, $z);
                                self::$claimedJobSites[$key] = $this->getId();
                                
                                if ($this->profession !== $prof) {
                                    $this->profession = $prof;
                                    $this->level = 1;
                                    $this->xp = 0;
                                    $this->generateRecipes();
                                }
                                $this->updateNetworkProperties();
                                
                                $this->playVillagerSound("mob.villager.yes", 1.0, 1.0);
                                $world->addParticle($this->getLocation()->add(0, 1.5, 0), new HappyVillagerParticle());
                                return;
                            }
                        }
                    }
                }
            }
        }

        
        if ($this->jobSite !== null) {
            $block = $world->getBlock($this->jobSite);
            $prof = self::getProfessionFromBlock($block);
            
            $key = $world->getFolderName() . ":" . $this->jobSite->x . ":" . $this->jobSite->y . ":" . $this->jobSite->z;
            
            if ($prof === null || ($this->xp > 0 && $prof !== $this->profession)) {
                unset(self::$claimedJobSites[$key]);
                $this->jobSite = null;

                if ($this->xp === 0) {
                    $this->profession = 0;
                    $this->tradeRecipes = [];
                    $this->updateNetworkProperties();
                    
                    $this->playVillagerSound("mob.villager.no", 1.0, 1.0);
                    $world->addParticle($this->getLocation()->add(0, 1.5, 0), new AngryVillagerParticle());
                }
            } else {
                if ($this->location->distanceSquared($this->jobSite) <= 9) {
                    $hasUsed = false;
                    foreach ($this->tradeRecipes as $r) {
                        if ($r['uses'] > 0) {
                            $hasUsed = true;
                            break;
                        }
                    }

                    $tick = $world->getServer()->getTick();
                    if ($hasUsed && $tick - $this->lastRestockTick >= 6000) {
                        $this->lastRestockTick = $tick;
                        $this->hostility = max(0, $this->hostility - 5);
                        foreach ($this->tradeRecipes as &$r) {
                            $r['uses'] = 0;
                            $r['demand'] = max(0, ($r['demand'] ?? 0) - 4);
                        }
                        $this->updateRecipesPrice();
                        $this->playVillagerSound("mob.villager.yes", 1.0, 1.1);
                        $world->addParticle($this->getLocation()->add(0, 1.5, 0), new HappyVillagerParticle());

                        foreach (TradeListener::$trading as $name => $v) {
                            if ($v->getId() === $this->getId()) {
                                $player = \pocketmine\Server::getInstance()->getPlayerExact($name);
                                if ($player !== null) {
                                    TradeListener::refreshTrade($player, $this);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public static function getProfessionFromBlock(Block $block): ?int {
        $name = strtolower($block->getName());
        $typeId = $block->getTypeId();

        switch ($typeId) {
            case BlockTypeIds::LECTERN: return 5;
            case BlockTypeIds::BARREL: return 2;
            case BlockTypeIds::LOOM: return 3;
            case BlockTypeIds::FLETCHING_TABLE: return 4;
            case BlockTypeIds::BREWING_STAND: return 7;
            case BlockTypeIds::CAULDRON: return 10;
            case BlockTypeIds::SMITHING_TABLE: return 11;
            case BlockTypeIds::STONECUTTER: return 13;
            case BlockTypeIds::SMOKER: return 9;
            case BlockTypeIds::BLAST_FURNACE: return 8;
            case BlockTypeIds::CARTOGRAPHY_TABLE: return 6;
        }

        if (str_contains($name, "lectern")) return 5;
        if (str_contains($name, "compost")) return 1;
        if (str_contains($name, "barrel")) return 2;
        if (str_contains($name, "loom")) return 3;
        if (str_contains($name, "fletch")) return 4;
        if (str_contains($name, "brew")) return 7;
        if (str_contains($name, "cauldron")) return 10;
        if (str_contains($name, "smith")) return 11;
        if (str_contains($name, "grind")) return 12;
        if (str_contains($name, "stonecutter")) return 13;
        if (str_contains($name, "smoker")) return 9;
        if (str_contains($name, "blast")) return 8;
        if (str_contains($name, "cartography")) return 6;

        return null;
    }

    public function getTradeRecipes(): array {
        if ($this->tradeRecipes === null) {
            $this->generateRecipes();
        }
        return $this->tradeRecipes;
    }

    public function setTradeRecipes(array $recipes): void {
        $this->tradeRecipes = $recipes;
    }

    public function generateRecipes(): void {
        $this->tradeRecipes = [];
        if ($this instanceof WanderingTrader) {
            $pool = $this->getWanderingTraderPool();
            shuffle($pool);
            $selectedCount = min(6, count($pool));
            for ($i = 0; $i < $selectedCount; $i++) {
                $trade = $pool[$i];
                $this->tradeRecipes[] = [
                    'buyA' => $trade['buyA'],
                    'buyB' => $trade['buyB'] ?? null,
                    'sell' => $trade['sell'],
                    'uses' => 0,
                    'maxUses' => $trade['maxUses'] ?? 12,
                    'tier' => 1,
                    'traderExp' => 0,
                    'rewardExp' => 1,
                    'priceMultiplierA' => 0.05,
                    'priceMultiplierB' => 0.05,
                    'originalCountA' => $trade['buyA']->getCount(),
                    'originalCountB' => (isset($trade['buyB']) && $trade['buyB'] !== null && !$trade['buyB']->isNull()) ? $trade['buyB']->getCount() : 0,
                    'demand' => 0,
                ];
            }
            $this->updateRecipesPrice();
            return;
        }

        if ($this->profession === 0 || $this->profession === 14) return;

        for ($tier = 1; $tier <= 5; $tier++) {
            $this->addTradesForTier($tier);
        }
        $this->updateRecipesPrice();
    }

    protected function getWanderingTraderPool(): array {
        $pool = [];
        $add = static function(array &$pool, int $emeralds, string $sellName, int $sellCount = 1, int $maxUses = 12): void {
            try {
                $sell = self::getItem($sellName, $sellCount);
                if (!$sell->isNull()) {
                    $pool[] = [
                        'buyA' => self::getItem("emerald", $emeralds),
                        'sell' => $sell,
                        'maxUses' => $maxUses,
                    ];
                }
            } catch (\Throwable $e) {
                // Skip only the unavailable item instead of losing the whole pool.
            }
        };

        $add($pool, 2, "sea_pickle");
        $add($pool, 4, "slime_ball");
        $add($pool, 2, "glowstone");
        $add($pool, 5, "nautilus_shell", 1, 5);
        foreach (["oak_sapling", "spruce_sapling", "birch_sapling", "jungle_sapling", "acacia_sapling", "dark_oak_sapling"] as $sapling) {
            $add($pool, 5, $sapling);
        }
        $add($pool, 6, "blue_ice");
        $add($pool, 3, "packed_ice");
        $add($pool, 3, "podzol", 3);
        $add($pool, 1, "sand", 8);
        $add($pool, 1, "red_sand", 4);
        $add($pool, 1, "gunpowder");
        $add($pool, 3, "cactus");
        $add($pool, 3, "kelp");
        $add($pool, 1, "lily_pad", 2);
        $add($pool, 1, "dandelion");
        $add($pool, 1, "poppy");
        $add($pool, 1, "blue_orchid");
        $add($pool, 1, "allium");
        $add($pool, 1, "cornflower");
        $add($pool, 1, "lily_of_the_valley");
        $add($pool, 1, "moss_block");
        $add($pool, 5, "small_dripleaf", 2);
        $add($pool, 5, "pointed_dripstone", 2);
        return $pool;
    }

    private function addTradesForTier(int $tier): void {
        $pool = $this->getTradePool($this->profession, $tier);
        if (empty($pool)) return;

        shuffle($pool);
        $selectedCount = min(2, count($pool));
        for ($i = 0; $i < $selectedCount; $i++) {
            $trade = $pool[$i];
            $this->tradeRecipes[] = [
                'buyA' => $trade['buyA'],
                'buyB' => $trade['buyB'] ?? null,
                'sell' => $trade['sell'],
                'uses' => 0,
                'maxUses' => $trade['maxUses'] ?? 12,
                'tier' => $tier,
                'traderExp' => $trade['traderExp'] ?? 2,
                'rewardExp' => $trade['rewardExp'] ?? 1,
                'priceMultiplierA' => $trade['priceMultiplierA'] ?? 0.05,
                'priceMultiplierB' => $trade['priceMultiplierB'] ?? 0.05,
                'originalCountA' => $trade['buyA']->getCount(),
                'originalCountB' => (isset($trade['buyB']) && $trade['buyB'] !== null && !$trade['buyB']->isNull()) ? $trade['buyB']->getCount() : 0,
                'demand' => 0,
            ];
        }
    }

    private static function getItem(string $name, int $count = 1): Item {
        $item = StringToItemParser::getInstance()->parse($name);
        if ($item === null) {
            throw new \RuntimeException("Failed to parse item: " . $name);
        }
        return $item->setCount($count);
    }

    private function getTradePool(int $profession, int $tier): array {
        $pool = [];
        try {
            switch ($profession) {
                case 1: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("wheat", 20), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("potato", 26), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("carrot", 22), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("bread", 6)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("pumpkin", 6), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("pumpkin_pie", 4)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("melon_slice", 14), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("golden_carrot", 3)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("glistering_melon_slice", 3)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("golden_apple", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("cake", 1)];
                    }
                    break;
                    
                case 2: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("coal", 10), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("raw_cod", 15), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("cooked_cod", 6)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("raw_salmon", 13), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("cooked_salmon", 6)];
                        $pool[] = ['buyA' => self::getItem("emerald", 6), 'sell' => self::getItem("fishing_rod", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("string", 20), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("tropical_fish", 6), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("pufferfish", 4), 'sell' => self::getItem("emerald", 1)];
                    }
                    break;

                case 3: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("white_wool", 18), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("shears", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("black_wool", 18), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("white_carpet", 4)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("yellow_dye", 12), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("white_bed", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("green_dye", 12), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 2), 'sell' => self::getItem("painting", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("white_banner", 1)];
                    }
                    break;

                case 4: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("gravel", 10), 'buyB' => self::getItem("emerald", 1), 'sell' => self::getItem("flint", 10)];
                        $pool[] = ['buyA' => self::getItem("flint", 26), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("arrow", 16)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("emerald", 2), 'sell' => self::getItem("bow", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("string", 14), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("feather", 14), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 5), 'sell' => self::getItem("arrow", 10)];
                    }
                    break;

                case 5: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("paper", 24), 'sell' => self::getItem("emerald", 1)];
                        
                        $book = self::getItem("enchanted_book", 1);
                        $enchants = [
                            VanillaEnchantments::PROTECTION(),
                            VanillaEnchantments::SHARPNESS(),
                            VanillaEnchantments::EFFICIENCY(),
                            VanillaEnchantments::UNBREAKING()
                        ];
                        $randomEnchant = $enchants[array_rand($enchants)];
                        $lvl = mt_rand(1, $randomEnchant->getMaxLevel());
                        $book->addEnchantment(new EnchantmentInstance($randomEnchant, $lvl));
                        $pool[] = ['buyA' => self::getItem("emerald", 12), 'buyB' => self::getItem("book", 1), 'sell' => $book];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("lantern", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("glass", 4)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("clock", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("compass", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 6), 'sell' => self::getItem("name_tag", 1)];
                    }
                    break;

                case 6: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("paper", 24), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 7), 'sell' => self::getItem("map", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("glass_pane", 11), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("compass", 1), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("emerald", 2), 'sell' => self::getItem("item_frame", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("paper", 1)];
                    }
                    break;

                case 7: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("rotten_flesh", 32), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("redstone", 2)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("gold_ingot", 3), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("lapis_lazuli", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("glowstone_dust", 2)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("emerald", 5), 'sell' => self::getItem("ender_pearl", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("nether_wart", 22), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("experience_bottle", 1)];
                    }
                    break;

                case 8: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("coal", 15), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("iron_boots", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 5), 'sell' => self::getItem("iron_helmet", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("iron_ingot", 4), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 9), 'sell' => self::getItem("iron_leggings", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("emerald", 9), 'sell' => self::getItem("iron_chestplate", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("diamond", 1), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("diamond_boots", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("diamond_helmet", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 12), 'sell' => self::getItem("diamond_chestplate", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 10), 'sell' => self::getItem("diamond_leggings", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 32), 'sell' => self::getItem("netherite_boots", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 32), 'sell' => self::getItem("netherite_helmet", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 48), 'sell' => self::getItem("netherite_chestplate", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 40), 'sell' => self::getItem("netherite_leggings", 1)];
                    }
                    break;

                case 9: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("raw_chicken", 14), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("raw_porkchop", 7), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("cooked_chicken", 8)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("raw_beef", 10), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("raw_mutton", 7), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("cooked_beef", 4)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("coal", 15), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("coal", 10), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("cooked_porkchop", 5)];
                    }
                    break;

                case 10: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("leather", 6), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("leather_boots", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 5), 'sell' => self::getItem("leather_helmet", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("emerald", 6), 'sell' => self::getItem("leather_leggings", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("leather_chestplate", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("leather", 4), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 6), 'sell' => self::getItem("leather_chestplate", 1)];
                    }
                    break;

                case 11: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("coal", 15), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("stone_axe", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("iron_ingot", 4), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 4), 'sell' => self::getItem("iron_pickaxe", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("flint", 30), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("diamond", 1), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 10), 'sell' => self::getItem("diamond_pickaxe", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("diamond_axe", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 6), 'sell' => self::getItem("diamond_shovel", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 5), 'sell' => self::getItem("diamond_hoe", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 36), 'sell' => self::getItem("netherite_pickaxe", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 32), 'sell' => self::getItem("netherite_axe", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 24), 'sell' => self::getItem("netherite_shovel", 1)];
                    }
                    break;

                case 12: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("coal", 15), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 3), 'sell' => self::getItem("iron_axe", 1)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("iron_ingot", 4), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 2), 'sell' => self::getItem("iron_sword", 1)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("flint", 24), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("diamond", 1), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 8), 'sell' => self::getItem("diamond_sword", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 32), 'sell' => self::getItem("netherite_sword", 1)];
                    }
                    break;

                case 13: 
                    if ($tier === 1) {
                        $pool[] = ['buyA' => self::getItem("clay", 10), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("brick", 10)];
                    } elseif ($tier === 2) {
                        $pool[] = ['buyA' => self::getItem("stone", 20), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("emerald", 1), 'sell' => self::getItem("stone_bricks", 4)];
                    } elseif ($tier === 3) {
                        $pool[] = ['buyA' => self::getItem("granite", 16), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("diorite", 16), 'sell' => self::getItem("emerald", 1)];
                        $pool[] = ['buyA' => self::getItem("andesite", 16), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 4) {
                        $pool[] = ['buyA' => self::getItem("quartz", 12), 'sell' => self::getItem("emerald", 1)];
                    } elseif ($tier === 5) {
                        $pool[] = ['buyA' => self::getItem("emerald", 2), 'sell' => self::getItem("quartz_block", 1)];
                    }
                    break;
            }
        } catch (\Throwable $e) {
            
        }
        return $pool;
    }

    private function loadRecipesFromNbt(ListTag $list): void {
        $this->tradeRecipes = [];
        foreach ($list as $tag) {
            if ($tag instanceof CompoundTag) {
                $buyATag = $tag->getCompoundTag("buyA");
                $buyBTag = $tag->getCompoundTag("buyB");
                $sellTag = $tag->getCompoundTag("sell");

                if ($buyATag !== null && $sellTag !== null) {
                    try {
                        $buyA = Item::nbtDeserialize($buyATag);
                        $buyB = $buyBTag !== null ? Item::nbtDeserialize($buyBTag) : null;
                        $sell = Item::nbtDeserialize($sellTag);

                        $this->tradeRecipes[] = [
                            'buyA' => $buyA,
                            'buyB' => $buyB,
                            'sell' => $sell,
                            'uses' => $tag->getInt("uses", 0),
                            'maxUses' => $tag->getInt("maxUses", 12),
                            'tier' => $tag->getInt("tier", 1),
                            'traderExp' => $tag->getInt("traderExp", 2),
                            'rewardExp' => $tag->getInt("rewardExp", 1),
                            'priceMultiplierA' => $tag->getFloat("priceMultiplierA", 0.05),
                            'priceMultiplierB' => $tag->getFloat("priceMultiplierB", 0.05),
                            'originalCountA' => $tag->getInt("originalCountA", $buyA->getCount()),
                            'originalCountB' => $tag->getInt("originalCountB", $buyB !== null ? $buyB->getCount() : 0),
                            'demand' => $tag->getInt("demand", 0),
                        ];
                    } catch (\Throwable $t) {
                        
                    }
                }
            }
        }
        $this->updateRecipesPrice();
    }

    private function saveRecipesToNbt(): ListTag {
        $list = new ListTag();
        if ($this->tradeRecipes !== null) {
            foreach ($this->tradeRecipes as $recipe) {
                $tag = CompoundTag::create()
                    ->setTag("buyA", $recipe['buyA']->nbtSerialize())
                    ->setInt("uses", $recipe['uses'])
                    ->setInt("maxUses", $recipe['maxUses'])
                    ->setInt("tier", $recipe['tier'])
                    ->setInt("traderExp", $recipe['traderExp'])
                    ->setInt("rewardExp", $recipe['rewardExp'])
                    ->setFloat("priceMultiplierA", $recipe['priceMultiplierA'])
                    ->setFloat("priceMultiplierB", $recipe['priceMultiplierB'])
                    ->setInt("originalCountA", $recipe['originalCountA'] ?? $recipe['buyA']->getCount())
                    ->setInt("originalCountB", $recipe['originalCountB'] ?? ($recipe['buyB'] !== null ? $recipe['buyB']->getCount() : 0))
                    ->setInt("demand", $recipe['demand'] ?? 0);

                if ($recipe['buyB'] !== null) {
                    $tag->setTag("buyB", $recipe['buyB']->nbtSerialize());
                }
                $tag->setTag("sell", $recipe['sell']->nbtSerialize());

                $list->push($tag);
            }
        }
        return $list;
    }

    protected function calculateAI(): void {
        // Bedrock villagers immediately interrupt work/trading and avoid village threats.
        $threat = $this->findNearestLiving(function(\pocketmine\entity\Living $entity): bool {
            if (!($entity instanceof \BeeAZ\AZVanillaMobs\entity\BaseMob)) {
                return false;
            }
            return in_array($entity->getShortEntityName(), [
                'zombie', 'zombievillager', 'husk', 'drowned',
                'pillager', 'vindicator', 'evoker', 'vex', 'ravager', 'zoglin'
            ], true);
        }, 8.0, false);

        if ($threat !== null) {
            $this->startPanic($threat, 80);
            return;
        }

        foreach (TradeListener::$trading as $villager) {
            if ($villager->getId() === $this->getId()) {
                $this->targetPosition = null;
                return;
            }
        }
        parent::calculateAI();
    }

    public function updateRecipesPrice(): void {
        if ($this->tradeRecipes === null) return;
        
        $hostilityPrice = (int) floor($this->hostility * 0.5);
        
        foreach ($this->tradeRecipes as &$recipe) {
            $originalCountA = $recipe['originalCountA'] ?? $recipe['buyA']->getCount();
            $recipe['originalCountA'] = $originalCountA;
            
            $demand = $recipe['demand'] ?? 0;
            $recipe['demand'] = $demand;
            
            
            $demandPriceA = (int) floor($demand * $recipe['priceMultiplierA'] * $originalCountA);
            if ($demand > 0 && $demandPriceA === 0) {
                $demandPriceA = (int) ceil($demand * 0.1);
            }
            
            $newCountA = $originalCountA + $demandPriceA + $hostilityPrice;
            $newCountA = max(1, min($recipe['buyA']->getMaxStackSize(), $newCountA));
            $recipe['buyA']->setCount($newCountA);
            
            if ($recipe['buyB'] !== null && !$recipe['buyB']->isNull()) {
                $originalCountB = $recipe['originalCountB'] ?? $recipe['buyB']->getCount();
                $recipe['originalCountB'] = $originalCountB;
                
                $demandPriceB = (int) floor($demand * $recipe['priceMultiplierB'] * $originalCountB);
                if ($demand > 0 && $demandPriceB === 0) {
                    $demandPriceB = (int) ceil($demand * 0.1);
                }
                
                $newCountB = $originalCountB + $demandPriceB + $hostilityPrice;
                $newCountB = max(1, min($recipe['buyB']->getMaxStackSize(), $newCountB));
                $recipe['buyB']->setCount($newCountB);
            }
        }
    }

    public function attack(\pocketmine\event\entity\EntityDamageEvent $source): void {
        parent::attack($source);
        if (!$source->isCancelled()) {
            if ($source instanceof \pocketmine\event\entity\EntityDamageByEntityEvent) {
                $damager = $source->getDamager();
                if ($damager instanceof \pocketmine\player\Player) {
                    $this->hostility += 10;
                    $this->updateRecipesPrice();
                    
                    foreach (TradeListener::$trading as $name => $v) {
                        if ($v->getId() === $this->getId()) {
                            $player = \pocketmine\Server::getInstance()->getPlayerExact($name);
                            if ($player !== null) {
                                TradeListener::refreshTrade($player, $this);
                            }
                        }
                    }
                }
            }
        }
    }
}
