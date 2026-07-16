<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissionNames;

/** [BetterPMMP-PATCH] */
class RestartCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"restart",
			new Translatable("pocketmine.command.restart.description")
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_STOP);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		$restartFlag = dirname(__FILE__, 5) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'restart.flag';
		@mkdir(dirname($restartFlag), 0777, true);
		file_put_contents($restartFlag, '1');

		Command::broadcastCommandMessage($sender, new Translatable("pocketmine.command.restart.start"));

		$sender->getServer()->shutdown();
		return true;
	}
}