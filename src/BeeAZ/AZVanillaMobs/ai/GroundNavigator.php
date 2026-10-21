<?php
declare(strict_types=1);
namespace BeeAZ\AZVanillaMobs\ai;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use pocketmine\math\Vector3;

final class GroundNavigator{
    private array $path=[];
    private int $retryAt=0;
    private ?Vector3 $goal=null;
    private static int $budgetTick=-1;
    private static int $used=0;
    private static float $deadline=0;
    public function next(BaseMob $mob,Vector3 $goal,array $options): ?Vector3{
        $tick=\pocketmine\Server::getInstance()->getTick();$pos=$mob->getLocation();
        if($this->goal!==null && $this->goal->distanceSquared($goal)>4){ $this->path=[]; }
        while($this->path!==[]){
            [$x,$y,$z]=$this->path[0];
            if(($pos->x-$x-0.5)**2+($pos->z-$z-0.5)**2>0.16 || abs($pos->y-$y)>0.75){ break; }
            array_shift($this->path);
        }
        if(self::$budgetTick!==$tick){
            self::$budgetTick=$tick;self::$used=0;
            self::$deadline=microtime(true)+max(0.5,min(10,(float)($options['path-budget-ms']??3)))/1000;
        }
        $evaluator=new WalkEvaluator($mob->getWorld(),$mob->getSize()->getWidth(),$mob->getSize()->getHeight());
        if($tick >= $this->retryAt && self::$used<max(1,min(16,(int)($options['paths-per-tick']??4))) && microtime(true)<self::$deadline){
            ++self::$used;$this->retryAt=$tick+max(10,min(100,(int)($options['repath-ticks']??20)));$this->goal=clone $goal;
            $this->path=GroundPathfinder::find([$pos->getFloorX(),$pos->getFloorY(),$pos->getFloorZ()],[(int)floor($goal->x),(int)floor($goal->y),(int)floor($goal->z)],$evaluator->stand(...),max(16,min(1024,(int)($options['max-path-nodes']??192))),max(4,min(48,(int)($options['path-radius']??24))),self::$deadline);
        }
        if($this->path===[]){ return null; }
        [$x,$y,$z]=$this->path[0];
        if(!$evaluator->safe($x,$y,$z) || abs($x-$pos->getFloorX())+abs($z-$pos->getFloorZ())>2){ $this->path=[];return null; }
        return new Vector3($x+0.5,$y,$z+0.5);
    }
}
