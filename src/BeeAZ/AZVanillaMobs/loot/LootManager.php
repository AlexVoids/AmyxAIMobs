<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\loot;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\item\StringToItemParser;
use BeeAZ\AZVanillaMobs\entity\BaseMob;

class LootManager implements Listener {

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        if (!$entity instanceof BaseMob) return;

        $type = $entity->getNetworkTypeId();
        $drops = [];

        switch ($type) {
            case 'minecraft:zombie':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("rotten_flesh", $count);
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("iron_ingot");
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("carrot");
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("potato");
                break;
            case 'minecraft:skeleton':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("bone", $count);
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("arrow", $count);
                break;
            case 'minecraft:creeper':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("gunpowder", $count);
                break;
            case 'minecraft:spider':
            case 'minecraft:cave_spider':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("string", $count);
                if (mt_rand(1, 3) === 1) $drops[] = $this->getItem("spider_eye");
                break;
            case 'minecraft:pig':
                $name = $entity->isOnFire() ? "cooked_porkchop" : "raw_porkchop";
                $drops[] = $this->getItem($name, mt_rand(1, 3));
                break;
            case 'minecraft:cow':
            case 'minecraft:mooshroom':
                $name = $entity->isOnFire() ? "cooked_beef" : "raw_beef";
                $drops[] = $this->getItem($name, mt_rand(1, 3));
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("leather", $count);
                break;
            case 'minecraft:sheep':
                $name = $entity->isOnFire() ? "cooked_mutton" : "raw_mutton";
                $drops[] = $this->getItem($name, mt_rand(1, 2));
                $drops[] = $this->getItem("white_wool");
                break;
            case 'minecraft:chicken':
                $name = $entity->isOnFire() ? "cooked_chicken" : "raw_chicken";
                $drops[] = $this->getItem($name);
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("feather", $count);
                break;
            case 'minecraft:enderman':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("ender_pearl");
                break;
            case 'minecraft:blaze':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("blaze_rod");
                break;
            case 'minecraft:ghast':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("ghast_tear");
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("gunpowder", $count);
                break;
            case 'minecraft:zombie_pigman':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("rotten_flesh");
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("gold_nugget");
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("gold_ingot");
                break;
            case 'minecraft:magma_cube':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("magma_cream");
                break;
            case 'minecraft:slime':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("slime_ball", $count);
                break;
            case 'minecraft:wither_skeleton':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("bone", $count);
                if (mt_rand(0, 2) === 0) $drops[] = $this->getItem("coal");
                if (mt_rand(1, 40) === 1) $drops[] = $this->getItem("wither_skeleton_skull");
                break;
            case 'minecraft:witch':
                $witchDrops = ["glass_bottle", "glowstone_dust", "gunpowder", "redstone", "spider_eye", "sugar", "stick"];
                $itemCount = mt_rand(0, 2);
                for ($i = 0; $i < $itemCount; $i++) {
                    $drops[] = $this->getItem($witchDrops[array_rand($witchDrops)]);
                }
                break;
            case 'minecraft:zombie_villager':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("rotten_flesh", $count);
                break;
            case 'minecraft:drowned':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("rotten_flesh", $count);
                if (mt_rand(1, 9) === 1) $drops[] = $this->getItem("copper_ingot");
                if(method_exists($entity, "getHeldVariant")){
                    $held = $entity->getHeldVariant();
                    if($held === "nautilus_shell"){
                        $drops[] = $this->getItem("nautilus_shell");
                    }elseif($held === "trident" && mt_rand(1, 12) === 1){
                        $drops[] = $this->getItem("trident");
                    }
                }
                break;
            case 'minecraft:husk':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("rotten_flesh", $count);
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("iron_ingot");
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("carrot");
                if (mt_rand(1, 100) <= 2) $drops[] = $this->getItem("potato");
                break;
            case 'minecraft:stray':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("bone", $count);
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("arrow");
                break;
            case 'minecraft:phantom':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("phantom_membrane");
                break;
            case 'minecraft:vindicator':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("emerald");
                if (mt_rand(1, 100) <= 8) $drops[] = $this->getItem("iron_axe");
                break;
            case 'minecraft:evoker':
                $drops[] = $this->getItem("totem_of_undying");
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("emerald");
                break;
            case 'minecraft:pillager':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("arrow", $count);
                if (mt_rand(1, 100) <= 8) $drops[] = $this->getItem("crossbow");
                break;
            case 'minecraft:ravager':
                $drops[] = $this->getItem("saddle");
                break;
            case 'minecraft:guardian':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("prismarine_shard", $count);
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("prismarine_crystals");
                if (mt_rand(0, 1) === 1) {
                    $name = $entity->isOnFire() ? "cooked_fish" : "raw_fish";
                    $drops[] = $this->getItem($name);
                }
                break;
            case 'minecraft:elder_guardian':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("prismarine_shard", $count);
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("prismarine_crystals");
                $drops[] = $this->getItem("wet_sponge");
                break;
            case 'minecraft:cat':
            case 'minecraft:ocelot':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("string", $count);
                break;
            case 'minecraft:horse':
            case 'minecraft:donkey':
            case 'minecraft:mule':
            case 'minecraft:llama':
            case 'minecraft:trader_llama':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("leather", $count);
                break;
            case 'minecraft:panda':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("bamboo", $count);
                break;
            case 'minecraft:parrot':
                $drops[] = $this->getItem("feather", mt_rand(1, 2));
                break;
            case 'minecraft:turtle':
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("seagrass", $count);
                break;
            case 'minecraft:dolphin':
                if (mt_rand(0, 1) === 1) {
                    $name = $entity->isOnFire() ? "cooked_fish" : "raw_fish";
                    $drops[] = $this->getItem($name);
                }
                break;
            case 'minecraft:squid':
                $count = mt_rand(1, 3);
                $drops[] = $this->getItem("ink_sac", $count);
                break;
            case 'minecraft:glow_squid':
                $count = mt_rand(1, 3);
                $drops[] = $this->getItem("glow_ink_sac", $count);
                break;
            case 'minecraft:goat':
                $name = $entity->isOnFire() ? "cooked_mutton" : "raw_mutton";
                $drops[] = $this->getItem($name, mt_rand(1, 2));
                break;
            case 'minecraft:frog':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("slime_ball");
                break;
            case 'minecraft:sniffer':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("moss_block");
                break;
            case 'minecraft:piglin':
            case 'minecraft:piglin_brute':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("gold_nugget");
                if ($type === 'minecraft:piglin_brute' && mt_rand(1, 100) <= 8) {
                    $drops[] = $this->getItem("golden_axe");
                }
                break;
            case 'minecraft:hoglin':
                $name = $entity->isOnFire() ? "cooked_porkchop" : "raw_porkchop";
                $drops[] = $this->getItem($name, mt_rand(1, 3));
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("leather", $count);
                break;
            case 'minecraft:zoglin':
                $drops[] = $this->getItem("rotten_flesh", mt_rand(1, 3));
                break;
            case 'minecraft:strider':
                $drops[] = $this->getItem("string", mt_rand(2, 5));
                break;
            case 'minecraft:shulker':
                if (mt_rand(0, 1) === 1) $drops[] = $this->getItem("shulker_shell");
                break;
            case 'minecraft:iron_golem':
                $drops[] = $this->getItem("iron_ingot", mt_rand(3, 5));
                $count = mt_rand(0, 2);
                if ($count > 0) $drops[] = $this->getItem("poppy", $count);
                break;
            case 'minecraft:snow_golem':
                $drops[] = $this->getItem("snowball", mt_rand(0, 15));
                break;
        }

        if (!empty($drops)) {
            $event->setDrops($drops);
        }

        $event->setXpDropAmount($entity->getXpDropAmount());
    }

    private function getItem(string $name, int $count = 1): Item {
        $item = StringToItemParser::getInstance()->parse($name) ?? VanillaItems::AIR();
        if (!$item->isNull() && $count > 1) {
            $item->setCount($count);
        }
        return $item;
    }
}
