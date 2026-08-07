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

/**
 * @see AttributeLayer&AttributeUpdateLayerSettings
 */
final class AttributeLayerSettings{

	public function __construct(
		private int $priority,
		private float $weight,
		private bool $enabled,
		private bool $transitionsPaused,
	){}

	public function getPriority() : int{ return $this->priority; }

	public function getWeight() : float{ return $this->weight; }

	public function isEnabled() : bool{ return $this->enabled; }

	public function isTransitionsPaused() : bool{ return $this->transitionsPaused; }

	public static function read(ByteBufferReader $in) : self{
		$priority = LE::readSignedInt($in);
		$weight = LE::readFloat($in);
		$enabled = CommonTypes::getBool($in);
		$transitionsPaused = CommonTypes::getBool($in);

		return new self(
			$priority,
			$weight,
			$enabled,
			$transitionsPaused,
		);
	}

	public function write(ByteBufferWriter $out) : void{
		LE::writeSignedInt($out, $this->priority);
		LE::writeFloat($out, $this->weight);
		CommonTypes::putBool($out, $this->enabled);
		CommonTypes::putBool($out, $this->transitionsPaused);
	}
}
