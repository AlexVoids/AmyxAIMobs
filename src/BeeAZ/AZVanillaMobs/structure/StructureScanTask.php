<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\structure;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\command\StructureMobsCommand;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Barrel as BarrelTile;
use pocketmine\block\tile\Bell as BellTile;
use pocketmine\block\tile\Chest as ChestTile;
use pocketmine\block\tile\MonsterSpawner;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;
use Throwable;
use function array_keys;
use function array_sum;
use function count;
use function floor;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function shuffle;
use function strtolower;
use function str_contains;

/**
 * Batched full-map scan for imported vanilla worlds.
 *
 * PocketMine does not expose Mojang structure metadata from an imported map,
 * so structures are identified by their distinctive blocks and block entities.
 * Work is spread over ticks to avoid a single large server freeze.
 */
final class StructureScanTask extends Task{
    private const MIN_BLOCK = -2000;
    private const MAX_BLOCK = 2000;
    private const MIN_CHUNK = -125;
    private const MAX_CHUNK = 125;
    private const CHUNKS_PER_TICK = 12;
    private const LOOT_VERSION = 4;

    /** @var array<string, true> */
    private static array $active = [];

    private int $chunkX = self::MIN_CHUNK;
    private int $chunkZ = self::MIN_CHUNK;
    private int $processed = 0;
    private int $found = 0;
    private int $spawned = 0;
    private int $filled = 0;
    private int $skippedExistingLoot = 0;
    private string $activeKey;
    private string $worldName;
    private Config $state;

    /** @var array<int, array{type:string,x:float,y:float,z:float}> */
    private array $mobRecords = [];
    /** @var array<string, bool> */
    private array $lootRecords = [];
    /** @var array<string, bool> */
    private array $legacyLootRecords = [];
    /** @var array<int, Position> */
    private array $villageCenters = [];
    /** @var array<int, array{type:string,position:Position}> */
    private array $lootStructureCenters = [];
    /** @var array<int, int> state ID => block type ID */
    private array $stateTypeCache = [];

    private function __construct(
        private Main $plugin,
        private string $senderName,
        private World $world,
        private string $dimension,
        private string $mode
    ){
        $this->worldName = $world->getFolderName();
        $this->activeKey = strtolower($mode . ':' . $this->worldName);
        $this->state = new Config($plugin->getDataFolder() . 'structure-population.yml', Config::YAML, [
            'mobs' => [],
            'loot' => [],
            'loot-version' => self::LOOT_VERSION,
        ]);
        if($mode === 'mobs'){
            $all = (array) $this->state->get('mobs', []);
            $this->mobRecords = array_values((array) ($all[$this->worldName] ?? []));
        }else{
            // Loot is refreshed only when the command is explicitly executed.
            // Saved coordinates are not used to suppress future command runs;
            // lootRecords only prevents filling the same double chest twice
            // during this one scan.
            $this->lootRecords = [];
            $this->legacyLootRecords = [];
        }
    }

    public static function start(Main $plugin, Player $sender, World $world, string $dimension, string $mode) : bool{
        $key = strtolower($mode . ':' . $world->getFolderName());
        if(isset(self::$active[$key])){
            return false;
        }
        self::$active[$key] = true;
        $plugin->getScheduler()->scheduleRepeatingTask(new self($plugin, $sender->getName(), $world, $dimension, $mode), 1);
        return true;
    }

    public function onRun() : void{
        for($i = 0; $i < self::CHUNKS_PER_TICK; ++$i){
            if($this->chunkZ > self::MAX_CHUNK){
                $this->finish();
                return;
            }
            try{
                $this->processChunk($this->chunkX, $this->chunkZ);
            }catch(Throwable $e){
                $this->plugin->getLogger()->warning("Ошибка сканирования чанка {$this->chunkX}:{$this->chunkZ}: " . $e->getMessage());
            }
            ++$this->processed;
            ++$this->chunkX;
            if($this->chunkX > self::MAX_CHUNK){
                $this->chunkX = self::MIN_CHUNK;
                ++$this->chunkZ;
            }
        }

        if($this->processed % 5000 < self::CHUNKS_PER_TICK){
            $percent = (int) floor(($this->processed / 63001) * 100);
            $this->message("§7Сканирование структур: §f{$percent}% §7({$this->processed}/63001 чанков)");
            $this->saveState();
        }
    }

    private function processChunk(int $chunkX, int $chunkZ) : void{
        // Scan only terrain which already exists in the map. The command must
        // not generate tens of thousands of new empty chunks just to look for
        // structures.
        if(!$this->world->isChunkGenerated($chunkX, $chunkZ)){
            return;
        }
        $wasLoaded = $this->world->isChunkLoaded($chunkX, $chunkZ);
        $chunk = $this->world->loadChunk($chunkX, $chunkZ);
        if(!$chunk instanceof Chunk){
            return;
        }

        $tiles = $chunk->getTiles();
        $containerCount = 0;
        foreach($tiles as $tile){
            if($tile instanceof ChestTile || $tile instanceof BarrelTile){
                ++$containerCount;
            }
        }

        foreach($tiles as $tile){
            if($tile instanceof BellTile && $this->dimension === 'overworld'){
                if($this->mode === 'mobs'){
                    $this->registerMobStructure('village', $tile->getPosition(), 48);
                }else{
                    $this->fillVillageContainersAround($tile->getPosition(), 72);
                }
                continue;
            }
            if($tile instanceof ChestTile || $tile instanceof BarrelTile){
                $type = $this->classifyContainer($tile->getPosition(), $containerCount);
                if($type !== null){
                    if($this->mode === 'mobs'){
                        $this->registerMobStructure($type, $tile->getPosition(), $this->radiusFor($type));
                    }else{
                        if($type === 'village'){
                            $this->fillVillageContainersAround($tile->getPosition(), 72);
                        }else{
                            $this->fillContainer($tile, $type);
                        }
                    }
                }
                continue;
            }
            if($tile instanceof MonsterSpawner && $this->mode === 'mobs'){
                $type = $this->classifyContainer($tile->getPosition(), $containerCount);
                if(in_array($type, ['mineshaft', 'stronghold', 'fortress'], true)){
                    $this->registerMobStructure($type, $tile->getPosition(), $this->radiusFor($type));
                }
            }
        }

        foreach($this->scanDistinctiveMarkers($chunk, $chunkX, $chunkZ) as [$type, $position]){
            if($this->mode === 'mobs'){
                $this->registerMobStructure($type, $position, $this->radiusFor($type));
            }elseif($this->hasLootTable($type)){
                $this->fillStructureContainersAround($position, $this->radiusFor($type), $type);
            }
        }

        if(!$wasLoaded){
            $this->world->unloadChunkRequest($chunkX, $chunkZ, true);
        }
    }

    /** @return array<int, array{0:string,1:Position}> */
    private function scanDistinctiveMarkers(Chunk $chunk, int $chunkX, int $chunkZ) : array{
        $counts = [
            'prismarine' => 0,
            'reinforced' => 0,
            'sculk' => 0,
            'portal_frame' => 0,
            'infested_brick' => 0,
            'rail' => 0,
            'cauldron' => 0,
            'nether_brick' => 0,
            'gilded_blackstone' => 0,
            'polished_blackstone' => 0,
            'obsidian' => 0,
            'crying_obsidian' => 0,
            'purpur' => 0,
        ];
        /** @var array<string, Position> $first */
        $first = [];
        $sampleXZ = [4, 12];
        foreach($sampleXZ as $localX){
            foreach($sampleXZ as $localZ){
                $ys = [];
                $highest = $chunk->getHighestBlockAt($localX, $localZ);
                if($highest !== null){
                    for($d = 0; $d <= 14; $d += 2){
                        $ys[] = $highest - $d;
                    }
                }
                if($this->dimension === 'nether'){
                    foreach([16, 28, 40, 52, 64, 76, 88, 100, 112] as $y){ $ys[] = $y; }
                }else{
                    foreach([-52, -44, -36, -20, -4, 12, 28, 44, 60, 76] as $y){ $ys[] = $y; }
                }
                foreach($ys as $y){
                    if($y < $this->world->getMinY() || $y >= $this->world->getMaxY()){
                        continue;
                    }
                    $stateId = $chunk->getBlockStateId($localX, $y, $localZ);
                    $typeId = $this->stateTypeCache[$stateId] ??= RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId)->getTypeId();
                    $category = $this->markerCategory($typeId);
                    if($category === null){
                        continue;
                    }
                    ++$counts[$category];
                    $first[$category] ??= new Position(($chunkX << 4) + $localX + 0.5, $y + 0.5, ($chunkZ << 4) + $localZ + 0.5, $this->world);
                }
            }
        }

        $result = [];
        if($counts['crying_obsidian'] >= 1 && $counts['obsidian'] >= 1){
            $result[] = ['ruined_portal', $first['crying_obsidian']];
        }
        if($this->dimension === 'nether'){
            if($counts['gilded_blackstone'] >= 1 || $counts['polished_blackstone'] >= 4){
                $result[] = ['bastion', $first['gilded_blackstone'] ?? $first['polished_blackstone']];
            }
            if($counts['nether_brick'] >= 4){
                $result[] = ['fortress', $first['nether_brick']];
            }
        }else{
            if($counts['purpur'] >= 3){
                $result[] = ['end_city', $first['purpur']];
            }
            if($counts['prismarine'] >= 3){
                $result[] = ['monument', $first['prismarine']];
            }
            if($counts['reinforced'] >= 1 || $counts['sculk'] >= 5){
                $result[] = ['ancient_city', $first['reinforced'] ?? $first['sculk']];
            }
            if($counts['portal_frame'] >= 1 || $counts['infested_brick'] >= 3){
                $result[] = ['stronghold', $first['portal_frame'] ?? $first['infested_brick']];
            }
            if($counts['rail'] >= 2){
                $result[] = ['mineshaft', $first['rail']];
            }
            if($counts['cauldron'] >= 1){
                $result[] = ['swamp_hut', $first['cauldron']];
            }
        }
        return $result;
    }

    private function markerCategory(int $typeId) : ?string{
        return match($typeId){
            BlockTypeIds::PRISMARINE, BlockTypeIds::PRISMARINE_BRICKS, BlockTypeIds::DARK_PRISMARINE => 'prismarine',
            BlockTypeIds::REINFORCED_DEEPSLATE => 'reinforced',
            BlockTypeIds::SCULK => 'sculk',
            BlockTypeIds::END_PORTAL_FRAME => 'portal_frame',
            BlockTypeIds::INFESTED_STONE_BRICK, BlockTypeIds::INFESTED_MOSSY_STONE_BRICK,
            BlockTypeIds::INFESTED_CRACKED_STONE_BRICK, BlockTypeIds::INFESTED_CHISELED_STONE_BRICK => 'infested_brick',
            BlockTypeIds::RAIL, BlockTypeIds::POWERED_RAIL, BlockTypeIds::DETECTOR_RAIL, BlockTypeIds::ACTIVATOR_RAIL => 'rail',
            BlockTypeIds::CAULDRON, BlockTypeIds::WATER_CAULDRON, BlockTypeIds::POTION_CAULDRON => 'cauldron',
            BlockTypeIds::NETHER_BRICKS, BlockTypeIds::CRACKED_NETHER_BRICKS, BlockTypeIds::CHISELED_NETHER_BRICKS,
            BlockTypeIds::RED_NETHER_BRICKS => 'nether_brick',
            BlockTypeIds::GILDED_BLACKSTONE => 'gilded_blackstone',
            BlockTypeIds::POLISHED_BLACKSTONE, BlockTypeIds::POLISHED_BLACKSTONE_BRICKS,
            BlockTypeIds::CHISELED_POLISHED_BLACKSTONE, BlockTypeIds::CRACKED_POLISHED_BLACKSTONE_BRICKS => 'polished_blackstone',
            BlockTypeIds::OBSIDIAN => 'obsidian',
            BlockTypeIds::CRYING_OBSIDIAN => 'crying_obsidian',
            BlockTypeIds::PURPUR, BlockTypeIds::PURPUR_PILLAR, BlockTypeIds::PURPUR_SLAB, BlockTypeIds::PURPUR_STAIRS => 'purpur',
            default => null,
        };
    }

    private function classifyContainer(Position $position, int $containerCount) : ?string{
        $counts = [
            'dark_oak' => 0, 'cobble' => 0, 'bookshelf' => 0, 'carpet' => 0,
            'bed' => 0, 'jobs' => 0, 'bell' => 0, 'stone_brick' => 0,
            'portal_frame' => 0, 'rail' => 0, 'prismarine' => 0, 'water' => 0,
            'sculk' => 0, 'reinforced' => 0, 'cauldron' => 0, 'wood' => 0,
            'nether_brick' => 0, 'blackstone' => 0, 'gilded' => 0,
            'obsidian' => 0, 'crying' => 0, 'sandstone' => 0, 'sand' => 0,
            'tnt' => 0, 'mossy' => 0, 'purpur' => 0, 'snow_ice' => 0, 'spawner' => 0,
        ];
        $cx = $position->getFloorX();
        $cy = $position->getFloorY();
        $cz = $position->getFloorZ();
        for($x = $cx - 7; $x <= $cx + 7; ++$x){
            for($z = $cz - 7; $z <= $cz + 7; ++$z){
                for($y = max($this->world->getMinY(), $cy - 6); $y <= min($this->world->getMaxY() - 1, $cy + 6); ++$y){
                    $block = $this->world->getBlockAt($x, $y, $z, false, false);
                    $type = $block->getTypeId();
                    switch($type){
                        case BlockTypeIds::DARK_OAK_LOG:
                        case BlockTypeIds::DARK_OAK_WOOD:
                        case BlockTypeIds::DARK_OAK_PLANKS:
                        case BlockTypeIds::DARK_OAK_STAIRS:
                        case BlockTypeIds::DARK_OAK_SLAB:
                        case BlockTypeIds::DARK_OAK_FENCE:
                            ++$counts['dark_oak']; ++$counts['wood']; break;
                        case BlockTypeIds::COBBLESTONE:
                            ++$counts['cobble']; break;
                        case BlockTypeIds::MOSSY_COBBLESTONE:
                            ++$counts['cobble']; ++$counts['mossy']; break;
                        case BlockTypeIds::SANDSTONE:
                        case BlockTypeIds::CHISELED_SANDSTONE:
                        case BlockTypeIds::CUT_SANDSTONE:
                        case BlockTypeIds::SMOOTH_SANDSTONE:
                            ++$counts['sandstone']; break;
                        case BlockTypeIds::SAND:
                        case BlockTypeIds::RED_SAND:
                            ++$counts['sand']; break;
                        case BlockTypeIds::TNT: ++$counts['tnt']; break;
                        case BlockTypeIds::MONSTER_SPAWNER: ++$counts['spawner']; break;
                        case BlockTypeIds::PURPUR:
                        case BlockTypeIds::PURPUR_PILLAR:
                        case BlockTypeIds::PURPUR_SLAB:
                        case BlockTypeIds::PURPUR_STAIRS:
                            ++$counts['purpur']; break;
                        case BlockTypeIds::SNOW:
                        case BlockTypeIds::SNOW_LAYER:
                        case BlockTypeIds::ICE:
                        case BlockTypeIds::PACKED_ICE:
                        case BlockTypeIds::BLUE_ICE:
                            ++$counts['snow_ice']; break;
                        case BlockTypeIds::BOOKSHELF:
                        case BlockTypeIds::CHISELED_BOOKSHELF:
                            ++$counts['bookshelf']; break;
                        case BlockTypeIds::BED: ++$counts['bed']; break;
                        case BlockTypeIds::BELL: ++$counts['bell']; break;
                        case BlockTypeIds::BARREL:
                        case BlockTypeIds::LECTERN:
                        case BlockTypeIds::LOOM:
                        case BlockTypeIds::FLETCHING_TABLE:
                        case BlockTypeIds::BREWING_STAND:
                        case BlockTypeIds::CARTOGRAPHY_TABLE:
                        case BlockTypeIds::SMITHING_TABLE:
                        case BlockTypeIds::STONECUTTER:
                        case BlockTypeIds::SMOKER:
                        case BlockTypeIds::BLAST_FURNACE:
                        case BlockTypeIds::GRINDSTONE:
                            ++$counts['jobs']; break;
                        case BlockTypeIds::STONE_BRICKS:
                        case BlockTypeIds::MOSSY_STONE_BRICKS:
                        case BlockTypeIds::CRACKED_STONE_BRICKS:
                        case BlockTypeIds::CHISELED_STONE_BRICKS:
                            ++$counts['stone_brick']; break;
                        case BlockTypeIds::END_PORTAL_FRAME: ++$counts['portal_frame']; break;
                        case BlockTypeIds::RAIL:
                        case BlockTypeIds::POWERED_RAIL:
                        case BlockTypeIds::DETECTOR_RAIL:
                        case BlockTypeIds::ACTIVATOR_RAIL:
                            ++$counts['rail']; break;
                        case BlockTypeIds::PRISMARINE:
                        case BlockTypeIds::PRISMARINE_BRICKS:
                        case BlockTypeIds::DARK_PRISMARINE:
                            ++$counts['prismarine']; break;
                        case BlockTypeIds::SCULK: ++$counts['sculk']; break;
                        case BlockTypeIds::REINFORCED_DEEPSLATE: ++$counts['reinforced']; break;
                        case BlockTypeIds::CAULDRON:
                        case BlockTypeIds::WATER_CAULDRON:
                        case BlockTypeIds::POTION_CAULDRON:
                            ++$counts['cauldron']; break;
                        case BlockTypeIds::NETHER_BRICKS:
                        case BlockTypeIds::CRACKED_NETHER_BRICKS:
                        case BlockTypeIds::CHISELED_NETHER_BRICKS:
                        case BlockTypeIds::RED_NETHER_BRICKS:
                            ++$counts['nether_brick']; break;
                        case BlockTypeIds::BLACKSTONE:
                        case BlockTypeIds::POLISHED_BLACKSTONE:
                        case BlockTypeIds::POLISHED_BLACKSTONE_BRICKS:
                        case BlockTypeIds::CHISELED_POLISHED_BLACKSTONE:
                        case BlockTypeIds::CRACKED_POLISHED_BLACKSTONE_BRICKS:
                            ++$counts['blackstone']; break;
                        case BlockTypeIds::GILDED_BLACKSTONE: ++$counts['gilded']; break;
                        case BlockTypeIds::OBSIDIAN: ++$counts['obsidian']; break;
                        case BlockTypeIds::CRYING_OBSIDIAN: ++$counts['crying']; break;
                    }
                    $name = strtolower($block->getName());
                    if(str_contains($name, 'carpet')) ++$counts['carpet'];
                    if(str_contains($name, 'water')) ++$counts['water'];
                    if(str_contains($name, 'planks') || str_contains($name, 'log')) ++$counts['wood'];
                    if(str_contains($name, 'sculk')) ++$counts['sculk'];
                }
            }
        }

        if($counts['crying'] >= 1 && $counts['obsidian'] >= 3) return 'ruined_portal';
        if($this->dimension === 'nether'){
            if($counts['gilded'] >= 1 || $counts['blackstone'] >= 28) return 'bastion';
            if($counts['nether_brick'] >= 20) return 'fortress';
            return null;
        }
        if($counts['reinforced'] >= 1 || $counts['sculk'] >= 20) return 'ancient_city';
        if($counts['purpur'] >= 18) return 'end_city';
        if($counts['sandstone'] >= 30 && $counts['tnt'] >= 1) return 'desert_pyramid';
        if($counts['mossy'] >= 10 && $counts['cobble'] >= 22 && $position->getFloorY() > 45) return 'jungle_temple';
        if($counts['snow_ice'] >= 20 && $counts['stone_brick'] >= 6) return 'igloo';
        if($counts['portal_frame'] >= 1 || ($counts['stone_brick'] >= 30 && $position->getFloorY() < 55)) return 'stronghold';
        if($counts['rail'] >= 4) return 'mineshaft';
        if($counts['spawner'] >= 1 && $counts['mossy'] >= 6 && $counts['cobble'] >= 16) return 'dungeon';
        if($counts['bed'] >= 2 || $counts['bell'] >= 1 || $counts['jobs'] >= 3) return 'village';
        if($counts['dark_oak'] >= 75 && ($containerCount >= 2 || $counts['bookshelf'] >= 2 || $counts['carpet'] >= 4 || ($counts['dark_oak'] >= 120 && $counts['cobble'] < 20))) return 'mansion';
        if($counts['dark_oak'] >= 25 && $counts['cobble'] >= 12) return 'outpost';
        if($counts['cauldron'] >= 1 && $counts['wood'] >= 20) return 'swamp_hut';
        if($position->getFloorY() <= 68 && $counts['water'] >= 25 && ($counts['prismarine'] + $counts['stone_brick'] + $counts['cobble']) >= 16) return 'ocean_ruin';
        if($position->getFloorY() <= 68 && $counts['water'] >= 25 && $counts['wood'] >= 32) return 'shipwreck';
        if($position->getFloorY() <= 64 && $counts['water'] >= 12 && $counts['sand'] >= 24 && $counts['wood'] < 15) return 'buried_treasure';
        return null;
    }

    private function registerMobStructure(string $type, Position $position, int $radius) : void{
        $groups = $this->dimension === 'nether' ? StructureMobsCommand::NETHER_GROUPS : StructureMobsCommand::OVERWORLD_GROUPS;
        if(!isset($groups[$type]) || $this->alreadyHasNearbyMobRecord($type, $position)){
            return;
        }

        $home = $position->asVector3();
        $structureId = StructureRespawnTask::makeStructureId($this->worldName, $type, $home);
        foreach($this->world->getEntities() as $entity){
            if($entity instanceof BaseMob && $entity->isAlive() && !$entity->isClosed() && $entity->belongsToStructure($structureId, $type, $home)){
                $this->mobRecords[] = [
                    'id' => $structureId,
                    'type' => $type,
                    'dimension' => $this->dimension,
                    'x' => (float) $position->x,
                    'y' => (float) $position->y,
                    'z' => (float) $position->z,
                    'radius' => $radius,
                    'respawn_at' => 0,
                ];
                ++$this->found;
                return;
            }
        }

        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;
        $newlyLoaded = [];
        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                $x = $chunkX + $dx;
                $z = $chunkZ + $dz;
                if($this->world->isChunkGenerated($x, $z) && !$this->world->isChunkLoaded($x, $z) && $this->world->loadChunk($x, $z) instanceof Chunk){
                    $newlyLoaded[] = [$x, $z];
                }
            }
        }
        $amount = StructureMobsCommand::spawnGroup($this->world, $type, $home, $radius, $this->dimension, $structureId);
        foreach($newlyLoaded as [$x, $z]){
            $this->world->unloadChunkRequest($x, $z, true);
        }
        if($amount < 1){
            return;
        }
        $this->mobRecords[] = [
            'id' => $structureId,
            'type' => $type,
            'dimension' => $this->dimension,
            'x' => (float) $position->x,
            'y' => (float) $position->y,
            'z' => (float) $position->z,
            'radius' => $radius,
            'respawn_at' => 0,
        ];
        ++$this->found;
        $this->spawned += $amount;
    }

    private function alreadyHasNearbyMobRecord(string $type, Position $position) : bool{
        $distance = match($type){
            'mansion', 'monument', 'ancient_city' => 112,
            'village', 'stronghold' => 80,
            default => 48,
        };
        $limit = $distance * $distance;
        foreach($this->mobRecords as $record){
            if(($record['type'] ?? '') !== $type) continue;
            $dx = (float) ($record['x'] ?? 0) - $position->x;
            $dz = (float) ($record['z'] ?? 0) - $position->z;
            if(($dx * $dx) + ($dz * $dz) <= $limit){
                return true;
            }
        }
        return false;
    }

    private function radiusFor(string $type) : int{
        return match($type){
            'mansion', 'monument', 'ancient_city' => 64,
            'village', 'stronghold', 'fortress', 'bastion' => 48,
            default => 32,
        };
    }

    private function hasLootTable(string $type) : bool{
        return in_array($type, [
            'village', 'outpost', 'mansion', 'stronghold', 'mineshaft', 'desert_pyramid',
            'jungle_temple', 'shipwreck', 'buried_treasure', 'igloo', 'dungeon',
            'ocean_ruin', 'fortress', 'bastion', 'ruined_portal', 'end_city', 'ancient_city'
        ], true);
    }

    private function fillStructureContainersAround(Position $center, int $radius, string $type) : void{
        foreach($this->lootStructureCenters as $known){
            if($known['type'] !== $type){
                continue;
            }
            $dx = $known['position']->x - $center->x;
            $dz = $known['position']->z - $center->z;
            $dedupeRadius = max(48, $radius);
            if(($dx * $dx) + ($dz * $dz) <= $dedupeRadius * $dedupeRadius){
                return;
            }
        }
        $this->lootStructureCenters[] = ['type' => $type, 'position' => clone $center];

        $radius = min(80, max(24, $radius));
        $minChunkX = max(self::MIN_CHUNK, ($center->getFloorX() - $radius) >> 4);
        $maxChunkX = min(self::MAX_CHUNK, ($center->getFloorX() + $radius) >> 4);
        $minChunkZ = max(self::MIN_CHUNK, ($center->getFloorZ() - $radius) >> 4);
        $maxChunkZ = min(self::MAX_CHUNK, ($center->getFloorZ() + $radius) >> 4);
        $radiusSquared = $radius * $radius;
        for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
            for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
                if(!$this->world->isChunkGenerated($chunkX, $chunkZ)){
                    continue;
                }
                $wasLoaded = $this->world->isChunkLoaded($chunkX, $chunkZ);
                $chunk = $this->world->loadChunk($chunkX, $chunkZ);
                if(!$chunk instanceof Chunk){
                    continue;
                }
                foreach($chunk->getTiles() as $tile){
                    if(!$tile instanceof ChestTile && !$tile instanceof BarrelTile){
                        continue;
                    }
                    $position = $tile->getPosition();
                    $dx = $position->x - $center->x;
                    $dz = $position->z - $center->z;
                    if(($dx * $dx) + ($dz * $dz) <= $radiusSquared){
                        $this->fillContainer($tile, $type);
                    }
                }
                if(!$wasLoaded){
                    $this->world->unloadChunkRequest($chunkX, $chunkZ, true);
                }
            }
        }
    }

    private function fillVillageContainersAround(Position $center, int $radius) : void{
        foreach($this->villageCenters as $known){
            $dx = $known->x - $center->x;
            $dz = $known->z - $center->z;
            if(($dx * $dx) + ($dz * $dz) <= 96 * 96){
                return;
            }
        }
        $this->villageCenters[] = clone $center;

        $minChunkX = max(self::MIN_CHUNK, ($center->getFloorX() - $radius) >> 4);
        $maxChunkX = min(self::MAX_CHUNK, ($center->getFloorX() + $radius) >> 4);
        $minChunkZ = max(self::MIN_CHUNK, ($center->getFloorZ() - $radius) >> 4);
        $maxChunkZ = min(self::MAX_CHUNK, ($center->getFloorZ() + $radius) >> 4);
        $radiusSquared = $radius * $radius;
        for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
            for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
                if(!$this->world->isChunkGenerated($chunkX, $chunkZ)){
                    continue;
                }
                $wasLoaded = $this->world->isChunkLoaded($chunkX, $chunkZ);
                $chunk = $this->world->loadChunk($chunkX, $chunkZ);
                if(!$chunk instanceof Chunk){
                    continue;
                }
                foreach($chunk->getTiles() as $tile){
                    if(!$tile instanceof ChestTile && !$tile instanceof BarrelTile){
                        continue;
                    }
                    $position = $tile->getPosition();
                    $dx = $position->x - $center->x;
                    $dz = $position->z - $center->z;
                    if(($dx * $dx) + ($dz * $dz) > $radiusSquared){
                        continue;
                    }
                    $this->fillContainer($tile, $this->detectVillageLootType($position));
                }
                if(!$wasLoaded){
                    $this->world->unloadChunkRequest($chunkX, $chunkZ, true);
                }
            }
        }
    }

    private function detectVillageLootType(Position $position) : string{
        $priority = [
            BlockTypeIds::BLAST_FURNACE => 'village_armorer',
            BlockTypeIds::GRINDSTONE => 'village_weaponsmith',
            BlockTypeIds::SMITHING_TABLE => 'village_toolsmith',
            BlockTypeIds::CARTOGRAPHY_TABLE => 'village_cartographer',
            BlockTypeIds::BREWING_STAND => 'village_temple',
            BlockTypeIds::SMOKER => 'village_butcher',
            BlockTypeIds::BARREL => 'village_fisher',
            BlockTypeIds::FLETCHING_TABLE => 'village_fletcher',
            BlockTypeIds::LOOM => 'village_shepherd',
            BlockTypeIds::CAULDRON => 'village_tannery',
            BlockTypeIds::WATER_CAULDRON => 'village_tannery',
            BlockTypeIds::STONECUTTER => 'village_mason',
        ];
        $cx = $position->getFloorX();
        $cy = $position->getFloorY();
        $cz = $position->getFloorZ();
        for($radius = 4; $radius <= 10; $radius += 3){
            for($x = $cx - $radius; $x <= $cx + $radius; ++$x){
                for($z = $cz - $radius; $z <= $cz + $radius; ++$z){
                    for($y = max($this->world->getMinY(), $cy - 4); $y <= min($this->world->getMaxY() - 1, $cy + 4); ++$y){
                        $type = $this->world->getBlockAt($x, $y, $z, false, false)->getTypeId();
                        if(isset($priority[$type])){
                            return $priority[$type];
                        }
                    }
                }
            }
        }
        return 'village_house';
    }

    private function fillContainer(ChestTile|BarrelTile $tile, string $type) : void{
        $key = $this->containerKey($tile);
        if(isset($this->lootRecords[$key])){
            return;
        }
        $inventory = $tile->getInventory();
        $items = $this->createLoot($type);
        if($items === []){
            return;
        }
        // An explicit /structureloot run is the refresh trigger. Only containers
        // which were recognized as part of a structure reach this method. Build
        // the new loot first so an unsupported item alias can never empty a chest.
        $inventory->clearAll();
        $slots = array_keys($inventory->getContents(true));
        shuffle($slots);
        foreach($items as $item){
            $slot = array_pop($slots);
            if($slot === null) break;
            $inventory->setItem((int) $slot, $item);
        }
        $this->lootRecords[$key] = true;
        ++$this->found;
        ++$this->filled;
    }

    private function containerKey(ChestTile|BarrelTile $tile) : string{
        $positions = [$tile->getPosition()];
        if($tile instanceof ChestTile && ($pair = $tile->getPair()) instanceof ChestTile){
            $positions[] = $pair->getPosition();
        }
        $keys = [];
        foreach($positions as $position){
            $keys[] = $position->getFloorX() . ':' . $position->getFloorY() . ':' . $position->getFloorZ();
        }
        sort($keys, SORT_STRING);
        return implode('|', $keys);
    }

    /**
     * Vanilla-style weighted chest pools. Each pool performs its own number of
     * rolls, so a chest can contain common supplies, equipment and a separate
     * rare item at the same time, just like Mojang loot tables.
     *
     * Entry format: [item id or null, min count, max count, weight, options].
     * A null item is an intentional empty roll.
     *
     * @return array<int, Item>
     */
    private function createLoot(string $type) : array{
        $tables = $this->lootTables();
        $pools = $tables[$type] ?? $tables['village_house'];
        $result = [];
        foreach($pools as $pool){
            [$minRolls, $maxRolls, $entries] = $pool;
            $rolls = mt_rand($minRolls, $maxRolls);
            for($roll = 0; $roll < $rolls; ++$roll){
                $entry = $this->weightedEntry($entries);
                $this->appendLootEntry($result, $entry);
            }
        }
        $this->addSupplementalLoot($type, $result);
        return array_slice($result, 0, 18);
    }

    /** @return array<string, array<int, array{0:int,1:int,2:array<int,array{0:?string,1:int,2:int,3:int,4:array<string,mixed>}>}>> */
    private function lootTables() : array{
        $e = static fn(?string $id, int $min, int $max, int $weight, array $options = []) : array => [$id, $min, $max, $weight, $options];
        return [
            'village_weaponsmith' => [[3, 8, [
                $e('diamond',1,3,3), $e('iron_ingot',1,5,10), $e('gold_ingot',1,3,5), $e('bread',1,3,15),
                $e('apple',1,3,15), $e('iron_pickaxe',1,1,5,['damage'=>true]), $e('iron_sword',1,1,5,['damage'=>true]),
                $e('iron_chestplate',1,1,5,['damage'=>true]), $e('iron_helmet',1,1,5,['damage'=>true]),
                $e('iron_leggings',1,1,5,['damage'=>true]), $e('iron_boots',1,1,5,['damage'=>true]),
                $e('obsidian',3,7,5), $e('oak_sapling',3,7,5), $e('saddle',1,1,3),
                $e('iron_horse_armor',1,1,1), $e('golden_horse_armor',1,1,1), $e('diamond_horse_armor',1,1,1),
            ]]],
            'village_toolsmith' => [[3, 8, [
                $e('diamond',1,3,3), $e('iron_ingot',1,5,10), $e('gold_ingot',1,3,5), $e('bread',1,3,15),
                $e('apple',1,3,15), $e('coal',1,3,10), $e('iron_pickaxe',1,1,5,['damage'=>true]),
                $e('iron_shovel',1,1,5,['damage'=>true]), $e('iron_hoe',1,1,5,['damage'=>true]),
                $e('stone_pickaxe',1,1,5,['damage'=>true]), $e('stone_axe',1,1,5,['damage'=>true]),
            ]]],
            'village_armorer' => [[3, 8, [
                $e('bread',1,4,15), $e('iron_ingot',1,5,10), $e('emerald',1,3,5), $e('coal',1,4,10),
                $e('iron_helmet',1,1,5,['damage'=>true]), $e('iron_chestplate',1,1,5,['damage'=>true]),
                $e('iron_leggings',1,1,5,['damage'=>true]), $e('iron_boots',1,1,5,['damage'=>true]),
                $e('chainmail_helmet',1,1,3,['damage'=>true]), $e('chainmail_chestplate',1,1,3,['damage'=>true]),
                $e('obsidian',1,4,4),
            ]]],
            'village_cartographer' => [[3, 8, [
                $e('paper',1,5,15), $e('bread',1,4,15), $e('stick',1,5,10), $e('compass',1,1,5),
                $e('map',1,1,10), $e('emerald',1,3,5), $e('glass_pane',1,5,10),
            ]]],
            'village_temple' => [[3, 8, [
                $e('redstone',1,4,10), $e('bread',1,4,15), $e('rotten_flesh',1,4,10), $e('gold_ingot',1,4,5),
                $e('emerald',1,3,5), $e('lapis_lazuli',1,4,5), $e('saddle',1,1,2),
            ]]],
            'village_butcher' => [[3, 8, [
                $e('raw_beef',1,3,10), $e('cooked_beef',1,3,5), $e('raw_porkchop',1,3,10),
                $e('cooked_porkchop',1,3,5), $e('wheat',1,3,10), $e('coal',1,3,10), $e('emerald',1,2,3),
            ]]],
            'village_fisher' => [[3, 8, [
                $e('raw_cod',1,3,10), $e('cooked_cod',1,3,5), $e('salmon',1,3,10), $e('water_bucket',1,1,3),
                $e('coal',1,4,10), $e('wheat_seeds',1,3,5), $e('fishing_rod',1,1,5,['damage'=>true]), $e('emerald',1,2,3),
            ]]],
            'village_fletcher' => [[3, 8, [
                $e('arrow',2,8,15), $e('flint',1,4,10), $e('stick',2,6,15), $e('feather',1,4,10),
                $e('bow',1,1,5,['damage'=>true]), $e('crossbow',1,1,4,['damage'=>true]), $e('emerald',1,2,3),
            ]]],
            'village_shepherd' => [[3, 8, [
                $e('white_wool',1,8,15), $e('wheat',1,6,10), $e('shears',1,1,5,['damage'=>true]),
                $e('white_dye',1,4,8), $e('black_dye',1,4,5), $e('emerald',1,2,3),
            ]]],
            'village_tannery' => [[3, 8, [
                $e('leather',1,4,15), $e('leather_helmet',1,1,5,['damage'=>true]), $e('leather_chestplate',1,1,5,['damage'=>true]),
                $e('leather_leggings',1,1,5,['damage'=>true]), $e('leather_boots',1,1,5,['damage'=>true]),
                $e('saddle',1,1,3), $e('emerald',1,2,3),
            ]]],
            'village_mason' => [[3, 8, [
                $e('clay_ball',1,4,15), $e('brick',1,4,15), $e('stone',1,4,10), $e('smooth_stone',1,4,8),
                $e('quartz',1,3,4), $e('emerald',1,2,3),
            ]]],
            'village_house' => [[3, 8, [
                $e('bread',1,4,20), $e('apple',1,5,15), $e('wheat',1,7,15), $e('potato',1,7,12),
                $e('carrot',1,7,12), $e('beetroot_seeds',1,5,8), $e('emerald',1,3,5),
                $e('iron_ingot',1,3,4), $e('gold_nugget',1,6,5), $e('book',1,3,5),
            ]]],
            'village' => [[3, 8, [
                $e('bread',1,4,20), $e('apple',1,5,15), $e('wheat',1,7,15), $e('potato',1,7,12),
                $e('carrot',1,7,12), $e('emerald',1,3,5), $e('iron_ingot',1,3,4), $e('book',1,3,5),
            ]]],

            'outpost' => [
                [2, 3, [$e('crossbow',1,1,1,['damage'=>true]), $e('bottle_o_enchanting',1,1,1), $e('string',1,6,4), $e('arrow',2,7,4), $e('tripwire_hook',1,3,3), $e('iron_ingot',1,3,2), $e(null,0,0,3)]],
                [1, 3, [$e('wheat',3,7,7), $e('potato',2,5,5), $e('carrot',2,5,5), $e('dark_oak_log',2,3,1)]],
                [1, 1, [$e('totem_of_undying',1,1,1), $e('emerald',1,3,6), $e(null,0,0,13)]],
            ],
            'mansion' => [
                [3, 6, [$e('bone',1,8,10), $e('gunpowder',1,8,10), $e('rotten_flesh',1,8,10), $e('string',1,8,10), $e('bread',1,1,20), $e('wheat',1,4,20), $e('redstone',1,4,15), $e('coal',1,4,15), $e(null,0,0,10)]],
                [1, 3, [$e('name_tag',1,1,20), $e('golden_apple',1,1,15), $e('diamond_hoe',1,1,5,['enchant'=>true]), $e('enchanted_book',1,1,10,['enchant'=>true]), $e('diamond_chestplate',1,1,2,['enchant'=>true]), $e(null,0,0,48)]],
                [1, 1, [$e('totem_of_undying',1,1,2), $e('emerald',1,4,8), $e(null,0,0,20)]],
            ],
            'stronghold' => [
                [2, 4, [$e('ender_pearl',1,3,10), $e('iron_ingot',1,5,10), $e('gold_ingot',1,3,5), $e('redstone',4,9,5), $e('coal',3,8,10), $e('bread',1,3,15), $e('apple',1,3,15), $e(null,0,0,5)]],
                [2, 6, [$e('book',1,3,20), $e('paper',2,7,20), $e('map',1,1,1), $e('compass',1,1,1), $e('enchanted_book',1,1,2,['enchant'=>true]), $e(null,0,0,5)]],
            ],
            'mineshaft' => [
                [3, 6, [$e('rail',4,8,20), $e('powered_rail',1,4,5), $e('detector_rail',1,4,5), $e('torch',1,16,15), $e('bread',1,3,15), $e('coal',3,8,10), $e('iron_ingot',1,5,10), $e('gold_ingot',1,3,5), $e('redstone',4,9,5), $e('lapis_lazuli',4,9,5), $e('melon_seeds',2,4,10), $e('pumpkin_seeds',2,4,10), $e('beetroot_seeds',2,4,10)]],
                [1, 2, [$e('name_tag',1,1,30), $e('golden_apple',1,1,20), $e('enchanted_book',1,1,10,['enchant'=>true]), $e('diamond',1,2,7), $e('iron_pickaxe',1,1,5,['enchant'=>true,'damage'=>true]), $e(null,0,0,28)]],
            ],
            'desert_pyramid' => [
                [2, 4, [$e('bone',4,6,25), $e('rotten_flesh',3,7,25), $e('gunpowder',1,8,25), $e('sand',1,8,10), $e('string',1,8,10)]],
                [2, 4, [$e('iron_ingot',1,5,15), $e('gold_ingot',1,7,15), $e('emerald',1,3,5), $e('diamond',1,3,3), $e('saddle',1,1,3), $e('iron_horse_armor',1,1,1), $e('golden_horse_armor',1,1,1), $e('diamond_horse_armor',1,1,1), $e('enchanted_book',1,1,2,['enchant'=>true]), $e('golden_apple',1,1,3), $e(null,0,0,15)]],
            ],
            'jungle_temple' => [
                [2, 5, [$e('bone',4,6,20), $e('rotten_flesh',3,7,20), $e('gold_ingot',2,7,15), $e('iron_ingot',1,5,15), $e('bamboo',1,3,15), $e('emerald',1,3,5), $e('diamond',1,3,3), $e('saddle',1,1,3), $e('enchanted_book',1,1,2,['enchant'=>true]), $e(null,0,0,10)]],
            ],
            'shipwreck' => [
                [4, 8, [$e('paper',1,12,20), $e('map',1,1,8), $e('compass',1,1,5), $e('feather',1,5,10), $e('book',1,5,10), $e('coal',2,8,15), $e('potato',2,6,12), $e('wheat',2,6,12), $e('carrot',2,6,12), $e('rotten_flesh',3,7,10)]],
                [2, 5, [$e('iron_ingot',1,5,15), $e('gold_ingot',1,5,10), $e('emerald',1,5,10), $e('lapis_lazuli',1,5,10), $e('diamond',1,1,3), $e('bottle_o_enchanting',1,1,5), $e(null,0,0,12)]],
            ],
            'buried_treasure' => [
                [1, 1, [$e('heart_of_the_sea',1,1,1)]],
                [5, 8, [$e('iron_ingot',1,4,20), $e('gold_ingot',1,4,10), $e('emerald',1,4,10), $e('diamond',1,2,5), $e('prismarine_crystals',1,5,5), $e('cooked_cod',2,4,10), $e('cooked_salmon',2,4,10), $e('tnt',1,2,3)]],
            ],
            'igloo' => [
                [2, 8, [$e('apple',1,3,15), $e('coal',1,4,15), $e('gold_nugget',1,3,10), $e('stone_axe',1,1,2,['damage'=>true]), $e('rotten_flesh',1,1,10), $e('emerald',1,1,1), $e('wheat',2,3,10), $e(null,0,0,10)]],
                [1, 1, [$e('golden_apple',1,1,1)]],
            ],
            'dungeon' => [
                [1, 3, [$e('saddle',1,1,20), $e('golden_apple',1,1,15), $e('enchanted_book',1,1,10,['enchant'=>true]), $e('name_tag',1,1,20), $e('iron_horse_armor',1,1,10), $e('golden_horse_armor',1,1,5), $e('diamond_horse_armor',1,1,2), $e('music_disc_13',1,1,15), $e('music_disc_cat',1,1,15), $e(null,0,0,20)]],
                [4, 8, [$e('iron_ingot',1,4,10), $e('gold_ingot',1,4,5), $e('bread',1,4,20), $e('wheat',1,4,20), $e('gunpowder',1,4,20), $e('string',1,4,20), $e('bucket',1,1,10), $e('redstone',1,4,10), $e('coal',1,4,10), $e('melon_seeds',2,4,10), $e('pumpkin_seeds',2,4,10), $e('beetroot_seeds',2,4,10), $e(null,0,0,10)]],
            ],
            'ocean_ruin' => [
                [3, 7, [$e('coal',2,8,20), $e('wheat',2,6,15), $e('gold_nugget',1,8,15), $e('rotten_flesh',2,6,10), $e('fishing_rod',1,1,5,['enchant'=>true,'damage'=>true]), $e('emerald',1,2,3), $e('map',1,1,3), $e(null,0,0,10)]],
            ],
            'swamp_hut' => [[3, 7, [$e('glass_bottle',1,6,15), $e('redstone',1,4,10), $e('gunpowder',1,4,10), $e('spider_eye',1,3,10), $e('sugar',1,4,10), $e('stick',1,4,10), $e('mushroom_stew',1,1,3)]]],
            'fortress' => [
                [2, 4, [$e('gold_ingot',1,3,15), $e('iron_ingot',1,5,15), $e('diamond',1,3,5), $e('flint_and_steel',1,1,5), $e('nether_wart',3,7,10), $e('saddle',1,1,10), $e('golden_horse_armor',1,1,8), $e('iron_horse_armor',1,1,8), $e('diamond_horse_armor',1,1,3), $e('obsidian',2,4,5), $e(null,0,0,10)]],
            ],
            'bastion' => [
                [3, 6, [$e('gold_ingot',2,8,20), $e('gold_block',1,2,5), $e('gilded_blackstone',1,4,10), $e('crying_obsidian',1,5,10), $e('iron_ingot',1,6,10), $e('string',2,8,10), $e('arrow',5,17,10), $e('magma_cream',2,6,8)]],
                [1, 3, [$e('ancient_debris',1,2,5), $e('diamond',1,3,8), $e('diamond_sword',1,1,3,['enchant'=>true,'damage'=>true]), $e('diamond_pickaxe',1,1,3,['enchant'=>true,'damage'=>true]), $e('diamond_chestplate',1,1,2,['enchant'=>true,'damage'=>true]), $e('enchanted_book',1,1,4,['enchant'=>true]), $e('netherite_scrap',1,1,2), $e(null,0,0,20)]],
            ],
            'ruined_portal' => [
                [4, 8, [$e('obsidian',1,2,20), $e('flint',1,4,10), $e('flint_and_steel',1,1,10,['damage'=>true]), $e('fire_charge',1,4,10), $e('gold_nugget',4,24,15), $e('gold_ingot',2,8,10), $e('glistering_melon_slice',1,4,5), $e('golden_carrot',1,4,5), $e('golden_apple',1,1,5), $e('golden_sword',1,1,8,['enchant'=>true,'damage'=>true]), $e('golden_axe',1,1,8,['enchant'=>true,'damage'=>true]), $e('golden_helmet',1,1,8,['enchant'=>true,'damage'=>true]), $e('golden_chestplate',1,1,8,['enchant'=>true,'damage'=>true]), $e('golden_leggings',1,1,8,['enchant'=>true,'damage'=>true]), $e('golden_boots',1,1,8,['enchant'=>true,'damage'=>true]), $e(null,0,0,10)]],
            ],
            'end_city' => [
                [2, 6, [$e('diamond',2,7,10), $e('iron_ingot',4,8,20), $e('gold_ingot',2,7,15), $e('emerald',2,6,15), $e('beetroot_seeds',1,10,5), $e('saddle',1,1,3), $e('diamond_sword',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_pickaxe',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_shovel',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_helmet',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_chestplate',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_leggings',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_boots',1,1,5,['enchant'=>true,'damage'=>true]), $e('enchanted_book',1,1,3,['enchant'=>true]), $e(null,0,0,10)]],
            ],
            'ancient_city' => [
                [5, 10, [$e('echo_shard',1,3,15), $e('disc_fragment_5',1,3,10), $e('amethyst_shard',1,15,10), $e('glow_berries',1,15,10), $e('sculk',4,10,10), $e('candle',1,4,10), $e('coal',6,15,10), $e('bone',1,15,10), $e('soul_torch',1,15,10)]],
                [1, 3, [$e('diamond',1,3,8), $e('diamond_hoe',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_leggings',1,1,3,['enchant'=>true,'damage'=>true]), $e('enchanted_book',1,1,8,['enchant'=>true]), $e('golden_apple',1,2,8), $e('enchanted_golden_apple',1,1,1), $e(null,0,0,15)]],
            ],
        ];
    }

    /** @param array<int, Item> $result */
    private function addSupplementalLoot(string $type, array &$result) : void{
        $e = static fn(?string $id, int $min, int $max, int $weight, array $options = []) : array => [$id, $min, $max, $weight, $options];
        $major = in_array($type, ['mansion', 'bastion', 'end_city', 'ancient_city', 'stronghold', 'desert_pyramid'], true);
        $minimumStacks = $major ? 10 : 7;

        $rareEntries = match($type){
            'mansion' => [$e('totem_of_undying',1,1,3), $e('diamond',1,3,8), $e('enchanted_book',1,1,8,['enchant'=>true]), $e('diamond_chestplate',1,1,3,['enchant'=>true,'damage'=>true]), $e(null,0,0,20)],
            'outpost' => [$e('totem_of_undying',1,1,1), $e('emerald',2,7,12), $e('crossbow',1,1,8,['enchant'=>true,'damage'=>true]), $e('enchanted_book',1,1,5,['enchant'=>true]), $e(null,0,0,24)],
            'bastion', 'fortress', 'ruined_portal' => [$e('ancient_debris',1,2,4), $e('diamond',1,3,7), $e('netherite_scrap',1,2,3), $e('enchanted_book',1,1,5,['enchant'=>true]), $e('golden_apple',1,2,8), $e(null,0,0,24)],
            'end_city' => [$e('diamond',2,6,10), $e('diamond_sword',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_pickaxe',1,1,5,['enchant'=>true,'damage'=>true]), $e('diamond_chestplate',1,1,4,['enchant'=>true,'damage'=>true]), $e('enchanted_book',1,1,5,['enchant'=>true]), $e(null,0,0,12)],
            'ancient_city' => [$e('enchanted_golden_apple',1,1,2), $e('diamond',1,4,8), $e('enchanted_book',1,1,10,['enchant'=>true]), $e('diamond_leggings',1,1,5,['enchant'=>true,'damage'=>true]), $e('echo_shard',1,4,12), $e(null,0,0,18)],
            default => [$e('diamond',1,2,3), $e('emerald',1,4,8), $e('golden_apple',1,1,5), $e('enchanted_book',1,1,4,['enchant'=>true]), $e(null,0,0,25)],
        };
        $this->appendLootEntry($result, $this->weightedEntry($rareEntries));

        $commonEntries = str_contains($type, 'village') ? [
            $e('bread',2,6,15), $e('apple',2,6,12), $e('emerald',1,4,8), $e('iron_ingot',1,5,10),
            $e('gold_ingot',1,4,6), $e('lapis_lazuli',2,8,5), $e('redstone',2,8,5), $e('book',1,4,8),
        ] : (in_array($type, ['fortress', 'bastion', 'ruined_portal'], true) ? [
            $e('gold_ingot',2,8,15), $e('gold_nugget',6,24,15), $e('quartz',2,10,10), $e('obsidian',1,5,8),
            $e('iron_ingot',1,6,8), $e('magma_cream',1,5,5), $e('fire_charge',1,5,5), $e('arrow',4,16,8),
        ] : [
            $e('iron_ingot',1,6,14), $e('gold_ingot',1,5,9), $e('emerald',1,5,8), $e('coal',3,12,12),
            $e('lapis_lazuli',2,10,8), $e('redstone',2,10,8), $e('bread',2,6,10), $e('golden_carrot',1,4,4),
            $e('arrow',4,16,8), $e('book',1,5,7),
        ]);

        $attempts = 0;
        while(count($result) < $minimumStacks && $attempts++ < 40){
            $this->appendLootEntry($result, $this->weightedEntry($commonEntries));
        }
    }

    /**
     * @param array<int, Item> $result
     * @param array{0:?string,1:int,2:int,3:int,4:array<string,mixed>}|null $entry
     */
    private function appendLootEntry(array &$result, ?array $entry) : void{
        if($entry === null || $entry[0] === null){
            return;
        }
        [$id, $minCount, $maxCount, , $options] = $entry;
        $item = $this->parseItem($id);
        if($item === null || $item->isNull()){
            return;
        }
        $item->setCount(mt_rand($minCount, $maxCount));
        $this->applyLootOptions($item, $id, $options);
        $result[] = $item;
    }

    /** @param array<int,array{0:?string,1:int,2:int,3:int,4:array<string,mixed>}> $entries */
    private function weightedEntry(array $entries) : ?array{
        $total = 0;
        foreach($entries as $entry){
            $total += max(0, (int) $entry[3]);
        }
        if($total < 1){
            return null;
        }
        $pick = mt_rand(1, $total);
        foreach($entries as $entry){
            $pick -= max(0, (int) $entry[3]);
            if($pick <= 0){
                return $entry;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $options */
    private function applyLootOptions(Item $item, string $id, array $options) : void{
        if(($options['damage'] ?? false) && $item instanceof Durable && $item->getMaxDurability() > 2){
            $item->setDamage(mt_rand(0, max(1, (int) floor($item->getMaxDurability() * 0.75))));
        }
        if(($options['enchant'] ?? false) === true){
            $pool = $this->enchantmentsForLootItem($id);
            if($pool !== []){
                shuffle($pool);
                $targetAmount = $id === 'enchanted_book' ? mt_rand(1, 2) : mt_rand(1, min(3, count($pool)));
                $selected = [];
                foreach($pool as $enchantment){
                    $compatible = true;
                    foreach($selected as $existing){
                        if($existing === $enchantment || !$existing->isCompatibleWith($enchantment)){
                            $compatible = false;
                            break;
                        }
                    }
                    if(!$compatible){
                        continue;
                    }
                    $maxLevel = max(1, $enchantment->getMaxLevel());
                    $level = mt_rand(1, $maxLevel);
                    $item->addEnchantment(new EnchantmentInstance($enchantment, $level));
                    $selected[] = $enchantment;
                    if(count($selected) >= $targetAmount){
                        break;
                    }
                }
            }
        }
    }

    /** @return array<int, Enchantment> */
    private function enchantmentsForLootItem(string $id) : array{
        if($id === 'enchanted_book'){
            return [
                VanillaEnchantments::PROTECTION(), VanillaEnchantments::SHARPNESS(), VanillaEnchantments::EFFICIENCY(),
                VanillaEnchantments::UNBREAKING(), VanillaEnchantments::FORTUNE(), VanillaEnchantments::MENDING(),
                VanillaEnchantments::POWER(), VanillaEnchantments::RESPIRATION(), VanillaEnchantments::FEATHER_FALLING(),
                VanillaEnchantments::SILK_TOUCH(), VanillaEnchantments::FIRE_ASPECT(), VanillaEnchantments::THORNS(),
            ];
        }
        if(str_contains($id, 'sword') || str_contains($id, '_axe')){
            return [VanillaEnchantments::SHARPNESS(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::FIRE_ASPECT(), VanillaEnchantments::KNOCKBACK(), VanillaEnchantments::MENDING()];
        }
        if(str_contains($id, 'pickaxe') || str_contains($id, 'shovel') || str_contains($id, '_hoe')){
            return [VanillaEnchantments::EFFICIENCY(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::FORTUNE(), VanillaEnchantments::SILK_TOUCH(), VanillaEnchantments::MENDING()];
        }
        if(str_contains($id, 'helmet')){
            return [VanillaEnchantments::PROTECTION(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::RESPIRATION(), VanillaEnchantments::AQUA_AFFINITY(), VanillaEnchantments::MENDING()];
        }
        if(str_contains($id, 'boots')){
            return [VanillaEnchantments::PROTECTION(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::FEATHER_FALLING(), VanillaEnchantments::FROST_WALKER(), VanillaEnchantments::MENDING()];
        }
        if(str_contains($id, 'chestplate') || str_contains($id, 'leggings')){
            return [VanillaEnchantments::PROTECTION(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::THORNS(), VanillaEnchantments::MENDING()];
        }
        if($id === 'bow'){
            return [VanillaEnchantments::POWER(), VanillaEnchantments::UNBREAKING(), VanillaEnchantments::FLAME(), VanillaEnchantments::INFINITY(), VanillaEnchantments::MENDING()];
        }
        if($id === 'fishing_rod' || $id === 'crossbow'){
            return [VanillaEnchantments::UNBREAKING(), VanillaEnchantments::MENDING()];
        }
        return [VanillaEnchantments::UNBREAKING(), VanillaEnchantments::MENDING()];
    }

    private function parseItem(string $id) : ?Item{
        $parser = StringToItemParser::getInstance();
        $item = $parser->parse($id) ?? $parser->parse('minecraft:' . $id);
        if($item !== null) return $item;
        $fallback = [
            'totem_of_undying' => 'totem',
            'bottle_o_enchanting' => 'experience_bottle',
            'redstone_dust' => 'redstone',
            'gold_block' => 'block_of_gold',
            'lapis_lazuli' => 'lapis',
            'raw_cod' => 'cod',
            'raw_beef' => 'beef',
            'raw_porkchop' => 'porkchop',
            'music_disc_13' => 'record_13',
            'music_disc_cat' => 'record_cat',
        ][$id] ?? null;
        return $fallback !== null ? $parser->parse($fallback) : null;
    }

    private function saveState() : void{
        // Reload before writing because StructureRespawnTask and manual commands
        // may update the same file while a long full-map scan is running.
        $this->state->reload();
        if($this->mode === 'mobs'){
            $all = (array) $this->state->get('mobs', []);
            $freshRecords = array_values((array) ($all[$this->worldName] ?? []));
            $byId = [];
            $withoutId = [];
            foreach($freshRecords as $record){
                $id = (string) ($record['id'] ?? '');
                $type = (string) ($record['type'] ?? '');
                if($id === '' && $type !== ''){
                    $id = StructureRespawnTask::makeStructureId($this->worldName, $type, new Vector3(
                        (float) ($record['x'] ?? 0.0),
                        (float) ($record['y'] ?? 64.0),
                        (float) ($record['z'] ?? 0.0)
                    ));
                    $record['id'] = $id;
                }
                if($id !== ''){
                    $byId[$id] = $record;
                }else{
                    $withoutId[] = $record;
                }
            }
            foreach($this->mobRecords as $record){
                $id = (string) ($record['id'] ?? '');
                $type = (string) ($record['type'] ?? '');
                if($id === '' && $type !== ''){
                    $id = StructureRespawnTask::makeStructureId($this->worldName, $type, new Vector3(
                        (float) ($record['x'] ?? 0.0),
                        (float) ($record['y'] ?? 64.0),
                        (float) ($record['z'] ?? 0.0)
                    ));
                    $record['id'] = $id;
                }
                if($id !== '' && isset($byId[$id])){
                    // Keep live timer fields written by StructureRespawnTask.
                    $record['respawn_at'] = (int) ($byId[$id]['respawn_at'] ?? ($record['respawn_at'] ?? 0));
                    if(isset($byId[$id]['last_respawn'])){
                        $record['last_respawn'] = $byId[$id]['last_respawn'];
                    }
                    $byId[$id] = $record;
                }elseif($id !== ''){
                    $byId[$id] = $record;
                }else{
                    $withoutId[] = $record;
                }
            }
            $all[$this->worldName] = array_merge(array_values($byId), $withoutId);
            $this->state->set('mobs', $all);
        }else{
            $all = (array) $this->state->get('loot', []);
            $all[$this->worldName] = $this->lootRecords;
            $this->state->set('loot', $all);
            $this->state->set('loot-version', self::LOOT_VERSION);
        }
        $this->state->save();
    }

    private function finish() : void{
        $this->saveState();
        unset(self::$active[$this->activeKey]);
        if($this->mode === 'mobs'){
            $this->message("§aСканирование завершено. Найдено новых структур: {$this->found}, создано мобов: {$this->spawned}.");
        }else{
            $this->message("§aСканирование сундуков завершено. Обновлено структурных сундуков: {$this->filled}.");
        }
        $this->getHandler()?->cancel();
    }

    private function message(string $message) : void{
        $player = $this->plugin->getServer()->getPlayerExact($this->senderName);
        if($player instanceof Player && $player->isConnected()){
            $player->sendMessage($message);
        }else{
            $this->plugin->getLogger()->info(strip_tags($message));
        }
    }
}
