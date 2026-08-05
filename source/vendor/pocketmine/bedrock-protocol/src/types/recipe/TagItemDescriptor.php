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

namespace pocketmine\network\mcpe\protocol\types\recipe;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\GetTypeIdFromConstTrait;

final class TagItemDescriptor implements ItemDescriptor{
	use GetTypeIdFromConstTrait;

	public const ID = ItemDescriptorType::TAG;

	public function __construct(
		private string $tag
	){}

	public function getTag() : string{ return $this->tag; }

	public static function read(ByteBufferReader $in) : self{
		$tag = CommonTypes::getString($in);
		VarInt::readSignedInt($in); //metadata, always the any-metadata wildcard for a tag

		return new self($tag);
	}

	public function write(ByteBufferWriter $out) : void{
		CommonTypes::putString($out, $this->tag);
		VarInt::writeSignedInt($out, ItemDescriptorType::ANY_METADATA);
	}
}
