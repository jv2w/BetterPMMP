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
 * @author BetterPMMP Team
 * @link https://github.com/jv2w/BetterPMMP
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function function_exists;
use function ord;
use function snappy_compress;
use function snappy_uncompress;
use function strlen;

/**
 * [BetterPMMP-PATCH]
 * Snappy packet compression. Trades a lower compression ratio (more bandwidth) for much cheaper CPU
 * compression than zlib, lowering the MSPT cost of the synchronous compression path.
 *
 * Requires the `snappy` PHP extension. Callers MUST check {@link SnappyCompressor::isAvailable()}
 * before selecting this compressor; without the extension its methods fatal.
 */
final class SnappyCompressor implements Compressor{
	use SingletonTrait;

	public const DEFAULT_THRESHOLD = 256;
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 8 * 1024 * 1024;

	/**
	 * @see SingletonTrait::make()
	 */
	private static function make() : self{
		return new self(self::DEFAULT_THRESHOLD, self::DEFAULT_MAX_DECOMPRESSION_SIZE);
	}

	public static function isAvailable() : bool{
		return function_exists('snappy_compress') && function_exists('snappy_uncompress');
	}

	public function __construct(
		private ?int $minCompressionSize,
		private int $maxDecompressionSize = self::DEFAULT_MAX_DECOMPRESSION_SIZE
	){}

	public function getCompressionThreshold() : ?int{
		return $this->minCompressionSize;
	}

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string{
		/** [BetterPMMP-PATCH] Cap the decompressed size like ZlibCompressor does. Inbound payloads are
		 * attacker-controlled, and a Snappy stream declares its uncompressed length in a leading base-128
		 * varint, so a few bytes can otherwise ask for an arbitrarily large allocation. Read that varint
		 * and reject before expanding, then re-check the real result in case the header lied. */
		$declaredLength = 0;
		$shift = 0;
		$size = strlen($payload);
		for($i = 0; $i < $size; $i++){
			$byte = ord($payload[$i]);
			$declaredLength |= ($byte & 0x7f) << $shift;
			if(($byte & 0x80) === 0){
				break;
			}
			$shift += 7;
			if($shift > 28){
				throw new DecompressionException("Failed to decompress data");
			}
		}
		if($declaredLength > $this->maxDecompressionSize){
			throw new DecompressionException("Failed to decompress data");
		}
		$result = @snappy_uncompress($payload);
		if($result === false || strlen($result) > $this->maxDecompressionSize){
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	public function compress(string $payload) : string{
		return Utils::assumeNotFalse(snappy_compress($payload), "Snappy compression failed");
	}

	public function getNetworkId() : int{
		return CompressionAlgorithm::SNAPPY;
	}
}
