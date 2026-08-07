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

namespace pocketmine\network\mcpe\protocol\types\skin;

use Ramsey\Uuid\UuidInterface;
use function str_starts_with;
use function substr;

final class PersonaSkinPiece{

	public const PIECE_TYPE_UNKNOWN = 0;
	public const PIECE_TYPE_PERSONA_SKELETON = 1;
	public const PIECE_TYPE_PERSONA_BODY = 2;
	public const PIECE_TYPE_PERSONA_SKIN = 3;
	public const PIECE_TYPE_PERSONA_BOTTOM = 4;
	public const PIECE_TYPE_PERSONA_FEET = 5;
	public const PIECE_TYPE_DRESS = 6;
	public const PIECE_TYPE_PERSONA_TOP = 7;
	public const PIECE_TYPE_HIGH_PANTS = 8;
	public const PIECE_TYPE_HANDS = 9;
	public const PIECE_TYPE_OUTERWEAR = 10;
	public const PIECE_TYPE_PERSONA_FACIAL_HAIR = 11;
	public const PIECE_TYPE_PERSONA_MOUTH = 12;
	public const PIECE_TYPE_PERSONA_EYES = 13;
	public const PIECE_TYPE_PERSONA_HAIR = 14;
	public const PIECE_TYPE_HOOD = 15;
	public const PIECE_TYPE_BACK = 16;
	public const PIECE_TYPE_FACE_ACCESSORY = 17;
	public const PIECE_TYPE_HEAD = 18;
	public const PIECE_TYPE_LEGS = 19;
	public const PIECE_TYPE_LEFT_LEG = 20;
	public const PIECE_TYPE_RIGHT_LEG = 21;
	public const PIECE_TYPE_ARMS = 22;
	public const PIECE_TYPE_LEFT_ARM = 23;
	public const PIECE_TYPE_RIGHT_ARM = 24;
	public const PIECE_TYPE_CAPES = 25;
	public const PIECE_TYPE_CLASSIC_SKIN = 26;
	public const PIECE_TYPE_EMOTE = 27;
	public const PIECE_TYPE_UNSUPPORTED = 28;

	//login JSON names the piece type, the wire numbers it. The names follow the same persona_* convention
	//the tint piece types use, so an unrecognised one is reported as unknown rather than silently becoming
	//a valid piece.
	public static function pieceTypeFromLoginName(string $name) : int{
		$shortName = str_starts_with($name, 'persona_') ? substr($name, 8) : $name;
		return match($shortName){
			'skeleton' => self::PIECE_TYPE_PERSONA_SKELETON,
			'body' => self::PIECE_TYPE_PERSONA_BODY,
			'skin' => self::PIECE_TYPE_PERSONA_SKIN,
			'bottom' => self::PIECE_TYPE_PERSONA_BOTTOM,
			'feet' => self::PIECE_TYPE_PERSONA_FEET,
			'dress' => self::PIECE_TYPE_DRESS,
			'top' => self::PIECE_TYPE_PERSONA_TOP,
			'high_pants' => self::PIECE_TYPE_HIGH_PANTS,
			'hand', 'hands' => self::PIECE_TYPE_HANDS,
			'outerwear' => self::PIECE_TYPE_OUTERWEAR,
			'facial_hair' => self::PIECE_TYPE_PERSONA_FACIAL_HAIR,
			'mouth' => self::PIECE_TYPE_PERSONA_MOUTH,
			'eyes' => self::PIECE_TYPE_PERSONA_EYES,
			'hair' => self::PIECE_TYPE_PERSONA_HAIR,
			'hood' => self::PIECE_TYPE_HOOD,
			'back' => self::PIECE_TYPE_BACK,
			'face_accessory' => self::PIECE_TYPE_FACE_ACCESSORY,
			'head' => self::PIECE_TYPE_HEAD,
			'legs' => self::PIECE_TYPE_LEGS,
			'left_leg' => self::PIECE_TYPE_LEFT_LEG,
			'right_leg' => self::PIECE_TYPE_RIGHT_LEG,
			'arms' => self::PIECE_TYPE_ARMS,
			'left_arm' => self::PIECE_TYPE_LEFT_ARM,
			'right_arm' => self::PIECE_TYPE_RIGHT_ARM,
			'capes' => self::PIECE_TYPE_CAPES,
			'classic_skin' => self::PIECE_TYPE_CLASSIC_SKIN,
			'emote' => self::PIECE_TYPE_EMOTE,
			'unsupported' => self::PIECE_TYPE_UNSUPPORTED,
			default => self::PIECE_TYPE_UNKNOWN
		};
	}

	public function __construct(
		private string $pieceId,
		private int $pieceType,
		private UuidInterface $packId,
		private bool $isDefaultPiece,
		private string $productId
	){}

	public function getPieceId() : string{
		return $this->pieceId;
	}

	public function getPieceType() : int{
		return $this->pieceType;
	}

	public function getPackId() : UuidInterface{
		return $this->packId;
	}

	public function isDefaultPiece() : bool{
		return $this->isDefaultPiece;
	}

	public function getProductId() : string{
		return $this->productId;
	}
}
