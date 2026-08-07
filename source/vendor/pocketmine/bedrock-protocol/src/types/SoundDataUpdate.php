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

namespace pocketmine\network\mcpe\protocol\types;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;

/**
 * A single change to a sound that is already playing, identified by the handle the server sent in PlaySoundPacket.
 */
final class SoundDataUpdate{

	public const TYPE_STOP = 0;
	public const TYPE_SET_VOLUME = 1;
	public const TYPE_SET_PITCH = 2;
	public const TYPE_FADE = 3;
	public const TYPE_SEEK_TO = 4;
	public const TYPE_PAUSE = 5;
	public const TYPE_RESUME = 6;

	public function __construct(
		private int $type,
		private float $volume = 0.0,
		private float $pitch = 0.0,
		private float $duration = 0.0,
		private float $targetVolume = 0.0,
		private float $seconds = 0.0,
	){}

	public function getType() : int{ return $this->type; }

	public function getVolume() : float{ return $this->volume; }

	public function getPitch() : float{ return $this->pitch; }

	public function getDuration() : float{ return $this->duration; }

	public function getTargetVolume() : float{ return $this->targetVolume; }

	public function getSeconds() : float{ return $this->seconds; }

	/**
	 * @throws PacketDecodeException
	 */
	public static function read(ByteBufferReader $in) : self{
		$type = VarInt::readUnsignedInt($in);
		$volume = 0.0;
		$pitch = 0.0;
		$duration = 0.0;
		$targetVolume = 0.0;
		$seconds = 0.0;
		if($type === self::TYPE_SET_VOLUME){
			$volume = LE::readFloat($in);
		}elseif($type === self::TYPE_SET_PITCH){
			$pitch = LE::readFloat($in);
		}elseif($type === self::TYPE_FADE){
			$duration = LE::readFloat($in);
			$targetVolume = LE::readFloat($in);
		}elseif($type === self::TYPE_SEEK_TO){
			$seconds = LE::readFloat($in);
		}elseif($type !== self::TYPE_STOP && $type !== self::TYPE_PAUSE && $type !== self::TYPE_RESUME){
			throw new PacketDecodeException("Unknown sound data update type {$type}");
		}

		return new self($type, $volume, $pitch, $duration, $targetVolume, $seconds);
	}

	public function write(ByteBufferWriter $out) : void{
		VarInt::writeUnsignedInt($out, $this->type);
		if($this->type === self::TYPE_SET_VOLUME){
			LE::writeFloat($out, $this->volume);
		}elseif($this->type === self::TYPE_SET_PITCH){
			LE::writeFloat($out, $this->pitch);
		}elseif($this->type === self::TYPE_FADE){
			LE::writeFloat($out, $this->duration);
			LE::writeFloat($out, $this->targetVolume);
		}elseif($this->type === self::TYPE_SEEK_TO){
			LE::writeFloat($out, $this->seconds);
		}
	}
}
