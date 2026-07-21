# BetterPMMP

A maintained continuation of **PocketMine-MP**, the Minecraft: Bedrock Edition server software, with performance, gameplay, and quality-of-life improvements baked directly into the source.

> 🌏 [한국어 README](README.ko.md)

## About

[PocketMine-MP](https://github.com/pmmp/PocketMine-MP) was archived by its maintainers on 2026-07-09; the upstream team no longer ships updates. BetterPMMP is a derivative work that carries the codebase forward. It is **based on PocketMine-MP 5.44.3** and ships the complete server source in [source/](source/), so there is no separate patch step: clone and run.

BetterPMMP is not affiliated with Mojang or with the original PocketMine Team. See [NOTICE](NOTICE) for attribution and modification details.

## Features

The performance improvements are on from the start and leave gameplay untouched. Only the features that change how the game plays (fixed light, per-world settings, PvP savers, and the like) start off turned off: turn them on yourself in `pocketmine.yml` when you want them.

### Developer Experience

- **Restart Command**: `/restart`, backed by a restart loop in `start.cmd` and `start.sh`. Source edits are picked up across a clean process boundary.
- **Run From Source**: the start scripts run `source/src/PocketMine.php` directly instead of a `.phar`, so edits take effect on the next start.
- **Cleaner Logs & Paths**: tidier startup output and consolidated data / log / crashdump directories.
- **Localized Config Comments**: the documentation comments in `pocketmine.yml` and `resource_packs.yml` render in the language you pick in the setup wizard, and re-translate when you change it in `server.properties`.

### Performance

- **Block Sync Trimming**: records the clicked block before an interaction and skips re-sending it when the interaction left it unchanged. The surrounding blocks are still sent, because that is what overwrites the client's optimistic prediction.
- **Fixed Light**: skips `LightPopulationTask` and fills every chunk's light arrays with a constant as it loads, dropping the async/serialization/flood-fill cost. Opt in via `pocketmine.yml`.
- **Per-World View Distance**: override `view-distance` per world (handy for lobbies).
- **Per-World Chunk Ticking**: set `tick-radius` and `blocks-per-subchunk-per-tick` per world; set both to `0` to disable random ticking entirely.
- **Event & Network Tuning**: event-bus fast paths, a closure-free attribute sync scan, cheaper packet framing, block and neighbour-update caching, and assorted engine fixes.
- **Hot-Path Micro-Optimizations**: the armor tick loop only runs for items that actually do something when worn (no more per-tick item clones on every living entity), per-packet profiler bookkeeping is skipped entirely while `/timings` is off, and random block ticking iterates subchunks without rebuilding an array per chunk per tick. Behaviour is identical; only the overhead is gone.
- **Snappy Compression**: opt-in Snappy packet compression as a lighter-CPU alternative to zlib; enable in `pocketmine.yml`. It needs the `snappy` PHP extension, which the bundled binary already has; without it the server warns and stays on zlib.
- **PvP Toggles**: opt-in switches for vanilla systems an arena server rarely needs: runtime light updates, XP orbs, explosion block destruction, item merging, and empty-world ticking.

### Gameplay

- **Critical Hits**: configurable via `pocketmine.yml`; defaults match vanilla.
- **Iron Door/Trapdoor No-Interact**: hand interaction no longer toggles iron doors and iron trapdoors, matching vanilla, where only redstone opens them. Set `gameplay.iron-door-hand-interaction` to put the upstream behaviour back.
- **Mechanic Toggles**: hunger exhaustion, fall damage, and farmland drying/trampling can each be switched off for minigame and arena servers. All default to vanilla behaviour.
- **No Upstream Telemetry**: crash reporting, the update check and usage statistics are off by default. Their stock hosts belong to the archived PocketMine-MP project and know nothing about this fork.

## Configuration

Every option lives under the `better-pmmp:` section of `pocketmine.yml`, and each one carries a comment in your server language explaining what it does. Options added by a later version are written into your existing file on the next startup, comments and all. **All of them require a server restart** — values are read once at startup.

Two settings outside that section are also changed from upstream: `network.compression-level` ships at `1` instead of `6` (much cheaper to compress, 10–15 % larger payloads, a trade that favours tick time), and `auto-report`, `auto-updater` and `anonymous-statistics` all default to off.

| Key | Default | What it does |
| --- | --- | --- |
| `config.enforce-format` | `false` | Rewrite this file into the BetterPMMP layout every startup. Values are kept; your own comments are not. |
| `world.block-cache-size` | `2048` | Per-world block/collision cache cap. |
| `world.neighbour-update-limit` | `0` | Max neighbour block updates per tick; `0` is vanilla. |
| `world.freeze-empty-worlds` | `false` | Run player-less worlds at 1 tick in 100. |
| `world.view-distance-per-world` | `{}` | View distance per world folder name. |
| `world.chunk-ticking.batch-recheck-limit` | `64` | Max chunks rechecked for tick eligibility per tick. |
| `world.chunk-ticking.per-world` | `{}` | `tick-radius` / `blocks-per-subchunk-per-tick` per world; both `0` disables random ticking. |
| `lighting.fixed-light` | `false` | Skip light population, fill with a constant. Implies `skip-runtime-updates`. |
| `lighting.fixed-light-level` | `15` | Level used by `fixed-light`. |
| `lighting.skip-runtime-updates` | `false` | Skip light recalculation on block changes. |
| `entities.item-merging` | `true` | Merge nearby dropped items. |
| `entities.item-despawn-ticks` | `6000` | Drop despawn time; `-1` never despawns. |
| `entities.xp-orbs` | `true` | Spawn XP orbs. Disabling **destroys** XP rather than crediting it. |
| `entities.pickup-scan-period` | `1` | Scan for pickups every N ticks. |
| `combat.critical-hit-ignore-sprint` | `false` | Allow crits while sprinting. |
| `combat.critical-hit-min-fall-distance` | `0.0` | Minimum fall distance for a crit. |
| `combat.explosion-block-destruction` | `true` | Explosions break blocks. Disabling also stops TNT chaining. |
| `combat.instant-hit-feedback` | `true` | Send hit feedback immediately instead of at end of tick. |
| `network.snappy-compression` | `false` | Snappy instead of zlib (needs `ext-snappy`). |
| `network.movement-broadcast-period` | `1` | Send movement packets every N ticks. |
| `network.rotation-broadcast-period` | `1` | Send view rotation every N ticks while the player stands still. |
| `network.skip-movement-send-event` | `false` | Skip `DataPacketSendEvent` for movement/motion packets. |
| `network.skip-auth-input-receive-event` | `false` | Skip `DataPacketReceiveEvent` for auth input. |
| `network.interaction-spam-window` | `20` | Duplicate-interaction filter window, in ms (upstream 100). |
| `network.block-sync-snapshot` | `true` | Skip re-sending the clicked block when unchanged. |
| `network.chunk-history-limit` | `8192` | Chunk positions remembered per session for world-change cleanup. |
| `events.move-event-period` | `1` | Fire `PlayerMoveEvent` every N ticks. Above 1 breaks anti-cheat and region plugins. |
| `recipes.load-vanilla` | `true` | Register vanilla crafting, furnace and brewing recipes. |
| `plugins.lifecycle-log` | `true` | Log plugin load/enable/disable. |
| `gameplay.hunger-exhaustion` | `true` | Hunger drain from every cause. |
| `gameplay.fall-damage` | `true` | Fall damage for living entities. |
| `gameplay.iron-door-hand-interaction` | `false` | Let hand interaction toggle iron doors and trapdoors. Vanilla needs redstone. |
| `gameplay.farmland-persistent` | `false` | Farmland never dries, tramples, or reverts. |
| `gameplay.farmland-instant-hydration` | `false` | Tilled/placed farmland starts wet. Needs `farmland-persistent` to stay wet. |

Options that change how the game plays are off by default; performance options that alter vanilla behaviour (`neighbour-update-limit`, `move-event-period`, `movement-broadcast-period`, `rotation-broadcast-period`, `pickup-scan-period`) ship at their vanilla-equivalent value.

## Requirements

- Windows (`start.cmd`) or Linux (`start.sh`)
- A PHP 8 binary. A Windows build is bundled at `source/bin/php/php.exe`. If it is missing, or you are on Linux, download a PM5 build from [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases) into `source/bin/php/`, or put a `php` binary on your `PATH` — the start scripts prefer the bundled one and fall back to `PATH`.

## Installation

1. Clone or download this repository.
2. Start the server with `start.cmd` on Windows, or `./start.sh` on Linux.

That's it: the source in `source/` already contains every BetterPMMP change.

## License

BetterPMMP is licensed under the **GNU Lesser General Public License v3.0 or later** (LGPL-3.0-or-later), the same license as PocketMine-MP. See [LICENSE](LICENSE) for the full text and [NOTICE](NOTICE) for attribution.
