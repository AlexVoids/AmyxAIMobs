<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\spawner;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\config\Settings;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\block\Lava;
use pocketmine\block\Water;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * Biome-aware natural spawner using random already-loaded chunks.
 *
 * Vanilla servers only simulate loaded chunks. This task follows the same
 * practical rule: it does not force-load the infinite world, but distributes
 * spawning across chunks already visible/ticking instead of choosing a point
 * directly around the player's feet. It also replenishes a few recognizable
 * vanilla structures using conservative block signatures.
 */
final class SpawnerTask extends Task{
    private int $seconds = 0;
    private function settings(World $world): Settings{ return Settings::forWorld($this->plugin->getConfig()->getAll(),$world->getFolderName()); }

    /** @var array<string, int> */
    private array $traderCooldownCycles = [];
    /** @var array<string, int> */
    private array $structureCooldownCycles = [];
    /** @var array<string, array{0:World,1:int,2:int}> */
    private array $structureQueue = [];

    public function __construct(private Main $plugin){}

    public function queueStructureChunk(World $world, int $chunkX, int $chunkZ) : void{
        if(count($this->structureQueue) >= 1024){
            array_shift($this->structureQueue);
        }
        $this->structureQueue[$world->getId() . ":" . $chunkX . ":" . $chunkZ] = [$world, $chunkX, $chunkZ];
    }

    public function onRun() : void{
        $config = $this->plugin->getConfig();
        if(!(bool) $config->get("natural-spawning", true)){
            return;
        }

        if((bool) $config->get("structure-spawning", true)){
            $this->processQueuedStructureChunks(max(1, min(6, (int) $config->get("structure-queue-per-second", 2))));
        }

        $this->seconds++;
        $cycleSeconds = max(4, (int) $config->get("spawn-cycle-seconds", 10));
        if($this->seconds < $cycleSeconds){
            return;
        }
        $this->seconds = 0;

        foreach($this->structureCooldownCycles as $key => $cycles){
            if($cycles <= 1){
                unset($this->structureCooldownCycles[$key]);
            }else{
                $this->structureCooldownCycles[$key] = $cycles - 1;
            }
        }

        $worldsConfig = (array) $config->get("worlds", []);
        $autoDetect = (bool) $config->get("auto-detect-worlds", true);
        $globalCap = max(0, (int) $config->get("global-mob-cap", 110));
        $perWorldCap = max(0, (int) $config->get("per-world-mob-cap", 72));
        $spawnChance = max(0, min(100, (int) $config->get("spawn-chance-percent", 42)));
        $pointsPerPlayer = max(2, min(10, (int) $config->get("spawn-chunks-per-player", 5)));
        $attemptsPerChunk = max(1, min(4, (int) $config->get("spawn-attempts-per-chunk", 2)));
        $successBudget = max(1, min(8, (int) $config->get("spawn-successes-per-world", 3)));
        $structureScans = max(0, min(8, (int) $config->get("structure-scan-chunks-per-cycle", 3)));

        $totalMobs = 0;
        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            $totalMobs += $this->countPluginMobs($world);
        }
        if($totalMobs >= $globalCap){
            return;
        }

        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            $folderName = $world->getFolderName();
            $dimension = $worldsConfig[$folderName] ?? ($autoDetect ? $this->detectDimension($folderName) : null);
            if($dimension === null){
                continue;
            }

            $profile=$this->settings($world);
            if(!(bool)$profile->get("natural-spawning",true) || !(bool)$profile->get("enabled",true)){ continue; }
            $perWorldCap=max(0,(int)$profile->get("per-world-mob-cap",90));
            $spawnChance=max(0,min(100,(int)$profile->get("spawn-chance-percent",42)));
            $players = array_values(array_filter($world->getPlayers(),static fn(Player $p): bool=>$p->isAlive() && !$p->isCreative() && !$p->isSpectator()));
            if($players === [] || $this->countPluginMobs($world) >= $perWorldCap){
                continue;
            }

            $candidates = $this->collectCandidateChunks($world, $players, $pointsPerPlayer);
            if($candidates === []){
                continue;
            }

            if((bool) $config->get("structure-spawning", true) && $structureScans > 0){
                $scanned = 0;
                foreach($candidates as [$chunkX, $chunkZ]){
                    $this->tryPopulateStructure($world, $chunkX, $chunkZ, (string) $dimension);
                    if(++$scanned >= $structureScans){
                        break;
                    }
                }
            }

            $successes = 0;
            foreach($candidates as [$chunkX, $chunkZ]){
                if(mt_rand(1, 100) > $spawnChance){
                    continue;
                }

                for($attempt = 0; $attempt < $attemptsPerChunk; $attempt++){
                    $x = ($chunkX << 4) + mt_rand(0, 15);
                    $z = ($chunkZ << 4) + mt_rand(0, 15);
                    if($this->attemptSpawnAt($world, $x, $z, (string) $dimension)){
                        $successes++;
                        $totalMobs=$this->countGlobalPluginMobs();
                        break;
                    }
                }

                if($successes >= $successBudget
                    || $totalMobs >= $globalCap
                    || $this->countPluginMobs($world) >= $perWorldCap){
                    break;
                }
            }

            // Wandering trader generation is fully disabled in this build.
            unset($this->traderCooldownCycles[$folderName]);
        }
    }

    private function processQueuedStructureChunks(int $limit) : void{
        for($i = 0; $i < $limit && $this->structureQueue !== []; $i++){
            $entry = array_shift($this->structureQueue);
            if($entry === null){
                break;
            }
            [$world, $chunkX, $chunkZ] = $entry;
            if(!$world->isLoaded() || !$world->isChunkLoaded($chunkX, $chunkZ)){
                continue;
            }
            $profile=$this->settings($world);
            if(!$profile->get("natural-spawning",true) || !$profile->get("enabled",true) || !$profile->get("structure-spawning",false)){ continue; }
            $dimension = (array)$profile->get("worlds",[]);
            $dimension = $dimension[$world->getFolderName()] ?? ($profile->get("auto-detect-worlds",true) ? $this->detectDimension($world->getFolderName()) : null);
            if($dimension===null){ continue; }
            $this->tryPopulateStructure($world, $chunkX, $chunkZ, $dimension);

            $config = $this->settings($world);
            $prepopulateChance = max(0, min(100, (int) $config->get("chunk-prepopulate-chance-percent", 14)));
            if((bool) $config->get("prepopulate-loaded-chunks", true)
                && mt_rand(1, 100) <= $prepopulateChance
                && $this->countPluginMobs($world) < max(1, (int) $config->get("per-world-mob-cap", 72))
                && $this->countGlobalPluginMobs() < max(1, (int) $config->get("global-mob-cap", 110))){
                $x = ($chunkX << 4) + mt_rand(0, 15);
                $z = ($chunkZ << 4) + mt_rand(0, 15);
                $this->attemptSpawnAt($world, $x, $z, $dimension);
            }
        }
    }

    private function countGlobalPluginMobs() : int{
        $count = 0;
        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            $count += $this->countPluginMobs($world);
        }
        return $count;
    }

    private function countPluginMobs(World $world) : int{
        $count = 0;
        foreach($world->getEntities() as $entity){
            if($entity instanceof BaseMob && !$entity->isClosed()){
                $count++;
            }
        }
        return $count;
    }

    private function countNearbyPluginMobsAt(World $world, float $x, float $y, float $z, int $radius) : int{
        $count = 0;
        $radiusSq = $radius * $radius;
        foreach($world->getEntities() as $entity){
            if(!($entity instanceof BaseMob) || $entity->isClosed()){
                continue;
            }
            $loc = $entity->getLocation();
            $dx = $loc->x - $x;
            $dy = $loc->y - $y;
            $dz = $loc->z - $z;
            if($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq){
                $count++;
            }
        }
        return $count;
    }

    private function countWorldCategory(World $world, bool $hostile) : int{
        $count = 0;
        foreach($world->getEntities() as $entity){
            if(!($entity instanceof BaseMob) || $entity->isClosed()){
                continue;
            }
            if(($entity instanceof Monster) === $hostile){
                $count++;
            }
        }
        return $count;
    }

    private function countNearbyCategoryAt(World $world, float $x, float $y, float $z, int $radius, bool $hostile) : int{
        $count = 0;
        $radiusSq = $radius * $radius;
        foreach($world->getEntities() as $entity){
            if(!($entity instanceof BaseMob) || $entity->isClosed() || (($entity instanceof Monster) !== $hostile)){
                continue;
            }
            $loc = $entity->getLocation();
            $dx = $loc->x - $x;
            $dy = $loc->y - $y;
            $dz = $loc->z - $z;
            if($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq){
                $count++;
            }
        }
        return $count;
    }

    private function countWorldAquatic(World $world) : int{
        $count = 0;
        foreach($world->getEntities() as $entity){
            if($entity instanceof BaseMob && !$entity->isClosed() && $entity->isAquatic() && !($entity instanceof Monster)){
                $count++;
            }
        }
        return $count;
    }

    private function countNearbyAquaticAt(World $world, float $x, float $y, float $z, int $radius) : int{
        $count = 0;
        $radiusSq = $radius * $radius;
        foreach($world->getEntities() as $entity){
            if(!($entity instanceof BaseMob) || $entity->isClosed() || !$entity->isAquatic() || $entity instanceof Monster){
                continue;
            }
            $loc = $entity->getLocation();
            $dx = $loc->x - $x;
            $dy = $loc->y - $y;
            $dz = $loc->z - $z;
            if($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq){
                $count++;
            }
        }
        return $count;
    }

    private function detectDimension(string $folderName) : string{
        $name = strtolower($folderName);
        if(str_contains($name, "nether")){
            return "nether";
        }
        if($name === "the_end" || str_contains($name, "theend") || str_contains($name, "_end")){
            return "the_end";
        }
        return "overworld";
    }

    private function getEntityTypeCount(string $entityClass) : int{
        $count = 0;
        foreach(Server::getInstance()->getWorldManager()->getWorlds() as $world){
            foreach($world->getEntities() as $entity){
                if($entity instanceof $entityClass && !$entity->isClosed()){
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @param Player[] $players
     *  @return array<int, array{0:int,1:int}>
     */
    private function collectCandidateChunks(World $world, array $players, int $perPlayer) : array{
        $result = [];
        $radiusChunks=max(2,min(8,(int)$this->settings($world)->get("spawn-radius-chunks",6)));
        shuffle($players);

        foreach($players as $player){
            $baseChunkX = $player->getPosition()->getFloorX() >> 4;
            $baseChunkZ = $player->getPosition()->getFloorZ() >> 4;
            for($i = 0; $i < $perPlayer; $i++){
                for($try = 0; $try < 12; $try++){
                    $dx = mt_rand(-$radiusChunks, $radiusChunks);
                    $dz = mt_rand(-$radiusChunks, $radiusChunks);
                    if(max(abs($dx), abs($dz)) < 2){
                        continue;
                    }
                    $chunkX = $baseChunkX + $dx;
                    $chunkZ = $baseChunkZ + $dz;
                    if(!$world->isChunkLoaded($chunkX, $chunkZ) || !$world->isChunkGenerated($chunkX, $chunkZ)){
                        continue;
                    }
                    if(method_exists($world, "getChunkPlayers") && $world->getChunkPlayers($chunkX, $chunkZ) === []){
                        continue;
                    }
                    $result[$chunkX . ":" . $chunkZ] = [$chunkX, $chunkZ];
                    break;
                }
            }
        }

        $result = array_values($result);
        shuffle($result);
        return $result;
    }

    private function attemptSpawnAt(World $world, int $x, int $z, string $dimension) : bool{
        if(!$world->isChunkLoaded($x >> 4, $z >> 4) || !$world->isChunkGenerated($x >> 4, $z >> 4)){
            return false;
        }

        $context = $dimension === "nether"
            ? $this->buildNetherContext($world, $x, $z)
            : ($dimension === "the_end" ? $this->buildEndContext($world, $x, $z) : $this->buildOverworldContext($world, $x, $z));
        if($context === null){
            return false;
        }

        $config = $this->settings($world);
        $minPlayerDistance = max(0, (int) $config->get("spawn-min-player-distance", 24));
        if($this->hasPlayerWithin($world, $x + 0.5, (float) $context["y"], $z + 0.5, $minPlayerDistance)){
            return false;
        }

        $localCap = max(1, (int) $config->get("local-mob-cap", 28));
        $localRadius = max(24, (int) $config->get("local-mob-cap-radius", 48));
        if($this->countNearbyPluginMobsAt($world, $x + 0.5, (float) $context["y"], $z + 0.5, $localRadius) >= $localCap){
            return false;
        }

        $sourceList = match($dimension){
            "nether" => $this->plugin->spawnerLists["nether"] ?? [],
            "the_end" => $this->plugin->spawnerLists["the_end"] ?? [],
            default => $context["hostile"]
                ? ($this->plugin->spawnerLists["overworld_hostile"] ?? [])
                : ($this->plugin->spawnerLists["overworld_passive"] ?? []),
        };

        if($dimension === "overworld"){
            if($context["water"]){
                $sourceList = ($context["night"] || $context["underground"])
                    ? array_merge(
                        $this->plugin->spawnerLists["overworld_hostile"] ?? [],
                        $this->plugin->spawnerLists["overworld_passive"] ?? []
                    )
                    : ($this->plugin->spawnerLists["overworld_passive"] ?? []);
            }elseif($context["underground"]){
                $sourceList = $this->plugin->spawnerLists["overworld_hostile"] ?? [];
            }
        }

        $weighted = [];
        foreach(array_unique($sourceList) as $class){
            $weight = $this->getSpawnWeight($class, $context);
            $name=Settings::name($class);
            if(!$this->settings($world)->allows($name,$context)){ continue; }
            $weight=$this->settings($world)->weight($name,$weight);
            if($weight > 0){
                $weighted[$class] = $weight;
            }
        }
        if($weighted === []){
            return false;
        }

        $class = $this->pickWeighted($weighted);
        if($class === null || !$this->hasEnoughSpace($world, $class, $context)){
            return false;
        }

        $parts = explode('\\', $class);
        $entityName = strtolower((string) array_pop($parts));
        $limits = (array) $config->get("entity-type-limits", []);
        if(isset($limits[$entityName]) && $this->getEntityTypeCount($class) >= (int) $limits[$entityName]){
            return false;
        }

        $isHostile = is_subclass_of($class, Monster::class);
        $isPassiveAquatic = $context["water"] && !$isHostile && in_array($entityName, [
            "cod", "salmon", "pufferfish", "tropicalfish", "squid", "glowsquid", "dolphin"
        ], true);

        if($isPassiveAquatic){
            $worldAquaticCap = max(1, (int) $config->get("per-world-aquatic-cap", 48));
            $localAquaticCap = max(1, (int) $config->get("local-aquatic-cap", 26));
            if($this->countWorldAquatic($world) >= $worldAquaticCap
                || $this->countNearbyAquaticAt($world, $x + 0.5, (float) $context["y"], $z + 0.5, $localRadius) >= $localAquaticCap){
                return false;
            }
        }else{
            $worldCategoryCap = $isHostile
                ? max(1, (int) $config->get("per-world-hostile-cap", 38))
                : max(1, (int) $config->get("per-world-passive-cap", 36));
            $localCategoryCap = $isHostile
                ? max(1, (int) $config->get("local-hostile-cap", 17))
                : max(1, (int) $config->get("local-passive-cap", 15));
            if($this->countWorldCategory($world, $isHostile) >= $worldCategoryCap
                || $this->countNearbyCategoryAt($world, $x + 0.5, (float) $context["y"], $z + 0.5, $localRadius, $isHostile) >= $localCategoryCap){
                return false;
            }
        }

        if(!$this->canSpawn($world,$class,$x,(int)$context["y"],$z,$context)){ return false; }
        $nbt = CompoundTag::create()->setByte("NaturalSpawned", 1);
        if($class === \BeeAZ\AZVanillaMobs\entity\overworld\Slime::class || $class === \BeeAZ\AZVanillaMobs\entity\nether\MagmaCube::class){
            $roll = mt_rand(1, 100);
            $size = $roll <= 45 ? 4 : ($roll <= 85 ? 2 : 1);
            $nbt->setInt("SlimeSize", $size);
        }

        if(in_array($entityName, ["squid", "glowsquid"], true)){
            $spawnY = (int) $context["y"];
            if(!$this->isWaterColumnBlock($world->getBlockAt($x, $spawnY + 1, $z))
                || !$this->isWaterColumnBlock($world->getBlockAt($x, $spawnY - 1, $z))){
                return false;
            }
        }

        $position = new Position($x + 0.5, (float) $context["y"], $z + 0.5, $world);
        try{
            $entity = new $class(Location::fromObject($position, $world, (float) mt_rand(0, 359), 0.0), $nbt);
            if(!$this->entityFits($entity)){ $entity->close(); return false; }
            $entity->spawnToAll();
            if(in_array($entityName, ["cow", "sheep", "chicken", "pig"], true)){
                $this->spawnPassiveCompanions($class, $entityName, $world, $x, $z, $context, $limits);
            }
            if($context["water"] && in_array($entityName, ["cod", "salmon", "squid", "glowsquid", "tropicalfish", "pufferfish"], true)){
                $this->spawnAquaticCompanions($class, $entityName, $world, $x, (int) $context["y"], $z, $limits);
            }
            return true;
        }catch(\Throwable $e){
            $this->plugin->getLogger()->debug("Natural spawn skipped for {$entityName}: " . $e->getMessage());
            return false;
        }
    }

    /** Every natural group member is checked against the current live population. */
    private function canSpawn(World $world,string $class,int $x,int $y,int $z,array $context): bool{
        $config=$this->settings($world);$global=$this->plugin->getConfig();$name=Settings::name($class);
        if(!$config->get("enabled",true) || !$config->get("natural-spawning",true) || !$config->allows($name,$context)){ return false; }
        if(!$world->isChunkLoaded($x>>4,$z>>4)){ return false; }
        $min=max(0,(int)$config->get("spawn-min-player-distance",24));
        $max=max($min+1,min(256,(int)$config->get("spawn-max-player-distance",96)));
        $nearest=INF;
        foreach($world->getPlayers() as $player){
            if(!$player->isAlive() || $player->isSpectator()){ continue; }
            $distance=$player->getPosition()->distanceSquared(new \pocketmine\math\Vector3($x+0.5,$y,$z+0.5));
            if($distance<$min*$min){ return false; }
            if(!$player->isCreative()){ $nearest=min($nearest,$distance); }
        }
        if($nearest>$max*$max){ return false; }
        if($this->countGlobalPluginMobs()>=max(0,(int)$global->get("global-mob-cap",135))
            || $this->countPluginMobs($world)>=max(0,(int)$config->get("per-world-mob-cap",90))){ return false; }
        $radius=max(8,(int)$config->get("local-mob-cap-radius",48));
        if($this->countNearbyPluginMobsAt($world,$x+0.5,$y,$z+0.5,$radius)>=max(0,(int)$config->get("local-mob-cap",36))){ return false; }
        $limits=(array)$config->get("entity-type-limits",[]);
        if($this->getEntityTypeCount($class)>=max(0,(int)($limits[$name]??135))){ return false; }
        if($config->get('passive-require-grass',true) && in_array($name,['cow','sheep','pig','chicken','horse','donkey'],true)
            && $world->getBlockAt($x,$y-1,$z)->getTypeId()!==\pocketmine\block\VanillaBlocks::GRASS()->getTypeId()){ return false; }
        $hostile=is_subclass_of($class,Monster::class);
        if($hostile && ($world->getDifficulty()===World::DIFFICULTY_PEACEFUL
            || ($context['dimension']??'overworld')==='overworld' && (int)($context['light']??0)>max(0,min(15,(int)$config->get("hostile-max-block-light",0))))){ return false; }
        $aquatic= !$hostile && in_array($name,['cod','salmon','pufferfish','tropicalfish','squid','glowsquid','dolphin'],true);
        $category=$aquatic?'aquatic':($hostile?'hostile':'passive');
        $worldCount=$aquatic?$this->countWorldAquatic($world):$this->countWorldCategory($world,$hostile);
        $localCount=$aquatic?$this->countNearbyAquaticAt($world,$x+0.5,$y,$z+0.5,$radius):$this->countNearbyCategoryAt($world,$x+0.5,$y,$z+0.5,$radius,$hostile);
        return $worldCount<max(0,(int)$config->get("per-world-".$category."-cap",40))
            && $localCount<max(0,(int)$config->get("local-".$category."-cap",16));
    }

    private function entityFits(BaseMob $mob): bool{
        $world=$mob->getWorld();$box=$mob->getBoundingBox();
        if(!$mob->isFlying() && !$mob->isAquatic()){
            $pos=$mob->getPosition();
            $walk=new \BeeAZ\AZVanillaMobs\ai\WalkEvaluator($world,$mob->getSize()->getWidth(),$mob->getSize()->getHeight());
            if(!$walk->safe($pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ())){ return false; }
        }
        for($x=(int)floor($box->minX);$x<=$box->maxX;$x++){
            for($z=(int)floor($box->minZ);$z<=$box->maxZ;$z++){
                if(!$world->isChunkLoaded($x>>4,$z>>4)){ return false; }
                for($y=(int)floor($box->minY);$y<=$box->maxY;$y++){
                    if($y<$world->getMinY() || $y>=$world->getMaxY()){ return false; }
                    $block=$world->getBlockAt($x,$y,$z);
                    foreach($block->getCollisionBoxes() as $collision){ if($collision->intersectsWith($box)){ return false; } }
                    if($block instanceof Lava && !$mob->isFireProof()){ return false; }
                }
            }
        }
        return $world->getCollidingEntities($box,$mob)===[];
    }

    private function hasPlayerWithin(World $world, float $x, float $y, float $z, int $radius) : bool{
        $radiusSq = $radius * $radius;
        foreach($world->getPlayers() as $player){
            if(!$player->isAlive() || $player->isSpectator()){ continue; }
            $loc = $player->getLocation();
            $dx = $loc->x - $x;
            $dy = $loc->y - $y;
            $dz = $loc->z - $z;
            if($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq){
                return true;
            }
        }
        return false;
    }

    private function tryPopulateStructure(World $world, int $chunkX, int $chunkZ, string $dimension) : void{
        $regionX = $chunkX >= 0 ? intdiv($chunkX, 4) : -intdiv(-$chunkX + 3, 4);
        $regionZ = $chunkZ >= 0 ? intdiv($chunkZ, 4) : -intdiv(-$chunkZ + 3, 4);
        $baseKey = $world->getFolderName() . ":" . $regionX . ":" . $regionZ;
        $cooldown = max(10, (int) $this->settings($world)->get("structure-replenish-cycles", 50));

        if($dimension === "overworld"){
            $signature = $this->scanSurfaceStructureSignature($world, $chunkX, $chunkZ);

            if($signature["dark_oak"] >= 10 && $signature["cobblestone"] >= 8 && $signature["height_span"] >= 7){
                $key = "outpost:" . $baseKey;
                if(!isset($this->structureCooldownCycles[$key])){
                    $existing = $this->countNearbyClass($world, \BeeAZ\AZVanillaMobs\entity\overworld\Pillager::class, ($chunkX << 4) + 8, 80.0, ($chunkZ << 4) + 8, 56);
                    if($existing < 3){
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\overworld\Pillager::class, 3 - $existing, "overworld");
                    }
                    $this->structureCooldownCycles[$key] = $cooldown;
                }
            }

            if($signature["bed"] >= 2 && ($signature["bell"] >= 1 || $signature["workstation"] >= 3)){
                $key = "village:" . $baseKey;
                if(!isset($this->structureCooldownCycles[$key])){
                    $existing = $this->countNearbyClass($world, \BeeAZ\AZVanillaMobs\entity\overworld\Villager::class, ($chunkX << 4) + 8, 80.0, ($chunkZ << 4) + 8, 64);
                    if($existing < 3){
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\overworld\Villager::class, 3 - $existing, "overworld");
                    }
                    $this->structureCooldownCycles[$key] = $cooldown;
                }
            }
            return;
        }

        if($dimension === "nether"){
            $fortress = $this->countChunkKeyword($world, $chunkX, $chunkZ, ["nether brick"], 3);
            if($fortress >= 18){
                $key = "fortress:" . $baseKey;
                if(!isset($this->structureCooldownCycles[$key])){
                    $existing = $this->countNearbyClass($world, \BeeAZ\AZVanillaMobs\entity\nether\WitherSkeleton::class, ($chunkX << 4) + 8, 64.0, ($chunkZ << 4) + 8, 48);
                    if($existing < 2){
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\nether\WitherSkeleton::class, 1, "nether");
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\nether\Blaze::class, 1, "nether");
                    }
                    $this->structureCooldownCycles[$key] = $cooldown;
                }
                return;
            }

            $bastion = $this->countChunkKeyword($world, $chunkX, $chunkZ, ["blackstone", "gilded"], 3);
            if($bastion >= 22){
                $key = "bastion:" . $baseKey;
                if(!isset($this->structureCooldownCycles[$key])){
                    $existing = $this->countNearbyClass($world, \BeeAZ\AZVanillaMobs\entity\nether\Piglin::class, ($chunkX << 4) + 8, 64.0, ($chunkZ << 4) + 8, 48);
                    if($existing < 3){
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\nether\Piglin::class, 2, "nether");
                        $this->spawnStructureGroup($world, $chunkX, $chunkZ, \BeeAZ\AZVanillaMobs\entity\nether\Hoglin::class, 1, "nether");
                    }
                    $this->structureCooldownCycles[$key] = $cooldown;
                }
            }
        }
    }

    /** @return array{dark_oak:int,cobblestone:int,bed:int,bell:int,workstation:int,height_span:int} */
    private function scanSurfaceStructureSignature(World $world, int $chunkX, int $chunkZ) : array{
        $counts = ["dark_oak" => 0, "cobblestone" => 0, "bed" => 0, "bell" => 0, "workstation" => 0, "height_span" => 0];
        $minSeen = PHP_INT_MAX;
        $maxSeen = PHP_INT_MIN;
        $workstations = ["composter", "blast furnace", "smoker", "cartography", "fletching", "lectern", "loom", "stonecutter", "grindstone", "brewing stand"];

        for($localX = 0; $localX < 16; $localX += 2){
            for($localZ = 0; $localZ < 16; $localZ += 2){
                $x = ($chunkX << 4) + $localX;
                $z = ($chunkZ << 4) + $localZ;
                $top = $world->getHighestBlockAt($x, $z);
                for($y = max($world->getMinY(), $top - 18); $y <= min($world->getMaxY() - 1, $top + 1); $y++){
                    $name = strtolower($world->getBlockAt($x, $y, $z)->getName());
                    if(str_contains($name, "dark oak")){
                        $counts["dark_oak"]++;
                    }
                    if(str_contains($name, "cobblestone")){
                        $counts["cobblestone"]++;
                    }
                    if(str_contains($name, " bed" ) || str_ends_with($name, "bed")){
                        $counts["bed"]++;
                    }
                    if(str_contains($name, "bell")){
                        $counts["bell"]++;
                    }
                    foreach($workstations as $keyword){
                        if(str_contains($name, $keyword)){
                            $counts["workstation"]++;
                            break;
                        }
                    }
                    if(!str_contains($name, "air")){
                        $minSeen = min($minSeen, $y);
                        $maxSeen = max($maxSeen, $y);
                    }
                }
            }
        }
        if($minSeen !== PHP_INT_MAX){
            $counts["height_span"] = $maxSeen - $minSeen;
        }
        return $counts;
    }

    private function countChunkKeyword(World $world, int $chunkX, int $chunkZ, array $keywords, int $step) : int{
        $count = 0;
        $minY = $world->getMinY();
        $maxY = $world->getMaxY();
        for($localX = 0; $localX < 16; $localX += $step){
            for($localZ = 0; $localZ < 16; $localZ += $step){
                $x = ($chunkX << 4) + $localX;
                $z = ($chunkZ << 4) + $localZ;
                for($y = $minY; $y < $maxY; $y += $step){
                    $name = strtolower($world->getBlockAt($x, $y, $z)->getName());
                    foreach($keywords as $keyword){
                        if(str_contains($name, $keyword)){
                            $count++;
                            if($count >= 40){
                                return $count;
                            }
                            break;
                        }
                    }
                }
            }
        }
        return $count;
    }

    private function countNearbyClass(World $world, string $class, float $x, float $y, float $z, int $radius) : int{
        $count = 0;
        $radiusSq = $radius * $radius;
        foreach($world->getEntities() as $entity){
            if(!($entity instanceof $class) || $entity->isClosed()){
                continue;
            }
            $loc = $entity->getLocation();
            $dx = $loc->x - $x;
            $dy = $loc->y - $y;
            $dz = $loc->z - $z;
            if($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSq){
                $count++;
            }
        }
        return $count;
    }

    private function spawnStructureGroup(World $world, int $chunkX, int $chunkZ, string $class, int $count, string $dimension) : int{
        $spawned = 0;
        for($i = 0; $i < $count; $i++){
            for($try = 0; $try < 18; $try++){
                $x = ($chunkX << 4) + mt_rand(-6, 21);
                $z = ($chunkZ << 4) + mt_rand(-6, 21);
                if(!$world->isChunkLoaded($x >> 4, $z >> 4) || !$world->isChunkGenerated($x >> 4, $z >> 4)){
                    continue;
                }

                $context = $dimension === "nether" ? $this->buildNetherContext($world, $x, $z) : $this->buildOverworldContext($world, $x, $z);
                if($context === null || ($dimension === "overworld" && ($context["water"] || $context["underground"])) || !$this->hasEnoughSpace($world, $class, $context)){
                    continue;
                }

                if(!$this->canSpawn($world,$class,$x,(int)$context["y"],$z,$context)){ continue; }
                try{
                    $nbt = CompoundTag::create()
                        ->setByte("StructureSpawned", 1)
                        ->setFloat("StructureHomeX", (float) (($chunkX << 4) + 8.5))
                        ->setFloat("StructureHomeY", (float) $context["y"])
                        ->setFloat("StructureHomeZ", (float) (($chunkZ << 4) + 8.5))
                        ->setFloat("StructureRadius", 40.0);
                    $position = new Position($x + 0.5, (float) $context["y"], $z + 0.5, $world);
                    $entity = new $class(Location::fromObject($position, $world, (float) mt_rand(0, 359), 0.0), $nbt);
                    $entity->spawnToAll();
                    $spawned++;
                    break;
                }catch(\Throwable $e){
                    $this->plugin->getLogger()->debug("Structure spawn skipped: " . $e->getMessage());
                    break;
                }
            }
        }
        return $spawned;
    }

    /** @return array<string, mixed>|null */
    private function buildOverworldContext(World $world, int $x, int $z) : ?array{
        $surfaceY = $world->getHighestBlockAt($x, $z);
        if($surfaceY === null){
            return null;
        }

        $surfaceBlock = $world->getBlockAt($x, $surfaceY, $z);
        $biomeName = $this->getBiomeName($world, $x, $surfaceY, $z);
        $tags = $this->classifyBiome($biomeName);
        $time = $world->getTimeOfDay();
        $night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;

        // On several PocketMine generators getHighestBlockAt() returns the
        // seabed instead of the top water cell. Scan above and below the result
        // so oceans and rivers are detected reliably.
        $waterY = $this->findWaterSpawnY($world, $x, $surfaceY, $z);
        if($waterY !== null){
            $light = $world->getBlockLightAt($x, $waterY, $z);
            return $this->makeContext("overworld", $biomeName, $tags, $waterY, $surfaceY, true, false, false, $night, $light, $world, $x, $z);
        }

        // A small share of attempts probe real underground cavities. This is
        // required for bats, glow squid, axolotls and underground slimes.
        if(mt_rand(1, 100) <= 18){
            $underground = $this->findUndergroundPosition($world, $x, $surfaceY, $z);
            if($underground !== null){
                [$y, $water] = $underground;
                $light = $world->getBlockLightAt($x, $y, $z);
                return $this->makeContext("overworld", $biomeName, $tags, $y, $surfaceY, $water, false, true, $night, $light, $world, $x, $z);
            }
        }

        $spawnY = $surfaceY + 1;
        if($world->getBlockAt($x, $spawnY, $z)->isSolid()){
            return null;
        }
        $light = $world->getBlockLightAt($x, $spawnY, $z);
        $hostile = $night && $light <= 7;
        return $this->makeContext("overworld", $biomeName, $tags, $spawnY, $surfaceY, false, false, false, $night, $light, $world, $x, $z, $hostile);
    }

    /** @return array<string, mixed>|null */
    private function buildNetherContext(World $world, int $x, int $z) : ?array{
        $minY = method_exists($world, "getMinY") ? $world->getMinY() : 0;
        $maxY = method_exists($world, "getMaxY") ? $world->getMaxY() : 128;
        $preferLava = mt_rand(1, 100) <= 18;

        if($preferLava){
            for($y = min($maxY - 2, 110); $y >= max($minY + 1, 20); $y--){
                if($world->getBlockAt($x, $y, $z) instanceof Lava && !$world->getBlockAt($x, $y + 1, $z)->isSolid()){
                    $biomeName = $this->getBiomeName($world, $x, $y, $z);
                    return $this->makeContext("nether", $biomeName, $this->classifyBiome($biomeName), $y + 1, $y, false, true, false, false, 0, $world, $x, $z, true);
                }
            }
        }

        $start = mt_rand(max($minY + 8, 28), min($maxY - 4, 112));
        for($radius = 0; $radius < 70; $radius++){
            foreach([$start - $radius, $start + $radius] as $y){
                if($y <= $minY + 1 || $y >= $maxY - 2){
                    continue;
                }
                $feet = $world->getBlockAt($x, $y, $z);
                $head = $world->getBlockAt($x, $y + 1, $z);
                $below = $world->getBlockAt($x, $y - 1, $z);
                if(!$feet->isSolid() && !$head->isSolid() && $below->isSolid() && !($feet instanceof Lava) && !($head instanceof Lava)){
                    $biomeName = $this->getBiomeName($world, $x, $y, $z);
                    return $this->makeContext("nether", $biomeName, $this->classifyBiome($biomeName), $y, $y - 1, false, false, false, false, 0, $world, $x, $z, true);
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function buildEndContext(World $world, int $x, int $z) : ?array{
        $surfaceY = $world->getHighestBlockAt($x, $z);
        if($surfaceY === null || $surfaceY < 10){
            return null;
        }
        $spawnY = $surfaceY + 1;
        if($world->getBlockAt($x, $spawnY, $z)->isSolid() || $world->getBlockAt($x, $spawnY + 1, $z)->isSolid()){
            return null;
        }
        $biomeName = $this->getBiomeName($world, $x, $surfaceY, $z);
        return $this->makeContext("the_end", $biomeName, $this->classifyBiome($biomeName), $spawnY, $surfaceY, false, false, false, false, 0, $world, $x, $z, true);
    }

    /** @param array<string, bool> $tags
     *  @return array<string, mixed>
     */
    private function makeContext(string $dimension, string $biomeName, array $tags, int $y, int $surfaceY, bool $water, bool $lava, bool $underground, bool $night, int $light, World $world, int $x, int $z, ?bool $hostile = null) : array{
        $isOverworld = $dimension === "overworld";
        $isNether = $dimension === "nether";
        $isEnd = $dimension === "the_end";
        $waterSpread = $isOverworld && $water ? $this->countNearbyWaterCells($world, $x, $y, $z, 5, 2) : 0;
        $inlandWater = $isOverworld && $water && !$tags["ocean"] && !$tags["river"] && $waterSpread >= 10;

        return [
            "dimension" => $dimension,
            "biome" => $biomeName,
            "tags" => $tags,
            "x" => $x,
            "z" => $z,
            "y" => $y,
            "surfaceY" => $surfaceY,
            "water" => $water,
            "waterSpread" => $waterSpread,
            "inlandWater" => $inlandWater,
            "lava" => $lava,
            "underground" => $underground,
            "night" => $night,
            "light" => $light,
            "hostile" => $hostile ?? true,
            "nearFlower" => $isOverworld && !$water && !$underground && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["flower", "poppy", "dandelion", "tulip", "allium", "orchid", "daisy", "cornflower", "lilac", "peony", "rose", "sunflower", "eyeblossom"], 5, 2),
            "nearClay" => $isOverworld && $water && $underground && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["clay"], 4, 3),
            "nearMineshaft" => $isOverworld && $underground && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["rail", "cobweb"], 6, 4),
            "nearFortress" => $isNether && !$lava && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["nether brick"], 8, 5),
            "nearBastion" => $isNether && !$lava && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["blackstone", "gilded"], 8, 5),
            "nearEndCity" => $isEnd && $this->hasNearbyBlockKeyword($world, $x, $y, $z, ["purpur", "end stone bricks"], 8, 6),
            "nearWater" => $isOverworld && !$water && !$underground && $this->hasNearbyLiquid($world, $x, $y, $z, Water::class, 5, 3),
        ];
    }

    private function getSpawnWeight(string $class, array $ctx) : int{
        $parts = explode('\\', $class);
        $mob = strtolower((string) array_pop($parts));
        $tags = $ctx["tags"];
        $dimension = $ctx["dimension"];

        if($dimension === "overworld"){
            if($ctx["water"]){
                $lake = (bool) ($ctx["inlandWater"] ?? false);
                return match($mob){
                    // Vanilla Bedrock uses weight 5 for most drowned water
                    // conditions. Keep them rare in lakes and rivers.
                    "drowned" => ($ctx["night"] && $ctx["light"] <= 7 && $ctx["y"] < $ctx["surfaceY"] + 35)
                        ? (($tags["ocean"] || $ctx["biome"] === "unknown") ? 5 : (($tags["river"] || $lake) ? 3 : 0))
                        : 0,

                    // Vanilla squid weight is 8 with groups of 2-4. Inland
                    // lakes use half weight so fish remain clearly dominant.
                    "squid" => ($tags["ocean"] || $tags["river"] || $ctx["biome"] === "unknown") ? 8 : ($lake ? 4 : 0),

                    // Glow squid are underground-only, Y <= 30, group 2-4.
                    "glowsquid" => ($ctx["underground"] && $ctx["y"] <= 30 && $ctx["light"] <= 3) ? 10 : 0,
                    "dolphin" => (($tags["ocean"] || $ctx["biome"] === "unknown") && !$tags["frozen"] && !$ctx["underground"]) ? 3 : 0,
                    "axolotl" => 0,

                    // Official Bedrock weights: cod 75 (group 4-7), salmon 26
                    // in cold oceans and 16 in rivers (group 3-5). Imported
                    // maps often report lake biomes as plains/unknown, so lakes
                    // receive dedicated fish weights that are well over 3x the
                    // combined drowned/squid chance.
                    "cod" => (($tags["ocean"] || $ctx["biome"] === "unknown") && !$tags["warm_ocean"] && !$tags["frozen"])
                        ? 70
                        : ($lake ? 56 : 0),
                    "salmon" => ($tags["river"] ? 24 : (($tags["cold_ocean"] || $tags["frozen"]) ? 30 : ($lake ? 32 : 0))),
                    "pufferfish" => ($tags["warm_ocean"] || $tags["lukewarm_ocean"]) ? 4 : 0,
                    "tropicalfish" => ($tags["warm_ocean"] || $tags["lukewarm_ocean"]) ? 24 : 0,
                    default => 0,
                };
            }

            if($ctx["underground"]){
                return match($mob){
                    "slime" => ($ctx["y"] < 40 && mt_rand(1, 12) === 1) ? 4 : 0,
                    "silverfish" => (($ctx["nearMineshaft"] || $tags["mountain"]) && $ctx["light"] <= 7) ? 1 : 0,
                    "zombie" => $ctx["light"] <= 7 ? 22 : 0,
                    "zombievillager" => ($ctx["light"] <= 7 && mt_rand(1, 30) === 1) ? 1 : 0,
                    "skeleton" => $ctx["light"] <= 7 ? 20 : 0,
                    "creeper" => $ctx["light"] <= 7 ? 11 : 0,
                    "spider" => $ctx["light"] <= 7 ? 15 : 0,
                    "witch" => $ctx["light"] <= 7 ? 1 : 0,
                    "enderman" => $ctx["light"] <= 7 ? 2 : 0,
                    default => 0,
                };
            }

            if($ctx["hostile"]){
                return match($mob){
                    "zombie" => 24,
                    "zombievillager" => mt_rand(1, 28) === 1 ? 1 : 0,
                    "skeleton" => 21,
                    "creeper" => 12,
                    "spider" => 18,
                    "witch" => $tags["swamp"] ? 3 : 1,
                    "enderman" => 3,
                    "slime" => ($tags["swamp"] && $ctx["night"] && $ctx["light"] <= 7) ? 4 : 0,
                    // A very rare patrol. Raid-only members are never generic.
                    "pillager" => (!$tags["mushroom"] && mt_rand(1, 90) === 1) ? 1 : 0,
                    default => 0,
                };
            }

            return match($mob){
                "mooshroom" => $tags["mushroom"] ? 20 : 0,
                "cow" => (!$tags["mushroom"] && ($tags["plains"] || $tags["forest"] || $tags["taiga"] || $tags["savanna"] || $tags["meadow"])) ? 22 : 0,
                "sheep" => (!$tags["mushroom"] && ($tags["plains"] || $tags["forest"] || $tags["taiga"] || $tags["savanna"] || $tags["meadow"] || $tags["snowy"])) ? 24 : 0,
                "pig" => (!$tags["mushroom"] && ($tags["plains"] || $tags["forest"] || $tags["taiga"] || $tags["savanna"] || $tags["swamp"])) ? 15 : 0,
                "chicken" => (!$tags["mushroom"] && ($tags["plains"] || $tags["forest"] || $tags["jungle"] || $tags["savanna"] || $tags["swamp"])) ? 23 : 0,
                "horse" => ($tags["plains"] || $tags["savanna"]) ? 3 : 0,
                "donkey" => ($tags["plains"] || $tags["savanna"]) ? 1 : 0,
                "llama" => ($tags["windswept"] || $tags["savanna_plateau"]) ? 1 : 0,
                "wolf" => ($tags["forest"] || $tags["taiga"] || $tags["grove"]) ? 3 : 0,
                "fox" => ($tags["taiga"] || $tags["grove"]) ? 2 : 0,
                "panda" => $tags["bamboo_jungle"] ? 1 : 0,
                "parrot" => $tags["jungle"] ? 2 : 0,
                "turtle" => ($tags["beach"] && $ctx["nearWater"]) ? 3 : 0,
                "goat" => $tags["mountain"] ? 1 : 0,
                "bee" => (($tags["meadow"] || $tags["plains"] || $tags["flower_forest"] || $tags["cherry"]) && $ctx["nearFlower"]) ? 2 : 0,
                // Village-, trader-, structure- and breeding-only mobs.
                "cat", "ocelot", "mule", "traderllama", "villager", "wanderingtrader",
                "irongolem", "snowgolem", "axolotl", "frog", "tadpole", "camel",
                "sniffer", "allay", "bat" => 0,
                default => 0,
            };
        }

        if($dimension === "nether"){
            if($ctx["lava"]){
                return 0;
            }
            return match($mob){
                "piglin" => ($tags["crimson"] || $tags["nether_wastes"]) ? 12 : 2,
                "hoglin" => $tags["crimson"] ? 10 : 0,
                "ghast" => ($tags["soul_sand"] || $tags["nether_wastes"] || $tags["basalt"]) ? 5 : 1,
                "blaze" => $ctx["nearFortress"] ? 5 : 0,
                "magmacube" => ($tags["basalt"] || $tags["nether_wastes"] || $ctx["nearFortress"]) ? 7 : 1,
                default => 0,
            };
        }

        return match($mob){
            "enderman" => 24,
            default => 0,
        };
    }

    /** @param array<string, int> $weighted */
    private function pickWeighted(array $weighted) : ?string{
        $total = array_sum($weighted);
        if($total <= 0){
            return null;
        }
        $roll = mt_rand(1, $total);
        foreach($weighted as $class => $weight){
            $roll -= $weight;
            if($roll <= 0){
                return $class;
            }
        }
        return null;
    }

    private function hasEnoughSpace(World $world, string $class, array $ctx) : bool{
        $x = (int) $ctx["x"];
        $y = (int) $ctx["y"];
        $z = (int) $ctx["z"];

        if($ctx["water"]){
            return $world->getBlockAt($x, $y, $z) instanceof Water;
        }
        if($ctx["lava"]){
            return $world->getBlockAt($x, $y - 1, $z) instanceof Lava;
        }

        $height = str_contains($class, "Enderman") ? 3 : 2;
        $width = str_contains($class, "Ghast") ? 4 : 1;
        for($dx = 0; $dx < $width; $dx++){
            for($dz = 0; $dz < $width; $dz++){
                for($h = 0; $h < $height; $h++){
                    if($world->getBlockAt($x + $dx, $y + $h, $z + $dz)->isSolid()){
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function findWaterSpawnY(World $world, int $x, int $surfaceY, int $z) : ?int{
        $minY = method_exists($world, "getMinY") ? $world->getMinY() : 0;
        $maxY = method_exists($world, "getMaxY") ? $world->getMaxY() : 320;
        $bottom = max($minY + 1, $surfaceY - 24);
        $top = min($maxY - 2, $surfaceY + 40);

        $waterCells = [];
        for($y = $top; $y >= $bottom; $y--){
            $block = $world->getBlockAt($x, $y, $z);
            if($this->isWaterColumnBlock($block)){
                if($block instanceof Water){
                    // Require water around the spawn cell so drowned and fish
                    // do not materialize in a one-block puddle.
                    $neighbours = 0;
                    foreach([[1,0],[-1,0],[0,1],[0,-1]] as [$dx, $dz]){
                        if($this->isWaterColumnBlock($world->getBlockAt($x + $dx, $y, $z + $dz))){
                            $neighbours++;
                        }
                    }
                    if($neighbours >= 2){
                        $waterCells[] = $y;
                    }
                }
            }
        }
        if($waterCells === []){
            return null;
        }

        // Prefer the middle/lower part of the column instead of the shoreline.
        sort($waterCells);
        $startIndex = (int) floor(count($waterCells) * 0.20);
        $endIndex = max($startIndex, (int) floor(count($waterCells) * 0.75));
        return $waterCells[mt_rand($startIndex, min($endIndex, count($waterCells) - 1))];
    }

    private function isWaterColumnBlock(\pocketmine\block\Block $block) : bool{
        if($block instanceof Water){
            return true;
        }
        $name = strtolower($block->getName() . " " . get_class($block));
        return str_contains($name, "kelp") || str_contains($name, "seagrass");
    }

    private function countNearbyWaterCells(World $world, int $x, int $y, int $z, int $horizontal, int $vertical) : int{
        $count = 0;
        for($dx = -$horizontal; $dx <= $horizontal; $dx += 2){
            for($dz = -$horizontal; $dz <= $horizontal; $dz += 2){
                for($dy = -$vertical; $dy <= $vertical; $dy++){
                    if($this->isWaterColumnBlock($world->getBlockAt($x + $dx, $y + $dy, $z + $dz))){
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    /** @return array{int, bool}|null */
    private function findUndergroundPosition(World $world, int $x, int $surfaceY, int $z) : ?array{
        $minY = method_exists($world, "getMinY") ? $world->getMinY() : 0;
        $top = $surfaceY - 4;
        $bottom = max($minY + 4, $surfaceY - 58);
        if($top <= $bottom){
            return null;
        }

        $start = mt_rand($bottom, $top);
        for($offset = 0; $offset <= ($top - $bottom); $offset++){
            foreach([$start - $offset, $start + $offset] as $y){
                if($y < $bottom || $y > $top){
                    continue;
                }
                $feet = $world->getBlockAt($x, $y, $z);
                $head = $world->getBlockAt($x, $y + 1, $z);
                $below = $world->getBlockAt($x, $y - 1, $z);
                if($feet instanceof Water){
                    return [$y, true];
                }
                if(!$feet->isSolid() && !$head->isSolid() && $below->isSolid()){
                    return [$y, false];
                }
            }
        }
        return null;
    }

    private function getBiomeName(World $world, int $x, int $y, int $z) : string{
        try{
            if(method_exists($world, "getBiome")){
                $biome = $world->getBiome($x, $y, $z);
                if(is_object($biome) && method_exists($biome, "getName")){
                    return strtolower((string) $biome->getName());
                }
            }
        }catch(\Throwable $e){}
        return "unknown";
    }

    /** @return array<string, bool> */
    private function classifyBiome(string $name) : array{
        $contains = static fn(array $words) : bool => array_reduce($words, static fn(bool $carry, string $word) : bool => $carry || str_contains($name, $word), false);
        return [
            "desert" => $contains(["desert", "badlands", "mesa"]),
            "snowy" => $contains(["snow", "frozen", "ice spikes", "ice_plains"]),
            "frozen" => $contains(["frozen", "ice"]),
            "plains" => $contains(["plains", "sunflower"]),
            "meadow" => str_contains($name, "meadow"),
            "forest" => $contains(["forest", "wooded", "birch", "dark oak"]),
            "flower_forest" => $contains(["flower forest", "flower_forest"]),
            "cherry" => str_contains($name, "cherry"),
            "taiga" => str_contains($name, "taiga"),
            "grove" => str_contains($name, "grove"),
            "savanna" => str_contains($name, "savanna"),
            "savanna_plateau" => $contains(["savanna plateau", "savanna_plateau"]),
            "jungle" => str_contains($name, "jungle"),
            "bamboo_jungle" => $contains(["bamboo jungle", "bamboo_jungle"]),
            "swamp" => $contains(["swamp", "mangrove"]),
            "mushroom" => $contains(["mushroom", "mooshroom", "mushroom_fields"]),
            "mountain" => $contains(["mountain", "peak", "slope", "windswept", "grove", "meadow"]),
            "windswept" => str_contains($name, "windswept"),
            "beach" => str_contains($name, "beach"),
            "river" => str_contains($name, "river"),
            "ocean" => str_contains($name, "ocean"),
            "warm_ocean" => $contains(["warm ocean", "warm_ocean"]),
            "lukewarm_ocean" => $contains(["lukewarm ocean", "lukewarm_ocean"]),
            "cold_ocean" => $contains(["cold ocean", "cold_ocean"]),
            "lush_caves" => $contains(["lush caves", "lush_caves"]),
            "deep_dark" => $contains(["deep dark", "deep_dark"]),
            "nether_wastes" => $contains(["nether wastes", "nether_wastes", "hell"]),
            "crimson" => str_contains($name, "crimson"),
            "warped" => str_contains($name, "warped"),
            "soul_sand" => $contains(["soul sand", "soulsand"]),
            "basalt" => str_contains($name, "basalt"),
        ];
    }

    /**
     * Adds at most two nearby companions for basic herd animals.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $limits
     */
    private function spawnPassiveCompanions(string $class, string $entityName, World $world, int $baseX, int $baseZ, array $ctx, array $limits) : void{
        $roll = mt_rand(1, 100);
        [$groupMin,$groupMax]=$this->settings($world)->group($entityName,1,3);
        $wanted=mt_rand($groupMin,$groupMax)-1;
        if($wanted <= 0){
            return;
        }

        $limit = isset($limits[$entityName]) ? (int) $limits[$entityName] : PHP_INT_MAX;
        for($i = 0; $i < $wanted; $i++){
            if($this->getEntityTypeCount($class) >= $limit){
                break;
            }

            $spawned = false;
            for($try = 0; $try < 7; $try++){
                $x = $baseX + mt_rand(-4, 4);
                $z = $baseZ + mt_rand(-4, 4);
                if(!$world->isChunkLoaded($x >> 4,$z >> 4) || !$world->isChunkGenerated($x >> 4, $z >> 4)){
                    continue;
                }
                $groundY = $world->getHighestBlockAt($x, $z);
                if($groundY === null){
                    continue;
                }
                $y = $groundY + 1;
                if($world->getBlockAt($x, $groundY, $z) instanceof Water
                    || $world->getBlockAt($x, $y, $z)->isSolid()
                    || $world->getBlockAt($x, $y + 1, $z)->isSolid()){
                    continue;
                }

                $groupContext=$ctx ?? ["biome"=>strtolower($this->getBiomeName($world,$x,$y,$z)),"night"=>$world->getTimeOfDay()>=World::TIME_NIGHT,"light"=>$world->getBlockLightAt($x,$y,$z),"water"=>true];
                $groupContext["y"]=$y;$groupContext["light"]=$world->getBlockLightAt($x,$y,$z);
                if(!$this->canSpawn($world,$class,$x,$y,$z,$groupContext)){ continue; }
                try{
                    $nbt = CompoundTag::create()->setByte("NaturalSpawned", 1);
                    $mob = new $class(new Location($x + 0.5, (float) $y, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0), $nbt);
                    if(!$this->entityFits($mob)){ $mob->close(); continue; }
                    $mob->spawnToAll();
                    $spawned = true;
                }catch(\Throwable $e){}
                break;
            }
            if(!$spawned){
                break;
            }
        }
    }

    /**
     * Spawns vanilla-like water groups. The first entity already exists, so
     * this method creates only the remaining members of the herd.
     *
     * @param array<string, mixed> $limits
     */
    private function spawnAquaticCompanions(string $class, string $entityName, World $world, int $baseX, int $baseY, int $baseZ, array $limits) : void{
        if(!(bool) $this->settings($world)->get("water-herd-spawning", true)){
            return;
        }

        [$minSize, $maxSize] = match($entityName){
            "cod" => [5, 8],
            "salmon" => [4, 6],
            "squid", "glowsquid" => [2, 4],
            "tropicalfish" => [4, 7],
            "pufferfish" => [1, 3],
            default => [1, 1],
        };
        [$minSize,$maxSize]=$this->settings($world)->group($entityName,$minSize,$maxSize);
        $wanted = max(0, mt_rand($minSize, $maxSize) - 1);
        if($wanted <= 0){
            return;
        }

        $limit = isset($limits[$entityName]) ? (int) $limits[$entityName] : PHP_INT_MAX;
        $localRadius = max(24, (int) $this->settings($world)->get("local-mob-cap-radius", 48));
        $localCap = max(1, (int) $this->settings($world)->get("local-aquatic-cap", 20));
        $worldCap = max(1, (int) $this->settings($world)->get("per-world-aquatic-cap", 40));
        $totalWorldCap = max(1, (int) $this->settings($world)->get("per-world-mob-cap", 90));

        for($i = 0; $i < $wanted; $i++){
            if($this->getEntityTypeCount($class) >= $limit
                || $this->countPluginMobs($world) >= $totalWorldCap
                || $this->countWorldAquatic($world) >= $worldCap
                || $this->countNearbyAquaticAt($world, $baseX + 0.5, (float) $baseY, $baseZ + 0.5, $localRadius) >= $localCap){
                break;
            }

            $spawned = false;
            for($try = 0; $try < 10; $try++){
                $x = $baseX + mt_rand(-5, 5);
                $y = $baseY + mt_rand(-2, 2);
                $z = $baseZ + mt_rand(-5, 5);
                if(!$world->isChunkLoaded($x >> 4, $z >> 4) || !$world->isChunkGenerated($x >> 4, $z >> 4)){
                    continue;
                }
                if(!$this->isWaterColumnBlock($world->getBlockAt($x, $y, $z))){
                    continue;
                }
                if(in_array($entityName, ["squid", "glowsquid"], true)
                    && (!$this->isWaterColumnBlock($world->getBlockAt($x, $y + 1, $z))
                        || !$this->isWaterColumnBlock($world->getBlockAt($x, $y - 1, $z)))){
                    continue;
                }

                $groupContext=$ctx ?? ["biome"=>strtolower($this->getBiomeName($world,$x,$y,$z)),"night"=>$world->getTimeOfDay()>=World::TIME_NIGHT,"light"=>$world->getBlockLightAt($x,$y,$z),"water"=>true];
                $groupContext["y"]=$y;$groupContext["light"]=$world->getBlockLightAt($x,$y,$z);
                if(!$this->canSpawn($world,$class,$x,$y,$z,$groupContext)){ continue; }
                try{
                    $nbt = CompoundTag::create()->setByte("NaturalSpawned", 1);
                    $mob = new $class(new Location($x + 0.5, (float) $y, $z + 0.5, $world, (float) mt_rand(0, 359), 0.0), $nbt);
                    if(!$this->entityFits($mob)){ $mob->close(); continue; }
                    $mob->spawnToAll();
                    $spawned = true;
                    break;
                }catch(\Throwable $e){}
            }
            if(!$spawned){
                break;
            }
        }
    }

    /** @param Player[] $players */
    private function trySpawnWanderingTrader(array $players) : bool{
        return false;
    }

    private function hasNearbyBlockKeyword(World $world, int $x, int $y, int $z, array $keywords, int $horizontal, int $vertical) : bool{
        // Sample every two blocks to keep rare spawn checks inexpensive.
        for($dx = -$horizontal; $dx <= $horizontal; $dx += 2){
            for($dz = -$horizontal; $dz <= $horizontal; $dz += 2){
                for($dy = -$vertical; $dy <= $vertical; $dy++){
                    $block = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
                    $text = strtolower($block->getName() . " " . get_class($block));
                    foreach($keywords as $keyword){
                        if(str_contains($text, strtolower($keyword))){
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    private function hasNearbyLiquid(World $world, int $x, int $y, int $z, string $liquidClass, int $horizontal, int $vertical) : bool{
        for($dx = -$horizontal; $dx <= $horizontal; $dx += 2){
            for($dz = -$horizontal; $dz <= $horizontal; $dz += 2){
                for($dy = -$vertical; $dy <= $vertical; $dy++){
                    if($world->getBlockAt($x + $dx, $y + $dy, $z + $dz) instanceof $liquidClass){
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
