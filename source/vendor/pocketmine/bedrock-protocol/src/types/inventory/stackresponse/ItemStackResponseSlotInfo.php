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

namespace pocketmine\network\mcpe\protocol\types\inventory\stackresponse;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

final class ItemStackResponseSlotInfo{
	public function __construct(
		private int $slot,
		private int $hotbarSlot,
		private int $count,
		private int $itemStackId,
		private string $customName,
		private string $filteredCustomName,
		private int $durabilityCorrection
	){}

	public function getSlot() : int{ return $this->slot; }

	public function getHotbarSlot() : int{ return $this->hotbarSlot; }

	public function getCount() : int{ return $this->count; }

	public function getItemStackId() : int{ return $this->itemStackId; }

	public function getCustomName() : string{ return $this->customName; }

	public function getFilteredCustomName() : string{ return $this->filteredCustomName; }

	public function getDurabilityCorrection() : int{ return $this->durabilityCorrection; }

	/**
	 * Names sit in two nested optionals. An absent name is a single unset outer bool, which is byte-identical
	 * to the empty string the field used to be, so only renamed items were affected by getting this wrong.
	 *
	 * @throws DataDecodeException
	 */
	private static function readName(ByteBufferReader $in) : string{
		return CommonTypes::readOptional($in, fn(ByteBufferReader $in) => CommonTypes::readOptional($in, CommonTypes::getString(...))) ?? "";
	}

	private static function writeName(ByteBufferWriter $out, string $name) : void{
		CommonTypes::writeOptional($out, $name !== "" ? $name : null, function(ByteBufferWriter $out, string $name) : void{
			CommonTypes::putBool($out, true);
			CommonTypes::putString($out, $name);
		});
	}

	public static function read(ByteBufferReader $in) : self{
		$slot = Byte::readUnsigned($in);
		$hotbarSlot = Byte::readUnsigned($in);
		$count = Byte::readUnsigned($in);
		$itemStackId = CommonTypes::readOptional($in, fn(ByteBufferReader $in) => CommonTypes::readOptional($in, CommonTypes::readServerItemStackId(...))) ?? 0;
		$customName = self::readName($in);
		$filteredCustomName = self::readName($in);
		$durabilityCorrection = VarInt::readSignedInt($in);
		return new self($slot, $hotbarSlot, $count, $itemStackId, $customName, $filteredCustomName, $durabilityCorrection);
	}

	public function write(ByteBufferWriter $out) : void{
		Byte::writeUnsigned($out, $this->slot);
		Byte::writeUnsigned($out, $this->hotbarSlot);
		Byte::writeUnsigned($out, $this->count);
		//the stack ID is nested in two optionals; the outer one is always set, and the inner one is unset
		//for a stack the server hasn't assigned an ID to
		CommonTypes::putBool($out, true);
		CommonTypes::writeOptional($out, $this->itemStackId > 0 ? $this->itemStackId : null, CommonTypes::writeServerItemStackId(...));
		self::writeName($out, $this->customName);
		self::writeName($out, $this->filteredCustomName);
		VarInt::writeSignedInt($out, $this->durabilityCorrection);
	}
}
