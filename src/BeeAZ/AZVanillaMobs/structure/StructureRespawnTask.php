<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\structure;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\command\StructureMobsCommand;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_values;
use function max;
use function sha1;
use function strtolower;
use function time;

/**
 * Persists structure mob locations and restores a full group 10 minutes after
 * the last member dies. Only loaded structure areas are checked, avoiding
 * duplicate groups when the original entities are sleeping in unloaded chunks.
 */
final class StructureRespawnTask extends Task implements Listener{
    private const DEFAULT_RESPAWN_SECONDS = 600;

    public function __construct(private Main $plugin){}

    public static function makeStructureId(string $worldName, string $type, Vector3 $home): string{
        return 'structure-' . substr(sha1(strtolower($worldName) . ':' . $type . ':' . (int) $home->x . ':' . (int) $home->y . ':' . (int) $home->z), 0, 20);
    }

    /**
     * Returns null when another record of the same type already covers this
     * location. Otherwise stores the new location and returns its stable ID.
     */
    public static function registerStructure(Main $plugin, World $world, string $type, Vector3 $home, int $radius, string $dimension): ?string{
        $state = self::openState($plugin);
        $all = (array) $state->get('mobs', []);
        $worldName = $world->getFolderName();
        $records = array_values((array) ($all[$worldName] ?? []));
        $distance = self::dedupeDistance($type);
        foreach($records as $record){
            if((string) ($record['type'] ?? '') !== $type){
                continue;
            }
            $dx = (float) ($record['x'] ?? 0.0) - $home->x;
            $dz = (float) ($record['z'] ?? 0.0) - $home->z;
            if(($dx * $dx) + ($dz * $dz) <= $distance * $distance){
                return null;
            }
        }

        $id = self::makeStructureId($worldName, $type, $home);
        $record = [
            'id' => $id,
            'type' => $type,
            'dimension' => $dimension,
            'x' => (float) $home->x,
            'y' => (float) $home->y,
            'z' => (float) $home->z,
            'radius' => $radius,
            'respawn_at' => 0,
        ];

        // Older builds created anchored mobs without a persistent structure ID.
        // Adopt such a nearby group instead of spawning a duplicate over it.
        $adoptedExistingGroup = false;
        foreach($world->getEntities() as $entity){
            if($entity instanceof BaseMob && $entity->isAlive() && !$entity->isClosed() && $entity->belongsToStructure($id, $type, $home)){
                $adoptedExistingGroup = true;
                break;
            }
        }

        $records[] = $record;
        $all[$worldName] = $records;
        $state->set('mobs', $all);
        $state->save();
        return $adoptedExistingGroup ? null : $id;
    }

    public static function removeStructure(Main $plugin, string $worldName, string $id): void{
        $state = self::openState($plugin);
        $all = (array) $state->get('mobs', []);
        $records = [];
        foreach((array) ($all[$worldName] ?? []) as $record){
            if((string) ($record['id'] ?? '') !== $id){
                $records[] = $record;
            }
        }
        $all[$worldName] = $records;
        $state->set('mobs', $all);
        $state->save();
    }

    public function onStructureMobDeath(EntityDeathEvent $event): void{
        $entity = $event->getEntity();
        if(!$entity instanceof BaseMob || !$entity->isStructureSpawnedMob()){
            return;
        }
        $id = $entity->getStructureId();
        $type = $entity->getStructureType();
        $home = $entity->getStructureHome();
        if($id === '' || $type === '' || $home === null){
            return;
        }

        $world = $entity->getWorld();
        foreach($world->getEntities() as $other){
            if($other === $entity){
                continue;
            }
            if($other instanceof BaseMob && $other->isAlive() && !$other->isClosed() && $other->belongsToStructure($id, $type, $home)){
                return;
            }
        }

        $state = self::openState($this->plugin);
        $all = (array) $state->get('mobs', []);
        $worldName = $world->getFolderName();
        $records = array_values((array) ($all[$worldName] ?? []));
        $respawnSeconds = max(60, (int) $this->plugin->getConfig()->get('structure-respawn-seconds', self::DEFAULT_RESPAWN_SECONDS));
        $changed = false;
        foreach($records as $index => $record){
            if((string) ($record['id'] ?? '') !== $id){
                continue;
            }
            $records[$index]['respawn_at'] = time() + $respawnSeconds;
            $changed = true;
            break;
        }
        if($changed){
            $all[$worldName] = $records;
            $state->set('mobs', $all);
            $state->save();
        }
    }

    public function onRun(): void{
        $state = self::openState($this->plugin);
        $all = (array) $state->get('mobs', []);
        $changed = false;
        $now = time();
        $respawnSeconds = max(60, (int) $this->plugin->getConfig()->get('structure-respawn-seconds', self::DEFAULT_RESPAWN_SECONDS));

        foreach($all as $worldName => $rawRecords){
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName((string) $worldName);
            if(!$world instanceof World){
                continue;
            }
            $records = array_values((array) $rawRecords);
            foreach($records as $index => $record){
                $type = (string) ($record['type'] ?? '');
                if($type === ''){
                    continue;
                }
                $home = new Vector3((float) ($record['x'] ?? 0.0), (float) ($record['y'] ?? 64.0), (float) ($record['z'] ?? 0.0));
                $id = (string) ($record['id'] ?? '');
                if($id === ''){
                    $id = self::makeStructureId((string) $worldName, $type, $home);
                    $records[$index]['id'] = $id;
                    $changed = true;
                }
                $radius = max(8, (int) ($record['radius'] ?? self::defaultRadius($type)));
                $dimension = (string) ($record['dimension'] ?? self::guessDimension($world));
                $records[$index]['radius'] = $radius;
                $records[$index]['dimension'] = $dimension;

                $centerChunkX = ((int) $home->x) >> 4;
                $centerChunkZ = ((int) $home->z) >> 4;
                if(!$world->isChunkLoaded($centerChunkX, $centerChunkZ)){
                    continue;
                }

                $respawnAt = max(0, (int) ($record['respawn_at'] ?? 0));
                // Before starting the timer, and again immediately before a
                // respawn, load the complete permitted home/chase area once.
                // This finds structure mobs sleeping in an unloaded edge chunk
                // and prevents duplicate groups.
                $forceAreaCheck = $respawnAt === 0 || $respawnAt <= $now;
                $alive = $this->hasLivingMember($world, $id, $type, $home, $radius, $forceAreaCheck);
                if($alive){
                    if($respawnAt !== 0){
                        $records[$index]['respawn_at'] = 0;
                        $changed = true;
                    }
                    continue;
                }

                if($respawnAt === 0){
                    $records[$index]['respawn_at'] = $now + $respawnSeconds;
                    $changed = true;
                    continue;
                }
                if($respawnAt > $now){
                    continue;
                }

                $newlyLoaded = self::loadSpawnChunks($world, $home);
                $spawned = StructureMobsCommand::spawnGroup($world, $type, $home, $radius, $dimension, $id);
                self::unloadSpawnChunks($world, $newlyLoaded);
                if($spawned > 0){
                    $records[$index]['respawn_at'] = 0;
                    $records[$index]['last_respawn'] = $now;
                    $changed = true;
                    $this->plugin->getLogger()->info("Структура {$type} в мире {$worldName}: восстановлено мобов {$spawned}.");
                }
            }
            $all[$worldName] = $records;
        }

        if($changed){
            $state->set('mobs', $all);
            $state->save();
        }
    }

    private function hasLivingMember(World $world, string $id, string $type, Vector3 $home, int $radius, bool $forceAreaCheck): bool{
        $find = static function() use ($world, $id, $type, $home): bool{
            foreach($world->getEntities() as $entity){
                if($entity instanceof BaseMob && $entity->isAlive() && !$entity->isClosed() && $entity->belongsToStructure($id, $type, $home)){
                    return true;
                }
            }
            return false;
        };
        if($find()){
            return true;
        }
        if(!$forceAreaCheck){
            return false;
        }

        $newlyLoaded = self::loadChunksAround($world, $home, $radius + 24);
        $alive = $find();
        self::unloadSpawnChunks($world, $newlyLoaded);
        return $alive;
    }

    private static function openState(Main $plugin): Config{
        return new Config($plugin->getDataFolder() . 'structure-population.yml', Config::YAML, [
            'mobs' => [],
            'loot' => [],
            'loot-version' => 4,
        ]);
    }

    /** @return array<int, array{0:int,1:int}> */
    private static function loadSpawnChunks(World $world, Vector3 $home): array{
        return self::loadChunksAround($world, $home, 24);
    }

    /** @return array<int, array{0:int,1:int}> */
    private static function loadChunksAround(World $world, Vector3 $home, int $blockRadius): array{
        $centerX = ((int) $home->x) >> 4;
        $centerZ = ((int) $home->z) >> 4;
        $chunkRadius = max(1, (int) ceil(max(16, $blockRadius) / 16));
        $loaded = [];
        for($dx = -$chunkRadius; $dx <= $chunkRadius; ++$dx){
            for($dz = -$chunkRadius; $dz <= $chunkRadius; ++$dz){
                $x = $centerX + $dx;
                $z = $centerZ + $dz;
                if(!$world->isChunkGenerated($x, $z)){
                    continue;
                }
                if(!$world->isChunkLoaded($x, $z) && $world->loadChunk($x, $z) instanceof Chunk){
                    $loaded[] = [$x, $z];
                }
            }
        }
        return $loaded;
    }

    /** @param array<int, array{0:int,1:int}> $chunks */
    private static function unloadSpawnChunks(World $world, array $chunks): void{
        foreach($chunks as [$x, $z]){
            $world->unloadChunkRequest($x, $z, true);
        }
    }

    private static function dedupeDistance(string $type): int{
        return match($type){
            'mansion', 'monument', 'ancient_city' => 112,
            'village', 'stronghold' => 80,
            default => 48,
        };
    }

    private static function defaultRadius(string $type): int{
        return match($type){
            'mansion', 'monument', 'ancient_city', 'end_city' => 64,
            'village', 'stronghold', 'fortress', 'bastion' => 48,
            default => 32,
        };
    }

    private static function guessDimension(World $world): string{
        $name = strtolower($world->getFolderName());
        return str_contains($name, 'nether') || str_contains($name, 'hell') ? 'nether' : 'overworld';
    }
}
