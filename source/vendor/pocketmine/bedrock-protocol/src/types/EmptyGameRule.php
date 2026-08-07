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

/**
 * A game rule that carries no value at all. The client uses it for rules it knows the name of but has no
 * setting for.
 */
final class EmptyGameRule extends GameRule{
	use GetTypeIdFromConstTrait;

	public const ID = GameRuleType::EMPTY;

	public function encode(ByteBufferWriter $out, bool $isStartGame) : void{
		//NOOP
	}

	public static function decode(ByteBufferReader $in, bool $isPlayerModifiable) : self{
		return new self($isPlayerModifiable);
	}
}
