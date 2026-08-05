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

namespace pocketmine\network\mcpe\protocol\types\login\clientdata;

use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use Ramsey\Uuid\Uuid;
use function array_map;
use function array_pad;
use function array_slice;
use function array_values;
use function base64_decode;

final class ClientDataToSkinDataHelper{

	/**
	 * @throws \InvalidArgumentException
	 */
	private static function safeB64Decode(string $base64, string $context) : string{
		$result = base64_decode($base64, true);
		if($result === false){
			throw new \InvalidArgumentException("$context: Malformed base64, cannot be decoded");
		}
		return $result;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromClientData(ClientData $clientData) : SkinData{
		/** @var SkinAnimation[] $animations */
		$animations = [];
		foreach($clientData->AnimatedImageData as $k => $animation){
			$animations[] = new SkinAnimation(
				new SkinImage(
					$animation->ImageHeight,
					$animation->ImageWidth,
					self::safeB64Decode($animation->Image, "AnimatedImageData.$k.Image")
				),
				$animation->Type,
				$animation->Frames,
				$animation->AnimationExpression
			);
		}
		return new SkinData(
			$clientData->SkinId,
			"",
			self::safeB64Decode($clientData->SkinResourcePatch, "SkinResourcePatch"),
			new SkinImage($clientData->SkinImageHeight, $clientData->SkinImageWidth, self::safeB64Decode($clientData->SkinData, "SkinData")),
			$animations,
			new SkinImage($clientData->CapeImageHeight, $clientData->CapeImageWidth, self::safeB64Decode($clientData->CapeData, "CapeData")),
			self::safeB64Decode($clientData->SkinGeometryData, "SkinGeometryData"),
			self::safeB64Decode($clientData->SkinGeometryDataEngineVersion, "SkinGeometryDataEngineVersion"), //yes, they actually base64'd the version!
			self::safeB64Decode($clientData->SkinAnimationData, "SkinAnimationData"),
			$clientData->CapeId,
			null,
			SkinData::convertArmSize($clientData->ArmSize),
			SkinData::convertColor($clientData->SkinColor),
			array_map(function(ClientDataPersonaSkinPiece $piece) : PersonaSkinPiece{
				return new PersonaSkinPiece(
					$piece->PieceId,
					(int) $piece->PieceType,
					Uuid::isValid($piece->PackId) ? Uuid::fromString($piece->PackId) : Uuid::fromInteger("0"),
					$piece->IsDefault,
					$piece->ProductId
				);
			}, $clientData->PersonaPieces),
			array_map(function(ClientDataPersonaPieceTintColor $tint) : PersonaPieceTintColor{
				$colors = array_map(SkinData::convertColor(...), array_slice(array_values($tint->Colors), 0, 4));
				return new PersonaPieceTintColor($tint->PieceType, array_pad($colors, 4, 0));
			}, $clientData->PieceTintColors),
			true,
			$clientData->PremiumSkin,
			$clientData->PersonaSkin,
			$clientData->CapeOnClassicSkin,
			true, //assume this is true? there's no field for it ...
			$clientData->OverrideSkin ?? true,
			$clientData->TrustedSkin ? SkinData::TRUSTED_SKIN_FLAG_TRUE : SkinData::TRUSTED_SKIN_FLAG_UNSET,
			$clientData->ProfileHash,
		);
	}
}
