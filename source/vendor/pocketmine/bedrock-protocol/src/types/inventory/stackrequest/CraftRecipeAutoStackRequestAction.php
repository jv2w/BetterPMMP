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

namespace pocketmine\network\mcpe\protocol\types\inventory\stackrequest;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\GetTypeIdFromConstTrait;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use function count;

/**
 * Tells that the current transaction crafted the specified recipe, using the recipe book. This is effectively the same
 * as the regular crafting result action.
 */
final class CraftRecipeAutoStackRequestAction extends ItemStackRequestAction{
	use GetTypeIdFromConstTrait;

	public const ID = ItemStackRequestActionType::CRAFTING_RECIPE_AUTO;

	private const DESCRIPTOR_INVALID = 0;
	private const DESCRIPTOR_ITEM_NAME = 1;
	private const DESCRIPTOR_MOLANG = 2;
	private const DESCRIPTOR_ITEM_TAG = 3;

	/**
	 * @param RecipeIngredient[] $ingredients
	 * @phpstan-param list<RecipeIngredient> $ingredients
	 */
	final public function __construct(
		private int $recipeId,
		private int $repetitions,
		private array $ingredients
	){}

	public function getRecipeId() : int{ return $this->recipeId; }

	public function getRepetitions() : int{ return $this->repetitions; }

	/**
	 * @return RecipeIngredient[]
	 * @phpstan-return list<RecipeIngredient>
	 */
	public function getIngredients() : array{ return $this->ingredients; }

	/**
	 * Ingredients inside a stack request are not encoded like recipe ingredients: the descriptor type is a
	 * variant index repeated as a byte, the payloads differ, and the count is a fixed-width short.
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private static function readIngredient(ByteBufferReader $in) : RecipeIngredient{
		$variantId = VarInt::readUnsignedInt($in);
		$descriptorType = Byte::readUnsigned($in);
		if($descriptorType !== $variantId){
			throw new PacketDecodeException("Item descriptor variant $variantId does not match type $descriptorType");
		}
		$descriptor = match($descriptorType){
			self::DESCRIPTOR_INVALID => null,
			self::DESCRIPTOR_ITEM_NAME => new StringIdMetaItemDescriptor(CommonTypes::getString($in), VarInt::readSignedInt($in)),
			self::DESCRIPTOR_MOLANG => new MolangItemDescriptor(CommonTypes::getString($in), LE::readSignedShort($in)),
			self::DESCRIPTOR_ITEM_TAG => new TagItemDescriptor(CommonTypes::getString($in)),
			default => throw new PacketDecodeException("Unknown item descriptor type $descriptorType")
		};
		$count = LE::readUnsignedShort($in);

		return new RecipeIngredient($descriptor, $count);
	}

	private static function writeIngredient(ByteBufferWriter $out, RecipeIngredient $ingredient) : void{
		$descriptor = $ingredient->getDescriptor();
		$descriptorType = match(true){
			$descriptor instanceof StringIdMetaItemDescriptor => self::DESCRIPTOR_ITEM_NAME,
			$descriptor instanceof MolangItemDescriptor => self::DESCRIPTOR_MOLANG,
			$descriptor instanceof TagItemDescriptor => self::DESCRIPTOR_ITEM_TAG,
			default => self::DESCRIPTOR_INVALID
		};
		VarInt::writeUnsignedInt($out, $descriptorType);
		Byte::writeUnsigned($out, $descriptorType);
		if($descriptor instanceof StringIdMetaItemDescriptor){
			CommonTypes::putString($out, $descriptor->getId());
			VarInt::writeSignedInt($out, $descriptor->getMeta());
		}elseif($descriptor instanceof MolangItemDescriptor){
			CommonTypes::putString($out, $descriptor->getMolangExpression());
			LE::writeSignedShort($out, $descriptor->getMolangVersion());
		}elseif($descriptor instanceof TagItemDescriptor){
			CommonTypes::putString($out, $descriptor->getTag());
		}
		LE::writeUnsignedShort($out, $ingredient->getCount());
	}

	public static function read(ByteBufferReader $in) : self{
		$recipeId = CommonTypes::readRecipeNetId($in);
		$repetitions = Byte::readUnsigned($in);
		$ingredients = [];
		for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
			$ingredients[] = self::readIngredient($in);
		}
		return new self($recipeId, $repetitions, $ingredients);
	}

	public function write(ByteBufferWriter $out) : void{
		CommonTypes::writeRecipeNetId($out, $this->recipeId);
		Byte::writeUnsigned($out, $this->repetitions);
		VarInt::writeUnsignedInt($out, count($this->ingredients));
		foreach($this->ingredients as $ingredient){
			self::writeIngredient($out, $ingredient);
		}
	}
}
