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

/* Modified by the BetterPMMP project (2026) - see the NOTICE file for details. */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\betterpmmp\BetterPMMPConfig;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\Server;
use pocketmine\timings\Timings;
use function count;
use function reset;
use function spl_object_id;
use function strlen;

final class StandardPacketBroadcaster implements PacketBroadcaster{
	public function __construct(
		private Server $server
	){}

	public function broadcastPackets(array $recipients, array $packets) : void{
		//TODO: this shouldn't really be called here, since the broadcaster might be replaced by an alternative
		//implementation that doesn't fire events
		//event engine: optionally skip DataPacketSendEvent for movement broadcasts - the largest outbound packet stream
		//(moving entities x viewers x 20/s)
		if(DataPacketSendEvent::hasHandlers()
			&& !(count($packets) === 1
				&& ($packets[0] instanceof MoveActorAbsolutePacket || $packets[0] instanceof SetActorMotionPacket)
				&& BetterPMMPConfig::$skipMovementSendEvent)){
			$ev = new DataPacketSendEvent($recipients, $packets);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
			$packets = $ev->getPackets();
		}

		if(count($recipients) === 0){
			return;
		}

		//One Compressor is resolved at startup and shared by every session (see RakLibInterface), so probe for
		//uniformity with a single identity compare per recipient instead of building two spl_object_id-keyed grouping
		//maps. The vanilla grouping below still runs when they differ.
		//TODO: different compressors might be compatible, it might not be necessary to split them up by object
		$firstCompressor = reset($recipients)->getCompressor();
		$uniform = true;
		foreach($recipients as $recipient){
			if($recipient->getCompressor() !== $firstCompressor){
				$uniform = false;
				break;
			}
		}

		$totalLength = 0;
		$packetBuffers = [];
		$writer = new ByteBufferWriter();
		foreach($packets as $packet){
			$writer->clear(); //memory reuse let's gooooo
			$buffer = NetworkSession::encodePacketTimed($writer, $packet);
			//varint length prefix + packet buffer
			//Bit-range lookup instead of libm log(). This is the exact byte count; vanilla's ((int) log($len, 128)) + 1
			//can be one short at exact powers of 128. $totalLength only feeds the threshold test below either way.
			$len = strlen($buffer);
			$totalLength += ($len <= 0x7F ? 1 : ($len <= 0x3FFF ? 2 : ($len <= 0x1FFFFF ? 3 : ($len <= 0xFFFFFFF ? 4 : 5)))) + $len;
			$packetBuffers[] = $buffer;
		}

		if($uniform){
			$this->sendToCompressorGroup($firstCompressor, $recipients, $packetBuffers, $totalLength);
			return;
		}

		$compressors = [];
		$targetsByCompressor = [];
		foreach($recipients as $recipient){
			$compressor = $recipient->getCompressor();
			$compressorId = spl_object_id($compressor);
			$compressors[$compressorId] = $compressor;
			$targetsByCompressor[$compressorId][] = $recipient;
		}

		foreach($targetsByCompressor as $compressorId => $compressorTargets){
			$this->sendToCompressorGroup($compressors[$compressorId], $compressorTargets, $packetBuffers, $totalLength);
		}
	}

	/**
	 * Extracted from broadcastPackets() so the uniform-compressor fast path and the
	 * vanilla per-compressor grouping share one implementation. Behaviour is byte-identical to vanilla.
	 *
	 * @param NetworkSession[] $compressorTargets
	 * @param string[]         $packetBuffers
	 * @phpstan-param list<string> $packetBuffers
	 */
	private function sendToCompressorGroup(Compressor $compressor, array $compressorTargets, array $packetBuffers, int $totalLength) : void{
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
