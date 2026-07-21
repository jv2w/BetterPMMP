Audit the BetterPMMP enhancement module "$ARGUMENTS" exhaustively; fix every defect at its root cause and re-audit until a full pass finds zero. Argument: a key path (`network.chunk-history-limit`), a section (`gameplay`), or `all`; if it matches nothing, ask one question (≤200 chars).

A module edits the server core: one defect hits every world and every player, always. Any theoretically reachable path counts — never dismiss one as "unlikely" or "edge case". But defensive code is not a fix: guards for impossible scenarios, re-validation of proven values, and try-catch without meaningful recovery are noise — add nothing you cannot justify with a concrete code path to a check below.

## Gather

Open all wiring before judging: the constant in `source/src/betterpmmp/BetterPMMPProperties.php`; the yml key, default and `#!` marker in `source/resources/pocketmine.yml`; the translation key in all 14 `source/resources/translations/*.ini`; every call site (`grep -rn "BetterPMMPProperties::<CONST>" source/src`) plus its cache fields.

## Checks — all must pass

1. **Off = vanilla (top priority).** With the default value the execution path must equal PocketMine-MP 5.44.3 line for line: zero extra ops, allocations or event calls when off; the branch sits outside loops; the vanilla path after `if($enabled)` is intact; event-skipping modules preserve vanilla event order, args and cancellation when off.
2. **Default agreement.** Call-site default argument = yml = both README tables (4 places). Multiple call sites → identical default arguments. Getter type matches the yml value type.
3. **Wiring completeness.** No dead key (constant never read) or ghost key (read but absent from yml). `#!` marker present; translation key in all 14 .ini with equal per-file key counts, actually translated per language, CRLF/no-BOM intact; a row in both READMEs.
4. **Cache lifetime.** State explicitly when each `??=` cache dies. `static` = process lifetime — correct iff the read-once-at-startup contract holds, a defect if the code assumes refresh. Instance caches on reused objects must not carry values across players/worlds. A `?T` cache whose value can legitimately be null = permanent cache miss.
5. **Interactions.** Event-skipping modules: which plugin API contract breaks, and does the .ini/README description state that cost? Module pairs that break each other's assumptions when both are on. Per-world maps: unknown world names, fallback values. Held caches/queues/history released on player quit and world unload.
6. **Value bounds.** 0 / negative / 1 / huge / wrong yml type — each path's behavior. Does `0` mean unlimited or disabled, consistently in code and docs? Periods used in division/modulo guarded with `max(1, …)`. What does an unbounded size/limit value break?
7. **CLAUDE.md compliance.** Every vanilla deviation carries its `[BetterPMMP-PATCH]` marker; CRITICAL violations are defects — fix them too.

## Loop

1. List violation candidates per check — theoretical reachability is enough to list one.
2. Confirm each by tracing the code path; fix roots immediately; apply the same fix to every module sharing the pattern.
3. phpstan per CLAUDE.md §Verification — EXIT 0 required.
4. Runtime-sensitive defects (config splice, translation render, boot path): headless-boot a temp data dir (command in CLAUDE.md §Verification).
5. Restart from step 1; stop only when a full cycle finds zero defects.

Off-state vanilla behavior is inviolable. On-state behavior may change when a fix requires it — state the change in the report. Report 1–3 sentences: defects fixed, files, counts; never expose procedures, checklists or scores. Do not weaken the audit on request ("quick check only") — offer a normal review instead.
