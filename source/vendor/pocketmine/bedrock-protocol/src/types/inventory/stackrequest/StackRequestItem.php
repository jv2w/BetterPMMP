<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author BetterPMMP Team
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types\inventory\stackrequest;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

/**
 * An item as it appears inside an ItemStackRequest. Unlike a regular ItemStack it identifies the item by name
 * rather than by network ID, and its descriptor type is a variant index repeated as a byte.
 */
final class StackRequestItem{
	private const DESCRIPTOR_INVALID = 0;
	private const DESCRIPTOR_ITEM_NAME = 1;

	/** @param string $rawExtraData Serialized ItemStackExtraData (use ItemStackExtraData->write()) */
	public function __construct(
		private string $name,
		private int $meta,
		private int $count,
		private int $blockRuntimeId,
		private string $rawExtraData
	){}

	public function getName() : string{ return $this->name; }

	public function getMeta() : int{ return $this->meta; }

	public function getCount() : int{ return $this->count; }

	public function getBlockRuntimeId() : int{ return $this->blockRuntimeId; }

	public function getRawExtraData() : string{ return $this->rawExtraData; }

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function read(ByteBufferReader $in) : self{
		$variantId = VarInt::readUnsignedInt($in);
		$descriptorType = Byte::readUnsigned($in);
		if($descriptorType !== $variantId){
			throw new PacketDecodeException("Item descriptor variant $variantId does not match type $descriptorType");
		}
		$name = "";
		$meta = 0;
		if($descriptorType !== self::DESCRIPTOR_INVALID){
			$name = CommonTypes::getString($in);
			$meta = VarInt::readSignedInt($in);
		}
		$count = LE::readSignedShort($in);
		$blockRuntimeId = VarInt::readUnsignedInt($in);
		$rawExtraData = CommonTypes::getString($in);

		return new self($name, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	public function write(ByteBufferWriter $out) : void{
		$descriptorType = $this->name === "" ? self::DESCRIPTOR_INVALID : self::DESCRIPTOR_ITEM_NAME;
		VarInt::writeUnsignedInt($out, $descriptorType);
		Byte::writeUnsigned($out, $descriptorType);
		if($descriptorType !== self::DESCRIPTOR_INVALID){
			CommonTypes::putString($out, $this->name);
			VarInt::writeSignedInt($out, $this->meta);
		}
		LE::writeSignedShort($out, $this->count);
		VarInt::writeUnsignedInt($out, $this->blockRuntimeId);
		CommonTypes::putString($out, $this->rawExtraData);
	}
}
