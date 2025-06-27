<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use pocketmine\entity\Attribute;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use function array_map;

/**
 * Uses pocketmine\network classes and reflects a scaled-down version of pocketmine's Entity class. The latter is an
 * overkill here because 1. we will only be displaying this entity to 1 player, and 2. this entity does not need all
 * the movement physics logic. A non-Entity class also means our entity remains "hidden" from getWorldEntities() and
 * other similar entity-querying methods.
 *
 * While the goal is to isolate pocketmine\network uses, this is not entirely possible in some places. PRs are welcome.
 */
final class NetworkEntity{

	/**
	 * @param Player $player
	 * @param int $id
	 * @param string $type
	 * @param Location $location
	 * @param Vector3|null $motion
	 * @param array<int, MetadataProperty> $metadata
	 * @param list<Attribute> $attributes
	 * @param list<EntityLink> $links
	 */
	public static function spawnTo(Player $player, int $id, string $type, Location $location, ?Vector3 $motion = null, array $metadata = [], array $attributes = [], array $links = []) : void{
		$player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
			$id,
			$id,
			$type,
			$location,
			$motion ?? Vector3::zero(),
			$location->pitch,
			$location->yaw,
			$location->yaw,
			$location->yaw,
			array_map(static fn(Attribute $attr) : NetworkAttribute => new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []), $attributes),
			$metadata,
			new PropertySyncData([], []),
			$links
		));
	}

	public static function despawnFrom(Player $player, int $id) : void{
		$player->getNetworkSession()->sendDataPacket(RemoveActorPacket::create($id));
	}

	/**
	 * @param Player $player
	 * @param int $id
	 * @param array<int, MetadataProperty> $metadata
	 */
	public static function sendData(Player $player, int $id, array $metadata) : void{
		$player->getNetworkSession()->sendDataPacket(SetActorDataPacket::create($id, $metadata, new PropertySyncData([], []), 0));
	}

	public static function sendLink(Player $player, EntityLink $link) : void{
		$player->getNetworkSession()->sendDataPacket(SetActorLinkPacket::create($link));
	}
}