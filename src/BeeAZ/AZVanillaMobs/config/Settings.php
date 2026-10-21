<?php
declare(strict_types=1);
namespace BeeAZ\AZVanillaMobs\config;

/** Immutable effective spawn profile. All keys are also usable in world-overrides. */
final class Settings{
    public function __construct(private array $values){}
    public static function fillMissing(array $actual, array $defaults): array{
        foreach($defaults as $key=>$value){
            if(!array_key_exists($key,$actual)){ $actual[$key]=$value; }
            elseif(is_array($value) && is_array($actual[$key]) && !array_is_list($value)){
                $actual[$key]=self::fillMissing($actual[$key],$value);
            }
        }
        return $actual;
    }
    public static function forWorld(array $config, string $world): self{
        $overrides=$config['world-overrides'][$world] ?? [];
        if(is_array($overrides)){
            foreach($overrides as $key=>$value){
                $config[$key]=in_array($key,['mobs','entity-type-limits'],true) && is_array($value)
                    ? array_replace_recursive((array)($config[$key]??[]),$value) : $value;
            }
        }
        return new self($config);
    }
    public function get(string $key, mixed $fallback=null): mixed{ return $this->values[$key]??$fallback; }
    public static function name(string $class): string{ return strtolower(substr($class,strrpos($class,'\\')+1)); }
    public function mob(string $name): array{ return (array)($this->values['mobs'][$name]??[]); }
    public function allows(string $name, array $context): bool{
        $rule=$this->mob($name);
        if(!($rule['enabled']??true) || $name==='wanderingtrader'){ return false; }
        if(($this->values['entity-type-limits'][$name]??1)<=0){ return false; }
        if(($context['y']??0)<($rule['min-y']??-64) || ($context['y']??0)>($rule['max-y']??319)){ return false; }
        $time=$rule['time']??'any';
        if(($time==='night' && !($context['night']??false)) || ($time==='day' && ($context['night']??false))){ return false; }
        if(($context['light']??0)>($rule['max-block-light']??15)){ return false; }
        $biomes=(array)($rule['biomes']??[]);
        if($biomes!==[] && !in_array(strtolower($context['biome']??''),array_map('strtolower',$biomes),true)){ return false; }
        return true;
    }
    public function weight(string $name,int $base): int{
        return max(0,min(10000,(int)round($base*max(0,min(100,(float)($this->mob($name)['weight-multiplier']??1))))));
    }
    public function group(string $name,int $defaultMin,int $defaultMax): array{
        $rule=$this->mob($name);$min=max(1,min(16,(int)($rule['group-min']??$defaultMin)));
        return [$min,max($min,min(16,(int)($rule['group-max']??$defaultMax)))];
    }
}
