<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\listener;

use BeeAZ\AZVanillaMobs\spawner\SpawnerTask;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPostChunkSendEvent;

/**
 * Queues chunks as soon as a client receives them. Structure detection is
 * throttled inside SpawnerTask, so large view distances do not scan every
 * chunk in the same server tick.
 */
final class ChunkPopulationListener implements Listener{
    public function __construct(private SpawnerTask $spawner){}

    public function onPlayerPostChunkSend(PlayerPostChunkSendEvent $event) : void{
        $player = $event->getPlayer();
        $this->spawner->queueStructureChunk(
            $player->getWorld(),
            $event->getChunkX(),
            $event->getChunkZ()
        );
    }
}
