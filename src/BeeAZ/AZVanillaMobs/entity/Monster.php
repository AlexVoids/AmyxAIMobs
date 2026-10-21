<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity;

use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

abstract class Monster extends BaseMob
{
    protected int $attackDelay = 0;

    public function __construct(Location $location, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $nbt);

        if (($nbt===null || $nbt->getTag("AmyxArmor")===null)
            && in_array($this->getShortEntityName(),['zombie','zombievillager','husk','drowned','skeleton','stray','witherskeleton','piglin','piglinbrute','zombifiedpiglin'],true)
            && mt_rand(1, 100) <= 6) {
            $armors = [
                VanillaItems::LEATHER_CAP(), VanillaItems::IRON_HELMET(), VanillaItems::GOLDEN_HELMET(),
                VanillaItems::LEATHER_TUNIC(), VanillaItems::IRON_CHESTPLATE(), VanillaItems::GOLDEN_CHESTPLATE(),
                VanillaItems::LEATHER_PANTS(), VanillaItems::IRON_LEGGINGS(), VanillaItems::GOLDEN_LEGGINGS(),
                VanillaItems::LEATHER_BOOTS(), VanillaItems::IRON_BOOTS(), VanillaItems::GOLDEN_BOOTS(),
            ];
            if (mt_rand(1, 10) <= 3) $this->getArmorInventory()->setHelmet($armors[mt_rand(0, 2)]);
            if (mt_rand(1, 10) <= 3) $this->getArmorInventory()->setChestplate($armors[mt_rand(3, 5)]);
            if (mt_rand(1, 10) <= 3) $this->getArmorInventory()->setLeggings($armors[mt_rand(6, 8)]);
            if (mt_rand(1, 10) <= 3) $this->getArmorInventory()->setBoots($armors[mt_rand(9, 11)]);
        }
    }

    public function attack(EntityDamageEvent $source): void
    {
        parent::attack($source);
        if ($source->isCancelled() || !($source instanceof EntityDamageByEntityEvent)) {
            return;
        }

        $damager = $source->getDamager();
        if ($damager instanceof Living && $this->canUseAsTarget($damager) && !$this->isFriendlyEntity($damager)) {
            $this->setCombatTarget($damager, 500);
            $this->broadcastRevenge($damager);
        }
    }

    /** Neutral/group mobs alert nearby allies when attacked. */
    protected function broadcastRevenge(Living $attacker): void
    {
        $name = $this->getShortEntityName();
        $radius = match ($name) {
            'zombifiedpiglin' => 20.0,
            'piglin', 'piglinbrute', 'hoglin' => 16.0,
            default => 0.0,
        };
        if ($radius <= 0.0) {
            return;
        }

        foreach ($this->getNearbyLiving($radius, 10.0) as $nearby) {
            if (!$nearby instanceof Monster) {
                continue;
            }
            $nearName = $nearby->getShortEntityName();
            $sameGroup = $name === 'zombifiedpiglin'
                ? $nearName === 'zombifiedpiglin'
                : (in_array($name, ['piglin', 'piglinbrute'], true)
                    ? in_array($nearName, ['piglin', 'piglinbrute'], true)
                    : $nearName === $name);
            if ($sameGroup) {
                $nearby->rememberAttacker($attacker, 500);
                $nearby->setCombatTarget($attacker, 500);
            }
        }
    }

    protected function calculateAI(): void
    {
        $this->attackDelay = max(0, $this->attackDelay - $this->getAIUpdateInterval());
        $this->setBehaviorSpeedMultiplier(1.0);

        // Species-specific fears have higher priority than combat.
        $threat = $this->findAvoidanceThreat();
        if ($threat !== null) {
            $this->clearCombatTarget();
            $this->targetPosition = $this->chooseEscapeDestination($threat, 9.0);
            $this->setBehaviorSpeedMultiplier(1.2);
            return;
        }

        // Revenge remains the highest priority. Otherwise periodically compare
        // the current target with newly appeared valid targets. This prevents a
        // zombie from following one villager forever while a much closer target
        // is standing beside it.
        $target = $this->getRevengeTarget();
        if ($target === null) {
            $current = $this->getCombatTarget();
            if ($current !== null && !$this->isValidNaturalTarget($current)) {
                $current = null;
            }
            $target = $this->selectPreferredTarget($current);
        }

        if ($target !== null) {
            $this->setCombatTarget($target, 340);
            $distanceSquared = $this->location->distanceSquared($target->getLocation());
            $this->setBehaviorSpeedMultiplier($this->getChaseSpeedMultiplier());

            if ($distanceSquared <= $this->getMeleeReachSquared() && $this->attackDelay <= 0) {
                if ($this->performMeleeAttack($target, $this->getAttackDamage(), $this->getMeleeReachSquared())) {
                    $this->applyOnHitEffects($target);
                    $this->attackDelay = $this->getAttackCooldownTicks();
                }
            }
            return;
        }

        $this->clearCombatTarget();

        if ($this->isAquatic() && !$this->isSwimming()) {
            $this->targetPosition = $this->findNearbyWaterForMonster(8, 4) ?? $this->chooseRandomDestination(3.0, 1.0);
            return;
        }

        if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 1.5 || mt_rand(1, 100) <= 6) {
            $this->targetPosition = mt_rand(1, 100) <= 70 ? $this->chooseRandomDestination($this->isFlying() ? 12.0 : 10.0, 4.0) : null;
        }
    }

    /**
     * Chooses between the current target and the best currently visible target.
     * A target switch is made only when the replacement is clearly better, so
     * mobs do not jitter between two equally distant entities.
     */
    protected function selectPreferredTarget(?Living $current): ?Living
    {
        if (!$this->isNaturallyHostileNow()) {
            return $current;
        }

        $candidate = $this->selectNaturalTarget();
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null || $candidate === $current) {
            return $current;
        }

        $currentScore = $this->getTargetScore($current);
        $candidateScore = $this->getTargetScore($candidate);
        $followRangeSq = $this->getFollowRange() ** 2;

        // Switch aggressively when the current target has escaped far away,
        // disappeared behind obstacles, or the new target is substantially
        // closer after species priority has been applied.
        if ($this->location->distanceSquared($current->getLocation()) > $followRangeSq * 0.82) {
            return $candidate;
        }
        if (!$this->canSeeEntity($current) && $this->canSeeEntity($candidate)) {
            return $candidate;
        }

        $isZombieFamily = in_array($this->getShortEntityName(), ['zombie', 'zombievillager', 'husk', 'drowned'], true);
        if($isZombieFamily){
            $currentDistance = $this->location->distanceSquared($current->getLocation());
            $candidateDistance = $this->location->distanceSquared($candidate->getLocation());
            // Zombies reconsider nearby villagers immediately. A clearly
            // closer target wins even while the old target is still visible.
            if($candidateDistance + 4.0 < $currentDistance){
                return $candidate;
            }
        }

        $factor = $isZombieFamily ? 0.96 : 0.82;
        return $candidateScore < $currentScore * $factor ? $candidate : $current;
    }

    protected function getTargetScore(Living $entity): float
    {
        return $this->location->distanceSquared($entity->getLocation()) * $this->getTargetPriorityMultiplier($entity);
    }

    protected function selectNaturalTarget(): ?Living
    {
        $radius = $this->getFollowRange();
        $mustSeeDefault = true;
        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($this->getNearbyLiving($radius, min(18.0, $radius)) as $entity) {
            if (!$this->isValidNaturalTarget($entity)) {
                continue;
            }
            if ($this->requiresLineOfSightFor($entity) && !$this->canSeeEntity($entity)) {
                continue;
            }
            $distance = $this->location->distanceSquared($entity->getLocation());
            $score = $distance * $this->getTargetPriorityMultiplier($entity);
            if ($score < $bestScore) {
                $best = $entity;
                $bestScore = $score;
            }
        }
        return $best;
    }

    protected function getFollowRange(): float
    {
        return match ($this->getShortEntityName()) {
            'warden' => 30.0,
            'enderman' => 32.0,
            'ghast' => 32.0,
            'zombie', 'zombievillager', 'husk', 'drowned' => 35.0,
            default => 24.0,
        };
    }

    protected function isNaturallyHostileNow(): bool
    {
        return match ($this->getShortEntityName()) {
            'zombifiedpiglin', 'enderman' => false,
            'spider', 'cavespider' => $this->isDarkEnvironment(),
            default => true,
        };
    }

    protected function isDarkEnvironment(): bool
    {
        $time = $this->getWorld()->getTimeOfDay();
        if ($time >= \pocketmine\world\World::TIME_NIGHT) {
            return true;
        }
        $x = $this->location->getFloorX();
        $z = $this->location->getFloorZ();
        return $this->getWorld()->getHighestBlockAt($x, $z) > $this->location->getFloorY() + 1;
    }

    protected function isFriendlyEntity(Living $entity): bool
    {
        if (!$entity instanceof BaseMob) {
            return false;
        }
        $self = $this->getShortEntityName();
        $other = $entity->getShortEntityName();

        // Vanilla factions do not treat every undead mob as one team. Zombies and
        // skeletons may retaliate against one another after friendly fire, while
        // close variants of the same family usually stay allied.
        $zombieFamily = ['zombie', 'zombievillager', 'husk', 'drowned'];
        if (in_array($self, $zombieFamily, true) && in_array($other, $zombieFamily, true)) {
            return true;
        }
        $skeletonFamily = ['skeleton', 'stray'];
        if (in_array($self, $skeletonFamily, true) && in_array($other, $skeletonFamily, true)) {
            return true;
        }
        $illagers = ['pillager', 'vindicator', 'evoker', 'ravager', 'vex'];
        if (in_array($self, $illagers, true) && in_array($other, $illagers, true)) {
            return true;
        }
        $piglins = ['piglin', 'piglinbrute'];
        if (in_array($self, $piglins, true) && in_array($other, $piglins, true)) {
            return true;
        }
        return $self === $other;
    }

    protected function isValidNaturalTarget(Living $entity): bool
    {
        if (!$this->canUseAsTarget($entity) || $this->isFriendlyEntity($entity)) {
            return false;
        }

        $self = $this->getShortEntityName();
        $other = $entity instanceof BaseMob ? $entity->getShortEntityName() : ($entity instanceof Player ? 'player' : 'living');
        $isPlayer = $entity instanceof Player;
        $isVillager = in_array($other, ['villager', 'wanderingtrader'], true);
        $isGolem = in_array($other, ['irongolem', 'snowgolem'], true);
        $isBabyTurtle = $other === 'turtle' && $entity instanceof \BeeAZ\AZVanillaMobs\entity\Animal && $entity->age < 0 && !$entity->isInsideOfWater();

        return match ($self) {
            'zombie', 'zombievillager', 'husk', 'drowned' => $isPlayer || $isVillager || $isGolem || $isBabyTurtle,
            'skeleton', 'stray' => $isPlayer || $isGolem || $isBabyTurtle,
            'creeper' => $isPlayer,
            'spider', 'cavespider' => $isPlayer || $isGolem,
            'silverfish', 'slime', 'magmacube', 'phantom', 'endermite', 'shulker' => $isPlayer || $isGolem,
            'witch' => $isPlayer || $other === 'irongolem',
            'vindicator', 'evoker', 'pillager', 'ravager', 'vex' => $isPlayer || $isVillager || $isGolem,
            'guardian', 'elderguardian' => $isPlayer || in_array($other, ['squid', 'glowsquid', 'dolphin', 'axolotl'], true),
            'piglin' => ($isPlayer && !$this->playerHasGoldArmor($entity)) || in_array($other, ['witherskeleton', 'hoglin'], true),
            'piglinbrute' => $isPlayer || $other === 'witherskeleton',
            'hoglin' => $isPlayer,
            'zoglin' => $other !== 'zoglin' && $other !== 'creeper' && $other !== 'armorstand',
            'blaze', 'ghast', 'witherskeleton' => $isPlayer || $isGolem || in_array($other, ['piglin', 'piglinbrute'], true),
            'warden' => false,
            'enderdragon' => $isPlayer,
            default => $isPlayer,
        };
    }

    protected function playerHasGoldArmor(Player $player): bool
    {
        foreach ($player->getArmorInventory()->getContents() as $item) {
            $typeId = $item->getTypeId();
            if ($typeId === VanillaItems::GOLDEN_HELMET()->getTypeId()
                || $typeId === VanillaItems::GOLDEN_CHESTPLATE()->getTypeId()
                || $typeId === VanillaItems::GOLDEN_LEGGINGS()->getTypeId()
                || $typeId === VanillaItems::GOLDEN_BOOTS()->getTypeId()) {
                return true;
            }
        }
        return false;
    }

    protected function requiresLineOfSightFor(Living $entity): bool
    {
        $self = $this->getShortEntityName();
        $other = $entity instanceof BaseMob ? $entity->getShortEntityName() : '';
        if (in_array($self, ['zombie', 'zombievillager', 'husk', 'drowned'], true)
            && in_array($other, ['villager', 'wanderingtrader'], true)) {
            return false;
        }
        if ($self === 'warden') {
            return false;
        }
        return true;
    }

    protected function getTargetPriorityMultiplier(Living $entity): float
    {
        $self = $this->getShortEntityName();
        $other = $entity instanceof BaseMob ? $entity->getShortEntityName() : ($entity instanceof Player ? 'player' : 'living');

        if (in_array($self, ['zombie', 'zombievillager', 'husk', 'drowned'], true)) {
            return match ($other) {
                'player' => 0.85,
                'villager', 'wanderingtrader' => 0.90,
                'irongolem', 'snowgolem' => 1.0,
                default => 1.15,
            };
        }
        return $other === 'player' ? 0.9 : 1.0;
    }

    protected function findAvoidanceThreat(): ?Living
    {
        $self = $this->getShortEntityName();
        return match ($self) {
            'skeleton', 'stray' => $this->findNearestLiving(
                fn(Living $entity): bool => $entity instanceof BaseMob && $entity->getShortEntityName() === 'wolf',
                6.0,
                false
            ),
            'creeper' => $this->findNearestLiving(
                fn(Living $entity): bool => $entity instanceof BaseMob && in_array($entity->getShortEntityName(), ['cat', 'ocelot'], true),
                6.0,
                false
            ),
            'piglin', 'piglinbrute' => $this->findNearestLiving(
                fn(Living $entity): bool => $entity instanceof BaseMob && in_array($entity->getShortEntityName(), ['zombifiedpiglin', 'zoglin'], true),
                6.0,
                false
            ),
            default => null,
        };
    }

    protected function getMeleeReachSquared(): float
    {
        return match ($this->getShortEntityName()) {
            'irongolem', 'ravager', 'hoglin', 'zoglin', 'warden' => 4.5,
            default => 2.8,
        };
    }

    protected function getAttackCooldownTicks(): int
    {
        return match ($this->getShortEntityName()) {
            'hoglin', 'zoglin' => 40,
            'warden' => 18,
            default => 20,
        };
    }

    protected function getChaseSpeedMultiplier(): float
    {
        return match ($this->getShortEntityName()) {
            'enderman' => 1.5,
            'creeper' => 1.25,
            'warden' => 1.2,
            default => 1.0,
        };
    }

    protected function applyOnHitEffects(Living $target): void
    {
        $effect = null;
        $duration = 0;
        switch ($this->getShortEntityName()) {
            case 'cavespider':
                $effect = \pocketmine\entity\effect\VanillaEffects::POISON();
                $duration = 100;
                break;
            case 'witherskeleton':
                $effect = \pocketmine\entity\effect\VanillaEffects::WITHER();
                $duration = 200;
                break;
            case 'husk':
                $effect = \pocketmine\entity\effect\VanillaEffects::HUNGER();
                $duration = 140;
                break;
        }
        if ($effect !== null) {
            $target->getEffects()->add(new \pocketmine\entity\effect\EffectInstance($effect, $duration, 0));
        }
    }

    protected function findNearbyWaterForMonster(int $radius, int $verticalRadius): ?\pocketmine\math\Vector3
    {
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        for ($x = -$radius; $x <= $radius; $x += 2) {
            for ($y = -$verticalRadius; $y <= $verticalRadius; $y++) {
                for ($z = -$radius; $z <= $radius; $z += 2) {
                    $position = $this->location->add($x, $y, $z);
                    if ($this->getWorld()->getBlock($position) instanceof \pocketmine\block\Water) {
                        $distance = $this->location->distanceSquared($position);
                        if ($distance < $bestDistance) {
                            $best = $position;
                            $bestDistance = $distance;
                        }
                    }
                }
            }
        }
        return $best;
    }

    public function getXpDropAmount(): int
    {
        return 5;
    }
}
