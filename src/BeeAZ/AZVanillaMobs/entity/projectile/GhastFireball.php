<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity\projectile;

use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\entity\Location;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\world\Explosion;

class GhastFireball extends Projectile {

    public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null) {
        parent::__construct($location, $shootingEntity, $nbt);
    }

    public static function getNetworkTypeId(): string {
        return "minecraft:fireball";
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(1.0, 1.0);
    }

    protected function getInitialDragMultiplier(): float {
        return 0.0;
    }

    protected function getInitialGravity(): float {
        return 0.0;
    }

    protected function onHit(ProjectileHitEvent $event): void {
        $world = $this->getWorld();
        $location = $this->getLocation();

        $primeEv = new EntityPreExplodeEvent($this, 1.5);
        $plugin = \pocketmine\Server::getInstance()->getPluginManager()->getPlugin("AmyxAIMobs");
        $allowBlockDamage = $plugin instanceof \BeeAZ\AZVanillaMobs\Main
            && (bool) $plugin->getConfig()->get("explosion-block-damage", false)
            && class_exists(\pocketmine\network\mcpe\protocol\types\ProtocolInfo::class);
        $primeEv->setBlockBreaking($allowBlockDamage);
        $primeEv->call();

        if (!$primeEv->isCancelled()) {
            $explosion = new Explosion(\pocketmine\world\Position::fromObject($location, $world), $primeEv->getRadius(), $this);
            if ($primeEv->isBlockBreaking() && $allowBlockDamage) {
                $explosion->explodeA();
            }
            $explosion->explodeB();
        }
        $this->flagForDespawn();
    }
}
