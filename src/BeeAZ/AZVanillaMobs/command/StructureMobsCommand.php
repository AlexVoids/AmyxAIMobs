<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\structure\StructureRespawnTask;
use pocketmine\block\Water;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\world\World;

final class StructureMobsCommand extends Command implements PluginOwned{
    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    /** @var array<string, array<class-string, int>> */
    public const OVERWORLD_GROUPS = [
        "outpost" => [\BeeAZ\AZVanillaMobs\entity\overworld\Pillager::class => 5],
        "mansion" => [
            \BeeAZ\AZVanillaMobs\entity\overworld\Vindicator::class => 3,
            \BeeAZ\AZVanillaMobs\entity\overworld\Evoker::class => 1,
            \BeeAZ\AZVanillaMobs\entity\overworld\Vex::class => 2,
        ],
        "village" => [
            \BeeAZ\AZVanillaMobs\entity\overworld\Villager::class => 5,
            \BeeAZ\AZVanillaMobs\entity\overworld\IronGolem::class => 1,
        ],
        "monument" => [
            \BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class => 6,
            \BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class => 1,
        ],
        "swamp_hut" => [\BeeAZ\AZVanillaMobs\entity\overworld\Witch::class => 1],
        "mineshaft" => [\BeeAZ\AZVanillaMobs\entity\overworld\CaveSpider::class => 4],
        "stronghold" => [\BeeAZ\AZVanillaMobs\entity\overworld\Silverfish::class => 4],
        "ancient_city" => [\BeeAZ\AZVanillaMobs\entity\overworld\Warden::class => 1],
        "ocean_ruin" => [\BeeAZ\AZVanillaMobs\entity\overworld\Drowned::class => 4],
    ];

    /** @var array<string, array<class-string, int>> */
    public const NETHER_GROUPS = [
        "fortress" => [
            \BeeAZ\AZVanillaMobs\entity\nether\WitherSkeleton::class => 3,
            \BeeAZ\AZVanillaMobs\entity\nether\Blaze::class => 2,
            \BeeAZ\AZVanillaMobs\entity\nether\MagmaCube::class => 1,
        ],
        "bastion" => [
            \BeeAZ\AZVanillaMobs\entity\nether\Piglin::class => 4,
            \BeeAZ\AZVanillaMobs\entity\nether\PiglinBrute::class => 2,
            \BeeAZ\AZVanillaMobs\entity\nether\Hoglin::class => 2,
        ],
        "ruined_portal" => [\BeeAZ\AZVanillaMobs\entity\nether\ZombifiedPiglin::class => 4],
    ];

    public function __construct(Main $plugin, private string $dimension){
        $this->__traitConstruct($plugin);
        $overworld = $dimension === "overworld";
        parent::__construct(
            $overworld ? "mobsworld" : "mobsnether",
            $overworld ? "Spawn anchored mobs in an overworld structure" : "Spawn anchored mobs in a Nether structure",
            $overworld ? "/mobsworld <structure|list|all> [radius]" : "/mobsnether <structure|list|all> [radius]",
            $overworld ? ["mobsoverworld"] : ["mobshell"]
        );
        $this->setPermission("azvanillamobs.command.structure");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool{
        if(!$this->testPermission($sender)){
            return false;
        }
        if(!$sender instanceof Player){
            $sender->sendMessage("§cЭту команду нужно использовать в игре.");
            return false;
        }

        $groups = $this->dimension === "overworld" ? self::OVERWORLD_GROUPS : self::NETHER_GROUPS;
        if(isset($args[0]) && in_array(strtolower($args[0]), ["all", "все", "scan", "скан"], true)){
            if(!\BeeAZ\AZVanillaMobs\structure\StructureScanTask::start($this->getOwningPlugin(), $sender, $sender->getWorld(), $this->dimension, "mobs")){
                $sender->sendMessage("§cДля этого мира уже выполняется сканирование структур.");
                return false;
            }
            $sender->sendMessage("§aЗапущен поиск структур по всей карте от -2000 до 2000.");
            $sender->sendMessage("§7Мобы будут добавляться постепенно без зависания сервера. Повторный запуск не создаст дубликаты.");
            return true;
        }
        if(!isset($args[0]) || strtolower($args[0]) === "list"){
            $sender->sendMessage("§eДоступные структуры: §f" . implode(", ", array_keys($groups)));
            $sender->sendMessage("§7Для всей карты: /" . $this->getName() . " all. Для одной структуры встань внутри неё и укажи тип.");
            return true;
        }

        $structure = strtolower($args[0]);
        $aliases = [
            "авангард" => "outpost", "аванпост" => "outpost", "особняк" => "mansion",
            "деревня" => "village", "монумент" => "monument", "хижина" => "swamp_hut",
            "шахта" => "mineshaft", "крепость" => $this->dimension === "nether" ? "fortress" : "stronghold",
            "древний_город" => "ancient_city", "руины" => "ocean_ruin",
            "бастион" => "bastion", "портал" => "ruined_portal",
        ];
        $structure = $aliases[$structure] ?? $structure;
        if(!isset($groups[$structure])){
            $sender->sendMessage("§cНеизвестная структура. Используй /" . $this->getName() . " list");
            return false;
        }

        $radius = isset($args[1]) ? max(8, min(128, (int) $args[1])) : 32;
        $home = $sender->getPosition()->asVector3();
        $structureId = StructureRespawnTask::registerStructure($this->getOwningPlugin(), $sender->getWorld(), $structure, $home, $radius, $this->dimension);
        if($structureId === null){
            $sender->sendMessage("§eЭта структура уже зарегистрирована. Повторная команда не создаёт дубликаты; после гибели всей группы мобы восстановятся через 10 минут.");
            return true;
        }
        $spawned = self::spawnGroup($sender->getWorld(), $structure, $home, $radius, $this->dimension, $structureId);

        if($spawned === 0){
            StructureRespawnTask::removeStructure($this->getOwningPlugin(), $sender->getWorld()->getFolderName(), $structureId);
            $sender->sendMessage("§cНе нашлось подходящего места для спавна. Встань внутри свободной части структуры.");
            return false;
        }
        $sender->sendMessage("§aСоздано мобов: $spawned. Домашний радиус: $radius блоков.");
        $sender->sendMessage("§7Группа создаётся один раз. После смерти последнего моба восстановится через 10 минут.");
        $sender->sendMessage("§7Покинуть домашний радиус мобы могут только во время погони за целью.");
        return true;
    }

    public static function spawnGroup(World $world, string $structure, Vector3 $home, int $radius, string $dimension, ?string $structureId = null): int{
        $groups = $dimension === "nether" ? self::NETHER_GROUPS : self::OVERWORLD_GROUPS;
        if(!isset($groups[$structure])){
            return 0;
        }
        $structureId ??= StructureRespawnTask::makeStructureId($world->getFolderName(), $structure, $home);
        $spawned = 0;
        foreach($groups[$structure] as $class => $amount){
            for($i = 0; $i < $amount; ++$i){
                $position = self::findSpawnPosition($world, $home, $class, min(16, max(4, intdiv($radius, 2))));
                if($position === null){
                    continue;
                }
                try{
                    $nbt = CompoundTag::create()
                        ->setByte("StructureSpawned", 1)
                        ->setString("StructureId", $structureId)
                        ->setString("StructureType", $structure)
                        ->setFloat("StructureHomeX", (float) $home->x)
                        ->setFloat("StructureHomeY", (float) $home->y)
                        ->setFloat("StructureHomeZ", (float) $home->z)
                        ->setFloat("StructureRadius", (float) $radius);
                    $entity = new $class(Location::fromObject($position, $world, (float) mt_rand(0, 359), 0.0), $nbt);
                    $entity->spawnToAll();
                    ++$spawned;
                }catch(\Throwable){
                }
            }
        }
        return $spawned;
    }

    /** @param class-string $class */
    public static function findSpawnPosition(World $world, Vector3 $home, string $class, int $spread): ?Vector3{
        $aquatic = is_a($class, \BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class, true)
            || is_a($class, \BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class, true)
            || is_a($class, \BeeAZ\AZVanillaMobs\entity\overworld\Drowned::class, true);
        $flying = is_a($class, \BeeAZ\AZVanillaMobs\entity\overworld\Vex::class, true)
            || is_a($class, \BeeAZ\AZVanillaMobs\entity\nether\Blaze::class, true);

        for($try = 0; $try < 36; $try++){
            $x = (int) floor($home->x) + mt_rand(-$spread, $spread);
            $z = (int) floor($home->z) + mt_rand(-$spread, $spread);
            if(!$world->isChunkLoaded($x >> 4, $z >> 4) || !$world->isChunkGenerated($x >> 4, $z >> 4)){
                continue;
            }
            $centerY = (int) floor($home->y);
            for($offset = 0; $offset <= 14; $offset++){
                foreach($offset === 0 ? [0] : [$offset, -$offset] as $dy){
                    $y = $centerY + $dy;
                    if($y <= $world->getMinY() + 1 || $y >= $world->getMaxY() - 2){
                        continue;
                    }
                    $feet = $world->getBlockAt($x, $y, $z);
                    if($aquatic){
                        if($feet instanceof Water){
                            return new Vector3($x + 0.5, $y + 0.25, $z + 0.5);
                        }
                        continue;
                    }
                    $head = $world->getBlockAt($x, $y + 1, $z);
                    $floor = $world->getBlockAt($x, $y - 1, $z);
                    if(!$feet->isSolid() && !$head->isSolid() && $floor->isSolid()){
                        return new Vector3($x + 0.5, $y + ($flying ? 1.5 : 0.05), $z + 0.5);
                    }
                }
            }
        }
        return null;
    }
}
