<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use Generator;
use Ramsey\Uuid\UuidInterface;
use SOFe\AwaitGenerator\Await;

interface WaypointLimitEvaluator{

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function evaluateConfiguredWaypointLimits(UuidInterface $uuid) : Generator;

	/**
	 * @param UuidInterface $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function evaluateSelectedWaypointLimits(UuidInterface $uuid) : Generator;
}