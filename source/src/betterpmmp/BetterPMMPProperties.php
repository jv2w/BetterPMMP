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

	/**
	 * Consumed by {@link BetterPMMPConfigFormat::enforceEnabled()} directly off the raw YAML, not through
	 * ConfigGroup::getProperty() - it is read before the config group exists, so it is the one key with no
	 * getProperty() call site.
	 */
	public const CONFIG_ENFORCE_FORMAT = 'better-pmmp.config.enforce-format';

	public const WORLD_BLOCK_CACHE_SIZE = 'better-pmmp.world.block-cache-size';
	public const WORLD_NEIGHBOUR_UPDATE_LIMIT = 'better-pmmp.world.neighbour-update-limit';
	public const WORLD_FREEZE_EMPTY_WORLDS = 'better-pmmp.world.freeze-empty-worlds';
	public const WORLD_VIEW_DISTANCE_PER_WORLD = 'better-pmmp.world.view-distance-per-world';
	public const WORLD_CHUNK_TICKING_BATCH_RECHECK_LIMIT = 'better-pmmp.world.chunk-ticking.batch-recheck-limit';
	public const WORLD_CHUNK_TICKING_PER_WORLD = 'better-pmmp.world.chunk-ticking.per-world';

	public const LIGHTING_FIXED_LIGHT = 'better-pmmp.lighting.fixed-light';
	public const LIGHTING_FIXED_LIGHT_LEVEL = 'better-pmmp.lighting.fixed-light-level';
	public const LIGHTING_SKIP_RUNTIME_UPDATES = 'better-pmmp.lighting.skip-runtime-updates';

	public const ENTITIES_ITEM_MERGING = 'better-pmmp.entities.item-merging';
	public const ENTITIES_ITEM_DESPAWN_TICKS = 'better-pmmp.entities.item-despawn-ticks';
	public const ENTITIES_XP_ORBS = 'better-pmmp.entities.xp-orbs';
	public const ENTITIES_PICKUP_SCAN_PERIOD = 'better-pmmp.entities.pickup-scan-period';

	public const COMBAT_CRITICAL_HIT_IGNORE_SPRINT = 'better-pmmp.combat.critical-hit-ignore-sprint';
	public const COMBAT_CRITICAL_HIT_MIN_FALL_DISTANCE = 'better-pmmp.combat.critical-hit-min-fall-distance';
	public const COMBAT_EXPLOSION_BLOCK_DESTRUCTION = 'better-pmmp.combat.explosion-block-destruction';
	public const COMBAT_INSTANT_HIT_FEEDBACK = 'better-pmmp.combat.instant-hit-feedback';

	public const NETWORK_SNAPPY_COMPRESSION = 'better-pmmp.network.snappy-compression';
	public const NETWORK_MOVEMENT_BROADCAST_PERIOD = 'better-pmmp.network.movement-broadcast-period';
	public const NETWORK_SKIP_MOVEMENT_SEND_EVENT = 'better-pmmp.network.skip-movement-send-event';
	public const NETWORK_SKIP_AUTH_INPUT_RECEIVE_EVENT = 'better-pmmp.network.skip-auth-input-receive-event';
	public const NETWORK_INTERACTION_SPAM_WINDOW = 'better-pmmp.network.interaction-spam-window';
	public const NETWORK_BLOCK_SYNC_SNAPSHOT = 'better-pmmp.network.block-sync-snapshot';
	public const NETWORK_CHUNK_HISTORY_LIMIT = 'better-pmmp.network.chunk-history-limit';

	public const EVENTS_MOVE_EVENT_PERIOD = 'better-pmmp.events.move-event-period';

	public const RECIPES_LOAD_VANILLA = 'better-pmmp.recipes.load-vanilla';

	public const PLUGINS_LIFECYCLE_LOG = 'better-pmmp.plugins.lifecycle-log';

	public const GAMEPLAY_HUNGER_EXHAUSTION = 'better-pmmp.gameplay.hunger-exhaustion';
	public const GAMEPLAY_FALL_DAMAGE = 'better-pmmp.gameplay.fall-damage';
	public const GAMEPLAY_FARMLAND_PERSISTENT = 'better-pmmp.gameplay.farmland-persistent';
	public const GAMEPLAY_FARMLAND_INSTANT_HYDRATION = 'better-pmmp.gameplay.farmland-instant-hydration';
}
