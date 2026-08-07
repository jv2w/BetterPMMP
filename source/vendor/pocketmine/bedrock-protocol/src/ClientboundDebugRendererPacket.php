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

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\DebugMarkerData;

class ClientboundDebugRendererPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_DEBUG_RENDERER_PACKET;

	public const TYPE_CLEAR = "cleardebugmarkers";
	public const TYPE_ADD_CUBE = "adddebugmarkercube";

	private string $type;
	private ?DebugMarkerData $data = null;

	private static function base(string $type) : self{
		$result = new self;
		$result->type = $type;
		return $result;
	}

	public static function clear() : self{ return self::base(self::TYPE_CLEAR); }

	public static function addCube(DebugMarkerData $data) : self{
		$result = self::base(self::TYPE_ADD_CUBE);
		$result->data = $data;
		return $result;
	}

	public function getType() : string{ return $this->type; }

	public function getData() : ?DebugMarkerData{ return $this->data; }

	protected function decodePayload(ByteBufferReader $in) : void{
		//the type string alone decides whether a body follows; there is no presence flag
		$this->type = CommonTypes::getString($in);
		$this->data = $this->type === self::TYPE_ADD_CUBE ? DebugMarkerData::read($in) : null;
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		CommonTypes::putString($out, $this->type);
		if($this->type === self::TYPE_ADD_CUBE && $this->data !== null){
			$this->data->write($out);
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleClientboundDebugRenderer($this);
	}
}
