<?php
declare(strict_types=1);
namespace AmyxAIMobsTests;
/** Keep only movement idle so a deterministic daylight test stays under the same sky. */
final class StationaryZombie extends \BeeAZ\AZVanillaMobs\entity\overworld\Zombie{
    public function getShortEntityName(): string{ return 'zombie'; }
    protected function calculateAI(): void{}
}
