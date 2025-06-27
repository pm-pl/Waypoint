<?php

declare(strict_types=1);

namespace cosmicpe\waypoint;

use function abs;

final class Waypoint{

	public function __construct(
		readonly public string $title,
		readonly public float $x,
		readonly public float $y,
		readonly public float $z
	){}

	public function equals(self $other) : bool{
		return $this->title === $other->title &&
			abs($this->x - $other->x) < 0.0001 &&
			abs($this->y - $other->y) < 0.0001 &&
			abs($this->z - $other->z) < 0.0001;
	}
}