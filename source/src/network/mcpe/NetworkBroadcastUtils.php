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

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use function count;
use function reset;
use function spl_object_id;

final class NetworkBroadcastUtils{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param Player[]            $recipients
	 * @param ClientboundPacket[] $packets
	 */
	public static function broadcastPackets(array $recipients, array $packets) : bool{
		if(count($packets) === 0){
			throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
		}

		return Timings::$broadcastPackets->time(function() use ($recipients, $packets) : bool{
			/** @var NetworkSession[] $sessions */
			$sessions = [];
			foreach($recipients as $player){
				if($player->isConnected()){
					$session = $player->getNetworkSession();
					$sessions[spl_object_id($session)] = $session;
				}
			}
			if(count($sessions) === 0){
				return false;
			}

			/** [BetterPMMP-PATCH] PvP optimization: a single PacketBroadcaster is shared server-wide in
			 * practice, so probe for uniformity with one identity compare per session and skip building the
			 * two spl_object_id-keyed grouping maps entirely. Saves 2 spl_object_id calls + 2 hash writes
			 * per (moving entity x viewer) pair per tick; the vanilla grouping below still runs whenever
			 * the broadcasters genuinely differ, so behaviour is unchanged either way. */
			$firstBroadcaster = reset($sessions)->getBroadcaster();
			$uniform = true;
			foreach($sessions as $session){
				if($session->getBroadcaster() !== $firstBroadcaster){
					$uniform = false;
					break;
				}
			}
			if($uniform){
				$firstBroadcaster->broadcastPackets($sessions, $packets);
				return true;
			}

			/** @var PacketBroadcaster[] $uniqueBroadcasters */
			$uniqueBroadcasters = [];
			/** @var NetworkSession[][] $broadcasterTargets */
			$broadcasterTargets = [];
			foreach($sessions as $sessionId => $recipient){
				$broadcaster = $recipient->getBroadcaster();
				$broadcasterId = spl_object_id($broadcaster);
				$uniqueBroadcasters[$broadcasterId] = $broadcaster;
				$broadcasterTargets[$broadcasterId][$sessionId] = $recipient;
			}
			foreach($uniqueBroadcasters as $broadcasterId => $broadcaster){
				$broadcaster->broadcastPackets($broadcasterTargets[$broadcasterId], $packets);
			}

			return true;
		});
	}

	/**
	 * @param Player[] $recipients
	 * @phpstan-param \Closure(EntityEventBroadcaster, array<int, NetworkSession>) : void $callback
	 */
	public static function broadcastEntityEvent(array $recipients, \Closure $callback) : void{
		/** [BetterPMMP-PATCH] PvP optimization: same uniform-broadcaster fast path as broadcastPackets().
		 * One EntityEventBroadcaster is shared server-wide, so the grouping maps below are pure overhead
		 * in the common case. */
		$sessions = [];
		foreach($recipients as $recipient){
			$session = $recipient->getNetworkSession();
			$sessions[spl_object_id($session)] = $session;
		}
		if(count($sessions) === 0){
			return;
		}

		$firstBroadcaster = reset($sessions)->getEntityEventBroadcaster();
		$uniform = true;
		foreach($sessions as $session){
			if($session->getEntityEventBroadcaster() !== $firstBroadcaster){
				$uniform = false;
				break;
			}
		}
		if($uniform){
			$callback($firstBroadcaster, $sessions);
			return;
		}

		$uniqueBroadcasters = [];
		$broadcasterTargets = [];
		foreach($sessions as $sessionId => $session){
			$broadcaster = $session->getEntityEventBroadcaster();
			$broadcasterId = spl_object_id($broadcaster);
			$uniqueBroadcasters[$broadcasterId] = $broadcaster;
			$broadcasterTargets[$broadcasterId][$sessionId] = $session;
		}

		foreach($uniqueBroadcasters as $k => $broadcaster){
			$callback($broadcaster, $broadcasterTargets[$k]);
		}
	}
}
