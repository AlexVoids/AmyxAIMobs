<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\entity;

use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\particle\HeartParticle;

abstract class Animal extends BaseMob
{
    public int $inLoveTicks = 0;
    public int $age = 0;
    public int $panicTicks = 0;
    protected ?Living $panicSource = null;

    public function __construct(\pocketmine\entity\Location $location, ?\pocketmine\nbt\tag\CompoundTag $nbt = null)
    {
        parent::__construct($location, $nbt);
        if ($nbt !== null) {
            $this->inLoveTicks = $nbt->getInt("InLove", 0);
            $this->age = $nbt->getInt("Age", 0);
            if($this->age<0){ $this->setScale(0.5); }
        }
    }

    public function saveNBT(): \pocketmine\nbt\tag\CompoundTag
    {
        $nbt = parent::saveNBT();
        $nbt->setInt("InLove", $this->inLoveTicks);
        $nbt->setInt("Age", $this->age);
        return $nbt;
    }

    protected function getBreedingItem(): Item
    {
        return VanillaItems::WHEAT();
    }

    public function isBreedingItem(Item $item): bool
    {
        return $item->getTypeId() === $this->getBreedingItem()->getTypeId();
    }

    protected function shouldPanicWhenHurt(): bool
    {
        // These mobs have their own retaliation state in their entity classes.
        return !in_array($this->getShortEntityName(), ['wolf', 'bee'], true);
    }

    public function startPanic(?Living $source, int $ticks = 100): void
    {
        $this->panicTicks = max($this->panicTicks, $ticks);
        $this->panicSource = $source;
        $this->clearCombatTarget();
        $this->setBehaviorSpeedMultiplier($this->getPanicSpeedMultiplier());

        if ($source !== null && $this->canUseAsTarget($source)) {
            $this->targetPosition = $this->chooseEscapeDestination($source, $this->isFlying() ? 16.0 : 16.0);
        } else {
            $this->targetPosition = $this->chooseRandomDestination($this->isFlying() ? 16.0 : 14.0, 4.0);
        }
    }

    protected function getPanicSpeedMultiplier(): float
    {
        return $this->getShortEntityName() === 'frog' ? 2.0 : 1.35;
    }

    public function onUpdate(int $currentTick): bool
    {
        if ($this->inLoveTicks > 0) {
            $this->inLoveTicks--;
        }
        if ($this->panicTicks > 0) {
            $this->panicTicks--;
            if ($this->panicTicks === 0) {
                $this->panicSource = null;
                $this->setBehaviorSpeedMultiplier(1.0);
            }
        }
        if ($this->age < 0) {
            $this->age++;
            if ($this->age >= 0) {
                $this->setScale(1.0);
            }
        } elseif ($this->age > 0) {
            $this->age--;
        }
        return parent::onUpdate($currentTick);
    }

    protected function isBehaviorActive(): bool
    {
        return parent::isBehaviorActive() || $this->panicTicks > 0 || $this->inLoveTicks > 0;
    }

    public function attack(EntityDamageEvent $source): void
    {
        parent::attack($source);
        if ($source->isCancelled() || !$this->shouldPanicWhenHurt()) {
            return;
        }

        $attacker = null;
        if ($source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Living) {
            $attacker = $source->getDamager();
        }
        $this->startPanic($attacker, 100);
    }

    public function onInteract(Player $player, Vector3 $clickPos): bool
    {
        $item = $player->getInventory()->getItemInHand();

        if ($this->age === 0 && $this->inLoveTicks <= 0 && $this->isBreedingItem($item)) {
            $this->inLoveTicks = 600;
            if($player->hasFiniteResources()){
                $item->pop();
                $player->getInventory()->setItemInHand($item);
            }
            $this->getWorld()->addParticle($this->getLocation()->add(0, $this->getEyeHeight() + 0.5, 0), new HeartParticle(2));
            return true;
        }

        if ($this->age < 0 && $this->isBreedingItem($item)) {
            $this->age += max(1, (int) abs($this->age * 0.1));
            if ($this->age >= 0) {
                $this->age = 0;
                $this->setScale(1.0);
            }
            if($player->hasFiniteResources()){
                $item->pop();
                $player->getInventory()->setItemInHand($item);
            }
            $this->getWorld()->addParticle($this->getLocation()->add(0, $this->getEyeHeight() + 0.5, 0), new HeartParticle(2));
            return true;
        }

        return parent::onInteract($player, $clickPos);
    }

    protected function calculateAI(): void
    {
        // Damage panic has a higher priority than food, breeding and wandering.
        if ($this->panicTicks > 0 || $this->isOnFire()) {
            $this->setBehaviorSpeedMultiplier($this->getPanicSpeedMultiplier());
            if ($this->panicSource !== null && $this->canUseAsTarget($this->panicSource)) {
                if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 4.0 || mt_rand(1, 100) <= 12) {
                    $this->targetPosition = $this->chooseEscapeDestination($this->panicSource, $this->isFlying() ? 16.0 : 16.0);
                }
            } elseif ($this->targetPosition === null || mt_rand(1, 100) <= 12) {
                $this->targetPosition = $this->chooseRandomDestination($this->isFlying() ? 16.0 : 14.0, 4.0);
            }
            return;
        }
        $this->setBehaviorSpeedMultiplier(1.0);

        // Aquatic creatures prefer remaining in water, and return to it when stranded.
        if ($this->isSwimming()) {
            if ($this->targetPosition === null || mt_rand(1, 100) <= 20) {
                $this->targetPosition = $this->chooseRandomDestination(11.0, 3.0);
            }
            return;
        }

        if ($this->isAquatic() && !$this->isSwimming()) {
            $water = $this->findNearbyWater(8, 4);
            $this->targetPosition = $water ?? $this->chooseRandomDestination(3.0, 1.0);
            return;
        }

        // Breed with the closest compatible adult in love mode.
        if ($this->inLoveTicks > 0 && $this->age === 0) {
            $mate = $this->findNearestLiving(
                fn(Living $entity): bool => $entity instanceof Animal
                    && get_class($entity) === static::class
                    && $entity->inLoveTicks > 0
                    && $entity->age === 0,
                8.0,
                false
            );
            if ($mate instanceof Animal) {
                $this->targetPosition = clone $mate->getLocation();
                if ($this->location->distanceSquared($mate->getLocation()) < 4.0 && $this->canSeeEntity($mate)) {
                    $this->breedWith($mate);
                }
                return;
            }
        }

        // Follow a player holding the species' temptation item.
        $targetPlayer = null;
        $nearestDistance = 100.0;
        foreach ($this->getWorld()->getPlayers() as $player) {
            $distance = $this->location->distanceSquared($player->getLocation());
            if ($distance < $nearestDistance && $this->isBreedingItem($player->getInventory()->getItemInHand()) && $this->canSeeEntity($player)) {
                $nearestDistance = $distance;
                $targetPlayer = $player;
            }
        }
        if ($targetPlayer !== null) {
            $this->targetPosition = clone $targetPlayer->getLocation();
            $this->setBehaviorSpeedMultiplier(1.05);
            return;
        }

        // Young land animals stay with a nearby adult of their own species.
        if($this->age<0){
            $adult=$this->findNearestLiving(fn(Living $entity): bool=>$entity instanceof Animal && $entity::class===static::class && $entity->age>=0,12.0,false);
            if($adult!==null){
                $this->targetPosition=$this->location->distanceSquared($adult->getLocation())>9.0 ? clone $adult->getLocation() : null;
                $this->setBehaviorSpeedMultiplier(1.1);
                return;
            }
        }

        if ($this->targetPosition === null || $this->location->distanceSquared($this->targetPosition) < 1.5 || mt_rand(1, 100) <= 6) {
            $this->targetPosition = mt_rand(1, 100) <= 65 ? $this->chooseRandomDestination(11.0, 3.0) : null;
        }
    }

    protected function findNearbyWater(int $radius, int $verticalRadius): ?Vector3
    {
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        for ($x = -$radius; $x <= $radius; $x += 2) {
            for ($y = -$verticalRadius; $y <= $verticalRadius; $y++) {
                for ($z = -$radius; $z <= $radius; $z += 2) {
                    $pos = $this->location->add($x, $y, $z);
                    if ($this->getWorld()->getBlock($pos) instanceof \pocketmine\block\Water) {
                        $distance = $this->location->distanceSquared($pos);
                        if ($distance < $bestDistance) {
                            $best = $pos;
                            $bestDistance = $distance;
                        }
                    }
                }
            }
        }
        return $best;
    }

    protected function breedWith(Animal $mate): void
    {
        $this->inLoveTicks = 0;
        $mate->inLoveTicks = 0;
        $this->age = 6000;
        $mate->age = 6000;

        $class = static::class;
        $baby = new $class(\pocketmine\entity\Location::fromObject($this->getPosition(), $this->getWorld()));
        $baby->age = -24000;
        $baby->setScale(0.5);
        $baby->spawnToAll();
        $this->getWorld()->addParticle($this->getLocation()->add(0, 1, 0), new HeartParticle(2));
    }

    public function getXpDropAmount(): int
    {
        return $this->age < 0 ? 0 : mt_rand(1, 3);
    }
}
