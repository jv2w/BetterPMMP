# BetterPMMP

A maintained continuation of **PocketMine-MP**, the Minecraft: Bedrock Edition server software, with performance, gameplay, and quality-of-life improvements baked directly into the source.

> 🌏 [한국어 README](README.ko.md)

## About

[PocketMine-MP](https://github.com/pmmp/PocketMine-MP) was archived by its maintainers on 2026-07-09; the upstream team no longer ships updates. BetterPMMP is a derivative work that carries the codebase forward. It is **based on PocketMine-MP 5.44.3** and ships the complete server source in [source/](source/), so there is no separate patch step: clone and run.

BetterPMMP is not affiliated with Mojang or with the original PocketMine Team. See [NOTICE](NOTICE) for attribution and modification details.

## Features

The performance improvements are on from the start and leave gameplay untouched. Only the features that change how the game plays (fixed light, per-world settings, PvP savers, and the like) start off turned off: turn them on yourself in `pocketmine.yml` when you want them.

### Developer Experience

- **Restart Command**: `/restart`, backed by a restart loop in `start.cmd`. Source edits are picked up across a clean process boundary.
- **Run From Source**: `start.cmd` runs `source/src/PocketMine.php` directly instead of a `.phar`, so edits take effect on the next start.
- **Cleaner Logs & Paths**: tidier startup output and consolidated data / log / crashdump directories.
- **Localized Config Comments**: the documentation comments in `pocketmine.yml` and `resource_packs.yml` render in the language you pick in the setup wizard, and re-translate when you change it in `server.properties`.

### Performance

- **Block Input Lag Fix**: captures surrounding blocks before an interaction and sends back only the ones that changed, killing rubber-banding on place/break.
- **Fixed Light**: skips `LightPopulationTask` and fills light arrays with a constant, dropping the async/serialization/flood-fill cost. Opt in via `pocketmine.yml`.
- **Per-World View Distance**: override `view-distance` per world (handy for lobbies).
- **Per-World Chunk Ticking**: set `tick-radius` and `blocks-per-subchunk-per-tick` per world; set both to `0` to disable random ticking entirely.
- **Event & Network Tuning**: event-bus fast paths, dirty-tracked attribute syncs, cheaper packet framing, block and neighbour-update caching, and assorted engine fixes.
- **Snappy Compression**: opt-in Snappy packet compression as a lighter-CPU alternative to zlib; enable in `pocketmine.yml` (needs the `snappy` PHP extension).
- **PvP Toggles**: opt-in switches for vanilla systems an arena server rarely needs: runtime light updates, XP orbs, explosion block destruction, item merging, and empty-world ticking.

### Gameplay

- **Critical Hits**: configurable via `pocketmine.yml`; defaults match vanilla.
- **Iron Door No-Interact**: hand interaction no longer toggles iron doors.

## Requirements

- Windows (the start script is `start.cmd`)
- A PHP 8 binary. One is bundled at `source/bin/php/php.exe`; if it is missing, download a PM5 build from [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases) or use a `php.exe` on your `PATH`.

## Installation

1. Clone or download this repository.
2. Start the server with `start.cmd`.

That's it: the source in `source/` already contains every BetterPMMP change.

## License

BetterPMMP is licensed under the **GNU Lesser General Public License v3.0 or later** (LGPL-3.0-or-later), the same license as PocketMine-MP. See [LICENSE](LICENSE) for the full text and [NOTICE](NOTICE) for attribution.
