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
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function round;

final class DebugMarkerData{

	public function __construct(
		private string $text,
		private Vector3 $position,
		private Color $color,
		private int $durationMillis
	){}

	public function getText() : string{ return $this->text; }

	public function getPosition() : Vector3{ return $this->position; }

	public function getColor() : Color{ return $this->color; }

	public function getDurationMillis() : int{ return $this->durationMillis; }

	public static function read(ByteBufferReader $in) : self{
		$text = CommonTypes::getString($in);
		$position = CommonTypes::getVector3($in);
		//unlike every other colour on the wire, this one is four normalised 0-1 channels
		$red = LE::readFloat($in);
		$green = LE::readFloat($in);
		$blue = LE::readFloat($in);
		$alpha = LE::readFloat($in);
		$color = new Color((int) round($red * 255), (int) round($green * 255), (int) round($blue * 255), (int) round($alpha * 255));
		$durationMillis = LE::readUnsignedLong($in);

		return new self(
			$text,
			$position,
			$color,
			$durationMillis
		);
	}

	public function write(ByteBufferWriter $out) : void{
		CommonTypes::putString($out, $this->text);
		CommonTypes::putVector3($out, $this->position);
		LE::writeFloat($out, $this->color->getR() / 255);
		LE::writeFloat($out, $this->color->getG() / 255);
		LE::writeFloat($out, $this->color->getB() / 255);
		LE::writeFloat($out, $this->color->getA() / 255);
		LE::writeUnsignedLong($out, $this->durationMillis);
	}
}
