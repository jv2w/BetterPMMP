<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/* Modified by the BetterPMMP project (2026) - see the NOTICE file for details. */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

final class PlayerAuthInputVehicleInfo{

	public function __construct(
		private ?float $vehicleRotationX = null,
		private ?float $vehicleRotationZ = null,
		private ?int $predictedVehicleActorUniqueId = null
	){}

	public function getVehicleRotationX() : ?float{ return $this->vehicleRotationX; }

	public function getVehicleRotationZ() : ?float{ return $this->vehicleRotationZ; }

	public function getPredictedVehicleActorUniqueId() : ?int{ return $this->predictedVehicleActorUniqueId; }

	public function isNull() : bool{
		return $this->vehicleRotationX === null && $this->vehicleRotationZ === null && $this->predictedVehicleActorUniqueId === null;
	}

	public static function read(ByteBufferReader $in) : self{
		//both fields are nested optionals - the outer one is skipped entirely when unset
		$rotation = CommonTypes::readOptional($in, fn(ByteBufferReader $in) => CommonTypes::readOptional($in, fn(ByteBufferReader $in) => [LE::readFloat($in), LE::readFloat($in)]));
		$predictedVehicleActorUniqueId = CommonTypes::readOptional($in, fn(ByteBufferReader $in) => CommonTypes::readOptional($in, CommonTypes::getActorUniqueId(...)));

		return new self($rotation[0] ?? null, $rotation[1] ?? null, $predictedVehicleActorUniqueId);
	}

	public function write(ByteBufferWriter $out) : void{
		$rotation = $this->vehicleRotationX !== null && $this->vehicleRotationZ !== null ? [$this->vehicleRotationX, $this->vehicleRotationZ] : null;
		CommonTypes::writeOptional($out, $rotation, fn(ByteBufferWriter $out, array $v) => CommonTypes::writeOptional($out, $v, function(ByteBufferWriter $out, array $rotation) : void{
			LE::writeFloat($out, $rotation[0]);
			LE::writeFloat($out, $rotation[1]);
		}));
		CommonTypes::writeOptional($out, $this->predictedVehicleActorUniqueId, fn(ByteBufferWriter $out, int $v) => CommonTypes::writeOptional($out, $v, CommonTypes::putActorUniqueId(...)));
	}
}
