<?php
declare(strict_types=1);
namespace AmyxAIMobsTests;
final class StationarySkeleton extends \BeeAZ\AZVanillaMobs\entity\overworld\Skeleton{
    public function getShortEntityName(): string{ return 'skeleton'; }
    protected function calculateAI(): void{}
}
