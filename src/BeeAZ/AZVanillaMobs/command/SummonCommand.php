<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use BeeAZ\AZVanillaMobs\Main;

class SummonCommand extends Command implements PluginOwned {
    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->__traitConstruct($plugin);
        parent::__construct("summon", "Summon an AZVanillaMob", "/summon <mob> [amount]", ["azsummon"]);
        $this->setPermission("azvanillamobs.command.summon");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) return false;
        if (!$sender instanceof Player) {
            $sender->sendMessage("Please use this command in-game.");
            return false;
        }

        if (count($args) < 1) {
            $sender->sendMessage("Usage: /summon <mob> [amount]");
            return false;
        }

        $mobName = strtolower($args[0]);
        $amount = isset($args[1]) ? max(1, (int)$args[1]) : 1;

        $targetClass = null;
        foreach ($this->plugin->spawnerLists as $list) {
            foreach ($list as $class) {
                $path = explode('\\', $class);
                $name = strtolower(array_pop($path));
                if ($name === $mobName || "minecraft:" . $name === $mobName) {
                    $targetClass = $class;
                    break 2;
                }
            }
        }

        if ($targetClass === null) {
            $sender->sendMessage("§cMob '$mobName' not found!");
            return false;
        }

        $aquaticClasses = [
            \BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Dolphin::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\GlowSquid::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Squid::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Turtle::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Cod::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class,
        ];
        $isAquatic = in_array($targetClass, $aquaticClasses, true);

        $flyingClasses = [
            \BeeAZ\AZVanillaMobs\entity\overworld\Vex::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Allay::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Bat::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Bee::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Phantom::class,
            \BeeAZ\AZVanillaMobs\entity\nether\Ghast::class,
            \BeeAZ\AZVanillaMobs\entity\nether\Blaze::class,
            \BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon::class,
        ];
        $isFlying = in_array($targetClass, $flyingClasses, true);

        for ($i = 0; $i < $amount; $i++) {
            $spawnPos = clone $sender->getPosition();

            $isSenderInWater = $sender->getWorld()->getBlock($sender->getPosition()) instanceof \pocketmine\block\Water || $sender->getWorld()->getBlock($sender->getPosition()->add(0, 1, 0)) instanceof \pocketmine\block\Water;
            if ($isAquatic && !$isSenderInWater) {

                $foundWater = null;
                for ($x = -8; $x <= 8; $x++) {
                    for ($y = -4; $y <= 4; $y++) {
                        for ($z = -8; $z <= 8; $z++) {
                            $pos = $sender->getPosition()->add($x, $y, $z);
                            if ($sender->getWorld()->getBlock($pos) instanceof \pocketmine\block\Water) {
                                $foundWater = $pos;
                                break 3;
                            }
                        }
                    }
                }
                if ($foundWater !== null) {
                    $spawnPos = $foundWater->add(0.5, 0.5, 0.5);
                }
            } elseif ($isFlying) {
                $spawnPos->y += 2.0;
            }

            $entity = new $targetClass(Location::fromObject($spawnPos, $sender->getWorld(), mt_rand(0, 360), 0));
            $entity->spawnToAll();
        }

        $sender->sendMessage("§aSummoned $amount x $mobName.");
        return true;
    }
}
