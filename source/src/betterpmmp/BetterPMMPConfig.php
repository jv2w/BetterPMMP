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

namespace pocketmine\betterpmmp;

use pocketmine\entity\object\ItemEntity;
use pocketmine\ServerConfigGroup;
use function is_array;
use function is_numeric;
use function max;
use function min;

/**
 * Every `better-pmmp` setting, read, validated and clamped once during server startup.
 *
 * Call sites read the typed statics directly; only init() writes them. Reading one before init() has
 * run throws, which is deliberate: a setting consumed too early is a bug, not something to paper over
 * with a default. The statics live on the main thread only - worker threads and child processes get
 * their own uninitialised copies.
 *
 * The documented meaning and default of each setting lives in resources/pocketmine.yml.
 */
final class BetterPMMPConfig{

	/** Read straight off the raw YAML by BetterPMMPConfigFormat, which runs before the config group exists. */
	public const CONFIG_ENFORCE_FORMAT = 'better-pmmp.config.enforce-format';

	public static int $blockCacheSize;
	public static int $neighbourUpdateLimit;
	public static bool $freezeEmptyWorlds;
	public static int $chunkTickBatchRecheckLimit;

	public static bool $fixedLight;
	public static int $fixedLightLevel;
	public static bool $skipRuntimeLightUpdates;

	public static bool $itemMerging;
	/** Ticks until a dropped item despawns, ItemEntity::NEVER_DESPAWN for never, or null to leave vanilla alone. */
	public static ?int $itemDespawnDelay;
	public static bool $xpOrbs;
	public static int $pickupScanPeriod;

	public static bool $criticalHitIgnoreSprint;
	public static float $criticalHitMinFallDistance;
	public static bool $explosionBlockDestruction;
	public static bool $instantHitFeedback;

	public static bool $snappyCompression;
	public static int $movementBroadcastPeriod;
	public static int $rotationBroadcastPeriod;
	public static bool $skipMovementSendEvent;
	public static bool $skipAuthInputReceiveEvent;
	public static float $interactionSpamWindowSeconds;
	public static bool $blockSyncSnapshot;
	public static int $chunkHistoryLimit;

	public static int $moveEventPeriod;
	public static bool $loadVanillaRecipes;
	public static bool $pluginLifecycleLog;

	public static bool $hungerExhaustion;
	public static bool $fallDamage;
	public static bool $ironDoorHandInteraction;
	public static bool $farmlandPersistent;
	public static bool $farmlandInstantHydration;

	/** @phpstan-var array<string, int> */
	private static array $viewDistancePerWorld = [];
	/** @phpstan-var array<string, int> */
	private static array $chunkTickRadiusPerWorld = [];
	/** @phpstan-var array<string, int> */
	private static array $chunkTickBlocksPerSubchunkPerWorld = [];

	private function __construct(){
		//NOOP
	}

	public static function init(ServerConfigGroup $cfg, \Logger $logger) : void{
		self::$blockCacheSize = self::clampedInt($cfg, $logger, 'better-pmmp.world.block-cache-size', 2048, 512, null);
		self::$neighbourUpdateLimit = self::clampedInt($cfg, $logger, 'better-pmmp.world.neighbour-update-limit', 0, 0, null);
		self::$freezeEmptyWorlds = $cfg->getPropertyBool('better-pmmp.world.freeze-empty-worlds', false);
		self::$viewDistancePerWorld = self::intMap($cfg, 'better-pmmp.world.view-distance-per-world', 2);
		self::$chunkTickBatchRecheckLimit = self::clampedInt($cfg, $logger, 'better-pmmp.world.chunk-ticking.batch-recheck-limit', 64, 0, null);
		self::readChunkTickingMaps($cfg, 'better-pmmp.world.chunk-ticking.per-world');

		self::$fixedLight = $cfg->getPropertyBool('better-pmmp.lighting.fixed-light', false);
		self::$fixedLightLevel = self::clampedInt($cfg, $logger, 'better-pmmp.lighting.fixed-light-level', 15, 0, 15);
		//fixed-light implies this: recalculating light would overwrite the fabricated uniform level
		self::$skipRuntimeLightUpdates = $cfg->getPropertyBool('better-pmmp.lighting.skip-runtime-updates', false) || self::$fixedLight;

		self::$itemMerging = $cfg->getPropertyBool('better-pmmp.entities.item-merging', true);
		self::$itemDespawnDelay = self::despawnDelay($cfg, $logger, 'better-pmmp.entities.item-despawn-ticks');
		self::$xpOrbs = $cfg->getPropertyBool('better-pmmp.entities.xp-orbs', true);
		self::$pickupScanPeriod = self::clampedInt($cfg, $logger, 'better-pmmp.entities.pickup-scan-period', 1, 1, null);

		self::$criticalHitIgnoreSprint = $cfg->getPropertyBool('better-pmmp.combat.critical-hit-ignore-sprint', false);
		self::$criticalHitMinFallDistance = self::nonNegativeFloat($cfg, $logger, 'better-pmmp.combat.critical-hit-min-fall-distance');
		self::$explosionBlockDestruction = $cfg->getPropertyBool('better-pmmp.combat.explosion-block-destruction', true);
		self::$instantHitFeedback = $cfg->getPropertyBool('better-pmmp.combat.instant-hit-feedback', true);

		self::$snappyCompression = $cfg->getPropertyBool('better-pmmp.network.snappy-compression', false);
		self::$movementBroadcastPeriod = self::clampedInt($cfg, $logger, 'better-pmmp.network.movement-broadcast-period', 1, 1, null);
		self::$rotationBroadcastPeriod = self::clampedInt($cfg, $logger, 'better-pmmp.network.rotation-broadcast-period', 1, 1, null);
		self::$skipMovementSendEvent = $cfg->getPropertyBool('better-pmmp.network.skip-movement-send-event', false);
		self::$skipAuthInputReceiveEvent = $cfg->getPropertyBool('better-pmmp.network.skip-auth-input-receive-event', false);
		self::$interactionSpamWindowSeconds = self::clampedInt($cfg, $logger, 'better-pmmp.network.interaction-spam-window', 20, 0, null) / 1000.0;
		self::$blockSyncSnapshot = $cfg->getPropertyBool('better-pmmp.network.block-sync-snapshot', true);
		self::$chunkHistoryLimit = self::clampedInt($cfg, $logger, 'better-pmmp.network.chunk-history-limit', 8192, 0, null);

		self::$moveEventPeriod = self::clampedInt($cfg, $logger, 'better-pmmp.events.move-event-period', 1, 1, null);
		self::$loadVanillaRecipes = $cfg->getPropertyBool('better-pmmp.recipes.load-vanilla', true);
		self::$pluginLifecycleLog = $cfg->getPropertyBool('better-pmmp.plugins.lifecycle-log', true);

		self::$hungerExhaustion = $cfg->getPropertyBool('better-pmmp.gameplay.hunger-exhaustion', true);
		self::$fallDamage = $cfg->getPropertyBool('better-pmmp.gameplay.fall-damage', true);
		self::$ironDoorHandInteraction = $cfg->getPropertyBool('better-pmmp.gameplay.iron-door-hand-interaction', false);
		self::$farmlandPersistent = $cfg->getPropertyBool('better-pmmp.gameplay.farmland-persistent', false);
		self::$farmlandInstantHydration = $cfg->getPropertyBool('better-pmmp.gameplay.farmland-instant-hydration', false);
	}

	/** The view distance configured for this world, or $fallback where the world has no override. */
	public static function viewDistance(string $worldFolderName, int $fallback) : int{
		return self::$viewDistancePerWorld[$worldFolderName] ?? $fallback;
	}

	public static function chunkTickRadius(string $worldFolderName) : ?int{
		return self::$chunkTickRadiusPerWorld[$worldFolderName] ?? null;
	}

	public static function chunkTickBlocksPerSubchunk(string $worldFolderName) : ?int{
		return self::$chunkTickBlocksPerSubchunkPerWorld[$worldFolderName] ?? null;
	}

	private static function clampedInt(ServerConfigGroup $cfg, \Logger $logger, string $key, int $default, int $min, ?int $max) : int{
		$value = $cfg->getPropertyInt($key, $default);
		$clamped = $max === null ? max($min, $value) : min($max, max($min, $value));
		if($clamped !== $value){
			$bound = $max === null ? "$min or above" : "$min to $max";
			$logger->warning("$key is set to $value, which is outside $bound; using $clamped");
		}
		return $clamped;
	}

	private static function nonNegativeFloat(ServerConfigGroup $cfg, \Logger $logger, string $key) : float{
		$raw = $cfg->getProperty($key, 0.0);
		$value = is_numeric($raw) ? (float) $raw : 0.0;
		if($value < 0.0){
			$logger->warning("$key is set to $value; a negative distance makes every hit critical, using 0.0");
			return 0.0;
		}
		return $value;
	}

	private static function despawnDelay(ServerConfigGroup $cfg, \Logger $logger, string $key) : ?int{
		$ticks = $cfg->getPropertyInt($key, ItemEntity::DEFAULT_DESPAWN_DELAY);
		if($ticks === ItemEntity::NEVER_DESPAWN){
			return ItemEntity::NEVER_DESPAWN;
		}
		if($ticks < 1){
			$logger->warning("$key is set to $ticks, which is neither a tick count nor -1 (never); using the vanilla " . ItemEntity::DEFAULT_DESPAWN_DELAY);
			return null;
		}
		if($ticks === ItemEntity::DEFAULT_DESPAWN_DELAY){
			return null;
		}
		if($ticks > ItemEntity::MAX_DESPAWN_DELAY){
			$logger->warning("$key is set to $ticks, which is more than the " . ItemEntity::MAX_DESPAWN_DELAY . " ticks the save format can hold; using " . ItemEntity::MAX_DESPAWN_DELAY);
			return ItemEntity::MAX_DESPAWN_DELAY;
		}
		return $ticks;
	}

	/**
	 * A world-name keyed map of clamped integers. Entries with no value are dropped so that a world
	 * listed without one falls back to the server-wide setting.
	 *
	 * @phpstan-return array<string, int>
	 */
	private static function intMap(ServerConfigGroup $cfg, string $key, int $min) : array{
		$raw = $cfg->getProperty($key, []);
		$map = [];
		if(is_array($raw)){
			foreach($raw as $world => $value){
				if($value !== null){
					$map[(string) $world] = max($min, is_numeric($value) ? (int) $value : 0);
				}
			}
		}
		return $map;
	}

	private static function readChunkTickingMaps(ServerConfigGroup $cfg, string $key) : void{
		$raw = $cfg->getProperty($key, []);
		if(!is_array($raw)){
			return;
		}
		foreach($raw as $world => $worldCfg){
			if(!is_array($worldCfg)){
				continue;
			}
			$tickRadius = $worldCfg['tick-radius'] ?? null;
			if($tickRadius !== null){
				self::$chunkTickRadiusPerWorld[(string) $world] = max(0, is_numeric($tickRadius) ? (int) $tickRadius : 0);
			}
			$tickedBlocks = $worldCfg['blocks-per-subchunk-per-tick'] ?? null;
			if($tickedBlocks !== null){
				self::$chunkTickBlocksPerSubchunkPerWorld[(string) $world] = max(0, is_numeric($tickedBlocks) ? (int) $tickedBlocks : 0);
			}
		}
	}
}
