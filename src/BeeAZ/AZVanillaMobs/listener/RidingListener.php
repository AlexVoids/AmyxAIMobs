<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\listener;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\entity\overworld\Horse;
use BeeAZ\AZVanillaMobs\entity\overworld\Donkey;
use BeeAZ\AZVanillaMobs\entity\overworld\Mule;
use BeeAZ\AZVanillaMobs\entity\overworld\Camel;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\math\Vector3;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use ReflectionClass;
use ReflectionException;

class RidingListener implements Listener
{
    private Main $plugin;

    public function __construct(Main $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onPlayerToggleSneak(PlayerToggleSneakEvent $event): void
    {
        $player = $event->getPlayer();
        if ($event->isSneaking()) {
            foreach ($player->getWorld()->getEntities() as $entity) {
                if (($entity instanceof Horse || $entity instanceof Donkey || $entity instanceof Mule || $entity instanceof Camel) && $entity->getRider() !== null && $entity->getRider()->getId() === $player->getId()) {
                    $entity->dismountPlayer();
                    break;
                }
            }
        }
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        if ($packet instanceof PlayerAuthInputPacket) {
            $player = $event->getOrigin()->getPlayer();
            if ($player !== null) {
                foreach ($player->getWorld()->getEntities() as $entity) {
                    if (($entity instanceof Horse || $entity instanceof Donkey || $entity instanceof Mule || $entity instanceof Camel) && $entity->getRider() !== null && $entity->getRider()->getId() === $player->getId()) {
                        $horseLocation = $entity->getLocation();
                        $seatHeight = 2.0;
                        if ($entity instanceof Horse) {
                            $seatHeight = 2.3;
                        } elseif ($entity instanceof Camel) {
                            $seatHeight = 2.8;
                        }

                        $expectedY = $horseLocation->y + $seatHeight;
                        $eyePosition = new Vector3($horseLocation->x, $expectedY + 1.62, $horseLocation->z);
                        $originalPosition = $packet->getPosition();
                        $clientFeetY = $originalPosition->y - 1.62;

                        if (abs($clientFeetY - $expectedY) > 0.12) {
                            $pk = MovePlayerPacket::create(
                                $player->getId(),
                                $eyePosition,
                                $player->getLocation()->pitch,
                                $player->getLocation()->yaw,
                                $player->getLocation()->yaw,
                                MovePlayerPacket::MODE_NORMAL,
                                $player->onGround,
                                $entity->getId(),
                                0,
                                0,
                                0
                            );
                            $player->getNetworkSession()->sendDataPacket($pk);
                        }

                        $eyePosition = new Vector3($horseLocation->x, $expectedY + 1.62, $horseLocation->z);
                        try {
                            $reflection = new ReflectionClass($packet);
                            $property = $reflection->getProperty("position");
                            $property->setAccessible(true);
                            $property->setValue($packet, $eyePosition);
                        } catch (ReflectionException $e) {
                        }

                        $moveVecX = $packet->getMoveVecX();
                        $moveVecZ = $packet->getMoveVecZ();

                        if (abs($moveVecX) > 0.05 || abs($moveVecZ) > 0.05) {
                            $yaw = $player->getLocation()->yaw;
                            $forwardX = -sin(deg2rad($yaw));
                            $forwardZ = cos(deg2rad($yaw));
                            $rightX = cos(deg2rad($yaw));
                            $rightZ = sin(deg2rad($yaw));

                            $speed = 0.38;
                            if ($entity instanceof Horse) {
                                $speed = 0.52;
                            } elseif ($entity instanceof Camel) {
                                $speed = 0.42;
                            }

                            $motionX = ($forwardX * $moveVecZ + $rightX * $moveVecX) * $speed;
                            $motionZ = ($forwardZ * $moveVecZ + $rightZ * $moveVecX) * $speed;

                            $currentLocation = $entity->getLocation();
                            $newPos = $currentLocation->add($motionX, 0, $motionZ);

                            $blockAtNewPos = $entity->getWorld()->getBlock($newPos);
                            $blockAbove = $entity->getWorld()->getBlock($newPos->add(0, 1.0, 0));

                            if (!$blockAtNewPos->isSolid()) {
                                $entity->teleport($newPos);
                            } else if (!$blockAbove->isSolid()) {
                                $entity->teleport($newPos->add(0, 1.0, 0));
                            }
                        }
                        break;
                    }
                }
            }
        } elseif ($packet instanceof InteractPacket) {
            if ($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE) {
                $player = $event->getOrigin()->getPlayer();
                if ($player !== null) {
                    foreach ($player->getWorld()->getEntities() as $entity) {
                        if (($entity instanceof Horse || $entity instanceof Donkey || $entity instanceof Mule || $entity instanceof Camel) && $entity->getRider() !== null && $entity->getRider()->getId() === $player->getId()) {
                            $entity->dismountPlayer();
                            break;
                        }
                    }
                }
            }
        } elseif ($packet instanceof InventoryTransactionPacket) {
            $trData = $packet->trData;
            if ($trData instanceof UseItemOnEntityTransactionData) {
                if ($trData->getActionType() === UseItemOnEntityTransactionData::ACTION_INTERACT) {
                    $player = $event->getOrigin()->getPlayer();
                    if ($player !== null) {
                        $targetRuntimeId = $trData->getActorRuntimeId();
                        $entity = $player->getWorld()->getEntity($targetRuntimeId);
                        if ($entity !== null) {
                            $item = $player->getInventory()->getItemInHand();
                            if ($entity instanceof Horse || $entity instanceof Donkey || $entity instanceof Mule || $entity instanceof Camel) {
                                $saddleItem = StringToItemParser::getInstance()->parse("minecraft:saddle");
                                if ($saddleItem !== null && $item->getTypeId() === $saddleItem->getTypeId()) {
                                    if (!$entity->isSaddled()) {
                                        $entity->setSaddled(true);
                                        $item->setCount($item->getCount() - 1);
                                        $player->getInventory()->setItemInHand($item);
                                        return;
                                    }
                                }
                                if ($entity->isSaddled()) {
                                    $entity->mountPlayer($player);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
