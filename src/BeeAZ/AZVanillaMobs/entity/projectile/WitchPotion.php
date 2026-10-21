<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\projectile;

use pocketmine\entity\projectile\Throwable;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\entity\Location;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\player\Player;
use pocketmine\world\particle\PotionSplashParticle;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;

class WitchPotion extends Throwable {

    public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null) {
        parent::__construct($location, $shootingEntity, $nbt);
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:splash_potion";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(0.25, 0.25);
    }

    protected function getInitialDragMultiplier(): float {
        return 0.05;
    }

    protected function getInitialGravity(): float {
        return 0.05;
    }

    protected function onHit(ProjectileHitEvent $event): void {
        $world = $this->getWorld();
        $location = $this->getLocation();

        $world->addParticle($location, new PotionSplashParticle(\pocketmine\color\Color::fromRGB(46, 139, 87)));

        $pk = new PlaySoundPacket();
        $pk->soundName = "random.glass";
        $pk->x = $location->x;
        $pk->y = $location->y;
        $pk->z = $location->z;
        $pk->volume = 1.0;
        $pk->pitch = 1.0;
        $world->broadcastPacketToViewers($location, $pk);

        foreach ($world->getNearbyEntities($this->boundingBox->expandedCopy(4.0, 4.0, 4.0)) as $entity) {
            if ($entity instanceof Player && !$entity->isCreative() && !$entity->isSpectator()) {

                $entity->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), 120, 0));

                $entity->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 120, 1));
            }
        }
    }
}
