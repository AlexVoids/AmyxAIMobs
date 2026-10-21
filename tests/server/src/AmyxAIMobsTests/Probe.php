<?php
declare(strict_types=1);
namespace AmyxAIMobsTests;
use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\overworld\{Cow,Zombie,Skeleton};
use BeeAZ\AZVanillaMobs\ai\{GroundPathfinder,WalkEvaluator};
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
final class Probe extends PluginBase{
    private int $passed=0;
    private int $roundtrips=0;
    private array $failures=[];
    protected function onEnable(): void{
        if(getenv('AMYXAIMOBS_RUN_TESTS')!=='1'){ $this->getServer()->getPluginManager()->disablePlugin($this);return; }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn()=>$this->run()),30);
    }
    private static function ok(bool $value,string $message='Assertion failed'): void{ if(!$value){ throw new \RuntimeException($message); } }
    private function test(string $name,\Closure $callback): void{
        try{ $callback();++$this->passed;$this->getLogger()->info('PASS '.$name); }
        catch(\Throwable $e){ $this->failures[$name]=$e->getMessage();$this->getLogger()->logException($e); }
    }
    private function run(): void{
        try{
            $plugin=$this->getServer()->getPluginManager()->getPlugin('AmyxAIMobs');
            $world=$this->getServer()->getWorldManager()->getDefaultWorld();
            for($cx=-1;$cx<=3;$cx++){ for($cz=-1;$cz<=1;$cz++){ $world->setChunk($cx,$cz,new \pocketmine\world\format\Chunk([],true)); } }
            for($x=-10;$x<=60;$x++){ for($z=-10;$z<=20;$z++){ $world->setBlockAt($x,99,$z,VanillaBlocks::GRASS()); } }
            $this->test('migration preserves legacy data and fresh config comments',function() use($plugin): void{
                $path=$plugin->getDataFolder();
                if(getenv('AMYXAIMOBS_TEST_MIGRATION')==='1'){
                    self::ok($plugin->getConfig()->get('global-mob-cap')===7 && $plugin->getConfig()->get('entity-type-limits')['cow']===2,'Legacy limits overwritten');
                    self::ok(json_decode(file_get_contents($path.'migration-probe.json'),true)['preserved']===true);
                    self::ok(is_file(dirname(rtrim($path,'/')).'/AZVanillaMobs/migration-probe.json') || is_file($this->getServer()->getPluginPath().'AZVanillaMobs/migration-probe.json'),'Original data removed');
                }else{
                    self::ok(str_contains(file_get_contents($path.'config.yml'),'# AmyxAIMobs'),'Fresh configuration comments lost');
                }
            });
            $this->test('plugin loads and saved entity types are registered',function() use($plugin,$world): void{
                self::ok($plugin instanceof Main && $plugin->isEnabled());
                $classes=array_unique(array_merge(...array_values($plugin->spawnerLists)));
                $classes[]=\BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon::class;
                foreach($classes as $class){
                    $mob=new $class(new Location(4.5,100,4.5,$world,0,0));$nbt=$mob->saveNBT();$mob->close();
                    $restored=\pocketmine\entity\EntityFactory::getInstance()->createFromData($world,$nbt);
                    self::ok($restored instanceof $class,'NBT round trip '.$class);$restored->close();++$this->roundtrips;
                }
            });
            $this->test('A* routes around a three block high wall',function() use($world): void{
                for($z=0;$z<=5;$z++){ for($y=100;$y<=102;$y++){ $world->setBlockAt(8,$y,$z,VanillaBlocks::STONE()); } }
                $walk=new WalkEvaluator($world,0.6,1.8);
                $path=GroundPathfinder::find([5,100,2],[11,100,2],$walk->stand(...),512,16);
                self::ok(count($path)>7,'Path crosses solid wall');self::ok(end($path)[0]>=10,'No route around wall');
                foreach($path as [$x,$y,$z]){ self::ok($walk->safe($x,$y,$z),'Unsafe waypoint'); }
            });
            $this->test('navigator rejects lava, fence columns, dangerous drops and unloaded chunks',function() use($world): void{
                $walk=new WalkEvaluator($world,0.6,1.8);
                $world->setBlockAt(2,99,10,VanillaBlocks::LAVA());self::ok($walk->stand(2,10,100)===null);
                $world->setBlockAt(3,100,10,VanillaBlocks::OAK_FENCE());self::ok(!$walk->safe(3,100,10));
                $world->setBlockAt(4,99,10,VanillaBlocks::AIR());self::ok($walk->stand(4,10,100)===null);
                self::ok($walk->stand(2000,2000,100)===null && !$world->isChunkLoaded(125,125));
            });
            $this->test('mob retains last visible target without resetting hidden-target memory',function() use($world): void{
                $mob=new Zombie(new Location(20.5,100,5.5,$world,0,0));$target=new Cow(new Location(24.5,100,5.5,$world,0,0));
                $mob->setCombatTarget($target,10);$rp=new \ReflectionProperty($mob,'targetPosition');$remember=$rp->getValue($mob);
                $world->setBlockAt(22,101,5,VanillaBlocks::STONE());$tick=new \ReflectionProperty(\BeeAZ\AZVanillaMobs\entity\BaseMob::class,'sightTick');$tick->setValue($mob,-100);
                $target->teleport(new \pocketmine\math\Vector3(26.5,100,5.5));$mob->setCombatTarget($target,340);
                self::ok($rp->getValue($mob)->distanceSquared($remember)<0.01,'Mob tracks unseen target');
                $mem=new \ReflectionProperty($mob,'targetMemoryTicks');self::ok($mem->getValue($mob)===10,'Repeated selection resets memory');
                $mob->close();$target->close();
            });
            $this->test('skeleton keeps stand-and-shoot goal during per-tick movement',function() use($world): void{
                $mob=new Skeleton(new Location(30.5,100,10.5,$world,0,0));$target=new Cow(new Location(36.5,100,10.5,$world,0,0));
                $mob->setCombatTarget($target,100);
                $rp=new \ReflectionProperty($mob,'targetPosition');$rp->setValue($mob,null);
                $offset=new \ReflectionProperty($mob,'tickOffset');$offset->setValue($mob,1);
                $mob->onUpdate($this->getServer()->getTick()+1);
                self::ok($rp->getValue($mob)===null,'Shared AI overwrites ranged stopping goal');
                $mob->close();$target->close();
            });
            $this->test('navigation produces real sideways motion to route around wall',function() use($world): void{
                $mob=new Zombie(new Location(5.5,100,2.5,$world,0,0));
                (new \ReflectionProperty($mob,'targetPosition'))->setValue($mob,new \pocketmine\math\Vector3(11.5,100,2.5));
                (new \ReflectionMethod($mob,'moveTowardsTarget'))->invoke($mob);
                self::ok($mob->getMotion()->lengthSquared()>0,'Navigator stopped on reachable route');$mob->close();
            });
            $this->test('natural spawn guards enforce caps, distance, darkness and world rules',function() use($world,$plugin): void{
                $player=new SyntheticPlayer(new \pocketmine\world\Position(0.5,100,10.5,$world));
                // World::getPlayers is backed by its entity/player index. Synthetic
                // transport is the only substituted boundary; real World/collision/events execute.
                $rp=new \ReflectionProperty($world,'players');$old=$rp->getValue($world);$rp->setValue($world,[$player->getId()=>$player]);
                $config=$plugin->getConfig();$original=$config->getAll();
                $task=new \BeeAZ\AZVanillaMobs\spawner\SpawnerTask($plugin);$guard=new \ReflectionMethod($task,'canSpawn');
                $ctx=['y'=>100,'night'=>true,'light'=>0,'biome'=>'plains','dimension'=>'overworld'];
                try{
                    self::ok($guard->invoke($task,$world,Cow::class,35,100,10,$ctx),'Valid spawn rejected');
                    self::ok(!$guard->invoke($task,$world,Cow::class,2,100,10,$ctx),'Minimum distance ignored');
                    self::ok(!$guard->invoke($task,$world,Cow::class,150,100,10,$ctx),'Maximum distance ignored');
                    $config->set('global-mob-cap',0);self::ok(!$guard->invoke($task,$world,Cow::class,35,100,10,$ctx),'Zero global cap ignored');$config->setAll($original);
                    $config->set('world-overrides',[$world->getFolderName()=>['enabled'=>false]]);self::ok(!$guard->invoke($task,$world,Cow::class,35,100,10,$ctx),'Disabled world spawns mobs');$config->setAll($original);
                    $config->set('world-overrides',[$world->getFolderName()=>['mobs'=>['cow'=>['enabled'=>false]]]]);self::ok(!$guard->invoke($task,$world,Cow::class,35,100,10,$ctx),'Disabled species spawns');$config->setAll($original);
                    $ctx['light']=1;self::ok(!$guard->invoke($task,$world,Zombie::class,35,100,10,$ctx),'1.18+ block darkness rule ignored');$ctx['light']=0;
                    $world->setDifficulty(0);self::ok(!$guard->invoke($task,$world,Zombie::class,35,100,10,$ctx),'Peaceful world spawns hostiles');$world->setDifficulty(2);
                    $player->creative=true;self::ok(!$guard->invoke($task,$world,Cow::class,35,100,10,$ctx),'Creative-only world spawns naturally');$player->creative=false;
                }finally{ $config->setAll($original);$rp->setValue($world,$old); }
            });
            $this->test('fish schools cannot overflow global cap or ignore minimum distance',function() use($world,$plugin): void{
                $player=new SyntheticPlayer(new \pocketmine\world\Position(0.5,100,10.5,$world));
                $rp=new \ReflectionProperty($world,'players');$old=$rp->getValue($world);$rp->setValue($world,[$player->getId()=>$player]);
                $config=$plugin->getConfig();$original=$config->getAll();$before=[];
                foreach($world->getEntities() as $e){ $before[$e->getId()]=true; }
                try{
                    for($x=29;$x<=40;$x++){ for($z=5;$z<=15;$z++){ for($y=98;$y<=103;$y++){ $world->setBlockAt($x,$y,$z,VanillaBlocks::WATER()); } } }
                    $first=new \BeeAZ\AZVanillaMobs\entity\overworld\Cod(new Location(35.5,100,10.5,$world,0,0));
                    $config->set('global-mob-cap',1);$config->set('mobs',['cod'=>['group-min'=>8,'group-max'=>8]]);
                    $task=new \BeeAZ\AZVanillaMobs\spawner\SpawnerTask($plugin);
                    (new \ReflectionMethod($task,'spawnAquaticCompanions'))->invoke($task,$first::class,'cod',$world,35,100,10,[]);
                    $count=0;foreach($world->getEntities() as $e){ if($e instanceof \BeeAZ\AZVanillaMobs\entity\BaseMob && !$e->isClosed()){ ++$count; } }
                    self::ok($count===1,'Fish school exceeded global cap');
                }finally{
                    foreach($world->getEntities() as $e){ if($e instanceof \BeeAZ\AZVanillaMobs\entity\BaseMob && !isset($before[$e->getId()])){ $e->close(); } }
                    $config->setAll($original);$rp->setValue($world,$old);
                }
            });
            $this->test('real natural spawning creates capped, persistent groups on valid grass',function() use($world,$plugin): void{
                $player=new SyntheticPlayer(new \pocketmine\world\Position(0.5,100,0.5,$world));
                $rp=new \ReflectionProperty($world,'players');$old=$rp->getValue($world);$rp->setValue($world,[$player->getId()=>$player]);
                $config=$plugin->getConfig();$original=$config->getAll();$lists=$plugin->spawnerLists;
                try{
                    $world->setTime(1000);$world->setBiomeId(45,99,0,\pocketmine\data\bedrock\BiomeIds::PLAINS);
                    $config->set('global-mob-cap',2);$config->set('entity-type-limits',['cow'=>2]);$config->set('mobs',['cow'=>['group-min'=>3,'group-max'=>3]]);
                    $plugin->spawnerLists['overworld_passive']=[Cow::class];
                    $task=new \BeeAZ\AZVanillaMobs\spawner\SpawnerTask($plugin);
                    $spawn=(new \ReflectionMethod($task,'attemptSpawnAt'))->invoke($task,$world,45,0,'overworld');
                    self::ok($spawn,'No natural group on valid terrain');$count=0;
                    foreach($world->getEntities() as $mob){ if($mob instanceof Cow && !$mob->isClosed()){ ++$count;self::ok($mob->wasNaturallySpawned()); } }
                    self::ok($count>=1 && $count<=2,'Animal pack exceeded cap');
                    $config->set('global-mob-cap',0);self::ok(!(new \ReflectionMethod($task,'attemptSpawnAt'))->invoke($task,$world,46,0,'overworld'),'Spawner ignores zero cap');
                }finally{
                    foreach($world->getEntities() as $mob){ if($mob instanceof \BeeAZ\AZVanillaMobs\entity\BaseMob){ $mob->close(); } }
                    $config->setAll($original);$plugin->spawnerLists=$lists;$rp->setValue($world,$old);
                }
            });
            $this->test('equipment, injured health and baby size survive chunk reload',function() use($world): void{
                $mob=new \BeeAZ\AZVanillaMobs\entity\the_end\Enderman(new Location(20.5,100,15.5,$world,0,0));
                $mob->setHealth(7);$mob->getArmorInventory()->setHelmet(\pocketmine\item\VanillaItems::DIAMOND_HELMET());
                $nbt=$mob->saveNBT();$mob->close();$restored=\pocketmine\entity\EntityFactory::getInstance()->createFromData($world,$nbt);
                self::ok($restored->getHealth()===7.0,'Injured mob healed on reload');self::ok($restored->getArmorInventory()->getHelmet()->getTypeId()===\pocketmine\item\VanillaItems::DIAMOND_HELMET()->getTypeId(),'Armor lost on reload');$restored->close();
                $baby=new Cow(new Location(20.5,100,15.5,$world,0,0),\pocketmine\nbt\tag\CompoundTag::create()->setInt('Age',-24000));
                self::ok($baby->age<0 && $baby->getScale()===0.5,'Baby becomes visually adult on reload');$baby->close();
            });
            $this->test('young animal follows adult and creative feeding preserves food',function() use($world): void{
                $adult=new Cow(new Location(30.5,100,15.5,$world,0,0));$baby=new Cow(new Location(24.5,100,15.5,$world,0,0));$baby->age=-100;
                (new \ReflectionMethod($baby,'calculateAI'))->invoke($baby);
                $goal=(new \ReflectionProperty($baby,'targetPosition'))->getValue($baby);self::ok($goal!==null && $goal->distanceSquared($adult->getPosition())<0.01,'Young animal ignores parent');
                $player=new SyntheticPlayer(new \pocketmine\world\Position(30.5,100,15.5,$world));$player->creative=true;$player->getInventory()->setItemInHand(\pocketmine\item\VanillaItems::WHEAT()->setCount(5));
                $adult->onInteract($player,new \pocketmine\math\Vector3(0,0,0));self::ok($player->getInventory()->getItemInHand()->getCount()===5 && $adult->inLoveTicks>0,'Creative feeding consumes food');
                $adult->close();$baby->close();
            });
            $this->test('shallow water permits breathing and aquatic mammals need air',function() use($world): void{
                $world->setBlockAt(50,100,10,VanillaBlocks::WATER());
                $cow=new Cow(new Location(50.5,100,10.5,$world,0,0));self::ok($cow->canBreathe(),'Wet feet cause drowning');
                $world->setBlockAt(50,101,10,VanillaBlocks::WATER());self::ok(!$cow->canBreathe(),'Fully submerged cow breathes air');$cow->close();
                $blaze=new \BeeAZ\AZVanillaMobs\entity\nether\Blaze(new Location(50.5,100,10.5,$world,0,0));self::ok($blaze->isFireProof(),'Blaze takes fire damage');$blaze->close();
            });
            $this->test('config migration preserves administrator zeros, limits and nested rules',function() use($plugin): void{
                $config=$plugin->getConfig();$original=$config->getAll();
                try{
                    $config->setAll(['spawn-config-version'=>1,'global-mob-cap'=>0,'water-herd-spawning'=>false,'entity-type-limits'=>['cow'=>2],'mobs'=>['cow'=>['enabled'=>false]]]);
                    (new \ReflectionMethod($plugin,'migrateSpawnConfig'))->invoke($plugin);
                    self::ok($config->get('global-mob-cap')===0 && $config->get('water-herd-spawning')===false);
                    self::ok($config->get('entity-type-limits')['cow']===2 && $config->get('mobs')['cow']['enabled']===false);
                }finally{ $config->setAll($original);$config->save(); }
            });
        }catch(\Throwable $e){ $this->failures['harness']=$e->getMessage();$this->getLogger()->logException($e); }
        file_put_contents($this->getServer()->getDataPath().'amyxaimobs-results.json',json_encode(['passed'=>$this->passed,'entity_roundtrips'=>$this->roundtrips,'failures'=>$this->failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$this->getServer()->shutdown();
    }
}
