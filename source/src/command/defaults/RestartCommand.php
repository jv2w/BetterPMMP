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
use Symfony\Component\Filesystem\Path;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

/**
 * [BetterPMMP-PATCH]
 * Asks the launcher script to start the server again once it has shut down, by leaving a flag file in the
 * data directory for start.cmd / start.sh to find.
 */
class RestartCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"restart",
			new Translatable("pocketmine.command.restart.description")
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_STOP);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		$server = $sender->getServer();
		/** [BetterPMMP-PATCH] The flag belongs in the server's data directory, which is what the launcher
		 * scripts watch. dirname(__FILE__, 5) hardcoded the source tree instead, so --data, a panel or a
		 * .phar build (where __FILE__ is a phar:// path) all wrote it somewhere nobody reads. */
		$restartFlag = Path::join($server->getDataPath(), "system", "restart.flag");
		$systemDir = dirname($restartFlag);
		/** [BetterPMMP-PATCH] Report a failure instead of shutting the server down anyway. Without the check,
		 * a read-only or full data directory produced the "restarting" broadcast and then a server that was
		 * simply gone, with nothing in the log to say why. */
		if((is_dir($systemDir) || @mkdir($systemDir, 0777, true) || is_dir($systemDir)) && file_put_contents($restartFlag, '1') !== false){
			Command::broadcastCommandMessage($sender, new Translatable("pocketmine.command.restart.start"));
			$server->shutdown();
			return true;
		}

		$sender->sendMessage(new Translatable("pocketmine.command.restart.failed", [$restartFlag]));
		return true;
	}
}