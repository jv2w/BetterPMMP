<?php

/*
 * [BetterPMMP-PATCH]
 * BetterPMMP carries a small number of modifications inside vendor/. Composer owns that directory, so
 * `composer install` / `composer update` silently reverts them. This script is wired into composer's
 * post-install-cmd / post-update-cmd hooks: it verifies every patched vendor file still carries its
 * [BetterPMMP-PATCH] marker and tells the operator exactly how to restore it if not.
 *
 * Reverted patches are a warning, not a hard failure - the server still runs correctly without them,
 * it just loses the optimisation.
 */

declare(strict_types=1);

/**
 * Patched vendor file => the .patch that reproduces it, relative to this directory.
 */
const BETTERPMMP_VENDOR_PATCHES = [
	'vendor/pocketmine/raklib/src/server/Server.php' => 'betterpmmp-vendor-patches/raklib-tick-slice.patch',
];

$root = dirname(__DIR__);
$missing = [];

foreach(BETTERPMMP_VENDOR_PATCHES as $target => $patch){
	$path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
	if(!is_file($path)){
		$missing[$target] = $patch;
		continue;
	}
	$contents = file_get_contents($path);
	if($contents === false || !str_contains($contents, '[BetterPMMP-PATCH]')){
		$missing[$target] = $patch;
	}
}

if($missing === []){
	echo "[BetterPMMP] vendor patches intact (" . count(BETTERPMMP_VENDOR_PATCHES) . " file(s) checked)." . PHP_EOL;
	exit(0);
}

fwrite(STDERR, PHP_EOL . "[BetterPMMP] WARNING: composer has reverted " . count($missing) . " patched vendor file(s)." . PHP_EOL);
foreach($missing as $target => $patch){
	fwrite(STDERR, "  - $target" . PHP_EOL);
	fwrite(STDERR, "    restore with: git apply source/build/$patch" . PHP_EOL);
}
fwrite(STDERR, "The server still runs without them; only the associated optimisation is lost." . PHP_EOL . PHP_EOL);
exit(0);
