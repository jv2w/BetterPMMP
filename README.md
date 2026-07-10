# BetterPMMP

A patch tool for **PocketMine-MP 5.0.0** that applies performance, gameplay, and quality-of-life fixes directly to the source.

> 🌏 [한국어 README](README.ko.md)

## Usage

```bash
php patch_tool.php <source_directory>
```

`patch_tool.php` runs about 50 patches over the PMMP source. Each patch tags its edits with `[BetterPMMP-PATCH]`, so already-applied patches are skipped and re-running is safe. You get an `APPLIED` / `SKIPPED` / `FAILED` summary at the end.

Every patch defaults to vanilla-observable behaviour. The optional tuning knobs in `pocketmine.yml` are opt-in.

## Features

### Developer Experience

- **Restart Command** — `/restart`, backed by a restart loop in `start.cmd`. Source edits are picked up across a clean process boundary.
- **Run From Source** — `start.cmd` runs `source/src/PocketMine.php` directly instead of a `.phar`, so edits take effect on the next start.
- **Cleaner Logs & Paths** — tidier startup output and consolidated data / log / crashdump directories.

### Performance

- **Block Input Lag Fix** — captures surrounding blocks before an interaction and sends back only the ones that changed, killing rubber-banding on place/break.
- **Fixed Light** — skips `LightPopulationTask` and fills light arrays with a constant, dropping the async/serialization/flood-fill cost. Opt in via `pocketmine.yml`.
- **Per-World View Distance** — override `view-distance` per world (handy for lobbies).
- **Per-World Chunk Ticking** — set `tick-radius` and `blocks-per-subchunk-per-tick` per world; set both to `0` to disable random ticking entirely.
- **Event & Network Tuning** — event-bus fast paths, dirty-tracked attribute syncs, cheaper packet framing, block and neighbour-update caching, and assorted engine fixes.
- **PvP Toggles** — opt-in switches for vanilla systems an arena server rarely needs: runtime light updates, XP orbs, explosion block destruction, item merging, and empty-world ticking.

### Gameplay

- **Critical Hits** — configurable via `pocketmine.yml`; defaults match vanilla.
- **Iron Door No-Interact** — hand interaction no longer toggles iron doors.

## Requirements

- PocketMine-MP 5.0.0 source
- A PMMP PHP 8 binary from [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases)
- Windows (the start scripts target `start.cmd`)

## Installation

1. Put the PocketMine-MP 5.0.0 source in `source/`.
2. Provide a PHP binary — either `bin/php/php.exe` inside the server (preferred) or a `php.exe` on your `PATH`.
3. Run `php patch_tool.php source` (or `makeBetterPMMP.bat`).
4. Start the server with `start.cmd`.

Patches are idempotent, so re-run after every source update.

## License

MIT
