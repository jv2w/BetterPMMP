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
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

class MoveActorDeltaPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::MOVE_ACTOR_DELTA_PACKET;

	public int $actorRuntimeId;
	public ?float $xPos = null;
	public ?float $yPos = null;
	public ?float $zPos = null;
	public ?float $pitch = null;
	public ?float $yaw = null;
	public ?float $headYaw = null;
	public bool $onGround = false;
	public bool $teleport = false;
	public bool $forceMoveLocalEntity = false;
	public bool $forceCompletion = false;

	/** @throws DataDecodeException */
	private function maybeReadCoord(ByteBufferReader $in) : ?float{
		if(CommonTypes::getBool($in)){
			return LE::readFloat($in);
		}
		return null;
	}

	/** @throws DataDecodeException */
	private function maybeReadRotation(ByteBufferReader $in) : ?float{
		if(CommonTypes::getBool($in)){
			return CommonTypes::getRotationByte($in);
		}
		return null;
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->actorRuntimeId = CommonTypes::getActorRuntimeId($in);
		$this->xPos = $this->maybeReadCoord($in);
		$this->yPos = $this->maybeReadCoord($in);
		$this->zPos = $this->maybeReadCoord($in);
		$this->pitch = $this->maybeReadRotation($in);
		$this->yaw = $this->maybeReadRotation($in);
		$this->headYaw = $this->maybeReadRotation($in);
		$this->onGround = CommonTypes::getBool($in);
		$this->teleport = CommonTypes::getBool($in);
		$this->forceMoveLocalEntity = CommonTypes::getBool($in);
		$this->forceCompletion = CommonTypes::getBool($in);
	}

	private function maybeWriteCoord(?float $val, ByteBufferWriter $out) : void{
		CommonTypes::putBool($out, $val !== null);
		if($val !== null){
			LE::writeFloat($out, $val);
		}
	}

	private function maybeWriteRotation(?float $val, ByteBufferWriter $out) : void{
		CommonTypes::putBool($out, $val !== null);
		if($val !== null){
			CommonTypes::putRotationByte($out, $val);
		}
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		CommonTypes::putActorRuntimeId($out, $this->actorRuntimeId);
		$this->maybeWriteCoord($this->xPos, $out);
		$this->maybeWriteCoord($this->yPos, $out);
		$this->maybeWriteCoord($this->zPos, $out);
		$this->maybeWriteRotation($this->pitch, $out);
		$this->maybeWriteRotation($this->yaw, $out);
		$this->maybeWriteRotation($this->headYaw, $out);
		CommonTypes::putBool($out, $this->onGround);
		CommonTypes::putBool($out, $this->teleport);
		CommonTypes::putBool($out, $this->forceMoveLocalEntity);
		CommonTypes::putBool($out, $this->forceCompletion);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleMoveActorDelta($this);
	}
}
