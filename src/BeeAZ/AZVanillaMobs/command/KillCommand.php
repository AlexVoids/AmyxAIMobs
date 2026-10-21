<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\entity\Entity;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use BeeAZ\AZVanillaMobs\Main;

class KillCommand extends Command implements PluginOwned {
    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->__traitConstruct($plugin);
        parent::__construct("azkill", "Kill all or specific entities (including projectiles)", "/azkill <@e|name>", ["killallmobs"]);
        $this->setPermission("azvanillamobs.command.kill");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) return false;

        if (count($args) < 1) {
            $sender->sendMessage("§cUsage: /azkill <@e|name>");
            return false;
        }

        $target = $args[0];
        $count = 0;

        $worlds = $this->plugin->getServer()->getWorldManager()->getWorlds();

        if ($target === "@e") {
            foreach ($worlds as $world) {
                foreach ($world->getEntities() as $entity) {
                    if ($entity instanceof Player) {
                        continue;
                    }
                    if (strpos(get_class($entity), "BeeAZ\\AZVanillaMobs\\") !== 0) {
                        continue;
                    }
                    $entity->close();
                    $count++;
                }
            }
            $sender->sendMessage("§a[AZVanillaMobs] Successfully removed all $count AZVanillaMobs entities (including projectiles) across all worlds!");
            return true;
        }

        $targetLower = strtolower($target);
        foreach ($worlds as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof Player) {
                    continue;
                }
                if (strpos(get_class($entity), "BeeAZ\\AZVanillaMobs\\") !== 0) {
                    continue;
                }

                $match = false;

                if (stripos($entity->getNameTag(), $target) !== false) {
                    $match = true;
                }

                if (!$match) {
                    $networkTypeId = strtolower($entity::getNetworkTypeId());
                    if (strpos($networkTypeId, "minecraft:") === 0) {
                        $networkTypeIdShort = substr($networkTypeId, 10);
                    } else {
                        $networkTypeIdShort = $networkTypeId;
                    }
                    if ($networkTypeId === $targetLower || $networkTypeIdShort === $targetLower) {
                        $match = true;
                    }
                }

                if (!$match) {
                    $classPath = explode('\\', get_class($entity));
                    $className = strtolower(array_pop($classPath));
                    if ($className === $targetLower) {
                        $match = true;
                    }
                }

                if ($match) {
                    $entity->close();
                    $count++;
                }
            }
        }

        $sender->sendMessage("§a[AZVanillaMobs] Successfully removed $count entities matching '$target'!");
        return true;
    }
}
