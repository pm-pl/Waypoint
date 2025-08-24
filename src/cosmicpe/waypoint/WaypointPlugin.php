<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use cosmicpe\awaitform\AwaitForm;
use cosmicpe\awaitform\AwaitFormException;
use cosmicpe\awaitform\FormControl;
use Generator;
use InvalidArgumentException;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\VanillaCommand;
use pocketmine\command\PluginCommand;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use function array_column;
use function array_key_first;
use function array_map;
use function array_slice;
use function ceil;
use function count;
use function ctype_alnum;
use function current;
use function implode;
use function is_int;
use function is_numeric;
use function mb_substr;
use function round;
use function spl_object_id;
use function strlen;
use function strtolower;
use function substr;
use function time;

final class WaypointPlugin extends PluginBase implements Listener{

	/** @var array<int, non-empty-array<int, WaypointRenderer>> */
	public static array $players = [];

	/** @var list<WaypointListener> */
	public static array $listeners = [];

	/** @var array<string, array<string, int>> */
	private array $runtime_lookup = [];

	/** @var array<int, Vector3> */
	private array $known_player_locations = [];

	/** @var array<int, true> */
	private array $operation_locks = [];

	private DataConnector $database;
	public WaypointLimitEvaluator $limit_evaluator;
	public float $movement_delta_sq;

	protected function onLoad() : void{
		$config = $this->getConfig();

		$movement_delta = $config->get("movement-delta");
		if(!is_int($movement_delta) || $movement_delta < 0){
			$movement_delta = 1.0;
			$this->getLogger()->warning("'movement-delta' must be a positive float, falling back to {$movement_delta}");
		}
		$this->movement_delta_sq = $movement_delta ** 2;

		$configurable_limit = $config->get("configurable-limit");
		if(!is_int($configurable_limit) || $configurable_limit < 0){
			$configurable_limit = 64;
			$this->getLogger()->warning("'configurable-limit' must be a positive integer, falling back to {$configurable_limit}");
		}
		$selectable_limit = $config->get("selectable-limit");
		if(!is_int($selectable_limit) || $selectable_limit < 0){
			$selectable_limit = 1;
			$this->getLogger()->warning("'selectable-limit' must be a positive integer, falling back to {$selectable_limit}");
		}
		$this->limit_evaluator ??= new StaticWaypointLimitEvaluator($configurable_limit, $selectable_limit);
	}

	protected function onEnable() : void{
		$this->database = libasynql::create($this, $this->getConfig()->get("database"), ["sqlite" => "sqlite.sql", "mysql" => "mysql.sql"]);

		if(!AwaitForm::isRegistered()){
			AwaitForm::register($this);
		}

		$command = $this->getCommand("waypoint");
		$command instanceof PluginCommand || throw new RuntimeException("Command not found");
		$command->setExecutor(new class implements CommandExecutor{
			public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
				$sender->sendMessage(TextFormat::RED . "Plugin is loading, please try again shortly.");
				return true;
			}
		});

		Await::f2c(function() use($command) : Generator{
			yield from Await::all([
				$this->database->asyncGeneric("waypoint.create_waypoints"),
				$this->database->asyncGeneric("waypoint.create_holders")
			]);

			// everything is ready.
			$command->setExecutor($this);
			$this->getServer()->getPluginManager()->registerEvents($this, $this);
		});
	}

	public function validateName(string $name) : void{
		$length = strlen($name);
		$length >= 1 || throw new InvalidArgumentException("Waypoint name cannot be empty");
		$length <= 64 || throw new InvalidArgumentException("Waypoint name cannot be longer than 64 characters");
		ctype_alnum(strtr($name, ["_" => ""])) || throw new InvalidArgumentException("Waypoint name must be alpha-numeric");
	}

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, array{string, float}|null>
	 */
	public function getPreferences(UuidInterface $uuid) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.preferences", ["uuid" => $uuid->toString()]);
		if(count($result) === 0){
			return null;
		}
		return [$result[0]["display"], $result[0]["distance"]];
	}

	/**
	 * @param UuidInterface $uuid
	 * @param string $display
	 * @param float $distance
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function setPreferences(UuidInterface $uuid, string $display, float $distance) : Generator{
		yield from $this->database->asyncChange("waypoint.set_preferences", [
			"uuid" => $uuid->toString(), "display" => $display, "distance" => $distance
		]);
	}

	/**
	 * @param UuidInterface $uuid
	 * @param string $name
	 * @param Waypoint $waypoint
	 * @param bool $selected
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function setWaypoint(UuidInterface $uuid, string $name, Waypoint $waypoint, bool $selected) : Generator{
		$changed = yield from $this->database->asyncChange("waypoint.set", [
			"uuid" => $uuid->toString(), "name" => $name, "title" => $waypoint->title, "x" => $waypoint->x,
			"y" => $waypoint->y, "z" => $waypoint->z, "selected" => (int) $selected, "updated" => time()
		]);
		if($changed > 0){
			foreach(self::$listeners as $listener){
				$listener->onCreate($uuid, $name, $waypoint);
			}
		}
	}

	/**
	 * @param UuidInterface $uuid
	 * @param string $name
	 * @return Generator<mixed, Await::RESOLVE, void, array{Waypoint, string, bool}|null>
	 */
	public function getWaypoint(UuidInterface $uuid, string $name) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.get", ["uuid" => $uuid->toString(), "name" => $name]);
		if(count($result) === 0){
			return null;
		}
		$result = $result[0];
		return [new Waypoint($result["title"], $result["x"], $result["y"], $result["z"]), $result["name"], (bool) $result["selected"]];
	}

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, list<array{Waypoint, string}>>
	 */
	public function getSelectedWaypoints(UuidInterface $uuid) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.selected", ["uuid" => $uuid->toString()]);
		$waypoints = [];
		foreach($result as ["name" => $name, "title" => $title, "x" => $x, "y" => $y, "z" => $z]){
			$waypoints[] = [new Waypoint($title, $x, $y, $z), $name];
		}
		return $waypoints;
	}

	/**
	 * @param UuidInterface $uuid
	 * @param int $offset
	 * @param int $length
	 * @return Generator<mixed, Await::RESOLVE, void, list<array{Waypoint, string, bool}>>
	 */
	public function listWaypoints(UuidInterface $uuid, int $offset, int $length) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.list", ["uuid" => $uuid->toString(), "offset" => $offset, "length" => $length]);
		$waypoints = [];
		foreach($result as ["name" => $name, "title" => $title, "x" => $x, "y" => $y, "z" => $z, "selected" => $selected]){
			$waypoints[] = [new Waypoint($title, $x, $y, $z), $name, (bool) $selected];
		}
		return $waypoints;
	}

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function listWaypointNames(UuidInterface $uuid) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.list_names", ["uuid" => $uuid->toString()]);
		return array_column($result, "name");
	}

	/**
	 * @param UuidInterface $uuid
	 * @param string $name
	 * @return Generator<mixed, Await::RESOLVE, void, bool>
	 */
	public function deleteWaypoint(UuidInterface $uuid, string $name) : Generator{
		$result = yield from $this->database->asyncChange("waypoint.delete", ["uuid" => $uuid->toString(), "name" => $name]);
		if($result === 0){
			return false;
		}
		foreach(self::$listeners as $listener){
			$listener->onDelete($uuid, $name);
		}
		return true;
	}

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function getWaypointCount(UuidInterface $uuid) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.count", ["uuid" => $uuid->toString()]);
		return $result[0]["c"] ?? 0;
	}

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function getSelectedWaypointCount(UuidInterface $uuid) : Generator{
		$result = yield from $this->database->asyncSelect("waypoint.count_configured", ["uuid" => $uuid->toString()]);
		return $result[0]["c"] ?? 0;
	}

	/**
	 * @param UuidInterface $uuid
	 * @param list<array{string, array{Waypoint, bool}|null}> $waypoints
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function syncWaypoints(UuidInterface $uuid, array $waypoints) : Generator{
		$player = $this->getServer()->getPlayerByUUID($uuid);
		if($player === null){
			return;
		}

		$player_id = $player->getId();
		$remove = [];
		$add = [];
		foreach($waypoints as [$name, $value]){
			$name = strtolower($name);
			if($value === null){
				$remove[$name] = $name;
				continue;
			}
			[$waypoint, $selected] = $value;
			if(!$selected){
				$remove[$name] = $name;
				continue;
			}
			if(!isset($this->runtime_lookup[$player_id][$name]) || isset($remove[$name])){
				$add[$name] = $waypoint;
				continue;
			}
			$id = $this->runtime_lookup[$player_id][$name];
			if(!self::$players[$player_id][$id]->waypoint->equals($waypoint)){
				$remove[$name] = $name;
				$add[$name] = $waypoint;
			}
		}
		foreach($remove as $name){
			if(isset($this->runtime_lookup[$player_id][$name])){
				$id = $this->runtime_lookup[$player_id][$name];
				self::$players[$player_id][$id]->destroy();
			}
		}

		if(count($add) === 0){
			return;
		}
		$preferences = yield from $this->getPreferences($uuid);
		if($preferences === null || !$player->isConnected()){
			return;
		}
		[$display, $distance] = $preferences;
		foreach($add as $name => $waypoint){
			$renderer = new WaypointRenderer($waypoint, $player, $distance, $display);
			$this->runtime_lookup[$player_id][$name] = $renderer->entity_id;
			$renderer->destroy_callbacks[] = function(WaypointRenderer $renderer) use($name) : void{
				unset($this->runtime_lookup[$renderer->viewer->getId()][$name]);
			};
		}
	}

	/**
	 * @param Player $player
	 * @param array{string, float}|null $preference
	 * @return Generator<mixed, Await::RESOLVE, void, array{string, float}|null>
	 */
	public function requestUpdatePreferences(Player $player, ?array $preference) : Generator{
		$uuid = $player->getUniqueId();
		$preference ??= [TextFormat::AQUA . "{TITLE}" . TextFormat::LIGHT_PURPLE . " [" . TextFormat::DARK_PURPLE . "{DISTANCE}m" . TextFormat::LIGHT_PURPLE . "]", 5.0];
		[$display, $distance] = $preference;
		while(true){
			try{
				[$display, $distance] = yield from AwaitForm::form("Waypoint Preferences", [
					FormControl::input("Display Format", $display, $display),
					FormControl::slider("Distance", 0.0, 16.0, 1.25, $distance)
				])->request($player);
			}catch(AwaitFormException){
				break;
			}
			try{
				$display = mb_substr(TextFormat::colorize($display), 0, 255);
			}catch(InvalidArgumentException){
				if($player->isConnected()){
					$player->sendToastNotification(
						TextFormat::BOLD . TextFormat::RED . "Invalid display specified!",
						TextFormat::GRAY . "Display contains illegal characters, please pick a different display format."
					);
				}
				continue;
			}
			yield from $this->setPreferences($uuid, $display, $distance);
			$waypoints = yield from $this->getSelectedWaypoints($uuid);
			$selected = [];
			foreach($waypoints as [$waypoint, $name]){
				$selected[] = [$name, null];
				$selected[] = [$name, [$waypoint, true]];
			}
			yield from $this->syncWaypoints($uuid, $selected);
			$this->_message($player, TextFormat::BOLD . TextFormat::GREEN . "(!) " . TextFormat::RESET . TextFormat::GREEN . "Your waypoint preferences have been updated!");
			return [$display, $distance];
		}
		return null;
	}

	/**
	 * @param PlayerJoinEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		Await::f2c(function() use($player) : Generator{
			$this->operation_locks[$id = spl_object_id($player)] = true;
			$uuid = $player->getUniqueId();
			$state = "load";
			$waypoints = [];
			$limit = 0;
			while($state !== null){
				if(!$player->isConnected()){
					$state = null;
				}elseif($state === "load"){
					[$waypoints, $limit] = yield from Await::all([
						$this->getSelectedWaypoints($uuid),
						$this->limit_evaluator->evaluateSelectedWaypointLimits($uuid)
					]);
					$state = count($waypoints) > 0 ? "wait" : null;
				}elseif($state === "wait"){
					yield from Await::promise(fn($resolve) => $this->getScheduler()->scheduleDelayedTask(new ClosureTask($resolve), 20));
					$state = "render";
				}elseif($state === "render"){
					$tasks = [];
					foreach(array_slice($waypoints, $limit) as [$waypoint, $name]){
						$tasks[] = $this->setWaypoint($uuid, $name, $waypoint, false);
					}
					$selected = array_map(static fn($e) => [$e[1], [$e[0], true]], array_slice($waypoints, 0, $limit));
					$tasks[] = $this->syncWaypoints($uuid, $selected);
					yield from Await::all($tasks);
					$state = null;
				}
			}
			unset($this->operation_locks[$id]);
		});
	}

	/**
	 * @param Player $player
	 * @param string $name
	 * @param string $title
	 * @param float $x
	 * @param float $y
	 * @param float $z
	 * @param bool $check_limits
	 * @return Generator<mixed, Await::RESOLVE, void, Waypoint|null>
	 */
	public function setWaypointForPlayer(Player $player, string $name, string $title, float $x, float $y, float $z, bool $check_limits = true) : Generator{
		$uuid = $player->getUniqueId();
		$preference = yield from $this->getPreferences($uuid);
		$preference ??= yield from $this->requestUpdatePreferences($player, $preference);
		if($preference === null){
			$this->_message($player, TextFormat::RED . "Cannot proceed without setting waypoint preferences.");
			return null;
		}
		try{
			$this->validateName($name);
		}catch(InvalidArgumentException $e){
			$this->_message($player, TextFormat::RED . $e->getMessage());
			return null;
		}
		try{
			$title = mb_substr(TextFormat::colorize($title), 0, 255);
		}catch(InvalidArgumentException){
			$this->_message($player, TextFormat::RED . "Invalid title specified");
			return null;
		}

		[$existing, $n_configurable, $n_selectable, $n_configured, $n_selected] = yield from Await::all([
			$this->getWaypoint($uuid, $name),
			$this->limit_evaluator->evaluateConfiguredWaypointLimits($uuid),
			$this->limit_evaluator->evaluateSelectedWaypointLimits($uuid),
			$this->getWaypointCount($uuid),
			$this->getSelectedWaypointCount($uuid)
		]);
		if($check_limits && $existing === null && $n_configured + 1 > $n_configurable){
			$this->_message($player, TextFormat::RED . "You are not allowed to configure more than {$n_configurable} waypoint" . ($n_configurable === 1 ? "" : "s") . ".");
			return null;
		}
		$tasks = [];
		if($n_selected + 1 > $n_selectable && isset($this->runtime_lookup[$player->getId()]) && !isset($this->runtime_lookup[$player->getId()][strtolower($name)])){
			$deselect = array_key_first($this->runtime_lookup[$player->getId()]);
			$deselect_waypoint = $deselect !== null ? yield from $this->getWaypoint($uuid, (string) $deselect) : null;
			if($deselect_waypoint !== null){
				$tasks[] = $this->setWaypoint($uuid, $deselect_waypoint[1], $deselect_waypoint[0], false);
				$tasks[] = $this->syncWaypoints($uuid, [[$deselect_waypoint[1], [$deselect_waypoint[0], false]]]);
			}
		}
		$waypoint = new Waypoint($title, $x, $y, $z);
		$tasks[] = $this->setWaypoint($uuid, $name, $waypoint, true);
		$tasks[] = $this->syncWaypoints($uuid, [[$name, [$waypoint, true]]]);
		yield from Await::all($tasks);
		$this->_message($player, TextFormat::BOLD . TextFormat::GREEN . "(!) " . TextFormat::RESET . TextFormat::GREEN .
			"Set waypoint {$name} at " . round($waypoint->x, 2) . "x, " . round($waypoint->y, 2) . "y " . round($waypoint->z, 2) . "z");
		return $waypoint;
	}

	/**
	 * @param PlayerQuitEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		$player = $event->getPlayer();
		if(isset(self::$players[$id = $player->getId()])){
			foreach(self::$players[$id] as $renderer){
				$renderer->destroy();
			}
			// self::$players[$id] cleanup is handled by renderer
		}
	}

	/**
	 * @param PlayerMoveEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerMove(PlayerMoveEvent $event) : void{
		$player = $event->getPlayer();
		if(isset(self::$players[$id = $player->getId()])){
			$this->processMovement($id, $event->getFrom(), $event->getTo());
		}
	}

	/**
	 * @param EntityTeleportEvent $event
	 * @priority MONITOR
	 */
	public function onEntityTeleport(EntityTeleportEvent $event) : void{
		$player = $event->getEntity();
		if(isset(self::$players[$id = $player->getId()])){
			$this->processMovement($id, $event->getFrom(), $event->getTo(), true);
		}
	}

	private function processMovement(int $player_id, Position $from, Position $to, bool $teleported = false) : void{
		if(
			$teleported ||
			$from->world !== $to->world ||
			$this->movement_delta_sq <= 0.0 ||
			!isset($this->known_player_locations[$player_id]) ||
			$this->known_player_locations[$player_id]->distanceSquared($to) > $this->movement_delta_sq
		){
			$from_world = ($teleported && $from->world === $to->world && $from->distanceSquared($to) > 4096) ? null : $from->world;
			foreach(self::$players[$player_id] as $renderer){
				$renderer->update($from_world, $to->world, $to);
			}
			$this->known_player_locations[$player_id] = $to->asVector3();
		}
	}

	/**
	 * @param PlayerRespawnEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerRespawn(PlayerRespawnEvent $event) : void{
		$player = $event->getPlayer();
		if(isset(self::$players[$id = $player->getId()])){
			foreach(self::$players[$id] as $renderer){
				$renderer->sendLinks();
			}
		}
	}

	private function _message(CommandSender $sender, string $message) : void{
		if(!($sender instanceof Player) || $sender->isConnected()){
			$sender->sendMessage($message);
		}
	}

	private function _getDouble(string $value, float $min, float $max) : float{
		$i = (double) $value;
		if($i < $min){
			$i = $min;
		}elseif($i > $max){
			$i = $max;
		}
		return $i;
	}

	private function _getRelativeDouble(float $original, string $input) : float{
		if($input[0] === "~"){
			$value = $this->_getDouble(substr($input, 1), VanillaCommand::MIN_COORD, VanillaCommand::MAX_COORD);
			return $original + $value;
		}
		return $this->_getDouble($input, VanillaCommand::MIN_COORD, VanillaCommand::MAX_COORD);
	}

	/**
	 * @param CommandSender $sender
	 * @param string $label
	 * @param array $args
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function onCommandAsync(CommandSender $sender, string $label, array $args) : Generator{
		if(!($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "This command can only be executed in-game.");
			return;
		}
		$uuid = $sender->getUniqueId();
		switch($args[0] ?? null){
			case "set":
				if(!$sender->hasPermission("waypoint.command.set")){
					$this->_message($sender, TextFormat::RED . "You do not have permission to use /{$label} {$args[0]}.");
					return;
				}
				if(count($args) < 5){
					$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name> <x> <y> <z> [title=name]");
					return;
				}
				$name = $args[1];
				$pos = $sender->getLocation();
				$x = $this->_getRelativeDouble($pos->x, $args[2]);
				$y = $this->_getRelativeDouble($pos->y, $args[3]);
				$z = $this->_getRelativeDouble($pos->z, $args[4]);
				$title = isset($args[5]) ? implode(" ", array_slice($args, 5)) : $name;
				yield from $this->setWaypointForPlayer($sender, $name, $title, $x, $y, $z);
				break;
			case "sethere":
				if(!$sender->hasPermission("waypoint.command.sethere")){
					$this->_message($sender, TextFormat::RED . "You do not have permission to use /{$label} {$args[0]}.");
					return;
				}
				if(count($args) < 2){
					$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name> [title=name]");
					return;
				}
				$name = $args[1];
				$title = isset($args[2]) ? implode(" ", array_slice($args, 2)) : $name;
				$pos = $sender->getPosition();
				yield from $this->setWaypointForPlayer($sender, $name, $title, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				break;
			case "delete":
				if(count($args) < 2){
					$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name>");
					return;
				}
				$name = $args[1];
				$success = yield from $this->deleteWaypoint($uuid, $name);
				yield from $this->syncWaypoints($uuid, [[$name, null]]);
				$this->_message($sender, $success ?
					TextFormat::BOLD . TextFormat::GREEN . "(!) " . TextFormat::RESET . TextFormat::GREEN . "You have deleted the waypoint {$name}!" :
					TextFormat::BOLD . TextFormat::RED . "(!) " . TextFormat::RESET . TextFormat::RED . "You do not have a waypoint named '{$name}' set!");
				break;
			case "toggle":
				$tasks = [];
				if(isset($args[1])){
					$selected = yield from $this->getWaypoint($uuid, $args[1]);
					if($selected === null){
						$this->_message($sender, TextFormat::GRAY . "You do not have a waypoint named '{$args[1]}' set!");
						return;
					}
					$value = !$selected[2];
					$selected = [[$selected[0], $selected[1]]];
					if($value){
						[$n_selectable, $n_selected] = yield from Await::all([
							$this->limit_evaluator->evaluateSelectedWaypointLimits($uuid),
							$this->getSelectedWaypointCount($uuid)
						]);
						if($n_selected + 1 > $n_selectable){
							$current = current(yield from $this->getSelectedWaypoints($uuid));
							if($current !== false){
								$tasks[] = $this->setWaypoint($uuid, $current[1], $current[0], false);
								$tasks[] = $this->syncWaypoints($uuid, [[$current[1], null]]);
							}
						}
					}
				}else{
					$selected = yield from $this->getSelectedWaypoints($uuid);
					if(count($selected) === 0){
						$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name>");
						$this->_message($sender, TextFormat::GRAY . "You currently do not have a waypoint selected. Specify a waypoint to toggle.");
						return;
					}
					$value = false;
				}
				/** @var non-empty-list<array{Waypoint, string}> $selected */
				$sync = [];
				foreach($selected as [$waypoint, $name]){
					$tasks[] = $this->setWaypoint($uuid, $name, $waypoint, $value);
					$sync[] = [$name, [$waypoint, $value]];
				}
				$tasks[] = $this->syncWaypoints($uuid, $sync);
				yield from Await::all($tasks);
				foreach($selected as [$waypoint, $name]){
					$this->_message($sender, TextFormat::BOLD . TextFormat::YELLOW . "(!) " . TextFormat::RESET . TextFormat::YELLOW . "Your waypoint ({$name}) is now " . ($value ? "visible" : "hidden") . "!");
				}
				break;
			case "cal":
			case "calibrate":
			case "face":
				if(count($args) < 2){
					$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name>");
					return;
				}
				$name = $args[1];
				$waypoint = yield from $this->getWaypoint($uuid, $name);
				if($waypoint === null){
					$this->_message($sender, TextFormat::BOLD . TextFormat::RED . "(!) " . TextFormat::RESET . TextFormat::RED . "You do not have a waypoint named '{$name}' set!");
					return;
				}
				[$waypoint, $name] = $waypoint;
				$sender->lookAt(new Vector3($waypoint->x, $waypoint->y, $waypoint->z));
				$sender->teleport($sender->getLocation());
				$this->_message($sender, TextFormat::BOLD . TextFormat::YELLOW . "(!) " . TextFormat::RESET . TextFormat::YELLOW . "You are now facing your waypoint ({$name})!");
				break;
			case "rename":
				if(count($args) < 3){
					$this->_message($sender, TextFormat::RED . "Usage: /{$label} {$args[0]} <name> <newTitle>");
					return;
				}

				$name = $args[1];
				$waypoint = yield from $this->getWaypoint($uuid, $name);
				if($waypoint === null){
					$this->_message($sender, TextFormat::BOLD . TextFormat::RED . "(!) " . TextFormat::RESET . TextFormat::RED . "You do not have a waypoint named '{$name}' set!");
					return;
				}
				try{
					$title = mb_substr(TextFormat::colorize(implode(" ", array_slice($args, 2))), 0, 255);
				}catch(InvalidArgumentException){
					$this->_message($sender, TextFormat::RED . "Invalid title specified");
					return;
				}
				[$waypoint, $name, $selected] = $waypoint;
				$waypoint = new Waypoint($title, $waypoint->x, $waypoint->y, $waypoint->z);
				yield from $this->setWaypoint($uuid, $name, $waypoint, $selected);
				if($selected){
					yield from $this->syncWaypoints($uuid, [[$name, null], [$name, [$waypoint, true]]]);
				}
				$this->_message($sender, TextFormat::BOLD . TextFormat::YELLOW . "(!) " . TextFormat::RESET . TextFormat::YELLOW . "Updated title of waypoint ({$name}) to {$title}" . TextFormat::RESET . TextFormat::YELLOW . "!");
				break;
			case "config":
			case "configure":
			case "preference":
			case "preferences":
				$preference = yield from $this->getPreferences($uuid);
				yield from $this->requestUpdatePreferences($sender, $preference);
				break;
			case "list":
				if(isset($args[1])){
					$page = $args[1];
					if(!is_numeric($page) || ((int) $page) < 1){
						$this->_message($sender, TextFormat::BOLD . TextFormat::RED . "(!) " . TextFormat::RESET . TextFormat::RED . "Invalid page number specified.");
						return;
					}
					$page = (int) $page;
				}else{
					$page = 1;
				}

				$length = 10;
				$offset = ($page - 1) * $length;
				[$waypoints, $count] = yield from Await::all([
					$this->listWaypoints($uuid, $offset, $length),
					$this->getWaypointCount($uuid)
				]);
				if($count > 0 && count($waypoints) === 0){
					$page = 1;
					$waypoints = yield from $this->listWaypoints($uuid, $offset, $length);
				}
				if($count === 0){
					$this->_message($sender, TextFormat::BOLD . TextFormat::YELLOW . "(!) " . TextFormat::RESET . TextFormat::YELLOW . "You do not have any waypoints set!");
					return;
				}
				$pages = (int) ceil($count / $length);
				$this->_message($sender, TextFormat::BOLD . TextFormat::DARK_PURPLE . "Waypoints" . TextFormat::RESET . TextFormat::GRAY . " ({$page} / {$pages})");
				foreach($waypoints as [$waypoint, $name, $selected]){
					$message = TextFormat::GRAY . ++$offset . ". " . TextFormat::DARK_PURPLE . $name;
					$message .= TextFormat::GRAY . " - {$waypoint->title}" . TextFormat::RESET . TextFormat::GRAY . " ({$waypoint->x}, {$waypoint->y}, {$waypoint->z})";
					if($selected){
						$message .= TextFormat::LIGHT_PURPLE . " (visible)";
					}
					$this->_message($sender, $message);
				}
				break;
			default:
				$this->_message($sender,
					TextFormat::BOLD . TextFormat::DARK_PURPLE . "Waypoints Help" . TextFormat::RESET . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} set <name> <x> <y> <z> [title=\ame]" . TextFormat::GRAY . " - Set a waypoint named <name> at <x> <y> <z>" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} sethere <name> [title=name]" . TextFormat::GRAY . " - Set a waypoint named <name> at your position" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} delete <name>" . TextFormat::GRAY . " - Delete a waypoint named <name>" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} toggle <name>" . TextFormat::GRAY . " - Toggle a waypoint (show/hide current waypoint)" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} rename <name> <newTitle>" . TextFormat::GRAY . " - Change title of a waypoint" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} face <name>" . TextFormat::GRAY . " - Face towards a waypoint" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} list [page=1]" . TextFormat::GRAY . " - List all waypoints" . TextFormat::EOL .
					TextFormat::DARK_PURPLE . "/{$label} config" . TextFormat::GRAY . " - Configure waypoint preferences"
				);
				break;
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		Await::f2c(function() use($sender, $label, $args) : Generator{
			if(isset($this->operation_locks[$id = spl_object_id($sender)])){
				$sender->sendMessage(TextFormat::GRAY . "You are executing this command too fast!");
				return;
			}
			try{
				yield from $this->onCommandAsync($sender, $label, $args);
			}finally{
				unset($this->operation_locks[$id]);
			}
		});
		return true;
	}
}