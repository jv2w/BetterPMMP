# BetterPMMP

A patch tool for **PocketMine-MP 5.0.0** that applies performance, gameplay, and quality-of-life fixes directly to the source.

> 🌏 [한국어 README](README.ko.md)

## Usage

```bash
php patch_tool.php <source_directory>
```

`patch_tool.php` runs about 60 patches over the PMMP source. Each patch tags its edits with `[BetterPMMP-PATCH]`, so already-applied patches are skipped and re-running is safe. You get an `APPLIED` / `SKIPPED` / `FAILED` summary at the end.

## Features

### Developer Experience

- **Plugin Hot Reload** — `/reload <plugin>` reloads a plugin at runtime without restarting the server. Its listeners, commands, and permissions are unloaded and re-registered, and any plugins depending on it are cycled in dependency order so they re-bind to the new instance. A failed reload rolls back to the last working version.
  - Only directory/source plugins can be reloaded; `.phar` and single-file plugins are rejected and need a full restart.
  - PHP can't unload classes, so each reload of a changed plugin leaves the old version in memory. Restart to reclaim it.
- **Restart Command** — `/restart`, backed by a restart loop in `start.cmd`.
- **Run From Source** — `start.cmd` runs `source/src/PocketMine.php` directly instead of a `.phar`, so edits take effect on the next start.
- **Cleaner Logs & Paths** — tidier startup output and consolidated data / log / crashdump directories.

### Performance

- **Block Input Lag Fix** — captures surrounding blocks before an interaction and sends back only the ones that changed, killing rubber-banding on place/break.
- **Fixed Light** — skips `LightPopulationTask` and fills light arrays with a constant, dropping the async/serialization/flood-fill cost. Toggle in `pocketmine.yml`.
- **Per-World View Distance** — override `view-distance` per world (handy for lobbies).
- **Per-World Chunk Ticking** — set `tick-radius` and `blocks-per-subchunk-per-tick` per world; set both to `0` to disable random ticking entirely.
- **Network & Entity Tuning** — broadcast batching, distance filtering for animations/particles/sounds, chunk-send pacing, block/neighbour caching, and assorted engine fixes.

### Gameplay

- **Critical Hits** — configurable via `pocketmine.yml` / `server.properties`.
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
