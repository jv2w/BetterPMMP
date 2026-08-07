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
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\SoundDataUpdate;

class ClientboundUpdateSoundDataPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_UPDATE_SOUND_DATA_PACKET;

	private int $serverSoundHandle;
	//each slot is a union that may hold any update variant; the slot name does not constrain what it carries
	private ?SoundDataUpdate $stop;
	private ?SoundDataUpdate $setVolume;
	private ?SoundDataUpdate $setPitch;
	private ?SoundDataUpdate $fade;
	private ?SoundDataUpdate $seekTo;
	private ?SoundDataUpdate $pause;
	private ?SoundDataUpdate $resume;

	/**
	 * @generate-create-func
	 */
	public static function create(int $serverSoundHandle, ?SoundDataUpdate $stop, ?SoundDataUpdate $setVolume, ?SoundDataUpdate $setPitch, ?SoundDataUpdate $fade, ?SoundDataUpdate $seekTo, ?SoundDataUpdate $pause, ?SoundDataUpdate $resume) : self{
		$result = new self;
		$result->serverSoundHandle = $serverSoundHandle;
		$result->stop = $stop;
		$result->setVolume = $setVolume;
		$result->setPitch = $setPitch;
		$result->fade = $fade;
		$result->seekTo = $seekTo;
		$result->pause = $pause;
		$result->resume = $resume;
		return $result;
	}

	public function getServerSoundHandle() : int{ return $this->serverSoundHandle; }

	public function getStop() : ?SoundDataUpdate{ return $this->stop; }

	public function getSetVolume() : ?SoundDataUpdate{ return $this->setVolume; }

	public function getSetPitch() : ?SoundDataUpdate{ return $this->setPitch; }

	public function getFade() : ?SoundDataUpdate{ return $this->fade; }

	public function getSeekTo() : ?SoundDataUpdate{ return $this->seekTo; }

	public function getPause() : ?SoundDataUpdate{ return $this->pause; }

	public function getResume() : ?SoundDataUpdate{ return $this->resume; }

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->serverSoundHandle = LE::readUnsignedLong($in);
		$this->stop = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->setVolume = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->setPitch = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->fade = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->seekTo = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->pause = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
		$this->resume = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		LE::writeUnsignedLong($out, $this->serverSoundHandle);
		$writer = fn(ByteBufferWriter $out, SoundDataUpdate $v) => $v->write($out);
		CommonTypes::writeOptional($out, $this->stop, $writer);
		CommonTypes::writeOptional($out, $this->setVolume, $writer);
		CommonTypes::writeOptional($out, $this->setPitch, $writer);
		CommonTypes::writeOptional($out, $this->fade, $writer);
		CommonTypes::writeOptional($out, $this->seekTo, $writer);
		CommonTypes::writeOptional($out, $this->pause, $writer);
		CommonTypes::writeOptional($out, $this->resume, $writer);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleClientboundUpdateSoundData($this);
	}
}
