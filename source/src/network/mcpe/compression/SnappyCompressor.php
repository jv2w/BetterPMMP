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

namespace pocketmine\network\mcpe\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function function_exists;
use function snappy_compress;
use function snappy_uncompress;

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

	/**
	 * @see SingletonTrait::make()
	 */
	private static function make() : self{
		return new self(self::DEFAULT_THRESHOLD);
	}

	public static function isAvailable() : bool{
		return function_exists('snappy_compress') && function_exists('snappy_uncompress');
	}

	public function __construct(
		private ?int $minCompressionSize
	){}

	public function getCompressionThreshold() : ?int{
		return $this->minCompressionSize;
	}

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string{
		$result = @snappy_uncompress($payload);
		if($result === false){
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
