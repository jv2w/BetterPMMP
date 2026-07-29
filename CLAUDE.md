# BetterPMMP
Maintenance successor to the archived PocketMine-MP, based on **PocketMine-MP 5.44.3**. The full server source lives in `source/` and is **edited directly**. All code must pass PMMP coding conventions and phpstan (max).

## License — top priority; violations block distribution
- **LGPL-3.0-or-later** for all of `source/`. Keep root `LICENSE` (full text) and `NOTICE` (attribution, §5a change notice, trademarks). Never relicense (e.g. back to MIT).
- Every `.php` keeps the PMMP LGPL header (ASCII art + "GNU Lesser General Public License" block) at the top; new files get the same header, copied from an existing file. Never delete or damage it.
- **§5a change notice** — every upstream file BetterPMMP modifies carries exactly one notice line, placed after the LGPL header and before `declare`: `/* Modified by the BetterPMMP project (2026) - see the NOTICE file for details. */`. New BetterPMMP files carry the LGPL header with `@author BetterPMMP Team` instead. This is the evidence of "modified from PMMP 5.44.3" — never remove it, and add it the first time you touch a previously untouched upstream file.
- Third party — never delete or edit the `LICENSE` files under `source/vendor/*` or `source/bin/php/license.txt`.

## Principles
- **Accuracy** — Read the target source before working; no guessing.
- **Integrity** — zero regressions in vanilla observable behavior. Always look for a lighter, safer alternative first; redesign anything questionable.
- **Justification** — state how each change strengthens the source (overhead removed, bug fixed, MSPT saved). No change without one.
- **Performance > readability** on hot paths — MSPT/TPS first.
- **Consistency** — one way to do each thing. All `better-pmmp.*` settings go through `BetterPMMPConfig` (typed statics, `init()` once in `Server::__construct`, main thread only); never cache a config value anywhere else. A new patch follows the shape of the nearest existing one rather than inventing its own.

## Verification — required after every change
- Tooling ships in the tree: `source/phpstan.neon.dist` (level max, strict-rules + phpunit extensions) + `source/phpstan-baseline.neon` (upstream-5.44.3 pre-existing errors only); dev deps are committed in `source/vendor/`.
- Run from `source/`: `./bin/php/php.exe vendor/bin/phpstan analyse --memory-limit=4G`
- Always use the bundled php (`source/bin/php/php.exe`) — it carries every server extension, so ext symbols resolve via reflection; system php false-positives. Use it for ad-hoc runtime checks too (`parse_ini_file`, `yaml_parse`, …).
- **Pass = full-tree EXIT 0, actually observed — never claim "no errors" without running.** New/modified code adds zero errors, with exact types, generic PHPDoc and `@phpstan-*`. Never hide a new error in the baseline or `@phpstan-ignore` — fix the root cause.
- `composer install`/`update` reverts the vendor files listed in `composer.json` `extra.betterpmmp-vendor-patches` — restore with `git checkout -- <file>` and confirm via a `Modified by the BetterPMMP project` grep. Changing such a file means regenerating its `.patch` under `source/build/betterpmmp-vendor-patches/`.
- When touching `src/betterpmmp/`, also run `./bin/php/php.exe vendor/bin/phpunit --configuration phpunit.xml.dist`.
- Runtime check (headless boot), from `source/`: `./bin/php/php.exe src/PocketMine.php --no-wizard --disable-ansi --no-log-file "--data=<tmp>" "--plugins=<tmp>/plugins"`. Options must use `--opt=value` form — with a space-separated value, later flags are silently ignored and the setup wizard blocks on STDIN. Config files are written early in boot; killing after ~30 s is fine.

## Code rules
- **Comments** — none, except exactly 3 kinds:
  1. the LGPL file header plus the modification notice line (§License, required)
  2. plain English comments, sparingly, only where a non-obvious *why* needs stating — 1–3 lines, never restating what the code does, no marker tags. `//` inside function bodies, `/** */` only as a docblock on a declaration.
  3. tool directives — `@var`, `@phpstan-ignore`, …
- **File format** — `source/resources/**` (`*.ini`, `*.yml`) are CRLF UTF-8, no BOM; keep `\r\n` in byte-level edits (mixed endings break config markers and parsers). Git-Bash grep hides `\r`; check with `grep -U`.
- **Performance** — cache repeated property reads in locals; `match` over `switch`; `readonly` for immutables; prefer `foreach`; no object cloning, dynamic properties, or array re-indexing.
- **Brevity** — inline 1–2-line logic; extract a function only for ≥2 existing call sites ("future reuse" doesn't count); pass callbacks/closures directly.
- **Guard clauses** — single-line early exits; no `else` after `return`.
- **State design** — make invalid states unrepresentable via types/enums; if error handling seems needed, redesign.
- **Transactions** — item grants are atomic and idempotent; check preconditions, deduplicate.
- **Naming** — camelCase vars/functions, PascalCase classes. Abbreviations (vars/params only): message→msg, player→pl, configuration→config, initialize→start, parameter→input, validate→check, information→info, temporary→temp.
- **Strings** — single quotes by default; interpolation only as `"Hello {$name}"`; no concatenation (`'a' . $x`).
- **Braces** — next line for classes/functions; 1-statement body on one line, 2+ statements as a block.

## CRITICAL — instant failure
- File shape: `<?php`, the LGPL header block, the modification notice line if the file is a modified upstream one, then `declare(strict_types=1);`.
- Damaged or removed LGPL header (§License).
- Any comment outside the 3 allowed kinds (§Code rules).
- Missing type hints (params, returns, properties).
- `==` — always `===`.
- Output functions `echo` `print` `PHP_EOL` `var_dump` `print_r` `var_export` `error_log` `debug_print_backtrace` — console output only via `$this->getLogger()`.
- Non-English string literals anywhere (source, batch files, logs, user messages) — English only.
