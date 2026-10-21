<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs;

use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntityDataHelper;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

class Main extends PluginBase implements Listener {
    private ?\pocketmine\item\Item $legacyWanderingTraderEgg = null;

    protected function onLoad(): void {
        // Essential 5.39.1 references ProtocolInfo from the wrong namespace in
        // UpdateSubChunkBlocksPacketEntry. Providing this alias restores normal
        // block updates, including creeper explosions, without modifying the core.
        $expected = "pocketmine\\network\\mcpe\\protocol\\types\\ProtocolInfo";
        $actual = "pocketmine\\network\\mcpe\\protocol\\ProtocolInfo";
        if (!class_exists($expected, false) && class_exists($actual)) {
            @class_alias($actual, $expected);
        }

        \BeeAZ\AZVanillaMobs\utils\CompatItemManager::bootstrap();
        $this->registerRemovedWanderingTraderEggMigration();
    }

    private const REMOVED_TRADER_EGG_TAG = "AZRemovedWanderingTraderEgg";

    /**
     * Old versions serialized the custom egg using this Bedrock ID. Returning
     * AIR here is unsafe because the same deserializer is used while building
     * the vanilla creative inventory. We therefore return a valid, marked
     * placeholder and remove it before inventories are synchronized.
     */
    private function registerRemovedWanderingTraderEggMigration(): void {
        $parser = \pocketmine\item\StringToItemParser::getInstance();
        try {
            $old = $parser->parse("wandering_trader_spawn_egg");
            if($old !== null && !$old->isNull()){
                $this->legacyWanderingTraderEgg = clone $old;
            }
        } catch (\Throwable) {}

        try {
            \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map(
                "minecraft:wandering_trader_spawn_egg",
                fn() => $this->createRemovedTraderEggPlaceholder()
            );
        } catch (\Throwable) {}
    }

    private function createRemovedTraderEggPlaceholder(): Item {
        $item = VanillaItems::PAPER();
        $item->setCustomName("§rУдалённое яйцо странствующего торговца");
        $item->getNamedTag()->setByte(self::REMOVED_TRADER_EGG_TAG, 1);
        return $item;
    }

    private function isRemovedTraderEggPlaceholder(Item $item): bool {
        return !$item->isNull() && $item->getNamedTag()->getByte(self::REMOVED_TRADER_EGG_TAG, 0) === 1;
    }

    private function purgeRemovedTraderEggs(Inventory $inventory): int {
        $removed = 0;
        foreach($inventory->getContents() as $slot => $item){
            if($this->isRemovedTraderEggPlaceholder($item)){
                $inventory->clear($slot);
                ++$removed;
            }
        }
        return $removed;
    }

    private function purgeCreativeMigrationEntries(): void {
        $creative = \pocketmine\inventory\CreativeInventory::getInstance();
        foreach($creative->getAll() as $item){
            if($item->isNull() || $this->isRemovedTraderEggPlaceholder($item)){
                $creative->remove($item);
            }
        }
        if($this->legacyWanderingTraderEgg !== null){
            try {
                while($creative->contains($this->legacyWanderingTraderEgg)){
                    $creative->remove($this->legacyWanderingTraderEgg);
                }
            } catch (\Throwable) {}
            $this->legacyWanderingTraderEgg = null;
        }
    }

    public function onPlayerLogin(PlayerLoginEvent $event): void {
        $player = $event->getPlayer();
        $removed = 0;
        $removed += $this->purgeRemovedTraderEggs($player->getInventory());
        $removed += $this->purgeRemovedTraderEggs($player->getArmorInventory());
        $removed += $this->purgeRemovedTraderEggs($player->getOffHandInventory());
        $removed += $this->purgeRemovedTraderEggs($player->getEnderInventory());
        if($removed > 0){
            $this->getLogger()->info("Удалено старых яиц странствующего торговца у " . $player->getName() . ": " . $removed);
        }
    }

    public function onInventoryOpen(InventoryOpenEvent $event): void {
        $this->purgeRemovedTraderEggs($event->getInventory());
    }

    protected function onEnable(): void {
        $legacy = $this->getServer()->getPluginManager()->getPlugin("AZVanillaMobs");
        if($legacy !== null){ throw new \RuntimeException("Удалите старый PHAR AZVanillaMobs перед установкой AmyxAIMobs: два плагина регистрируют одинаковые сущности."); }
        $this->importLegacyData();
        $this->saveDefaultConfig();
        $this->migrateSpawnConfig();
        $this->getLogger()->info("Настройки: " . $this->getDataFolder() . "config.yml");
        // Force creative initialization while the migration placeholder is
        // valid, then remove the placeholder and the old built-in egg before
        // any client receives the creative inventory packet.
        $this->purgeCreativeMigrationEntries();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\loot\LootManager(), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\EventListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\TradeListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\LeashListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\RidingListener($this), $this);

        $map = $this->getServer()->getCommandMap();
        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\SummonCommand($this));
        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\KillCommand($this));

        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\MobsAdminCommand($this));
        $this->registerEntities();
        $spawnerTask = new \BeeAZ\AZVanillaMobs\spawner\SpawnerTask($this);
        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\StructureMobsCommand($this, "overworld"));
        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\StructureMobsCommand($this, "nether"));
        $map->register("AmyxAIMobs", new \BeeAZ\AZVanillaMobs\command\StructureLootCommand($this));
        if(class_exists(\pocketmine\event\player\PlayerPostChunkSendEvent::class)){
            $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\ChunkPopulationListener($spawnerTask), $this);
        }
        $this->getScheduler()->scheduleRepeatingTask($spawnerTask, 20);
        // Check registered structure populations every five seconds. The same
        // object listens for the last member's death so the ten-minute timer
        // starts at the exact death event instead of at the next periodic scan.
        $structureRespawnTask = new \BeeAZ\AZVanillaMobs\structure\StructureRespawnTask($this);
        $this->getServer()->getPluginManager()->registerEvents($structureRespawnTask, $this);
        $this->getScheduler()->scheduleRepeatingTask($structureRespawnTask, 100);
        // LeashListener owns the single leash tick task. Older builds scheduled
        // it twice, causing unnecessary duplicate motion updates.
    }


    /**
     * Version 2.0.2 shipped very high caps and no biome weights. Existing
     * servers keep their old config after replacing a PHAR, so migrate those
     * values once instead of requiring users to delete the whole data folder.
     */
    /** Fill only missing keys. Administrator settings, including zero limits, win. */
    private function migrateSpawnConfig(): void {
        $defaults = yaml_parse(stream_get_contents($this->getResource("config.yml")));
        $merged = \BeeAZ\AZVanillaMobs\config\Settings::fillMissing($this->getConfig()->getAll(), $defaults);
        $merged['spawn-config-version']=12;
        if($merged!==$this->getConfig()->getAll()){
            $this->getConfig()->setAll($merged);
            $this->getConfig()->save();
        }
    }

    private function importLegacyData(): void {
        if(is_file($this->getDataFolder() . "config.yml")){ return; }
        $candidates=[
            dirname(rtrim($this->getDataFolder(),"/\\")) . "/AZVanillaMobs",
            rtrim($this->getServer()->getPluginPath(),"/\\") . "/AZVanillaMobs",
            $this->getServer()->getDataPath() . "plugin_data/AZVanillaMobs"
        ];
        $source=null;
        foreach(array_unique($candidates) as $candidate){ if(is_dir($candidate)){ $source=$candidate;break; } }
        if($source===null){ return; }
        @mkdir($this->getDataFolder(), 0777, true);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file){
            if(!$file->isFile() || $file->isLink()){ continue; }
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if(!in_array(strtolower($file->getExtension()), ["yml", "yaml", "json"], true)){ continue; }
            $target = $this->getDataFolder() . $relative;
            @mkdir(dirname($target), 0777, true);
            if(!is_file($target) && !copy($file->getPathname(), $target)){ throw new \RuntimeException("Не удалось перенести " . $relative); }
        }
        $this->getLogger()->info("Скопированы настройки и данные AZVanillaMobs; исходная папка сохранена.");
    }

    public array $spawnerLists = [
        'nether' => [],
        'the_end' => [],
        'overworld_hostile' => [],
        'overworld_passive' => []
    ];

    private function registerEntities(): void {
        $creativeInventory = \pocketmine\inventory\CreativeInventory::getInstance();

        /** @var array<string, \pocketmine\item\SpawnEgg> $creativeEggItems */
        $creativeEggItems = [];
        // One compact creative group. Creeper is both the first entry and the
        // group icon, as requested. The rest are ordered by hostile, passive,
        // aquatic and Nether mobs instead of being scattered through Nature.
        $creativeEggOrder = [
            'minecraft:creeper',
            'minecraft:zombie', 'minecraft:skeleton', 'minecraft:spider',
            'minecraft:slime', 'minecraft:silverfish', 'minecraft:witch',
            'minecraft:drowned', 'minecraft:zombie_villager', 'minecraft:enderman',
            'minecraft:pillager', 'minecraft:vindicator', 'minecraft:evoker', 'minecraft:vex',

            'minecraft:cow', 'minecraft:pig', 'minecraft:sheep', 'minecraft:chicken',
            'minecraft:mooshroom', 'minecraft:fox', 'minecraft:panda', 'minecraft:cat',
            'minecraft:wolf', 'minecraft:horse', 'minecraft:donkey', 'minecraft:llama',
            'minecraft:trader_llama', 'minecraft:parrot', 'minecraft:turtle',
            'minecraft:bee', 'minecraft:goat', 'minecraft:villager_v2',

            'minecraft:dolphin', 'minecraft:squid', 'minecraft:glow_squid',
            'minecraft:cod', 'minecraft:salmon', 'minecraft:pufferfish',
            'minecraft:tropicalfish',

            'minecraft:blaze', 'minecraft:ghast', 'minecraft:magma_cube',
            'minecraft:piglin', 'minecraft:hoglin',
        ];

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\GhastFireball::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\GhastFireball(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['GhastFireball', 'minecraft:fireball']);

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\BlazeFireball::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\BlazeFireball(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['BlazeFireball', 'minecraft:small_fireball']);

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\WitchPotion::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\WitchPotion(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['WitchPotion', 'minecraft:splash_potion']);

        $register = function(string $class, string $name, string $id, string $category) use ($creativeInventory, &$creativeEggItems) {
            EntityFactory::getInstance()->register($class, function(World $world, CompoundTag $nbt) use ($class) : \pocketmine\entity\Entity {
                return new $class(EntityDataHelper::parseLocation($nbt, $world), $nbt);
            }, [$name, $id]);
            $this->spawnerLists[$category][] = $class;

            // Keep the creative menu focused on the mob set selected for the
            // 1.18+ server profile. Other entity classes remain registered so
            // old chunks do not break, but their plugin eggs are not added.
            $creativeEggAllowlist = [
                'minecraft:zombie', 'minecraft:zombie_villager', 'minecraft:drowned',
                'minecraft:skeleton', 'minecraft:creeper', 'minecraft:spider',
                'minecraft:slime', 'minecraft:silverfish', 'minecraft:witch',
                'minecraft:pillager', 'minecraft:vindicator', 'minecraft:evoker',
                'minecraft:vex', 'minecraft:enderman',
                'minecraft:cow', 'minecraft:mooshroom', 'minecraft:pig',
                'minecraft:sheep', 'minecraft:chicken', 'minecraft:fox',
                'minecraft:panda', 'minecraft:cat', 'minecraft:llama',
                'minecraft:trader_llama', 'minecraft:wolf', 'minecraft:horse',
                'minecraft:donkey', 'minecraft:parrot', 'minecraft:turtle',
                'minecraft:bee', 'minecraft:goat', 'minecraft:villager_v2',
                'minecraft:squid',
                'minecraft:glow_squid', 'minecraft:dolphin', 'minecraft:cod',
                'minecraft:salmon', 'minecraft:pufferfish', 'minecraft:tropicalfish',
                'minecraft:blaze', 'minecraft:ghast', 'minecraft:magma_cube',
                'minecraft:piglin', 'minecraft:hoglin'
            ];

            $eggMapping = [
                'minecraft:skeleton' => 'skeleton_spawn_egg',
                'minecraft:zombie' => 'zombie_spawn_egg',
                'minecraft:creeper' => 'creeper_spawn_egg',
                'minecraft:spider' => 'spider_spawn_egg',
                'minecraft:cave_spider' => 'cave_spider_spawn_egg',
                'minecraft:slime' => 'slime_spawn_egg',
                'minecraft:silverfish' => 'silverfish_spawn_egg',
                'minecraft:witch' => 'witch_spawn_egg',
                'minecraft:zombie_villager' => 'zombie_villager_spawn_egg',
                'minecraft:drowned' => 'drowned_spawn_egg',
                'minecraft:husk' => 'husk_spawn_egg',
                'minecraft:stray' => 'stray_spawn_egg',
                'minecraft:phantom' => 'phantom_spawn_egg',
                'minecraft:vindicator' => 'vindicator_spawn_egg',
                'minecraft:evoker' => 'evoker_spawn_egg',
                'minecraft:pillager' => 'pillager_spawn_egg',
                'minecraft:ravager' => 'ravager_spawn_egg',
                'minecraft:vex' => 'vex_spawn_egg',
                'minecraft:guardian' => 'guardian_spawn_egg',
                'minecraft:elder_guardian' => 'elder_guardian_spawn_egg',
                'minecraft:cow' => 'cow_spawn_egg',
                'minecraft:mooshroom' => 'mooshroom_spawn_egg',
                'minecraft:pig' => 'pig_spawn_egg',
                'minecraft:sheep' => 'sheep_spawn_egg',
                'minecraft:chicken' => 'chicken_spawn_egg',
                'minecraft:wolf' => 'wolf_spawn_egg',
                'minecraft:ocelot' => 'ocelot_spawn_egg',
                'minecraft:cat' => 'cat_spawn_egg',
                'minecraft:horse' => 'horse_spawn_egg',
                'minecraft:donkey' => 'donkey_spawn_egg',
                'minecraft:mule' => 'mule_spawn_egg',
                'minecraft:llama' => 'llama_spawn_egg',
                'minecraft:trader_llama' => 'trader_llama_spawn_egg',
                'minecraft:fox' => 'fox_spawn_egg',
                'minecraft:panda' => 'panda_spawn_egg',
                'minecraft:turtle' => 'turtle_spawn_egg',
                'minecraft:dolphin' => 'dolphin_spawn_egg',
                'minecraft:squid' => 'squid_spawn_egg',
                'minecraft:glow_squid' => 'glow_squid_spawn_egg',
                'minecraft:bat' => 'bat_spawn_egg',
                'minecraft:villager_v2' => 'villager_spawn_egg',
                'minecraft:axolotl' => 'axolotl_spawn_egg',
                'minecraft:goat' => 'goat_spawn_egg',
                'minecraft:frog' => 'frog_spawn_egg',
                'minecraft:tadpole' => 'tadpole_spawn_egg',
                'minecraft:cod' => 'cod_spawn_egg',
                'minecraft:salmon' => 'salmon_spawn_egg',
                'minecraft:pufferfish' => 'pufferfish_spawn_egg',
                'minecraft:tropicalfish' => 'tropical_fish_spawn_egg',
                'minecraft:camel' => 'camel_spawn_egg',
                'minecraft:sniffer' => 'sniffer_spawn_egg',
                'minecraft:allay' => 'allay_spawn_egg',
                'minecraft:bee' => 'bee_spawn_egg',
                'minecraft:parrot' => 'parrot_spawn_egg',
                'minecraft:zombie_pigman' => 'zombie_pigman_spawn_egg',
                'minecraft:piglin' => 'piglin_spawn_egg',
                'minecraft:piglin_brute' => 'piglin_brute_spawn_egg',
                'minecraft:hoglin' => 'hoglin_spawn_egg',
                'minecraft:zoglin' => 'zoglin_spawn_egg',
                'minecraft:ghast' => 'ghast_spawn_egg',
                'minecraft:blaze' => 'blaze_spawn_egg',
                'minecraft:magma_cube' => 'magma_cube_spawn_egg',
                'minecraft:wither_skeleton' => 'wither_skeleton_spawn_egg',
                'minecraft:strider' => 'strider_spawn_egg',
                'minecraft:enderman' => 'enderman_spawn_egg',
                'minecraft:endermite' => 'endermite_spawn_egg',
                'minecraft:shulker' => 'shulker_spawn_egg',
                'minecraft:warden' => 'warden_spawn_egg',
            ];

            if (!isset($eggMapping[$id])) {
                return;
            }

            $parseId = $eggMapping[$id];
            if (!in_array($id, $creativeEggAllowlist, true)) {
                try {
                    $oldEgg = \pocketmine\item\StringToItemParser::getInstance()->parse($parseId);
                    if ($oldEgg !== null) {
                        \pocketmine\inventory\CreativeInventory::getInstance()->remove($oldEgg);
                    }
                } catch (\Throwable) {}
                return;
            }

            try {
                $oldEgg = \pocketmine\item\StringToItemParser::getInstance()->parse($parseId);
                if ($oldEgg !== null) {
                    \pocketmine\inventory\CreativeInventory::getInstance()->remove($oldEgg);
                }
            } catch (\Exception $e) {}

            $typeId = \pocketmine\item\ItemTypeIds::newId();
            $identifier = new \pocketmine\item\ItemIdentifier($typeId);

            $eggItem = new class($identifier, "§r§e" . $name . " Spawn Egg") extends \pocketmine\item\SpawnEgg {
                public string $entityClass;
                protected function createEntity(\pocketmine\world\World $world, \pocketmine\math\Vector3 $pos, float $yaw, float $pitch) : \pocketmine\entity\Entity {
                    $c = $this->entityClass;
                    return new $c(\pocketmine\entity\Location::fromObject($pos, $world, $yaw, $pitch));
                }
            };
            $eggItem->entityClass = $class;

            try {

                \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($eggItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:" . $parseId));
            } catch (\Exception $e) {}

            try {

                \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:" . $parseId, fn() => clone $eggItem);
            } catch (\Exception $e) {}

            // Add all eggs together only after every entity has been
            // registered. This keeps them in one creative group and allows a
            // deterministic order with the creeper egg first.
            $creativeEggItems[$id] = clone $eggItem;

            try {

                if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                    \pocketmine\item\StringToItemParser::getInstance()->override($parseId, fn() => clone $eggItem);
                } else {
                    \pocketmine\item\StringToItemParser::getInstance()->register($parseId, fn() => clone $eggItem);
                }
            } catch (\Exception $e) {}
        };

        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Skeleton::class, 'Skeleton', 'minecraft:skeleton', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Zombie::class, 'Zombie', 'minecraft:zombie', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Creeper::class, 'Creeper', 'minecraft:creeper', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Spider::class, 'Spider', 'minecraft:spider', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\CaveSpider::class, 'CaveSpider', 'minecraft:cave_spider', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Slime::class, 'Slime', 'minecraft:slime', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Silverfish::class, 'Silverfish', 'minecraft:silverfish', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Witch::class, 'Witch', 'minecraft:witch', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\ZombieVillager::class, 'ZombieVillager', 'minecraft:zombie_villager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Drowned::class, 'Drowned', 'minecraft:drowned', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Husk::class, 'Husk', 'minecraft:husk', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Stray::class, 'Stray', 'minecraft:stray', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Phantom::class, 'Phantom', 'minecraft:phantom', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Vindicator::class, 'Vindicator', 'minecraft:vindicator', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Evoker::class, 'Evoker', 'minecraft:evoker', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pillager::class, 'Pillager', 'minecraft:pillager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Ravager::class, 'Ravager', 'minecraft:ravager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Vex::class, 'Vex', 'minecraft:vex', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class, 'Guardian', 'minecraft:guardian', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class, 'ElderGuardian', 'minecraft:elder_guardian', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Warden::class, 'Warden', 'minecraft:warden', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cow::class, 'Cow', 'minecraft:cow', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Mooshroom::class, 'Mooshroom', 'minecraft:mooshroom', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pig::class, 'Pig', 'minecraft:pig', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Sheep::class, 'Sheep', 'minecraft:sheep', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Chicken::class, 'Chicken', 'minecraft:chicken', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Wolf::class, 'Wolf', 'minecraft:wolf', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Ocelot::class, 'Ocelot', 'minecraft:ocelot', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cat::class, 'Cat', 'minecraft:cat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Horse::class, 'Horse', 'minecraft:horse', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Donkey::class, 'Donkey', 'minecraft:donkey', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Mule::class, 'Mule', 'minecraft:mule', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Llama::class, 'Llama', 'minecraft:llama', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\TraderLlama::class, 'TraderLlama', 'minecraft:trader_llama', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Fox::class, 'Fox', 'minecraft:fox', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Panda::class, 'Panda', 'minecraft:panda', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Parrot::class, 'Parrot', 'minecraft:parrot', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Turtle::class, 'Turtle', 'minecraft:turtle', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Dolphin::class, 'Dolphin', 'minecraft:dolphin', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Squid::class, 'Squid', 'minecraft:squid', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\GlowSquid::class, 'GlowSquid', 'minecraft:glow_squid', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Bat::class, 'Bat', 'minecraft:bat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Villager::class, 'Villager', 'minecraft:villager_v2', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\WanderingTrader::class, 'WanderingTrader', 'minecraft:wandering_trader', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\IronGolem::class, 'IronGolem', 'minecraft:iron_golem', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\SnowGolem::class, 'SnowGolem', 'minecraft:snow_golem', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class, 'Axolotl', 'minecraft:axolotl', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Goat::class, 'Goat', 'minecraft:goat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Frog::class, 'Frog', 'minecraft:frog', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class, 'Tadpole', 'minecraft:tadpole', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cod::class, 'Cod', 'minecraft:cod', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class, 'Salmon', 'minecraft:salmon', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class, 'Pufferfish', 'minecraft:pufferfish', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class, 'TropicalFish', 'minecraft:tropicalfish', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Camel::class, 'Camel', 'minecraft:camel', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Sniffer::class, 'Sniffer', 'minecraft:sniffer', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Allay::class, 'Allay', 'minecraft:allay', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Bee::class, 'Bee', 'minecraft:bee', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\ZombifiedPiglin::class, 'ZombifiedPiglin', 'minecraft:zombie_pigman', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Piglin::class, 'Piglin', 'minecraft:piglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\PiglinBrute::class, 'PiglinBrute', 'minecraft:piglin_brute', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Hoglin::class, 'Hoglin', 'minecraft:hoglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Zoglin::class, 'Zoglin', 'minecraft:zoglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Ghast::class, 'Ghast', 'minecraft:ghast', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Blaze::class, 'Blaze', 'minecraft:blaze', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\MagmaCube::class, 'MagmaCube', 'minecraft:magma_cube', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\WitherSkeleton::class, 'WitherSkeleton', 'minecraft:wither_skeleton', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Strider::class, 'Strider', 'minecraft:strider', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Enderman::class, 'Enderman', 'minecraft:enderman', 'the_end');
        $this->spawnerLists['overworld_hostile'][] = \BeeAZ\AZVanillaMobs\entity\the_end\Enderman::class;
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Endermite::class, 'Endermite', 'minecraft:endermite', 'the_end');
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Shulker::class, 'Shulker', 'minecraft:shulker', 'the_end');
        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon(EntityDataHelper::parseLocation($nbt, $world), $nbt);
        }, ['EnderDragon', 'minecraft:ender_dragon']);

        // PocketMine 5 supports custom creative groups. Essential forks based
        // on older API revisions fall back to consecutive Nature entries.
        $eggGroup = null;
        if(isset($creativeEggItems['minecraft:creeper'])
            && class_exists(\pocketmine\inventory\CreativeGroup::class)){
            try {
                $groupClass = \pocketmine\inventory\CreativeGroup::class;
                $eggGroup = new $groupClass("Яйца мобов", clone $creativeEggItems['minecraft:creeper']);
            } catch (\Throwable) {
                $eggGroup = null;
            }
        }

        $addCreativeEgg = static function(\pocketmine\item\SpawnEgg $egg) use ($creativeInventory, $eggGroup): void {
            if($eggGroup !== null){
                try {
                    $creativeInventory->add($egg, \pocketmine\inventory\CreativeCategory::NATURE, $eggGroup);
                    return;
                } catch (\Throwable) {
                    // Some protocol forks expose CreativeGroup but still use
                    // the older two-argument add() implementation.
                }
            }
            try {
                if(class_exists(\pocketmine\inventory\CreativeCategory::class)){
                    $creativeInventory->add($egg, \pocketmine\inventory\CreativeCategory::NATURE);
                }else{ $creativeInventory->add($egg); }
            } catch (\Throwable) {}
        };

        $addedEggs = [];
        foreach($creativeEggOrder as $entityId){
            if(!isset($creativeEggItems[$entityId])){
                continue;
            }
            $addCreativeEgg($creativeEggItems[$entityId]);
            $addedEggs[$entityId] = true;
        }
        // Future allowlisted eggs not yet listed above are still kept together
        // after the known entries instead of disappearing from creative mode.
        foreach($creativeEggItems as $entityId => $egg){
            if(!isset($addedEggs[$entityId])){
                $addCreativeEgg($egg);
            }
        }

        try {
            $saddleId = \pocketmine\item\ItemTypeIds::newId();
            $saddleIdentifier = new \pocketmine\item\ItemIdentifier($saddleId);
            $saddleItem = new \pocketmine\item\Item($saddleIdentifier, "Saddle");

            \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($saddleItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:saddle"));
            \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:saddle", fn() => clone $saddleItem);

            if(class_exists(\pocketmine\inventory\CreativeCategory::class)){
                $creativeInventory->add($saddleItem, \pocketmine\inventory\CreativeCategory::EQUIPMENT);
            }else{ $creativeInventory->add($saddleItem); }

            if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                \pocketmine\item\StringToItemParser::getInstance()->override("saddle", fn() => clone $saddleItem);
            } else {
                \pocketmine\item\StringToItemParser::getInstance()->register("saddle", fn() => clone $saddleItem);
            }
        } catch (\Exception $e) {}

        try {
            $leadId = \pocketmine\item\ItemTypeIds::newId();
            $leadIdentifier = new \pocketmine\item\ItemIdentifier($leadId);
            $leadItem = new \BeeAZ\AZVanillaMobs\item\Lead($leadIdentifier, "Lead");

            \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($leadItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:lead"));
            \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:lead", fn() => clone $leadItem);

            \pocketmine\inventory\CreativeInventory::getInstance()->add($leadItem);

            if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                \pocketmine\item\StringToItemParser::getInstance()->override("lead", fn() => clone $leadItem);
            } else {
                \pocketmine\item\StringToItemParser::getInstance()->register("lead", fn() => clone $leadItem);
            }
        } catch (\Exception $e) {}

        try {
            $registerBucket = function(string $class, string $name, string $id, string $parseId) {
                try {
                    $bucketId = \pocketmine\item\ItemTypeIds::newId();
                    $bucketIdentifier = new \pocketmine\item\ItemIdentifier($bucketId);
                    $bucketItem = new \BeeAZ\AZVanillaMobs\item\FishBucket($bucketIdentifier, $name, $class);

                    \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($bucketItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:" . $id));
                    \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:" . $id, fn() => clone $bucketItem);

                    if(class_exists(\pocketmine\inventory\CreativeCategory::class)){
                        $creativeInventory->add($bucketItem, \pocketmine\inventory\CreativeCategory::NATURE);
                    }else{ $creativeInventory->add($bucketItem); }

                    if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                        \pocketmine\item\StringToItemParser::getInstance()->override($parseId, fn() => clone $bucketItem);
                    } else {
                        \pocketmine\item\StringToItemParser::getInstance()->register($parseId, fn() => clone $bucketItem);
                    }
                } catch (\Exception $e) {}
            };

            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Cod::class, "Cod Bucket", "cod_bucket", "cod_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class, "Salmon Bucket", "salmon_bucket", "salmon_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class, "Pufferfish Bucket", "pufferfish_bucket", "pufferfish_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class, "Tropical Fish Bucket", "tropical_fish_bucket", "tropical_fish_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class, "Axolotl Bucket", "axolotl_bucket", "axolotl_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class, "Tadpole Bucket", "tadpole_bucket", "tadpole_bucket");
        } catch (\Exception $e) {}
    }
}
