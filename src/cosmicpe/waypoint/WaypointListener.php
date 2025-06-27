<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use Ramsey\Uuid\UuidInterface;

interface WaypointListener{

	public function onCreate(UuidInterface $uuid, string $name, Waypoint $waypoint) : void;

	public function onDelete(UuidInterface $uuid, string $name) : void;

	public function onRender(WaypointRenderer $renderer) : void;

	public function onDerender(WaypointRenderer $renderer) : void;
}