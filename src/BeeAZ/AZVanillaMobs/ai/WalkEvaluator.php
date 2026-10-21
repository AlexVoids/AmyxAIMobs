<?php
declare(strict_types=1);
namespace BeeAZ\AZVanillaMobs\ai;
use pocketmine\math\AxisAlignedBB;
use pocketmine\world\World;

/** Collision-aware ground checks, shared by navigation and idle destinations. */
final class WalkEvaluator{
    public function __construct(private World $world,private float $width,private float $height){}
    public function stand(int $x,int $z,int $fromY,?int $fromX=null,?int $fromZ=null): ?int{
        // Flat first: never jump onto an adjacent roof when a ground route exists.
        foreach([0,1,-1,-2,-3] as $offset){
            $y=$fromY+$offset;
            if($this->safe($x,$y,$z)){
                if($offset===1 && $fromX!==null && !$this->clear($fromX,$fromY+1,$fromZ,$this->height)){ continue; }
                return $y;
            }
        }
        return null;
    }
    public function safe(int $x,int $y,int $z): bool{
        if($y< $this->world->getMinY()+1 || $y+$this->height >= $this->world->getMaxY()){ return false; }
        if(!$this->world->isChunkLoaded($x>>4,$z>>4)){ return false; }
        $support=$this->world->getBlockAt($x,$y-1,$z);
        if(!$support->isSolid() || $this->hazard($support) || $support->getCollisionBoxes()===[]){ return false; }
        // Reject fence tops, thin supports and low/slab floors until fractional-Y navigation exists.
        $supported=false;
        foreach($support->getCollisionBoxes() as $bb){
            if(abs($bb->maxY-$y)<0.01 && $bb->minX<=$x+0.5 && $bb->maxX>=$x+0.5 && $bb->minZ<=$z+0.5 && $bb->maxZ>=$z+0.5){ $supported=true; }
        }
        return $supported && $this->clear($x,$y,$z,$this->height);
    }
    public function clear(int $x,int $y,int $z,float $height): bool{
        $half=$this->width/2;$box=new AxisAlignedBB($x+0.5-$half+0.001,$y+0.001,$z+0.5-$half+0.001,$x+0.5+$half-0.001,$y+$height-0.001,$z+0.5+$half-0.001);
        for($bx=(int)floor($box->minX);$bx<=$box->maxX;$bx++){
            for($bz=(int)floor($box->minZ);$bz<=$box->maxZ;$bz++){
                if(!$this->world->isChunkLoaded($bx>>4,$bz>>4)){ return false; }
                for($by=$y;$by<=$box->maxY;$by++){
                    $block=$this->world->getBlockAt($bx,$by,$bz);
                    if($this->hazard($block) || $block instanceof \pocketmine\block\Liquid){ return false; }
                    foreach($block->getCollisionBoxes() as $bb){ if($bb->intersectsWith($box)){ return false; } }
                }
            }
        }
        return true;
    }
    private function hazard(\pocketmine\block\Block $block): bool{
        return $block instanceof \pocketmine\block\Liquid || preg_match('/lava|fire|cactus|magma|campfire|sweet berry|powder snow/i',$block->getName())===1;
    }
}
