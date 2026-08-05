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

final class ItemDescriptorType{

	public const INT_ID_META = 1;
	public const MOLANG = 2;
	public const TAG = 3;
	public const STRING_ID_META = 4;
	public const COMPLEX_ALIAS = 5;

	/** Wire names identifying a descriptor inside a recipe ingredient. */
	public const NAME_STRING_ID_META = "name";
	public const NAME_MOLANG = "molang";
	public const NAME_TAG = "item_tag";

	/** Metadata value used by descriptors which match any variant of an item. */
	public const ANY_METADATA = 32767;
}
