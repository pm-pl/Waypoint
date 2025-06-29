<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use Closure;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\World;
use RuntimeException;
use function ceil;
use function count;
use function sqrt;

final class WaypointRenderer{

	private const UPDATER_MOVEMENT = 0;
	private const UPDATER_WORLD_CHANGE = 1;

	readonly public int $dummy_entity_id; // this entity rides the player. the actual waypoint entity rides this entity.
	readonly public int $entity_id; // this entity is name-tagged

	/** @var list<Closure(self) : void> */
	public array $destroy_callbacks = [];

	/** @var self::UPDATER_* */
	private int $updater_state = self::UPDATER_MOVEMENT;

	public function __construct(
		readonly public Waypoint $waypoint,
		readonly public Player $viewer,
		readonly public float $distance, // how far the waypoint is from the player. influences the "size" of the text.
		readonly public string $format
	){
		$this->dummy_entity_id = Entity::nextRuntimeId();
		$this->entity_id = Entity::nextRuntimeId();
		$this->showGraphic();
		WaypointPlugin::$players[$this->viewer->getId()][$this->entity_id] = $this;
		$pos = $this->viewer->getPosition();
		$this->update($pos->world, $pos->world, $pos);
		foreach(WaypointPlugin::$listeners as $listener){
			$listener->onRender($this);
		}
	}

	public function destroy() : void{
		$this->hideGraphic();
		if(isset(WaypointPlugin::$players[$id = $this->viewer->getId()][$this->entity_id])){
			unset(WaypointPlugin::$players[$id][$this->entity_id]);
			if(count(WaypointPlugin::$players[$id]) === 0){
				unset(WaypointPlugin::$players[$id]);
			}
		}
		foreach($this->destroy_callbacks as $callback){
			$callback($this);
		}
		$this->destroy_callbacks = [];
		foreach(WaypointPlugin::$listeners as $listener){
			$listener->onDerender($this);
		}
	}

	/**
	 * @return list<EntityLink>
	 */
	private function getEntityLinks() : array{
		return [
			new EntityLink($this->dummy_entity_id, $this->entity_id, EntityLink::TYPE_PASSENGER, true, false, 0.0),
			new EntityLink($this->viewer->getId(), $this->dummy_entity_id, EntityLink::TYPE_RIDER, true, false, 0.0)
		];
	}

	public function showGraphic() : void{
		$metadata = new EntityMetadataCollection();
		$metadata->setGenericFlag(EntityMetadataFlags::INVISIBLE, true);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.01);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 0.01);
		$metadata->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 1);
		NetworkEntity::spawnTo($this->viewer, $this->dummy_entity_id, EntityIds::SNOWBALL, Location::fromObject(Vector3::zero(), null), Vector3::zero(), $metadata->getAll());

		$metadata = new EntityMetadataCollection();
		$metadata->setGenericFlag(EntityMetadataFlags::IMMOBILE, true);
		$metadata->setFloat(EntityMetadataProperties::SCALE, 0.01);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.01);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 0.01);
		$metadata->setByte(EntityMetadataProperties::ALWAYS_SHOW_NAMETAG, 1);
		$metadata->setInt(EntityMetadataProperties::VARIANT, TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId()));
		NetworkEntity::spawnTo($this->viewer, $this->entity_id, EntityIds::FALLING_BLOCK, Location::fromObject($this->viewer->getEyePos(), null), Vector3::zero(), $metadata->getAll(), [], $this->getEntityLinks());
	}

	public function hideGraphic() : void{
		NetworkEntity::despawnFrom($this->viewer, $this->entity_id);
		NetworkEntity::despawnFrom($this->viewer, $this->dummy_entity_id);
	}

	public function sendLinks() : void{
		// called during PlayerRespawnEvent event - on death, entity links are reset
		foreach($this->getEntityLinks() as $link){
			NetworkEntity::sendLink($this->viewer, $link);
		}
	}

	public function update(?World $from_world, ?World $to_world, Vector3 $to) : void{
		// called during PlayerMoveEvent
		switch($this->updater_state){
			case self::UPDATER_MOVEMENT:
				// for some odd reason, waypoints despawn when you tp far away so this logic is needed.
				if($from_world !== $to_world){
					// the waypoint is hidden from the player during world change.
					// it is later displayed when the player moves for the first time
					// after the world changes. resending the waypoint right away
					// does not work when player is viewing dimension change screen.
					$this->updater_state = self::UPDATER_WORLD_CHANGE;
					$this->hideGraphic();
					return;
				}

				$x = $this->waypoint->x - $to->x;
				$y = $this->waypoint->y - $to->y;
				$z = $this->waypoint->z - $to->z;
				$len_sq = ($x * $x) + ($z * $z);
				$len = sqrt($len_sq);

				if($len > 0){
					$x /= $len;
					$y /= $len;
					$z /= $len;
				}

				$x *= $this->distance;
				$y *= $this->distance;
				$z *= $this->distance;

				$metadata = [
					EntityMetadataProperties::RIDER_SEAT_POSITION => new Vec3MetadataProperty(new Vector3($x, $y + 0.295, $z)),
					EntityMetadataProperties::NAMETAG => new StringMetadataProperty(strtr($this->format, [
						"{TITLE}" => $this->waypoint->title,
						"{DISTANCE}" => (string) ceil(sqrt($len_sq + ($y * $y)))
					]))
				];
				NetworkEntity::sendData($this->viewer, $this->entity_id, $metadata);
				break;
			case self::UPDATER_WORLD_CHANGE:
				$this->showGraphic();
				$this->updater_state = self::UPDATER_MOVEMENT;
				break;
			default:
				throw new RuntimeException("Invalid updater state: {$this->updater_state}");
		}
	}
}