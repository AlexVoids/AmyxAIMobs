<?php
declare(strict_types=1);
namespace BeeAZ\AZVanillaMobs\command;
use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use pocketmine\command\{Command,CommandSender};
final class MobsAdminCommand extends Command{
    public function __construct(private Main $plugin){
        parent::__construct('aimobs','Статистика мобов и перезагрузка настроек','/aimobs [status|reload]');
        $this->setPermission('amyxaimobs.admin');
    }
    public function execute(CommandSender $sender,string $label,array $args): bool{
        if(!$this->testPermission($sender)){ return true; }
        if(($args[0]??'status')==='reload'){
            $this->plugin->reloadConfig();
            $sender->sendMessage('§a[AmyxAIMobs] Настройки спавна и навигации перечитаны.');return true;
        }
        $sender->sendMessage('§b[AmyxAIMobs] §fКонфиг: '.$this->plugin->getDataFolder().'config.yml');
        foreach($this->plugin->getServer()->getWorldManager()->getWorlds() as $world){
            $counts=[];
            foreach($world->getEntities() as $mob){ if($mob instanceof BaseMob && !$mob->isClosed()){ $name=$mob->getShortEntityName();$counts[$name]=($counts[$name]??0)+1; } }
            ksort($counts);$items=[];foreach($counts as $name=>$count){ $items[]=$name.': '.$count; }
            $sender->sendMessage('§b'.$world->getFolderName().' §f('.array_sum($counts).'): '.implode(', ',$items));
        }
        return true;
    }
}
