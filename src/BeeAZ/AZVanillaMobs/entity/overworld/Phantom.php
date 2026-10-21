<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;

class Phantom extends Monster {

    private const STATE_CIRCLING = 0;
    private const STATE_SWOOPING = 1;
    private const STATE_RETREATING = 2;

    private int $phantomState = self::STATE_CIRCLING;
    private int $stateTicks = 0;
    private float $circleAngle = 0.0;
    private ?Vector3 $circleCenter = null;

    public static function getNetworkTypeId(): string {
        return "minecraft:phantom";
    }

    public function isFlying(): bool {
        return true;
    }

    public function getMovementSpeed(): float {
        return 0.35; 
    }

    public function getAttackDamage(): float {
        return 6.0; 
    }

    protected function calculateAI(): void {
        $nearest = null;
        $minDist = 1600; 

        foreach ($this->getWorld()->getPlayers() as $player) {
            if ($player->isCreative() || $player->isSpectator()) {
                continue;
            }

            $dist = $this->location->distanceSquared($player->getLocation());
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $player;
            }
        }

        if ($nearest !== null) {
            $this->circleCenter = null; 
            $playerPos = $nearest->getLocation();

            if ($this->phantomState === self::STATE_CIRCLING) {
                $this->stateTicks += 10;
                
                
                $this->circleAngle += 0.4;
                $radius = 12.0;
                $heightAbovePlayer = 15.0;
                
                $targetX = $playerPos->x + cos($this->circleAngle) * $radius;
                $targetZ = $playerPos->z + sin($this->circleAngle) * $radius;
                $targetY = $playerPos->y + $heightAbovePlayer;
                
                $this->targetPosition = new Vector3($targetX, $targetY, $targetZ);

                
                if ($this->stateTicks >= 100) {
                    $this->phantomState = self::STATE_SWOOPING;
                    $this->stateTicks = 0;
                }
            } elseif ($this->phantomState === self::STATE_SWOOPING) {
                
                $this->targetPosition = $playerPos->add(0, 1.0, 0);

                if ($this->location->distanceSquared($playerPos) < 3.0) {
                    
                    $ev = new EntityDamageByEntityEvent($this, $nearest, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->getAttackDamage());
                    $nearest->attack($ev);

                    
                    $this->phantomState = self::STATE_RETREATING;
                    $this->stateTicks = 0;
                }

                
                $this->stateTicks += 10;
                if ($this->stateTicks >= 160) {
                    $this->phantomState = self::STATE_RETREATING;
                    $this->stateTicks = 0;
                }
            } elseif ($this->phantomState === self::STATE_RETREATING) {
                
                $this->targetPosition = $playerPos->add(0, 18.0, 0);

                if ($this->location->y >= $playerPos->y + 15.0) {
                    $this->phantomState = self::STATE_CIRCLING;
                    $this->stateTicks = 0;
                }
            }
        } else {
            
            if ($this->circleCenter === null) {
                $this->circleCenter = clone $this->location;
            }

            $this->circleAngle += 0.2;
            $radius = 10.0;
            
            $targetX = $this->circleCenter->x + cos($this->circleAngle) * $radius;
            $targetZ = $this->circleCenter->z + sin($this->circleAngle) * $radius;
            
            $targetY = $this->circleCenter->y;
            $highestBlockY = $this->getWorld()->getHighestBlockAt((int)floor($targetX), (int)floor($targetZ));
            if ($highestBlockY !== null) {
                $targetY = max($targetY, $highestBlockY + 15.0);
            }

            $this->targetPosition = new Vector3($targetX, $targetY, $targetZ);
            $this->phantomState = self::STATE_CIRCLING;
            $this->stateTicks = 0;
        }
    }
}
