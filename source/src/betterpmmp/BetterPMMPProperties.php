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

namespace pocketmine\betterpmmp;

/**
 * [BetterPMMP-PATCH]
 * Constants for every property BetterPMMP reads from the `better-pmmp` section of pocketmine.yml.
 *
 * This mirrors {@link \pocketmine\YmlServerProperties} for the BetterPMMP config surface: the class
 * holds the dotted config keys only, and each call site passes its default inline to
 * ConfigGroup::getProperty(), exactly as the vanilla properties are consumed. Referencing a class
 * constant compiles to a cached constant fetch, so hot-path reads carry no extra runtime cost.
 *
 * The documented meaning and default of each key lives in resources/pocketmine.yml.
 */
final class BetterPMMPProperties{

	private function __construct(){
		//NOOP
	}

	/* Fixed light: skip async LightPopulationTask and fill light arrays with a constant. */
	public const FIXED_LIGHT_ENABLED = 'better-pmmp.fixed-light.enabled';
	public const FIXED_LIGHT_LEVEL = 'better-pmmp.fixed-light.level';

	/* Per-world overrides, keyed by world folder name. */
	public const PER_WORLD_VIEW_DISTANCE = 'better-pmmp.per-world-view-distance';
	public const PER_WORLD_CHUNK_TICKING = 'better-pmmp.per-world-chunk-ticking';

	/* Engine throttles shared across worlds. */
	public const CHUNK_OPTIMIZATION_BATCH_RECHECK_LIMIT = 'better-pmmp.chunk-optimization.batch-recheck-limit';
	public const NEIGHBOUR_UPDATE_LIMIT = 'better-pmmp.neighbour-update-limit';
	public const BLOCK_CACHE_SIZE = 'better-pmmp.block-cache-size';

	/* One-time startup toggle for vanilla recipe registration. */
	public const LOAD_VANILLA_RECIPES = 'better-pmmp.load-vanilla-recipes';

	/* PvP server optimization: opt-in toggles for vanilla systems arena servers rarely need. */
	public const PVP_OPTIMIZATION_SKIP_LIGHT_UPDATES = 'better-pmmp.pvp-optimization.skip-light-updates';
	public const PVP_OPTIMIZATION_XP_ORBS = 'better-pmmp.pvp-optimization.xp-orbs';
	public const PVP_OPTIMIZATION_EXPLOSION_BLOCK_DESTRUCTION = 'better-pmmp.pvp-optimization.explosion-block-destruction';
	public const PVP_OPTIMIZATION_ITEM_MERGING = 'better-pmmp.pvp-optimization.item-merging';
	public const PVP_OPTIMIZATION_ITEM_DESPAWN_TICKS = 'better-pmmp.pvp-optimization.item-despawn-ticks';
	public const PVP_OPTIMIZATION_MOVEMENT_BROADCAST_PERIOD = 'better-pmmp.pvp-optimization.movement-broadcast-period';
	public const PVP_OPTIMIZATION_PICKUP_SCAN_PERIOD = 'better-pmmp.pvp-optimization.pickup-scan-period';
	public const PVP_OPTIMIZATION_FREEZE_EMPTY_WORLDS = 'better-pmmp.pvp-optimization.freeze-empty-worlds';

	/* Event engine optimization: decimate the hottest event call sites. */
	public const EVENT_OPTIMIZATION_MOVE_EVENT_PERIOD = 'better-pmmp.event-optimization.move-event-period';
	public const EVENT_OPTIMIZATION_SKIP_AUTH_INPUT_RECEIVE_EVENT = 'better-pmmp.event-optimization.skip-auth-input-receive-event';
	public const EVENT_OPTIMIZATION_SKIP_MOVEMENT_SEND_EVENT = 'better-pmmp.event-optimization.skip-movement-send-event';

	/* Critical hit tuning. */
	public const CRITICAL_HIT_IGNORE_SPRINT = 'better-pmmp.critical-hit.ignore-sprint';
	public const CRITICAL_HIT_MIN_FALL_DISTANCE = 'better-pmmp.critical-hit.min-fall-distance';
}
