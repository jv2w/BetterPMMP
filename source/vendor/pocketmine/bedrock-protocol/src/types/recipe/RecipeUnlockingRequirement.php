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
use function count;

final class RecipeUnlockingRequirement{

	public const CONTEXT_NONE = 0;
	public const CONTEXT_ALWAYS_UNLOCKED = 1;
	public const CONTEXT_PLAYER_IN_WATER = 2;
	public const CONTEXT_PLAYER_HAS_MANY_ITEMS = 3;

	/**
	 * @param RecipeIngredient[]|null $unlockingIngredients
	 * @phpstan-param list<RecipeIngredient>|null $unlockingIngredients
	 */
	public function __construct(
		private int $context,
		private ?array $unlockingIngredients
	){}

	public function getContext() : int{ return $this->context; }

	/**
	 * @return RecipeIngredient[]|null
	 * @phpstan-return list<RecipeIngredient>|null
	 */
	public function getUnlockingIngredients() : ?array{ return $this->unlockingIngredients; }

	public static function read(ByteBufferReader $in) : self{
		$context = VarInt::readSignedInt($in);
		$unlockingIngredients = null;
		if(CommonTypes::getBool($in)){
			$unlockingIngredients = [];
			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; $i++){
				$unlockingIngredients[] = CommonTypes::getRecipeIngredient($in);
			}
		}

		return new self($context, $unlockingIngredients);
	}

	public function write(ByteBufferWriter $out) : void{
		VarInt::writeSignedInt($out, $this->context);
		//an ingredient list only accompanies the none context; every other context is self-describing
		$hasIngredients = $this->context === self::CONTEXT_NONE;
		CommonTypes::putBool($out, $hasIngredients);
		if($hasIngredients){
			$ingredients = $this->unlockingIngredients ?? [];
			VarInt::writeUnsignedInt($out, count($ingredients));
			foreach($ingredients as $ingredient){
				CommonTypes::putRecipeIngredient($out, $ingredient);
			}
		}
	}
}
