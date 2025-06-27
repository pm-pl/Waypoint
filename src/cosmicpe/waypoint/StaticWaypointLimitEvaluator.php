<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use Generator;
use Ramsey\Uuid\UuidInterface;

final class StaticWaypointLimitEvaluator implements WaypointLimitEvaluator{

	public function __construct(
		readonly public int $configured,
		readonly public int $selected
	){}

	public function evaluateConfiguredWaypointLimits(UuidInterface $uuid) : Generator{
		yield from [];
		return $this->configured;
	}

	public function evaluateSelectedWaypointLimits(UuidInterface $uuid) : Generator{
		yield from [];
		return $this->selected;
	}
}