Add the BetterPMMP enhancement module "$ARGUMENTS" — a feature toggled by a `better-pmmp.*` config key. A module is complete only when all 7 wiring points below are filled; any gap breaks silently (ghost key, default mismatch, missing language).

## Parse input

Determine 4 things from "$ARGUMENTS"; if any is unclear, ask one question (≤200 chars):

- **Key path** — without the `better-pmmp.` prefix (e.g. `network.foo-bar`), lowercase kebab. Prefer existing sections: `config` `world` `lighting` `entities` `combat` `network` `events` `recipes` `plugins` `gameplay`.
- **Type** — bool / int / string / map
- **Default** — the value that produces vanilla behavior; new modules default to off. Enabling-by-default needs justification in the report.
- **Behavior** — what changes where. Read the target sources before editing.

Reject: key already exists → ask whether this extends the existing module. Change that cannot be toggled → not a module; ask whether to apply it as a plain `[BetterPMMP-PATCH]` edit instead.

## 7 wiring points, in order

1. **`source/src/betterpmmp/BetterPMMPProperties.php`** — constant named from the key path (`network.foo-bar` → `NETWORK_FOO_BAR`), placed in its section group, same order as the yml.

2. **`source/resources/pocketmine.yml`** — under `better-pmmp:` in the matching section, two lines: `#! pocketmine.betterpmmp.yml.<key path>` then `<last-segment>: <default>`. 2-space indent; nesting mirrors the key path 1:1. A new section gets its own `#!` line too.

3. **`source/resources/translations/*.ini` — all 14** — `pocketmine.betterpmmp.yml.<key path>=<description>`, inside the existing betterpmmp block, same position as yml order. Translate into each language — all 45 existing keys are fully translated in all 14 files (bul.ini included); English copy-paste is a regression. Description = what it does + why that default + cost of enabling, 1–3 sentences. Newlines as literal `\n`; never a raw `;` inside a value (the parser truncates the rest). Batch-insert with a bundled-php script, then confirm every file reports the same `grep -c 'pocketmine.betterpmmp'` count.

4. **Call sites** — `Server::getInstance()->getConfigGroup()->getPropertyBool|Int|String(BetterPMMPProperties::X, <default>)`; maps use `getProperty(..., [])`. Values are read once at startup (README contract); on hot paths cache with `??=` — instance field for per-entity/session state, `private static ?T $x = null` for global. **The disabled path must be identical to vanilla**: branch once, as far out as possible, zero extra work when off — if that is impossible, redesign instead of implementing. No new files; only logic independent of vanilla classes may go in `source/src/betterpmmp/`.

5. **`README.md`** and 6. **`README.ko.md`** — add a row to the `| Key | Default | ... |` table in yml order, key and default in backticks, description matching the .ini text in each README's language.

7. **Verify** — phpstan per CLAUDE.md §검증 (full tree, EXIT 0). Confirm the default is identical in all 4 places: call-site argument / yml / both READMEs. Headless-boot (command in CLAUDE.md §검증) a temp data dir whose `pocketmine.yml` lacks the new key; confirm the key is spliced in with its comment and no `#!` marker remains.

## Done

All 7 points filled and phpstan EXIT 0 — never report completion earlier. Report 1–3 sentences: key, default, changed behavior. Never expose procedures or checklists.
