<?php
declare(strict_types=1);
require __DIR__.'/../src/BeeAZ/AZVanillaMobs/ai/GroundPathfinder.php';
require __DIR__.'/../src/BeeAZ/AZVanillaMobs/config/Settings.php';
use BeeAZ\AZVanillaMobs\ai\GroundPathfinder as P;
use BeeAZ\AZVanillaMobs\config\Settings as S;
$passed=0;
function check(bool $v,string $name): void{ global $passed;if(!$v){ throw new RuntimeException($name); }++$passed; }
$map=static fn(int $x,int $z,int $y): ?int=>abs($x)<=12 && abs($z)<=12 ? 0 : null;
check(count(P::find([0,0,0],[8,0,0],$map))>=7,'open terrain');
$wall=static fn(int $x,int $z,int $y): ?int=>$x===2 && $z>=-3 && $z<=3 ? null : 0;
$path=P::find([0,0,0],[5,0,0],$wall,512,16);check(count($path)>8 && end($path)[0]>=4,'long detour');
$pit=static fn(int $x,int $z,int $y): ?int=>$x===1 ? null : 0;
$path=P::find([0,0,0],[3,0,0],$pit,64,5);check($path===[],'cannot cross bottomless pit');
$enclosed=static fn(int $x,int $z,int $y): ?int=>null;
check(P::find([0,0,0],[3,0,0],$enclosed)===[],'enclosed start');
$calls=0;P::find([0,0,0],[100,0,100],static function($x,$z,$y) use(&$calls){++$calls;return 0;},16,24);check($calls<=64,'hard expansion budget');
check(P::find([0,0,0],[8,0,0],$map,192,24,microtime(true)-1)===[],'expired time budget');
$path=P::find([0,0,0],[0,0,0],$map);check($path===[],'already at goal');
$path=P::find([-8,0,-8],[-2,0,-8],$map);check(end($path)[0]>=-3,'negative coordinates');
$values=['global-mob-cap'=>0,'entity-type-limits'=>['cow'=>0],'mobs'=>['cow'=>['enabled'=>false]]];
$merged=S::fillMissing($values,['global-mob-cap'=>135,'entity-type-limits'=>['cow'=>22,'pig'=>18],'mobs'=>['cow'=>['enabled'=>true,'weight-multiplier'=>1]]]);
check($merged['global-mob-cap']===0 && $merged['entity-type-limits']['cow']===0,'preserve zero settings');
check($merged['mobs']['cow']['enabled']===false && isset($merged['entity-type-limits']['pig']),'fill missing keys');
$settings=S::forWorld(['mobs'=>['cow'=>['enabled'=>true,'weight-multiplier'=>2]],'world-overrides'=>['lobby'=>['mobs'=>['cow'=>['enabled'=>false]]]]],'lobby');
check(!$settings->allows('cow',['y'=>100]),'nested world override');check($settings->weight('cow',10)===20,'world override keeps other nested keys');
$settings=new S(['mobs'=>['zombie'=>['min-y'=>-20,'max-y'=>80,'time'=>'night','max-block-light'=>0,'biomes'=>['plains']]]]);
$ctx=['y'=>40,'night'=>true,'light'=>0,'biome'=>'plains'];
check($settings->allows('zombie',$ctx),'configured habitat');
foreach(['y'=>90,'night'=>false,'light'=>1,'biome'=>'ocean'] as $k=>$v){ check(!$settings->allows('zombie',array_replace($ctx,[$k=>$v])),'reject habitat '.$k); }
check(!(new S([]))->allows('wanderingtrader',$ctx),'removed trader stays disabled');
check((new S(['mobs'=>['cow'=>['weight-multiplier'=>0]]]))->weight('cow',22)===0,'zero weight disables');
check((new S(['mobs'=>['cod'=>['group-min'=>40,'group-max'=>90]]]))->group('cod',5,8)===[16,16],'group size bounded');
echo $passed," rule checks passed\n";
