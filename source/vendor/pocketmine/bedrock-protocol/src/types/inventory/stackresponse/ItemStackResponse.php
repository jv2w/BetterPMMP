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
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function count;

final class ItemStackResponse{

	public const RESULT_OK = 0;
	public const RESULT_ERROR = 1;
	//TODO: there are a ton more possible result types but we don't need them yet and they are wayyyyyy too many for me
	//to waste my time on right now...

	/**
	 * @param ItemStackResponseContainerInfo[] $containerInfos
	 */
	public function __construct(
		private int $result,
		private int $requestId,
		private array $containerInfos = []
	){
		if($this->result !== self::RESULT_OK && count($this->containerInfos) !== 0){
			throw new \InvalidArgumentException("Container infos must be empty if rejecting the request");
		}
	}

	public function getResult() : int{ return $this->result; }

	public function getRequestId() : int{ return $this->requestId; }

	/** @return ItemStackResponseContainerInfo[] */
	public function getContainerInfos() : array{ return $this->containerInfos; }

	public static function read(ByteBufferReader $in) : self{
		$result = Byte::readUnsigned($in);
		$requestId = CommonTypes::readItemStackRequestId($in);
		$containerInfos = CommonTypes::readOptional($in, fn(ByteBufferReader $in) => CommonTypes::readOptional($in, function(ByteBufferReader $in) : array{
			$containerInfos = [];
			for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
				$containerInfos[] = ItemStackResponseContainerInfo::read($in);
			}
			return $containerInfos;
		})) ?? [];
		return new self($result, $requestId, $containerInfos);
	}

	public function write(ByteBufferWriter $out) : void{
		Byte::writeUnsigned($out, $this->result);
		CommonTypes::writeItemStackRequestId($out, $this->requestId);
		//the container info list is nested in two optionals, and its presence no longer depends on the
		//result; the outer one is always set
		CommonTypes::putBool($out, true);
		CommonTypes::writeOptional($out, count($this->containerInfos) > 0 ? $this->containerInfos : null, function(ByteBufferWriter $out, array $containerInfos) : void{
			VarInt::writeUnsignedInt($out, count($containerInfos));
			foreach($containerInfos as $containerInfo){
				$containerInfo->write($out);
			}
		});
	}
}
