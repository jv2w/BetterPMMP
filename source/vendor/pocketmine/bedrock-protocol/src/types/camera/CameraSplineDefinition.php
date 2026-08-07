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

namespace pocketmine\network\mcpe\protocol\types\camera;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function count;

final class CameraSplineDefinition{

	/**
	 * @param Vector3[] $controlPoints
	 * @param CameraProgressOption[] $progressKeyFrames
	 * @param CameraRotationOption[] $rotationKeyFrames
	 */
	public function __construct(
		private string $name,
		private float $totalTime,
		private ?string $splineType,
		private array $controlPoints,
		private array $progressKeyFrames,
		private array $rotationKeyFrames,
	){}

	public function getName() : string{ return $this->name; }

	public function getTotalTime() : float{ return $this->totalTime; }

	public function getSplineType() : ?string{ return $this->splineType; }

	/**
	 * @return Vector3[]
	 */
	public function getControlPoints() : array{ return $this->controlPoints; }

	/**
	 * @return CameraProgressOption[]
	 */
	public function getProgressKeyFrames() : array{ return $this->progressKeyFrames; }

	/**
	 * @return CameraRotationOption[]
	 */
	public function getRotationKeyFrames() : array{ return $this->rotationKeyFrames; }

	public static function read(ByteBufferReader $in) : self{
		$name = CommonTypes::getString($in);
		$totalTime = LE::readFloat($in);
		$splineType = CommonTypes::readOptional($in, CommonTypes::getString(...));

		$controlPoints = [];
		for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
			$controlPoints[] = CommonTypes::getVector3($in);
		}

		$progressKeyFrames = [];
		for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
			$progressKeyFrames[] = CameraProgressOption::read($in);
		}

		$rotationKeyFrames = [];
		for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
			$rotationKeyFrames[] = CameraRotationOption::read($in);
		}

		return new self($name, $totalTime, $splineType, $controlPoints, $progressKeyFrames, $rotationKeyFrames);
	}

	public function write(ByteBufferWriter $out) : void{
		CommonTypes::putString($out, $this->name);
		LE::writeFloat($out, $this->totalTime);
		CommonTypes::writeOptional($out, $this->splineType, CommonTypes::putString(...));

		VarInt::writeUnsignedInt($out, count($this->controlPoints));
		foreach($this->controlPoints as $point){
			CommonTypes::putVector3($out, $point);
		}

		VarInt::writeUnsignedInt($out, count($this->progressKeyFrames));
		foreach($this->progressKeyFrames as $keyFrame){
			$keyFrame->write($out);
		}

		VarInt::writeUnsignedInt($out, count($this->rotationKeyFrames));
		foreach($this->rotationKeyFrames as $keyFrame){
			$keyFrame->write($out);
		}
	}
}
