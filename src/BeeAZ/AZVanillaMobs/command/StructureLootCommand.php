<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\structure\StructureScanTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

final class StructureLootCommand extends Command implements PluginOwned{
    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    public function __construct(Main $plugin){
        $this->__traitConstruct($plugin);
        parent::__construct('structureloot', 'Заполнить ванильным лутом сундуки структур на всей карте', '/structureloot [overworld|nether|end]', ['lootstructures', 'лутструктур']);
        $this->setPermission('azvanillamobs.command.structureloot');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool{
        if(!$this->testPermission($sender)) return false;
        if(!$sender instanceof Player){
            $sender->sendMessage('§cЭту команду нужно использовать в нужном игровом мире.');
            return false;
        }
        $argument = strtolower((string) ($args[0] ?? ''));
        $worldName = strtolower($sender->getWorld()->getFolderName());
        if(in_array($argument, ['nether', 'ад'], true) || ($argument === '' && (str_contains($worldName, 'nether') || str_contains($worldName, 'hell')))){
            $dimension = 'nether';
        }elseif(in_array($argument, ['end', 'the_end', 'край', 'энд'], true) || ($argument === '' && (str_contains($worldName, 'the_end') || str_contains($worldName, 'end')))){
            $dimension = 'end';
        }else{
            $dimension = 'overworld';
        }
        if(!StructureScanTask::start($this->getOwningPlugin(), $sender, $sender->getWorld(), $dimension, 'loot')){
            $sender->sendMessage('§cДля этого мира уже выполняется заполнение сундуков.');
            return false;
        }
        $sender->sendMessage('§aЗапущен поиск сундуков структур по карте от -2000 до 2000.');
        $sender->sendMessage('§7Обычные сундуки не затрагиваются. Распознанные сундуки структур очищаются и получают новый богатый лут только при этой команде.');
        return true;
    }
}
