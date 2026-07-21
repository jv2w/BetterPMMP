#!/usr/bin/env bash
# BetterPMMP By UserX0001 — Linux launcher

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR" || exit 1

printf '\033]0;BetterPMMP By UserX0001\007'

PHP_BINARY=""

# [BetterPMMP-PATCH] The bundled binary lives in source/bin/php, matching start.cmd. This probed
# source/bin/php7/bin/php, a PHP 7 era path that no build of this repository has ever shipped, so the
# branch could never be taken and Linux always fell through to whatever php was on PATH.
if [ -x "$DIR/source/bin/php/bin/php" ]; then
	# always use the local PHP binary if it exists
	export PHPRC=""
	PHP_BINARY="$DIR/source/bin/php/bin/php"
elif command -v php >/dev/null 2>&1; then
	PHP_BINARY="php"
fi

if [ "$PHP_BINARY" = "" ]; then
	echo "Couldn't find a PHP binary in system PATH or \"$DIR/source/bin/php/bin\""
	echo "Please refer to the installation instructions at https://doc.pmmp.io/en/rtfd/installation.html"
	exit 1
fi

# [BetterPMMP-PATCH]
if [ -f "$DIR/source/src/PocketMine.php" ]; then
	POCKETMINE_FILE="$DIR/source/src/PocketMine.php"
else
	echo "source folder not found"
	exit 1
fi

if [ ! -d "$DIR/source/bin" ]; then
	echo "source/bin folder not found"
	exit 1
fi

set +e

while true; do
	"$PHP_BINARY" "$POCKETMINE_FILE" "$@"
	EXIT_CODE=$?
	if [ -f "$DIR/system/restart.flag" ]; then
		rm -f "$DIR/system/restart.flag"
		continue
	fi
	exit $EXIT_CODE
done
