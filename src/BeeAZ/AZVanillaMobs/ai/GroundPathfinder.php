<?php
declare(strict_types=1);
namespace BeeAZ\AZVanillaMobs\ai;

/** Bounded A*: cardinal steps prevent cutting through diagonal block corners.
 * The stand callback returns a safe feet Y, or null. No world loading occurs here.
 */
final class GroundPathfinder{
    /** @return list<array{int,int,int}> excludes start; may end at nearest reachable point */
    public static function find(array $start,array $goal,\Closure $stand,int $maxNodes=192,int $radius=24,?float $deadline=null): array{
        $key=static fn(array $p):string=>implode(':',$p);
        $heuristic=static fn(array $p):float=>abs($goal[0]-$p[0])+abs($goal[2]-$p[2])+abs($goal[1]-$p[1])*0.5;
        $first=$key($start);$best=$first;$bestH=$heuristic($start);
        $nodes=[$first=>$start];$cost=[$first=>0.0];$from=[];$closed=[];
        $open=new \SplPriorityQueue();$open->insert($first,-$bestH);
        for($count=0;!$open->isEmpty() && $count<max(1,min(2048,$maxNodes));){
            if($deadline!==null && microtime(true)>$deadline){ break; }
            $current=$open->extract();if(isset($closed[$current])){ continue; }$closed[$current]=true;++$count;
            $p=$nodes[$current];$h=$heuristic($p);
            if($h<$bestH){ $best=$current;$bestH=$h; }
            if($p===$goal || ($h<=1 && abs($goal[1]-$p[1])<=1)){ $best=$current;break; }
            foreach([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dz]){
                $x=$p[0]+$dx;$z=$p[2]+$dz;
                if(abs($x-$start[0])>$radius || abs($z-$start[2])>$radius){ continue; }
                $y=$stand($x,$z,$p[1],$p[0],$p[2]);if($y===null){ continue; }
                $next=[$x,$y,$z];$id=$key($next);
                $g=$cost[$current]+1.0+abs($y-$p[1])*0.5;
                if(isset($closed[$id]) || $g>=($cost[$id]??INF)){ continue; }
                $nodes[$id]=$next;$cost[$id]=$g;$from[$id]=$current;
                $open->insert($id,-($g+$heuristic($next)));
            }
        }
        $path=[];
        while($best!==$first){ $path[]=$nodes[$best];$best=$from[$best]; }
        return array_reverse($path);
    }
}
