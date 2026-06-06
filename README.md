# BetterPMMP

A patch tool that applies performance optimizations, gameplay fixes, and developer-experience improvements directly to **PocketMine-MP 5.0.0** source code.

> 🌏 [한국어 README](README.ko.md)

## How It Works

`patch_tool.php` runs the PMMP source tree through ~60 idempotent patch functions. Each patch:

- Marks itself with `[BETTERPMMP-PATCH]` so it is never applied twice.
- Reports `APPLIED` / `SKIPPED` / `FAILED` in a summary at the end.
- Is self-healing — re-running on an already-patched (or partially patched) tree is safe.

```bash
php patch_tool.php <source_directory_path>
```

## Features

### Developer Experience

- **Plugin Hot Reload** — Reload plugins at runtime via the `/reload <pluginName>` command, no server restart. Plugins are fully unloaded and reloaded along with their event listeners, commands, and permissions. Uses a versioned-namespace class cache invalidator (`ClassCacheInvalidator`) and a resource index (`PluginResourceIndex` / `PluginResources`) to track and rebuild plugin state, including reverse dependency maps.
- **Restart Command** — `/restart` command plus a `start.cmd` restart loop for clean server cycling.
- **Source-Based Startup** — Patches `start.cmd` to run from `source/src/PocketMine.php` instead of a `.phar`, so source edits take effect immediately.
- **Cleaner Logs & Paths** — Tidier startup logs, info prefixes, GC logging, and consolidated data / log / crashdump paths.

### Performance Optimizations

- **Block Input Lag Fix** — Snapshot-based block sync. Surrounding block states are captured before an interaction; only changed blocks are sent back, eliminating rubber-banding on place/break.
- **Fixed Light** — Skips the async `LightPopulationTask` and fills light arrays with a fixed value, removing async submission, igbinary serialization, and BFS flood-fill overhead. Configurable in `pocketmine.yml`.
- **Per-World View Distance** — Override `view-distance` per world (great for lobbies).
- **Per-World Chunk Ticking** — Independent `tick-radius` and `blocks-per-subchunk-per-tick` per world; set both to `0` to fully disable random ticking on lobbies.
- **FPS / Network Optimizations** — Entity broadcast batching, actor animation distance filtering, particle/sound distance filtering, chunk-send pacing, and item-entity suppression.
- **Entity & World Tuning** — Move-in-place fast path, blocks-around caching, motion epsilon cleanup, neighbour-update throttling, block cache sizing, and safer entity tick/unload iteration.
- **Misc Engine Tuning** — Handler-list merge/registration caching, network session handler guards, health float-comparison fix, respawn lock reset, online-player snapshot on removal, and class-map-authoritative autoloading.

### Gameplay

- **Critical Hit** — Configurable critical-hit mechanics via `pocketmine.yml` / `server.properties`.
- **Iron Door No-Interact** — Prevents hand interaction toggling iron doors.

## Requirements

- PocketMine-MP 5.0.0 source code
- PHP 8.x
- Windows (start scripts target `start.cmd`)

## Installation

1. Place the PocketMine-MP 5.0.0 source in the `source/` folder.
2. Run the patch tool:
   ```bash
   php patch_tool.php source
   ```
   (or run `makeBetterPMMP.bat`)
3. Start the server with `start.cmd`.

Patches are idempotent — re-run any time after updating the source.

## License

LGPL-3.0
