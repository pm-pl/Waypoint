# Waypoint
Waypoint is a plugin that lets you set a visual holographic marker at your desired position. The concept is similar to
homes in that a waypoint is a labeled position, but differs in that you need to walk all the way to your desired location.

https://github.com/user-attachments/assets/c629811d-d5b5-4c31-b246-778a8231198e

## Installation
Simply download this plugin as a .phar file from Poggit CI and restart your server. Use `/waypoint` or `/wp` to manage
your personal waypoints. A player may have up to 64 waypoints configured, but can see only 1 waypoint  at any given
moment. However, both these values are configurable from [config.yml](resources/config.yml).

## Usage
Quick start: use `/wp sethere mywaypoint` to set a waypoint at your position.

A waypoint comprises of 5 properties—a name, a title, X, Y, Z position values. A name is a unique identifier, so you are
restricted to the usage of alpha-numeric characters and underscores. But you can use fancier characters including color
codes (`§` and `&`) in the title. Title is displayed in the hologram. X Y Z are the coordinates to your waypoint. You
may use `~`, `~+40`, etc. to set relative coordinates when using `/wp set`.

| Command                                   | Description                                           |
|-------------------------------------------|-------------------------------------------------------|
| `/wp set <name> <x> <y> <z> [title=name]` | Set a waypoint named `<name>` at `<x> <y> <z>`        |
| `/wp sethere <name> [title=name]`         | Set a waypoint named `<name>` at your position        |
| `/wp delete <name>`                       | Delete a waypoint named `<name>`                      |
| `/wp toggle <name>`                       | Toggle a waypoint (show/hide current waypoint)        |
| `/wp rename <name> <newTitle>`            | Change title of a waypoint                            |
| `/wp face <name>`                         | Face towards a waypoint                               |
| `/wp list [page=1]`                       | List all waypoints                                    |
| `/wp config`                              | Configure waypoint preferences                        |

## Developer documentation
As a developer, you get to have more control over waypoint management. To programatically create a waypoint for a player,
use `WaypointPlugin::setWaypointForPlayer()`. As this plugin uses `await-generator`, your plugin must use the library as
well. If you are new to await-generator, wrap the `yield from` statements in `Await::f2c()` closure. I will show you an
example here so you get an idea of how to use it:
```php
Await::f2c(function() : Generator{
	$server = Server::getInstance();
	$player = $server->getPlayerExact("BlahCoast30765");
	
	/** @var WaypointPlugin $plugin */
	$plugin = $server->getPluginManager()->getPlugin("Waypoint");
	
	// for an online player
	yield from $plugin->setWaypointForPlayer($player, "name", "Title", 320, 64, 128);
	
	// or if they are offline
	yield from $plugin->setWaypoint($uuid, "name", new Waypoint("Title", 320, 64, 128), true);
	
	// delete it
	yield from $plugin->deleteWaypoint($uuid, "name");
	
	// list all waypoint names
	$names = yield from $plugin->listWaypointNames($uuid); // list<string>
	
	// list a waypoint
	$value = yield from $plugin->getWaypoint($uuid, "name"); // array{Waypoint, string, bool}|null
	[$waypoint, $name, $selected] = $value;
});
```

Both `setWaypoint()` and `setWaypointForPlayer()` persist waypoints in database. You may instead create waypoint objects
directly if you do not intend to persist them. However, these waypoints will stay hidden from `/wp` command as they will
not be tied a `<name>`:
```php
$waypoint = new Waypoint("Title", 320, 64, 128);
$renderer = new WaypointRenderer($waypoint, $player, 5.0, "{TITLE} [{DISTANCE}m]");

// to hide the waypoint (which will also destroy the $renderer object's state)
$renderer->destroy();
```

While static limits can be defined in `config.yml`, you as a developer have more control in that you get to set
per-player limits. These limits are evaluated during creation or when a player toggles a waypoint:
```php
$plugin->limit_evaluator = new MyWaypointLimitEvaluator();
```

Registering a `WaypointListener` will notify you whenever a waypoint is created or deleted. This is generally sufficient
if you are working on something like updating a command enum of per-player waypoints (pair it with listWaypointNames
during player join):
```php
WaypointPlugin::$listeners[] = new class implements WaypointListener{
	public function onCreate(UuidInterface $uuid, string $name, Waypoint $waypoint) : void{}
	public function onDelete(UuidInterface $uuid, string $name) : void{}
	public function onRender(WaypointRenderer $renderer) : void{}
	public function onDerender(WaypointRenderer $renderer) : void{}
};
```
