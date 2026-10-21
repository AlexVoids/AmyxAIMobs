<?php
declare(strict_types=1);
namespace AmyxAIMobsTests;

use pocketmine\network\mcpe\{InventoryManager,NetworkSession,EntityEventBroadcaster,StandardEntityEventBroadcaster,PacketBroadcaster};
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;

final class SyntheticSession extends NetworkSession{
    public InventoryManager $manager;
    public array $sent=[];
    public ?TypeConverter $converter=null;
    private ?EntityEventBroadcaster $events=null;
    private ?PacketBroadcaster $packets=null;
    public function __construct(private Player $actor){}
    public function getPlayer(): ?Player{ return $this->actor; }
    public function getInvManager(): ?InventoryManager{ return $this->manager; }
    public function getTypeConverter(): TypeConverter{ return $this->converter ?? TypeConverter::getInstance(); }
    public function sendDataPacket(ClientboundPacket $packet,bool $immediate=false): bool{ $this->sent[]=$packet;return true; }
    public function getBroadcaster(): PacketBroadcaster{
        return $this->packets ??= new class implements PacketBroadcaster{
            public function broadcastPackets(array $recipients,array $packets): void{
                foreach($recipients as $recipient){ foreach($packets as $packet){ $recipient->sendDataPacket($packet); } }
            }
        };
    }
    public function getEntityEventBroadcaster(): EntityEventBroadcaster{
        if($this->events===null){
            $first=(new \ReflectionMethod(StandardEntityEventBroadcaster::class,'__construct'))->getParameters()[0]->getType()->getName();
            if($first===\pocketmine\network\mcpe\StandardPacketBroadcaster::class){
                $context=new \pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext(TypeConverter::getInstance()->getItemTypeDictionary());
                $broadcaster=new \pocketmine\network\mcpe\StandardPacketBroadcaster(\pocketmine\Server::getInstance(),$context);
            }else{ $broadcaster=$this->getBroadcaster(); }
            $this->events=new StandardEntityEventBroadcaster($broadcaster,$this->getTypeConverter());
        }
        return $this->events;
    }
    public function getLogger(): \Logger{ return \pocketmine\Server::getInstance()->getLogger(); }
    public function isConnected(): bool{ return true; }
    public function __destruct(){}
}
