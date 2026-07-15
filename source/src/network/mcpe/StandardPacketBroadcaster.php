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

use pmmp\encoding\ByteBufferWriter;
use pocketmine\betterpmmp\BetterPMMPProperties;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\Server;
use pocketmine\timings\Timings;
use function count;
use function spl_object_id;
use function strlen;

final class StandardPacketBroadcaster implements PacketBroadcaster{
	public function __construct(
		private Server $server
	){}

	public function broadcastPackets(array $recipients, array $packets) : void{
		//TODO: this shouldn't really be called here, since the broadcaster might be replaced by an alternative
		//implementation that doesn't fire events
		/** [BetterPMMP-PATCH] event engine: optionally skip DataPacketSendEvent for movement broadcasts -
		 * the largest outbound packet stream (moving entities x viewers x 20/s) */
		if(DataPacketSendEvent::hasHandlers()
			&& !(count($packets) === 1
				&& (($packets[0] ?? null) instanceof \pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket || ($packets[0] ?? null) instanceof \pocketmine\network\mcpe\protocol\SetActorMotionPacket)
				&& (bool) $this->server->getConfigGroup()->getProperty(BetterPMMPProperties::NETWORK_SKIP_MOVEMENT_SEND_EVENT, false))){
			$ev = new DataPacketSendEvent($recipients, $packets);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
			$packets = $ev->getPackets();
		}

		$compressors = [];

		$targetsByCompressor = [];
		foreach($recipients as $recipient){
			//TODO: different compressors might be compatible, it might not be necessary to split them up by object
			$compressor = $recipient->getCompressor();
			$compressors[spl_object_id($compressor)] = $compressor;

			$targetsByCompressor[spl_object_id($compressor)][] = $recipient;
		}

		$totalLength = 0;
		$packetBuffers = [];
		$writer = new ByteBufferWriter();
		foreach($packets as $packet){
			$writer->clear(); //memory reuse let's gooooo
			$buffer = NetworkSession::encodePacketTimed($writer, $packet);
			//varint length prefix + packet buffer
			/** [BetterPMMP-PATCH] inline varint length: replace libm log() with a branch-predicted
			 * bit-range lookup (byte-perfect parity with vanilla log-truncation across all reachable lengths) */
			$len = strlen($buffer);
			$totalLength += ($len <= 0x7F ? 1 : ($len <= 0x3FFF ? 2 : ($len <= 0x1FFFFF ? 3 : ($len <= 0xFFFFFFF ? 4 : 5)))) + $len;
			$packetBuffers[] = $buffer;
		}

		foreach($targetsByCompressor as $compressorId => $compressorTargets){
			$compressor = $compressors[$compressorId];

			$threshold = $compressor->getCompressionThreshold();
			if(count($compressorTargets) > 1 && $threshold !== null && $totalLength >= $threshold){
				//do not prepare shared batch unless we're sure it will be compressed
				$stream = new ByteBufferWriter();
				PacketBatch::encodeRaw($stream, $packetBuffers);
				$batchBuffer = $stream->getData();

				$batch = $this->server->prepareBatch($batchBuffer, $compressor, timings: Timings::$playerNetworkSendCompressBroadcast);
				foreach($compressorTargets as $target){
					$target->queueCompressed($batch);
				}
			}else{
				foreach($compressorTargets as $target){
					foreach($packetBuffers as $packetBuffer){
						$target->addToSendBuffer($packetBuffer);
					}
				}
			}
		}
	}
}
