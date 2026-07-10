#!/usr/bin/env bash
# BetterPMMP phpstan verification.
# Runs phpstan (level max, phpVersion 80100) on patch_tool.php and EVERY PHP file that
# patch_tool.php modifies or creates in the PMMP source, resolving symbols via origin_source's
# autoload. Compares the patched tree against the vanilla origin_source baseline per-message and
# fails only on NEW errors (vanilla pre-existing noise such as ext-morton / LightArray /
# pmmp\encoding / mixed getProperty casts is ignored because it appears in both runs).
# NOTE: no `set -e`/`pipefail` on purpose - diff returns 1 when files differ and phpstan returns 1
# when it reports errors; both are expected control-flow, not script failures.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOTW="$(cd "$ROOT" && pwd -W)"
ORIGIN="$ROOT/origin_source"
ORIGINW="$ROOTW/origin_source"
STUBW="$ROOTW/.phpstan/pmmpthread.stub.php"
PHAR="$ROOT/.phpstan/phpstan.phar"
PHARW="$ROOTW/.phpstan/phpstan.phar"
WORK="$ROOT/.phpstan/_work"
PATCHED="$WORK/patched"
PATCHEDW="$ROOTW/.phpstan/_work/patched"

[ -d "$ORIGIN" ] || { echo "origin_source not found at $ORIGIN"; exit 2; }

# Acquire phpstan.phar on first run.
if [ ! -f "$PHAR" ]; then
	echo "Downloading phpstan.phar ..."
	curl -fsSL -o "$PHAR" https://github.com/phpstan/phpstan/releases/download/1.11.11/phpstan.phar
fi

# Fresh patched tree.
rm -rf "$WORK"
mkdir -p "$WORK"
cp -r "$ORIGIN" "$PATCHED"
php "$ROOT/patch_tool.php" "$PATCHED" | grep -E "^(Applied|Skipped|Failed)" || true

# Discover changed (.php) and created (.php) files relative to src tree.
MODIFIED=$(diff -rq "$ORIGIN" "$PATCHED" 2>/dev/null | grep -E "^Files .*\.php" | sed -E "s#^Files .*/origin_source/([^ ]+) and.*#\1#")
CREATED=$(diff -rq "$ORIGIN" "$PATCHED" 2>/dev/null | grep -E "^Only in .*: .*\.php" | sed -E "s#^Only in (.*/patched/?)([^:]*): (.+)#\2/\3#; s#^/##")

mkneon() {
	local autoload="$1"; shift
	echo "parameters:"
	echo "    level: max"
	echo "    phpVersion: 80100"
	echo "    treatPhpDocTypesAsCertain: false"
	echo "    parallel:"
	echo "        maximumNumberOfProcesses: 1"
	echo "    bootstrapFiles:"
	echo "        - $STUBW"
	echo "        - $autoload"
	echo "    paths:"
	for p in "$@"; do echo "        - $p"; done
}

# Patched config: patch_tool.php + all modified + created files.
{
	PATHS=("$ROOTW/patch_tool.php")
	for f in $MODIFIED $CREATED; do PATHS+=("$PATCHEDW/$f"); done
	mkneon "$PATCHEDW/vendor/autoload.php" "${PATHS[@]}"
} > "$WORK/patched.neon"

# Vanilla config: only the modified files (created files have no baseline).
{
	VPATHS=()
	for f in $MODIFIED; do VPATHS+=("$ORIGINW/$f"); done
	mkneon "$ORIGINW/vendor/autoload.php" "${VPATHS[@]}"
} > "$WORK/vanilla.neon"

ROOTSLASH=$(printf '%s' "$ROOTW" | tr '\\' '/')
run() { php -d memory_limit=3G "$PHARW" analyse -c "$1" --no-progress --error-format=raw 2>/dev/null \
	| tr '\\' '/' | sed -E "s#^${ROOTSLASH}/.phpstan/_work/patched/##; s#^${ROOTSLASH}/origin_source/##; s#^${ROOTSLASH}/##" \
	| sed -E "s#:[0-9]+:#|#" \
	| grep -vE "parallel worker|Internal error|Run PHPStan|github|^$" | sort; }

run "$WORK/patched.neon" > "$WORK/patched.txt"
run "$WORK/vanilla.neon" > "$WORK/vanilla.txt"

BASELINE="$ROOT/.phpstan/baseline.txt"
NEW_RAW=$(comm -23 "$WORK/patched.txt" "$WORK/vanilla.txt")

# --update-baseline: snapshot current pre-existing (non-vanilla) findings as the accepted baseline.
if [ "${1:-}" = "--update-baseline" ]; then
	printf '%s\n' "$NEW_RAW" | grep -vE "^$" | sort -u > "$BASELINE"
	echo "Baseline updated: $(grep -c . "$BASELINE") finding(s) recorded in .phpstan/baseline.txt"
	exit 0
fi

touch "$BASELINE"
NEW=$(printf '%s\n' "$NEW_RAW" | grep -vE "^$" | sort -u | comm -23 - <(sort -u "$BASELINE"))
echo "=== Regressions beyond baseline (must be empty) ==="
if [ -z "$NEW" ]; then
	echo "(none) - no new phpstan errors vs vanilla + baseline"
	echo "Injected patch code is phpstan-clean (it contributes 0 to the baseline)."
	exit 0
fi
echo "$NEW"
echo "=== FAIL: $(printf '%s\n' "$NEW" | grep -c .) regression(s) beyond baseline ==="
exit 1
