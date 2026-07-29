<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

/* Modified by the BetterPMMP project (2026) - see the NOTICE file for details. */

declare(strict_types=1);

namespace pocketmine\entity;

class AttributeMap{
	/** @var Attribute[] */
	private array $attributes = [];

	public function add(Attribute $attribute) : void{
		$this->attributes[$attribute->getId()] = $attribute;
	}

	public function get(string $id) : ?Attribute{
		return $this->attributes[$id] ?? null;
	}

	/**
	 * @return Attribute[]
	 */
	public function getAll() : array{
		return $this->attributes;
	}

	/**
	 * @return Attribute[]
	 */
	public function needSend() : array{
		//Collect by hand rather than array_filter(): one closure call per attribute per tick, and both consumers
		//ignore keys. Still a full scan, not dirty tracking.
		$dirty = [];
		foreach($this->attributes as $attribute){
			if($attribute->isSyncable() && $attribute->isDesynchronized()){
				$dirty[] = $attribute;
			}
		}
		return $dirty;
	}
}
