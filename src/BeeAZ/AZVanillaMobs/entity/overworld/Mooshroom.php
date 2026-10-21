<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\overworld;

/** Red mooshroom. Natural spawning is restricted to mushroom fields. */
class Mooshroom extends Cow
{
    public static function getNetworkTypeId(): string
    {
        return "minecraft:mooshroom";
    }
}
