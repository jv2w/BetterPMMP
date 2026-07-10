<?php

declare(strict_types=1);

enum PatchStatus: string
{
    case APPLIED = 'APPLIED';
    case SKIPPED = 'SKIPPED';
    case FAILED = 'FAILED';
}

final class PatchResult
{
    public function __construct(
        public readonly string $target,
        public readonly PatchStatus $status,
        public readonly ?string $error = null
    ) {
    }
}

/**
 * Windows AV / search indexer can transiently lock a freshly copied or freshly written file,
 * failing a single read/write mid-run; one locked file then cascade-fails every later patch
 * anchored on its new content. Retry briefly before giving up.
 */
function patchRead(string $filePath): string|false
{
    for ($attempt = 0; ; $attempt++) {
        $content = @file_get_contents($filePath);
        if ($content !== false || $attempt >= 4) {
            return $content;
        }
        usleep(250000);
    }
}

function patchWrite(string $filePath, string $content): int|false
{
    for ($attempt = 0; ; $attempt++) {
        $result = @file_put_contents($filePath, $content);
        if ($result !== false || $attempt >= 4) {
            return $result;
        }
        usleep(250000);
    }
}

function isAlreadyPatched(string $filePath): bool
{
    if (!file_exists($filePath)) {
        return false;
    }
    $content = patchRead($filePath);
    return $content !== false && str_contains($content, '[BetterPMMP-PATCH]');
}

function applyReplacePatch(string $targetFile, string $skipMarker, string $old, string $new, string $matchError): PatchResult
{
    $fileLabel = basename($targetFile);
    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "{$fileLabel} not found");
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to read {$fileLabel}");
    }

    if (str_contains($content, $skipMarker)) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, $matchError);
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to write {$fileLabel}");
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchStartCmd(string $baseDir): PatchResult
{
    $targetFile = $baseDir . '/start.cmd';

    if (isAlreadyPatched($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (file_exists($targetFile)) {
        $content = patchRead($targetFile);
        if ($content === false) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read start.cmd');
        }

        if (str_contains($content, 'source\src\PocketMine.php')) {
            return new PatchResult($targetFile, PatchStatus::SKIPPED);
        }

        if (str_contains($content, 'PocketMine-MP.phar')) {
            $newBlock = <<<'BAT'
REM [BetterPMMP-PATCH]
if exist source\src\PocketMine.php (
	set POCKETMINE_FILE=source\src\PocketMine.php
) else (
	echo source folder not found
	pause
	exit 1
)
BAT;

            $newContent = preg_replace(
                '/if exist PocketMine-MP\.phar\s*\([^)]*set POCKETMINE_FILE=PocketMine-MP\.phar[^)]*\)\s*else\s*\([^)]*\)/s',
                $newBlock,
                $content
            );
            if ($newContent === null || $newContent === $content) {
                return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to replace phar block in start.cmd');
            }

            if (patchWrite($targetFile, $newContent) === false) {
                return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched start.cmd');
            }

            return new PatchResult($targetFile, PatchStatus::APPLIED);
        }
    }

    $startCmdContent = <<<'BAT'
@echo off
TITLE PocketMine-MP server software for Minecraft: Bedrock Edition
cd /d %~dp0

set PHP_BINARY=

where /q php.exe
if %ERRORLEVEL%==0 (
	set PHP_BINARY=php
)

if exist bin\php\php.exe (
	rem always use the local PHP binary if it exists
	set PHPRC=""
	set PHP_BINARY=bin\php\php.exe
)

if "%PHP_BINARY%"=="" (
	echo Couldn't find a PHP binary in system PATH or "%~dp0bin\php"
	echo Please refer to the installation instructions at https://doc.pmmp.io/en/rtfd/installation.html
	pause
	exit 1
)

REM [BetterPMMP-PATCH]
if exist source\src\PocketMine.php (
	set POCKETMINE_FILE=source\src\PocketMine.php
) else (
	echo source folder not found
	pause
	exit 1
)

if exist bin\mintty.exe (
	start "" bin\mintty.exe -o Columns=88 -o Rows=32 -o AllowBlinking=0 -o FontQuality=3 -o Font="Consolas" -o FontHeight=10 -o CursorType=0 -o CursorBlinks=1 -h error -t "PocketMine-MP" -i bin/pocketmine.ico -w max %PHP_BINARY% %POCKETMINE_FILE% --enable-ansi %*
) else (
	REM pause on exitcode != 0 so the user can see what went wrong
	%PHP_BINARY% %POCKETMINE_FILE% %* || pause
)
BAT;

    if (patchWrite($targetFile, $startCmdContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to create start.cmd');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchComposerSyncCheck(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/PocketMine.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PocketMine.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read PocketMine.php');
    }

    if (str_contains($content, 'Composer sync check bypassed')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $searchBlock = <<<'PHP'
	$composerGitHash = InstalledVersions::getReference('pocketmine/pocketmine-mp');
		if($composerGitHash !== null){
			//we can't verify dependency versions if we were installed without using git
			$currentGitHash = explode("-", VersionInfo::GIT_HASH(), 2)[0];
			if($currentGitHash !== $composerGitHash){
				critical_error("Composer dependencies and/or autoloader are out of sync.");
				critical_error("- Current revision is $currentGitHash");
				critical_error("- Composer dependencies were last synchronized for revision $composerGitHash");
				critical_error("Out-of-sync Composer dependencies may result in crashes and classes not being found.");
				critical_error("Please synchronize Composer dependencies before running the server.");
				exit(1);
			}
		}
PHP;

    if (!str_contains($content, 'Out-of-sync Composer dependencies')) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Composer sync check block not found in PocketMine.php');
    }

    $newContent = str_replace($searchBlock, '', $content);

    if ($newContent === $content) {
        $newContent = preg_replace(
            '/\$composerGitHash\s*=\s*InstalledVersions::getReference.*?(?:exit\(1\);\s*\}\s*\})/s',
            "/** [BetterPMMP-PATCH] Composer sync check bypassed for source folder execution */",
            $content
        );
        if ($newContent === null || $newContent === $content) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to patch Composer sync check');
        }
    } else {
        $newContent = str_replace(
            "require_once(\$bootstrap);\n",
            "require_once(\$bootstrap);\n\n\t\t/** [BetterPMMP-PATCH] Composer sync check bypassed for source folder execution */\n",
            $newContent
        );
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched PocketMine.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchStartCmdBinPath(string $baseDir): PatchResult
{
    $targetFile = $baseDir . '/start.cmd';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'start.cmd not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read start.cmd');
    }

    if (str_contains($content, 'source\\bin\\php\\php.exe')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = str_replace(
        'TITLE PocketMine-MP server software for Minecraft: Bedrock Edition',
        'TITLE §aBetterPMMP By UserX0001',
        $content
    );

    $content = str_replace(
        'TITLE PocketMine-MP server software',
        'TITLE §aBetterPMMP By UserX0001',
        $content
    );

    $content = preg_replace(
        '/if exist bin\\\\php\\\\php\.exe \(\r?\n\trem always use the local PHP binary if it exists\r?\n\tset PHPRC=""\r?\n\tset PHP_BINARY=bin\\\\php\\\\php\.exe\r?\n\)/',
        "if exist source\\bin\\php\\php.exe (\n\trem always use the local PHP binary if it exists\n\tset PHPRC=\"\"\n\tset PHP_BINARY=source\\bin\\php\\php.exe\n)",
        $content
    ) ?? $content;

    $content = str_replace(
        "Couldn't find a PHP binary in system PATH or \"%~dp0bin\\php\"",
        "Couldn't find a PHP binary in system PATH or \"%~dp0source\\bin\\php\"",
        $content
    );

    if (str_contains($content, "if exist bin\\mintty.exe (")) {
        $nl = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $sourceBinErrorBlock = "if not exist source\\bin (" . $nl . "\techo source\\bin folder not found" . $nl . "\tpause" . $nl . "\texit 1" . $nl . ")" . $nl . $nl;
        $content = str_replace(
            "if exist bin\\mintty.exe (",
            $sourceBinErrorBlock . "if exist source\\bin\\mintty.exe (",
            $content
        );

        $content = str_replace(
            'start "" bin\\mintty.exe',
            'start "" source\\bin\\mintty.exe',
            $content
        );

        $content = str_replace(
            '-t "PocketMine-MP" -i bin/pocketmine.ico',
            '-t "BetterPMMP" -i source\\bin/pocketmine.ico',
            $content
        );
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched start.cmd');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchDataPath(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/PocketMine.php';

    if (!file_exists($targetFile))
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PocketMine.php not found');

    $content = patchRead($targetFile);
    if ($content === false)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read PocketMine.php');

    if (str_contains($content, '[BetterPMMP-PATCH] Create system subdirectory'))
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    $oldMkdirBlock = 'if(!@mkdir($dataPath, 0777, true) && !is_dir($dataPath)){';
    $newMkdirBlock = '/** [BetterPMMP-PATCH] Create system subdirectory */' . "\n\t\t"
        . '@mkdir($dataPath . DIRECTORY_SEPARATOR . "system", 0777, true);' . "\n\t\t"
        . 'if(!@mkdir($dataPath, 0777, true) && !is_dir($dataPath)){';

    $newContent = str_replace($oldMkdirBlock, $newMkdirBlock, $content, $mkdirCount);
    if ($mkdirCount !== 1)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'mkdir anchor not found in PocketMine.php');

    $newContent = str_replace(
        'Path::join($dataPath, \'server.lock\')',
        'Path::join($dataPath, "system", \'server.lock\')',
        $newContent,
        $lockCount
    );
    if ($lockCount !== 1)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'server.lock path anchor not found in PocketMine.php');

    $newContent = str_replace(
        'Path::join($dataPath, "log_archive")',
        'Path::join($dataPath, "system", "log_archive")',
        $newContent,
        $logCount
    );
    if ($logCount !== 1)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'log_archive path anchor not found in PocketMine.php');

    if (patchWrite($targetFile, $newContent) === false)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched PocketMine.php');

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchServerPaths(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/Server.php';

    if (!file_exists($targetFile))
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Server.php not found');

    $content = patchRead($targetFile);
    if ($content === false)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Server.php');

    if (str_contains($content, '"system", "players"'))
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    $pathMap = [
        'Path::join($dataPath, "players")' => 'Path::join($dataPath, "system", "players")',
        'Path::join($this->dataPath, "ops.txt")' => 'Path::join($this->dataPath, "system", "ops.txt")',
        'Path::join($this->dataPath, "white-list.txt")' => 'Path::join($this->dataPath, "system", "white-list.txt")',
        'Path::join($this->dataPath, "banned.txt")' => 'Path::join($this->dataPath, "system", "banned.txt")',
        'Path::join($this->dataPath, "banned-players.txt")' => 'Path::join($this->dataPath, "system", "banned-players.txt")',
        'Path::join($this->dataPath, "banned-ips.txt")' => 'Path::join($this->dataPath, "system", "banned-ips.txt")',
        'Path::join($this->dataPath, "players")' => 'Path::join($this->dataPath, "system", "players")',
        'Path::join($this->dataPath, "plugin_list.yml")' => 'Path::join($this->dataPath, "system", "plugin_list.yml")',
    ];

    $newContent = $content;
    foreach ($pathMap as $old => $new) {
        $newContent = str_replace($old, $new, $newContent);
    }

    if ($newContent === $content)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to patch server paths in Server.php');

    if (patchWrite($targetFile, $newContent) === false)
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Server.php');

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchStartWarning(string $sourceDir): PatchResult
{
    return applyReplacePatch(
        $sourceDir . '/src/PocketMine.php',
        '§aBetterPMMP By UserX0001',
        '$logger->warning("Non-packaged installation detected. This will degrade autoloading speed and make startup times longer.");',
        '/** [BetterPMMP-PATCH] Start warning replaced */' . "\n\t\t" . '$logger->info("§aBetterPMMP By UserX0001");',
        'Failed to replace Non-packaged installation warning in PocketMine.php'
    );
}

function patchGarbageCollectorLog(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/GarbageCollectorManager.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'GarbageCollectorManager.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read GarbageCollectorManager.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] GC log output removed')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $newContent = preg_replace(
        '/\s*\$this->logger->info\(sprintf\(\s*"Run #%d.*?\)\);/s',
        "\n\t\t\t/** [BetterPMMP-PATCH] GC log output removed */",
        $content
    );

    if ($newContent === null || $newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to remove GC log output');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched GarbageCollectorManager.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchServerStartLogs(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/Server.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Server.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Server.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] Default game mode log removed')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $hasDefaultGameMode = str_contains($content, 'pocketmine_server_defaultGameMode');
    $hasLinkBlock = str_contains($content, '$highlight = TextFormat::AQUA;');

    if (!$hasDefaultGameMode && !$hasLinkBlock) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $newContent = $content;

    if ($hasDefaultGameMode) {
        $newContent = preg_replace(
            '/\s*\$this->logger->info\(\$this->language->translate\(\s*KnownTranslationFactory::pocketmine_server_defaultGameMode\(.*?\)\s*\)\);/s',
            "\n\t\t/** [BetterPMMP-PATCH] Default game mode log removed */",
            $newContent
        ) ?? $newContent;
    }

    if ($hasLinkBlock) {
        $newContent = preg_replace(
            '/\s*\$highlight\s*=\s*TextFormat::AQUA;.*?\$this->logger->info\(\$splash\);/s',
            "\n\t\t/** [BetterPMMP-PATCH] Start link logs removed */",
            $newContent
        ) ?? $newContent;
    }

    if ($newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to remove server start logs');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Server.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchInfoPrefix(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/utils/MainLogger.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'MainLogger.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read MainLogger.php');
    }

    if (str_contains($content, '$prefix === "INFO"')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldSprintf = '$message = sprintf($this->format, $time->format("H:i:s.v"), $color, $threadName, $prefix, TextFormat::addBase($color, TextFormat::clean($message, false)));';

    if (!str_contains($content, $oldSprintf)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'sprintf format line not found in MainLogger.php send()');
    }
    $newBlock = '/** [BetterPMMP-PATCH] INFO prefix removed for cleaner output */' . "\n\t\t"
        . 'if($prefix === "INFO"){' . "\n\t\t\t"
        . '$message = sprintf(' . "\n\t\t\t\t"
        . 'TextFormat::AQUA . "[%s] " . TextFormat::RESET . "%s%s" . TextFormat::RESET,' . "\n\t\t\t\t"
        . '$time->format("H:i:s.v"),' . "\n\t\t\t\t"
        . '$color,' . "\n\t\t\t\t"
        . 'TextFormat::addBase($color, TextFormat::clean($message, false))' . "\n\t\t\t"
        . ');' . "\n\t\t"
        . '}else{' . "\n\t\t\t"
        . '$message = sprintf($this->format, $time->format("H:i:s.v"), $color, $threadName, $prefix, TextFormat::addBase($color, TextFormat::clean($message, false)));' . "\n\t\t"
        . '}';

    $newContent = str_replace($oldSprintf, $newBlock, $content);

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched MainLogger.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchBlockInputLag(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/handler/InGamePacketHandler.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'InGamePacketHandler.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read InGamePacketHandler.php');
    }

    $changeCount = 0;

    if (!str_contains($content, 'use pocketmine\network\mcpe\convert\TypeConverter;')) {
        $content = str_replace(
            'use pocketmine\network\mcpe\convert\ItemTranslator;',
            "use pocketmine\\network\\mcpe\\convert\\ItemTranslator;\nuse pocketmine\\network\\mcpe\\convert\\TypeConverter;",
            $content
        );
    }
    if (!str_contains($content, 'use pocketmine\network\mcpe\protocol\UpdateBlockPacket;')) {
        $content = str_replace(
            'use pocketmine\network\PacketHandlingException;',
            "use pocketmine\\network\\mcpe\\protocol\\UpdateBlockPacket;\nuse pocketmine\\network\\PacketHandlingException;",
            $content
        );
    }
    if (!str_contains($content, 'use pocketmine\world\World;')) {
        $content = str_replace(
            'use function array_push;',
            "use pocketmine\\world\\World;\nuse function array_push;",
            $content
        );
    }

    $syncSig = 'private function syncBlocksNearby(Vector3 $blockPos, ?int $face) : void{';
    $syncSigPos = strpos($content, $syncSig);
    if ($syncSigPos !== false) {
        $startPos = $syncSigPos;
        $beforeSig = substr($content, 0, $syncSigPos);
        if (preg_match('/(\t\/\*\*(?:[^*]|\*(?!\/))*\*\/\s+)$/s', $beforeSig, $docMatch)) {
            $startPos -= strlen($docMatch[1]);
        }

        $nextMethodPos = strpos($content, "\n\tprivate function ", $syncSigPos + strlen($syncSig));
        if ($nextMethodPos === false) {
            $nextMethodPos = strpos($content, "\n\tpublic function ", $syncSigPos + strlen($syncSig));
        }
        if ($nextMethodPos !== false) {
            $newSyncAndCapture = <<<'NEWSYNC'
	/** [BetterPMMP-PATCH] Block lag fix - snapshot-based sync */
	/**
	 * @phpstan-param array<int, int> $oldBlockSnapshot
	 */
	private function syncBlocksNearby(Vector3 $blockPos, ?int $face, array $oldBlockSnapshot = []): void
	{
		if ($blockPos->distanceSquared($this->player->getLocation()) >= 10000) {
			return;
		}
		$blocks = $blockPos->sidesArray();
		$blocks[] = $blockPos;
		if ($face !== null) {
			$sidePos = $blockPos->getSide($face);
			array_push($blocks, ...$sidePos->sidesArray());
		}
		$world = $this->player->getWorld();
		$blockTranslator = TypeConverter::getInstance()->getBlockTranslator();
		foreach ($world->createBlockUpdatePackets($blocks) as $packet) {
			if (count($oldBlockSnapshot) > 0 && $packet instanceof UpdateBlockPacket) {
				$hash = World::blockHash(
					$packet->blockPosition->getX(),
					$packet->blockPosition->getY(),
					$packet->blockPosition->getZ()
				);
				if (isset($oldBlockSnapshot[$hash]) && $blockTranslator->internalIdToNetworkId($oldBlockSnapshot[$hash]) === $packet->blockRuntimeId) {
					continue;
				}
			}
			$this->session->sendDataPacket($packet);
		}
	}

	/**
	 * @phpstan-return array<int, int>
	 */
	private function captureBlockSnapshot(Vector3 $blockPos, int $face): array
	{
		$world = $this->player->getWorld();
		$sidePos = $blockPos->getSide($face);
		$snapshot = [];
		foreach ([$blockPos, ...$blockPos->sidesArray(), $sidePos, ...$sidePos->sidesArray()] as $pos) {
			$x = (int) $pos->x;
			$y = (int) $pos->y;
			$z = (int) $pos->z;
			$snapshot[World::blockHash($x, $y, $z)] = $world->getBlockAt($x, $y, $z)->getStateId();
		}
		return $snapshot;
	}
NEWSYNC;

            $content = substr($content, 0, $startPos) . $newSyncAndCapture . substr($content, $nextMethodPos);
            $changeCount++;
        }
    }

    $interactAnchor = '$this->player->interactBlock($vBlockPos, $data->getFace(), $clickPos);';
    $predictAnchor = '$data->getClientInteractPrediction() === PredictedResult::SUCCESS';
    $interactPos = strpos($content, $interactAnchor);
    if ($interactPos !== false && str_contains($content, $predictAnchor)) {
        $beforeInteract = strrpos(substr($content, 0, $interactPos), '$vBlockPos = new Vector3(');
        if ($beforeInteract !== false) {
            $afterInteract = strpos($content, 'return true;', $interactPos);
            if ($afterInteract !== false) {
                $returnEnd = $afterInteract + strlen('return true;');
                $oldBlock = substr($content, $beforeInteract, $returnEnd - $beforeInteract);

                $newBlock =
                    '$vBlockPos = new Vector3($blockPos->getX(), $blockPos->getY(), $blockPos->getZ());' . "\n\n" .
                    "\t\t\t\t/** [BetterPMMP-PATCH] Block lag fix - capture snapshot before interaction */\n" .
                    "\t\t\t\t\$oldBlockSnapshot = \$this->captureBlockSnapshot(\$vBlockPos, \$data->getFace());\n" .
                    "\t\t\t\t\$interactResult = \$this->player->interactBlock(\$vBlockPos, \$data->getFace(), \$clickPos);\n\n" .
                    "\t\t\t\t\$syncAdjacentFace = null;\n" .
                    "\t\t\t\tif (\$data->getItemInHand()->getItemStack()->getBlockRuntimeId() === ItemTranslator::NO_BLOCK_RUNTIME_ID) {\n" .
                    "\t\t\t\t\t\$syncAdjacentFace = \$data->getFace();\n" .
                    "\t\t\t\t}\n\n" .
                    "\t\t\t\t\$this->syncBlocksNearby(\$vBlockPos, \$syncAdjacentFace, \$interactResult ? \$oldBlockSnapshot : []);\n" .
                    "\t\t\t\treturn true;";

                $content = str_replace($oldBlock, $newBlock, $content);
                $changeCount++;
            }
        }
    }

    if ($changeCount !== 2) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Block lag fix requires both method and interaction anchors; matched ' . $changeCount . '/2 in InGamePacketHandler.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched InGamePacketHandler.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPluginManagerLazyDataFolder(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/plugin/PluginManager.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PluginManager.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read PluginManager.php');
    }

    if (str_contains($content, '[BETTERPMMP-PATCH-LAZY-DATAFOLDER]')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $marker = '/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Data folder creation deferred to first use */';
    $old = "\t\tif(!file_exists(\$dataFolder)){\n\t\t\tmkdir(\$dataFolder, 0777, true);\n\t\t}";
    $newContent = str_replace($old, "\t\t" . $marker, $content);
    if ($newContent === $content) {
        $newContent = preg_replace(
            '/(\t+)if\s*\(\s*!\s*(?:file_exists|is_dir)\s*\(\s*\$dataFolder\s*\)\s*\)\s*\{\s*\r?\n\s*@?mkdir\s*\(\s*\$dataFolder\s*,\s*0777\s*,\s*true\s*\)\s*;\s*\r?\n\s*\}/m',
            '$1' . $marker,
            $content
        );
        if ($newContent === null || $newContent === $content) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match data folder mkdir block in PluginManager.php');
        }
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched PluginManager.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPluginBaseLazyDataFolder(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/plugin/PluginBase.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PluginBase.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read PluginBase.php');
    }

    if (str_contains($content, '[BETTERPMMP-PATCH-LAZY-DATAFOLDER]')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $ensureMethod = "\t/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Lazy data folder creation */\n"
        . "\tprivate function ensureDataFolderExists(): void\n"
        . "\t{\n"
        . "\t\tif (!is_dir(\$this->dataFolder)) {\n"
        . "\t\t\t@mkdir(\$this->dataFolder, 0777, true);\n"
        . "\t\t}\n"
        . "\t}\n";

    $anchor = 'public function saveResource(';
    if (str_contains($content, $anchor)) {
        $content = str_replace($anchor, $ensureMethod . "\n\t" . $anchor, $content);
    } elseif (preg_match('/class\s+PluginBase\s+[^{]*\{/', $content, $classMatch, PREG_OFFSET_CAPTURE) === 1) {
        $insertPos = $classMatch[0][1] + strlen($classMatch[0][0]);
        $content = substr($content, 0, $insertPos) . "\n" . $ensureMethod . substr($content, $insertPos);
    } else {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to insert ensureDataFolderExists method');
    }
    $injectEnsureCall = static function (string $content, string $signaturePattern): string {
        if (!preg_match($signaturePattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return $content;
        }
        $bracePos = strpos($content, '{', $m[0][1]);
        if ($bracePos === false) {
            return $content;
        }
        $afterBrace = $bracePos + 1;
        if (preg_match('/\$this->ensureDataFolderExists\(\);/', substr($content, $afterBrace, 200))) {
            return $content;
        }
        return substr($content, 0, $afterBrace)
            . "\n\t\t\$this->ensureDataFolderExists();"
            . substr($content, $afterBrace);
    };

    foreach ([
        '/public\s+function\s+saveResource\s*\([^)]*\)\s*:\s*bool\s*\{/',
        '/public\s+function\s+saveConfig\s*\(\s*\)\s*:\s*void\s*\{/',
        '/public\s+function\s+getDataFolder\s*\(\s*\)\s*:\s*string\s*\{/',
        '/public\s+function\s+getConfig\s*\(\s*\)\s*:\s*Config\s*\{/',
    ] as $signaturePattern) {
        $content = $injectEnsureCall($content, $signaturePattern);
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched PluginBase.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function createRestartCommand(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/command/defaults';
    $targetFile = $targetDir . '/RestartCommand.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Command defaults directory not found');
    }

    $commandContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;

/** [BetterPMMP-PATCH] */
class RestartCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"restart",
			"Restart the server"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_STOP);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		$restartFlag = dirname(__FILE__, 5) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'restart.flag';
		file_put_contents($restartFlag, '1');

		Command::broadcastCommandMessage($sender, "§eServer is restarting...");

		$sender->getServer()->shutdown();
		return true;
	}
}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $commandContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $commandContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write RestartCommand.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchSimpleCommandMapRestartCommand(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/command/SimpleCommandMap.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'SimpleCommandMap.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read SimpleCommandMap.php');
    }

    if (str_contains($content, 'new RestartCommand()')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (!str_contains($content, 'use pocketmine\command\defaults\RestartCommand;')) {
        $useAnchor = 'use pocketmine\command\defaults\StopCommand;';
        $content = str_replace(
            $useAnchor,
            $useAnchor . "\nuse pocketmine\\command\\defaults\\RestartCommand; /** [BetterPMMP-PATCH] */",
            $content,
            $useCount
        );
        if ($useCount !== 1) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'StopCommand use anchor not found in SimpleCommandMap.php');
        }
    }

    $registerAnchor = 'new StopCommand(),';
    $content = str_replace(
        $registerAnchor,
        $registerAnchor . "\n\t\t\tnew RestartCommand(),",
        $content,
        $registerCount
    );
    if ($registerCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'StopCommand registration anchor not found in SimpleCommandMap.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched SimpleCommandMap.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchStartCmdRestartLoop(string $baseDir): PatchResult
{
    $targetFile = $baseDir . '/start.cmd';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'start.cmd not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read start.cmd');
    }

    $hasMintty = preg_match('/(\r?\n)if exist [^\r\n]*mintty\.exe \(/', $content, $minttyMatch, PREG_OFFSET_CAPTURE) === 1;
    if (str_contains($content, ':betterpmmp_start') && !$hasMintty) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $nl = str_contains($content, "\r\n") ? "\r\n" : "\n";
    $restartLoop =
        ":betterpmmp_start" . $nl
        . "%PHP_BINARY% %POCKETMINE_FILE% %*" . $nl
        . "if exist system\\restart.flag (" . $nl
        . "\tdel system\\restart.flag" . $nl
        . "\tgoto :betterpmmp_start" . $nl
        . ")" . $nl
        . "if errorlevel 1 pause";

    if ($hasMintty) {
        $newContent = rtrim(substr($content, 0, $minttyMatch[0][1])) . $nl . $nl . $restartLoop . $nl;
    } else {
        $newContent = preg_replace(
            '/\r?\nif exist [^\r\n]*\.exe \([^\r\n]*\r?\n[^\r\n]*\r?\n\) else \(\r?\n[^\r\n]*\r?\n[^\r\n]*\r?\n\)[\r\n]*$/s',
            $nl . $nl . $restartLoop . $nl,
            $content
        );
        if ($newContent === null || $newContent === $content) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to inject restart loop into start.cmd');
        }
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched start.cmd');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchIronDoorNoInteract(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/block/Door.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Door.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Door.php');
    }

    if (!str_contains($content, 'use pocketmine\block\BlockTypeIds;')) {
        $content = str_replace(
            'use pocketmine\block\utils\HorizontalFacing;',
            "use pocketmine\\block\\BlockTypeIds;\nuse pocketmine\\block\\utils\\HorizontalFacing;",
            $content
        );
    }

    $oldOnInteract = 'public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{' . "\n"
        . "\t\t" . '$this->open = !$this->open;';

    if (!str_contains($content, $oldOnInteract)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match onInteract pattern in Door.php');
    }

    $newOnInteract = '/** [BetterPMMP-PATCH] Iron door: block onInteract completely */' . "\n"
        . "\t" . 'public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{' . "\n"
        . "\t\t" . 'if($this->getTypeId() === BlockTypeIds::IRON_DOOR){' . "\n"
        . "\t\t\t" . 'return true;' . "\n"
        . "\t\t" . '}' . "\n"
        . "\t\t" . '$this->open = !$this->open;';

    $content = str_replace($oldOnInteract, $newOnInteract, $content);

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Door.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlBetterPmmp(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'pocketmine.yml not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read pocketmine.yml');
    }

    if (str_contains($content, 'better-pmmp:')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $betterPmmpBlock = <<<'YAML'

# [BetterPMMP-PATCH] Extreme optimization settings
better-pmmp:
  # Fixed light: skip async LightPopulationTask entirely.
  # Fills server-side light arrays with a fixed value instead of running BFS flood-fill on worker threads.
  # Performance gain: eliminates AsyncTask submission, igbinary serialization, and BFS light calculation.
  # NOTE: This does NOT affect client-side rendering. Bedrock clients calculate their own lighting.
  # Server-side light values are used only for mob spawning conditions and similar server logic.
  fixed-light:
    enabled: false
    level: 15
  # Per-world view distance override. Key MUST be the world FOLDER NAME (the directory name under worlds/),
  # not the world display name. Overrides server.properties view-distance for that world.
  # Worlds without an entry use server.properties view-distance.
  # When a player teleports between worlds, the override is re-evaluated from the player's
  # originally requested view-distance, so a previous world's override does not leak.
  # Example:
  #   lobby: 4
  #   game: 12
  per-world-view-distance: {}
  # Advanced chunk optimization settings.
  chunk-optimization:
    # Max chunks to recheck tick eligibility per tick. Prevents tick spikes on mass teleport. 0 = unlimited.
    batch-recheck-limit: 64
  # Per-world chunk ticking override. Key MUST be the world FOLDER NAME (same as above).
  # tick-radius is clamped to this world's per-world-view-distance (or server.properties if not set).
  # Worlds without an entry inherit the global chunk-ticking settings.
  # Example:
  #   lobby:
  #     tick-radius: 0
  #     blocks-per-subchunk-per-tick: 0
  #   game:
  #     tick-radius: 6
  per-world-chunk-ticking: {}
  # Max neighbour block updates processed per tick. Prevents chain-reaction TPS spikes. 0 = unlimited.
  neighbour-update-limit: 512
  # Block state and collision cache size per world. Higher values reduce getBlockAt() cost.
  block-cache-size: 8192
YAML;

    $newContent = $content . $betterPmmpBlock . "\n";

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched pocketmine.yml');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchWorldFixedLight(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\tprivate function orderLightPopulation(int \$chunkX, int \$chunkZ) : void{\n"
        . "\t\t\$chunkHash = World::chunkHash(\$chunkX, \$chunkZ);\n"
        . "\t\t\$lightPopulatedState = \$this->chunks[\$chunkHash]->isLightPopulated();\n"
        . "\t\tif(\$lightPopulatedState === false){\n"
        . "\t\t\t\$this->chunks[\$chunkHash]->setLightPopulated(null);\n"
        . "\t\t\t\$this->markTickingChunkForRecheck(\$chunkX, \$chunkZ);\n"
        . "\n"
        . "\t\t\t\$this->workerPool->submitTask(new LightPopulationTask(";

    $new = "\t/** [BetterPMMP-PATCH] Fixed light values bypass - skip LightPopulationTask when enabled */\n"
        . "\tprivate function orderLightPopulation(int \$chunkX, int \$chunkZ) : void{\n"
        . "\t\t\$chunkHash = World::chunkHash(\$chunkX, \$chunkZ);\n"
        . "\t\t\$lightPopulatedState = \$this->chunks[\$chunkHash]->isLightPopulated();\n"
        . "\t\tif(\$lightPopulatedState === false){\n"
        . "\t\t\tif((bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.fixed-light.enabled', false)){\n"
        . "\t\t\t\t\$fixedLevel = min(15, max(0, (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.fixed-light.level', 15)));\n"
        . "\t\t\t\t\$targetChunk = \$this->chunks[\$chunkHash];\n"
        . "\t\t\t\tforeach(\$targetChunk->getSubChunks() as \$subChunk){\n"
        . "\t\t\t\t\t\$subChunk->setBlockSkyLightArray(LightArray::fill(\$fixedLevel));\n"
        . "\t\t\t\t\t\$subChunk->setBlockLightArray(LightArray::fill(\$fixedLevel));\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\t\$targetChunk->setLightPopulated(true);\n"
        . "\t\t\t\t\$this->markTickingChunkForRecheck(\$chunkX, \$chunkZ);\n"
        . "\t\t\t\treturn;\n"
        . "\t\t\t}\n"
        . "\t\t\t\$this->chunks[\$chunkHash]->setLightPopulated(null);\n"
        . "\t\t\t\$this->markTickingChunkForRecheck(\$chunkX, \$chunkZ);\n"
        . "\n"
        . "\t\t\t\$this->workerPool->submitTask(new LightPopulationTask(";

    return applyReplacePatch($targetFile, 'Fixed light values bypass', $old, $new, 'Failed to match orderLightPopulation pattern in World.php');
}

function patchWorldPerWorldChunkTicking(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\t\t\$this->tickedBlocksPerSubchunkPerTick = \$cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_BLOCKS_PER_SUBCHUNK_PER_TICK, self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK);\n"
        . "\t\t\$this->maxConcurrentChunkPopulationTasks = \$cfg->getPropertyInt(YmlServerProperties::CHUNK_GENERATION_POPULATION_QUEUE_SIZE, 2);";

    $new = "\t\t\$this->tickedBlocksPerSubchunkPerTick = \$cfg->getPropertyInt(YmlServerProperties::CHUNK_TICKING_BLOCKS_PER_SUBCHUNK_PER_TICK, self::DEFAULT_TICKED_BLOCKS_PER_SUBCHUNK_PER_TICK);\n"
        . "\t\t/** [BetterPMMP-PATCH] Per-world chunk ticking override.\n"
        . "\t\t * tick-radius is clamped to this world's effective view-distance (per-world override if set, else server.properties),\n"
        . "\t\t * NOT to the global server view-distance, so a world configured with a larger view-distance can also tick farther. */\n"
        . "\t\t\$perWorldChunkTicking = \$cfg->getProperty('better-pmmp.per-world-chunk-ticking', []);\n"
        . "\t\tif(is_array(\$perWorldChunkTicking) && isset(\$perWorldChunkTicking[\$this->folderName])){\n"
        . "\t\t\t\$worldTickCfg = \$perWorldChunkTicking[\$this->folderName];\n"
        . "\t\t\tif(is_array(\$worldTickCfg)){\n"
        . "\t\t\t\tif(isset(\$worldTickCfg['tick-radius'])){\n"
        . "\t\t\t\t\t\$perWorldViewDistanceMap = \$cfg->getProperty('better-pmmp.per-world-view-distance', []);\n"
        . "\t\t\t\t\t\$worldViewDistance = \$this->server->getViewDistance();\n"
        . "\t\t\t\t\tif(is_array(\$perWorldViewDistanceMap) && isset(\$perWorldViewDistanceMap[\$this->folderName])){\n"
        . "\t\t\t\t\t\t\$worldViewDistance = max(2, (int) \$perWorldViewDistanceMap[\$this->folderName]);\n"
        . "\t\t\t\t\t}\n"
        . "\t\t\t\t\t\$this->chunkTickRadius = min(\$worldViewDistance, max(0, (int) \$worldTickCfg['tick-radius']));\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\tif(isset(\$worldTickCfg['blocks-per-subchunk-per-tick'])){\n"
        . "\t\t\t\t\t\$this->tickedBlocksPerSubchunkPerTick = max(0, (int) \$worldTickCfg['blocks-per-subchunk-per-tick']);\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\t\$this->maxConcurrentChunkPopulationTasks = \$cfg->getPropertyInt(YmlServerProperties::CHUNK_GENERATION_POPULATION_QUEUE_SIZE, 2);";

    return applyReplacePatch($targetFile, 'Per-world chunk ticking override', $old, $new, 'Failed to match chunk ticking config pattern in World.php');
}

function patchWorldChunkOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $oldTickChunks = "\t\tif(count(\$this->recheckTickingChunks) > 0){\n"
        . "\t\t\t\$this->timings->randomChunkUpdatesChunkSelection->startTiming();\n"
        . "\n"
        . "\t\t\t\$chunkTickableCache = [];\n"
        . "\n"
        . "\t\t\tforeach(\$this->recheckTickingChunks as \$hash => \$_){\n"
        . "\t\t\t\tWorld::getXZ(\$hash, \$chunkX, \$chunkZ);\n"
        . "\t\t\t\tif(\$this->isChunkTickable(\$chunkX, \$chunkZ, \$chunkTickableCache)){\n"
        . "\t\t\t\t\t\$this->validTickingChunks[\$hash] = \$hash;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t\t\$this->recheckTickingChunks = [];\n"
        . "\n"
        . "\t\t\t\$this->timings->randomChunkUpdatesChunkSelection->stopTiming();\n"
        . "\t\t}";

    $newTickChunks = "\t\t/** [BetterPMMP-PATCH] Batch recheck limit for chunk tick optimization */\n"
        . "\t\tif(count(\$this->recheckTickingChunks) > 0){\n"
        . "\t\t\t\$this->timings->randomChunkUpdatesChunkSelection->startTiming();\n"
        . "\n"
        . "\t\t\t\$chunkTickableCache = [];\n"
        . "\t\t\t\$batchLimit = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.chunk-optimization.batch-recheck-limit', 64);\n"
        . "\t\t\t\$processed = 0;\n"
        . "\n"
        . "\t\t\tforeach(\$this->recheckTickingChunks as \$hash => \$_){\n"
        . "\t\t\t\tif(\$batchLimit > 0 && \$processed >= \$batchLimit){\n"
        . "\t\t\t\t\tbreak;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\tWorld::getXZ(\$hash, \$chunkX, \$chunkZ);\n"
        . "\t\t\t\tif(\$this->isChunkTickable(\$chunkX, \$chunkZ, \$chunkTickableCache)){\n"
        . "\t\t\t\t\t\$this->validTickingChunks[\$hash] = \$hash;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\tunset(\$this->recheckTickingChunks[\$hash]);\n"
        . "\t\t\t\t\$processed++;\n"
        . "\t\t\t}\n"
        . "\t\t\tif(\$batchLimit <= 0 || \$processed < \$batchLimit){\n"
        . "\t\t\t\t\$this->recheckTickingChunks = [];\n"
        . "\t\t\t}\n"
        . "\n"
        . "\t\t\t\$this->timings->randomChunkUpdatesChunkSelection->stopTiming();\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'Batch recheck limit for chunk tick optimization', $oldTickChunks, $newTickChunks, 'Failed to match chunk optimization patterns in World.php');
}

function patchPlayerPerWorldViewDistance(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'Per-world view distance override')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldField = "\tprotected int \$viewDistance = -1;";
    $newField = "\tprotected int \$viewDistance = -1;\n"
        . "\t/** [BetterPMMP-PATCH] Original view-distance requested by client (pre-clamp, pre-override). Used to re-apply per-world override on world change. */\n"
        . "\tprotected int \$requestedViewDistance = -1;";
    $newContent = str_replace($oldField, $newField, $content);
    if ($newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match $viewDistance field declaration in Player.php');
    }

    $oldSetView = "\t\t\$newViewDistance = \$this->server->getAllowedViewDistance(\$distance);\n"
        . "\n"
        . "\t\tif(\$newViewDistance !== \$this->viewDistance){";

    $newSetView = "\t\t/** [BetterPMMP-PATCH] Remember the original requested distance so we can re-apply per-world override on world change */\n"
        . "\t\t\$this->requestedViewDistance = \$distance;\n"
        . "\t\t\$newViewDistance = \$this->server->getAllowedViewDistance(\$distance);\n"
        . "\n"
        . "\t\t/** [BetterPMMP-PATCH] Per-world view distance override */\n"
        . "\t\t\$perWorldViewDistance = \$this->server->getConfigGroup()->getProperty('better-pmmp.per-world-view-distance', []);\n"
        . "\t\tif(is_array(\$perWorldViewDistance)){\n"
        . "\t\t\t\$worldFolder = \$this->getWorld()->getFolderName();\n"
        . "\t\t\tif(isset(\$perWorldViewDistance[\$worldFolder])){\n"
        . "\t\t\t\t\$newViewDistance = max(2, (int) \$perWorldViewDistance[\$worldFolder]);\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\n"
        . "\t\tif(\$newViewDistance !== \$this->viewDistance){";

    $newContent2 = str_replace($oldSetView, $newSetView, $newContent);
    if ($newContent2 === $newContent) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match setViewDistance pattern in Player.php');
    }
    $newContent = $newContent2;

    $oldChunks = "\t\t\t\$this->server->getAllowedViewDistance(\$this->viewDistance),";
    $newChunks = "\t\t\t\$this->viewDistance,";
    $beforeChunks = $newContent;
    $newContent = str_replace($oldChunks, $newChunks, $newContent);
    if ($newContent === $beforeChunks) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match getAllowedViewDistance chunk-request call in Player.php');
    }

    $oldTeleport = "\tpublic function teleport(Vector3 \$pos, ?float \$yaw = null, ?float \$pitch = null) : bool{\n"
        . "\t\tif(parent::teleport(\$pos, \$yaw, \$pitch)){\n"
        . "\n"
        . "\t\t\t\$this->removeCurrentWindow();\n"
        . "\t\t\t\$this->stopSleep();";

    $newTeleport = "\tpublic function teleport(Vector3 \$pos, ?float \$yaw = null, ?float \$pitch = null) : bool{\n"
        . "\t\t/** [BetterPMMP-PATCH] Capture old world before parent::teleport mutates position */\n"
        . "\t\t\$oldWorld = \$this->getWorld();\n"
        . "\t\tif(parent::teleport(\$pos, \$yaw, \$pitch)){\n"
        . "\n"
        . "\t\t\t\$this->removeCurrentWindow();\n"
        . "\t\t\t\$this->stopSleep();\n"
        . "\n"
        . "\t\t\t/** [BetterPMMP-PATCH] Re-evaluate per-world view distance using the original requested distance,\n"
        . "\t\t\t * so a previous world's override does not leak into a world that has no override. */\n"
        . "\t\t\tif(\$oldWorld !== \$this->getWorld()){\n"
        . "\t\t\t\t\$baseDistance = \$this->requestedViewDistance > 0 ? \$this->requestedViewDistance : \$this->server->getViewDistance();\n"
        . "\t\t\t\t\$this->setViewDistance(\$baseDistance);\n"
        . "\t\t\t}";

    $beforeTeleport = $newContent;
    $newContent = str_replace($oldTeleport, $newTeleport, $newContent);
    if ($newContent === $beforeTeleport) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match teleport method in Player.php');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchServerLogPath(string $sourceDir): PatchResult
{
    return applyReplacePatch(
        $sourceDir . '/src/PocketMine.php',
        '"system", "server.log"',
        'Path::join($dataPath, "server.log")',
        'Path::join($dataPath, "system", "server.log")',
        'Failed to patch server.log path in PocketMine.php'
    );
}

function patchCrashdumpsPath(string $sourceDir): PatchResult
{
    return applyReplacePatch(
        $sourceDir . '/src/Server.php',
        '"system", "crashdumps"',
        'Path::join($this->dataPath, "crashdumps")',
        'Path::join($this->dataPath, "system", "crashdumps")',
        'Failed to patch crashdumps path in Server.php'
    );
}

function patchWorldNeighbourUpdateThrottle(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\t\t\$this->timings->neighbourBlockUpdates->startTiming();\n"
        . "\t\t//Normal updates\n"
        . "\t\twhile(\$this->neighbourBlockUpdateQueue->count() > 0){\n"
        . "\t\t\t\$index = \$this->neighbourBlockUpdateQueue->dequeue();\n"
        . "\t\t\tunset(\$this->neighbourBlockUpdateQueueIndex[\$index]);\n"
        . "\t\t\tWorld::getBlockXYZ(\$index, \$x, \$y, \$z);\n"
        . "\t\t\tif(!\$this->isChunkLoaded(\$x >> Chunk::COORD_BIT_SIZE, \$z >> Chunk::COORD_BIT_SIZE)){\n"
        . "\t\t\t\tcontinue;\n"
        . "\t\t\t}\n"
        . "\n"
        . "\t\t\t\$block = \$this->getBlockAt(\$x, \$y, \$z);\n"
        . "\n"
        . "\t\t\tif(BlockUpdateEvent::hasHandlers()){\n"
        . "\t\t\t\t\$ev = new BlockUpdateEvent(\$block);\n"
        . "\t\t\t\t\$ev->call();\n"
        . "\t\t\t\tif(\$ev->isCancelled()){\n"
        . "\t\t\t\t\tcontinue;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t\tforeach(\$this->getNearbyEntities(AxisAlignedBB::one()->offset(\$x, \$y, \$z)) as \$entity){\n"
        . "\t\t\t\t\$entity->onNearbyBlockChange();\n"
        . "\t\t\t}\n"
        . "\t\t\t\$block->onNearbyBlockChange();\n"
        . "\t\t}";

    $new = "\t\t\$this->timings->neighbourBlockUpdates->startTiming();\n"
        . "\t\t/** [BetterPMMP-PATCH] Neighbour block update throttle */\n"
        . "\t\t\$neighbourUpdateLimit = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.neighbour-update-limit', 512);\n"
        . "\t\t\$neighbourUpdateCount = 0;\n"
        . "\t\twhile(\$this->neighbourBlockUpdateQueue->count() > 0){\n"
        . "\t\t\tif(\$neighbourUpdateLimit > 0 && \$neighbourUpdateCount >= \$neighbourUpdateLimit){\n"
        . "\t\t\t\tbreak;\n"
        . "\t\t\t}\n"
        . "\t\t\t\$neighbourUpdateCount++;\n"
        . "\t\t\t\$index = \$this->neighbourBlockUpdateQueue->dequeue();\n"
        . "\t\t\tunset(\$this->neighbourBlockUpdateQueueIndex[\$index]);\n"
        . "\t\t\tWorld::getBlockXYZ(\$index, \$x, \$y, \$z);\n"
        . "\t\t\tif(!\$this->isChunkLoaded(\$x >> Chunk::COORD_BIT_SIZE, \$z >> Chunk::COORD_BIT_SIZE)){\n"
        . "\t\t\t\tcontinue;\n"
        . "\t\t\t}\n"
        . "\n"
        . "\t\t\t\$block = \$this->getBlockAt(\$x, \$y, \$z);\n"
        . "\n"
        . "\t\t\tif(BlockUpdateEvent::hasHandlers()){\n"
        . "\t\t\t\t\$ev = new BlockUpdateEvent(\$block);\n"
        . "\t\t\t\t\$ev->call();\n"
        . "\t\t\t\tif(\$ev->isCancelled()){\n"
        . "\t\t\t\t\tcontinue;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t\tforeach(\$this->getNearbyEntities(AxisAlignedBB::one()->offset(\$x, \$y, \$z)) as \$entity){\n"
        . "\t\t\t\t\$entity->onNearbyBlockChange();\n"
        . "\t\t\t}\n"
        . "\t\t\t\$block->onNearbyBlockChange();\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'Neighbour block update throttle', $old, $new, 'Failed to match neighbour update loop in World.php');
}

function patchWorldBlockCacheSize(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'World.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read World.php');
    }

    if (str_contains($content, 'blockCacheSizeCap')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = str_replace(
        "\tprivate int \$blockCacheSize = 0;",
        "\tprivate int \$blockCacheSize = 0;\n\t/** [BetterPMMP-PATCH] Configurable block cache cap */\n\tprivate int \$blockCacheSizeCap = 2048;",
        $content
    );

    $content = str_replace(
        "\t\t\$this->initRandomTickBlocksFromConfig(\$cfg);\n\n\t\t\$this->timings = new WorldTimings(\$this);",
        "\t\t/** [BetterPMMP-PATCH] Block cache size from config */\n"
        . "\t\t\$this->blockCacheSizeCap = max(512, (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.block-cache-size', 8192));\n"
        . "\t\t\$this->initRandomTickBlocksFromConfig(\$cfg);\n\n\t\t\$this->timings = new WorldTimings(\$this);",
        $content
    );

    $newContent = str_replace('self::BLOCK_CACHE_SIZE_CAP', '$this->blockCacheSizeCap', $content);
    if (str_contains($newContent, 'self::BLOCK_CACHE_SIZE_CAP') || !str_contains($newContent, 'private int $blockCacheSizeCap')) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to patch block cache size in World.php - anchor mismatch');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched World.php (block cache size)');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchEntityMoveInPlace(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    $old = "\t\t\$this->location = new Location(\n"
        . "\t\t\t(\$this->boundingBox->minX + \$this->boundingBox->maxX) / 2,\n"
        . "\t\t\t\$this->boundingBox->minY - \$this->ySize,\n"
        . "\t\t\t(\$this->boundingBox->minZ + \$this->boundingBox->maxZ) / 2,\n"
        . "\t\t\t\$this->location->world,\n"
        . "\t\t\t\$this->location->yaw,\n"
        . "\t\t\t\$this->location->pitch\n"
        . "\t\t);";

    $new = "\t\t/** [BetterPMMP-PATCH] In-place location update - avoids new Location() allocation per move */\n"
        . "\t\t\$this->location->x = (\$this->boundingBox->minX + \$this->boundingBox->maxX) / 2;\n"
        . "\t\t\$this->location->y = \$this->boundingBox->minY - \$this->ySize;\n"
        . "\t\t\$this->location->z = (\$this->boundingBox->minZ + \$this->boundingBox->maxZ) / 2;";

    return applyReplacePatch($targetFile, 'In-place location update', $old, $new, 'Failed to match Location construction in Entity.php move()');
}

function patchEntitySmartBlocksAroundCache(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Entity.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'lastBlockCellMinX')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = str_replace(
        "\tprotected ?array \$blocksAround = null;",
        "\tprotected ?array \$blocksAround = null;\n"
        . "\t/** [BetterPMMP-PATCH] Smart blocksAround cache tracking - the block cell range getBlocksIntersected() last spanned */\n"
        . "\tprivate int \$lastBlockCellMinX = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockCellMinY = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockCellMinZ = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockCellMaxX = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockCellMaxY = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockCellMaxZ = PHP_INT_MIN;",
        $content,
        $fieldCount
    );
    if ($fieldCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'blocksAround field anchor not found in Entity.php');
    }

    $content = str_replace(
        "\tprotected function move(float \$dx, float \$dy, float \$dz) : void{\n"
        . "\t\t\$this->blocksAround = null;\n"
        . "\n"
        . "\t\tTimings::\$entityMove->startTiming();",
        "\tprotected function move(float \$dx, float \$dy, float \$dz) : void{\n"
        . "\t\tTimings::\$entityMove->startTiming();",
        $content,
        $moveCount
    );
    if ($moveCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'move() blocksAround reset anchor not found in Entity.php');
    }

    $old = "\t\t\$this->getWorld()->onEntityMoved(\$this);\n"
        . "\t\t\$this->checkBlockIntersections();";

    $new = "\t\t/** [BetterPMMP-PATCH] Smart blocksAround cache - invalidate only when the bounding box spans a\n"
        . "\t\t * different block cell range, which is exactly what getBlocksIntersected() iterates. Keyed on the\n"
        . "\t\t * AABB rather than the entity centre, so a centre that stays put while an edge crosses into the\n"
        . "\t\t * next cell still invalidates. The 0.001 inset mirrors getBlocksAroundWithEntityInsideActions(). */\n"
        . "\t\t\$bbInset = 0.001;\n"
        . "\t\t\$newCellMinX = (int) floor(\$this->boundingBox->minX + \$bbInset);\n"
        . "\t\t\$newCellMinY = (int) floor(\$this->boundingBox->minY + \$bbInset);\n"
        . "\t\t\$newCellMinZ = (int) floor(\$this->boundingBox->minZ + \$bbInset);\n"
        . "\t\t\$newCellMaxX = (int) floor(\$this->boundingBox->maxX - \$bbInset);\n"
        . "\t\t\$newCellMaxY = (int) floor(\$this->boundingBox->maxY - \$bbInset);\n"
        . "\t\t\$newCellMaxZ = (int) floor(\$this->boundingBox->maxZ - \$bbInset);\n"
        . "\t\tif(\$newCellMinX !== \$this->lastBlockCellMinX || \$newCellMinY !== \$this->lastBlockCellMinY || \$newCellMinZ !== \$this->lastBlockCellMinZ\n"
        . "\t\t\t|| \$newCellMaxX !== \$this->lastBlockCellMaxX || \$newCellMaxY !== \$this->lastBlockCellMaxY || \$newCellMaxZ !== \$this->lastBlockCellMaxZ){\n"
        . "\t\t\t\$this->blocksAround = null;\n"
        . "\t\t\t\$this->lastBlockCellMinX = \$newCellMinX;\n"
        . "\t\t\t\$this->lastBlockCellMinY = \$newCellMinY;\n"
        . "\t\t\t\$this->lastBlockCellMinZ = \$newCellMinZ;\n"
        . "\t\t\t\$this->lastBlockCellMaxX = \$newCellMaxX;\n"
        . "\t\t\t\$this->lastBlockCellMaxY = \$newCellMaxY;\n"
        . "\t\t\t\$this->lastBlockCellMaxZ = \$newCellMaxZ;\n"
        . "\t\t}\n"
        . "\t\t\$this->getWorld()->onEntityMoved(\$this);\n"
        . "\t\t\$this->checkBlockIntersections();";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content || !str_contains($newContent, 'private int $lastBlockCellMinX')) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to patch smart blocksAround cache in Entity.php - anchor mismatch');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Entity.php (smart blocksAround cache)');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlCriticalHit(string $sourceDir): PatchResult
{
    $anchor = '  block-cache-size: 8192';
    $critBlock = <<<'YAML'
  # Critical hit settings
  critical-hit:
    # If true, critical hits can land even while sprinting. Vanilla requires not sprinting.
    ignore-sprint: false
    # Minimum fall distance required for a critical hit. Vanilla is 0.0, which lets a critical land
    # from the moment the player starts falling. Raise it to demand a longer fall before a hit crits.
    min-fall-distance: 0.0
YAML;

    return applyReplacePatch(
        $sourceDir . '/resources/pocketmine.yml',
        'ignore-sprint:',
        $anchor,
        $anchor . "\n" . $critBlock,
        'better-pmmp anchor (block-cache-size) not found in pocketmine.yml'
    );
}

function patchPlayerCriticalHit(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'better-pmmp.critical-hit.ignore-sprint')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $pattern = '/^([ \t]*)if\s*\(\s*!\$this->isSprinting\(\)\s*&&\s*!\$this->isFlying\(\)\s*&&\s*\$this->fallDistance\s*>\s*0\s*&&\s*!\$this->effectManager->has\(\s*VanillaEffects::BLINDNESS\(\)\s*\)\s*&&\s*!\$this->isUnderwater\(\)\s*\)\s*\{[^\n]*\n([ \t]*)\$ev->setModifier\(\s*\$ev->getFinalDamage\(\)\s*\/\s*2\s*,\s*EntityDamageEvent::MODIFIER_CRITICAL\s*\)\s*;[^\n]*\n[ \t]*\}/m';

    if (!preg_match($pattern, $content, $matches)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match critical hit condition in Player.php');
    }

    $indent = $matches[1];
    $innerIndent = $matches[2];

    $replacement = $indent . "/** [BetterPMMP-PATCH] Configurable critical hit logic */\n"
        . $indent . "\$config = \$this->server->getConfigGroup();\n"
        . $indent . "\$critMinFall = (float) \$config->getProperty('better-pmmp.critical-hit.min-fall-distance', 0.0);\n"
        . $indent . "\$critIgnoreSprint = (bool) \$config->getProperty('better-pmmp.critical-hit.ignore-sprint', false);\n"
        . $indent . "if((\$critIgnoreSprint || !\$this->isSprinting()) && !\$this->isFlying() && \$this->fallDistance > \$critMinFall && !\$this->effectManager->has(VanillaEffects::BLINDNESS()) && !\$this->isUnderwater()){\n"
        . $innerIndent . "\$ev->setModifier(\$ev->getFinalDamage() / 2, EntityDamageEvent::MODIFIER_CRITICAL);\n"
        . $indent . "}";

    $newContent = preg_replace($pattern, $replacement, $content, 1);
    if ($newContent === null || $newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to replace critical hit condition in Player.php');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlPvpOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    $anchor = "  block-cache-size: 8192";
    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] PvP server optimization\n"
        . "  # Toggles for vanilla systems that PvP-focused servers (lifesteal, KitPvP, arena) rarely need\n"
        . "  # but that cost real CPU time. Every option defaults to vanilla behaviour - opt in per server.\n"
        . "  pvp-optimization:\n"
        . "    # If true, skip ALL runtime light recalculation when blocks change (a main-thread BFS flood\n"
        . "    # fill per block update). Bedrock clients compute their own lighting for rendering; server-side\n"
        . "    # light only feeds server logic such as mob spawning, which PvP servers rarely use.\n"
        . "    # Combine with better-pmmp.fixed-light to also skip light population on chunk load.\n"
        . "    skip-light-updates: false\n"
        . "    # If false, XP orb entities are never spawned (player/mob kills, mining, smelting).\n"
        . "    # Every orb re-scans for the nearest player and re-checks block obstruction each tick.\n"
        . "    xp-orbs: true\n"
        . "    # If false, explosions (TNT, end crystals, respawn anchors) still damage and knock back\n"
        . "    # entities but never destroy blocks - the entire ray-tracing block destruction pass and the\n"
        . "    # following block/light updates and item drops are skipped. Also protects arenas from grief.\n"
        . "    explosion-block-destruction: true\n"
        . "    # If false, dropped items never merge into stacks, skipping the periodic nearby-entity scan\n"
        . "    # every ground item performs. Useful when kills drop whole inventories at once.\n"
        . "    item-merging: true\n"
        . "    # Despawn time in ticks for dropped items (vanilla: 6000 = 5 minutes). Lower values keep\n"
        . "    # fewer item entities ticking after fights. <= 0 means vanilla default.\n"
        . "    item-despawn-ticks: 6000";

    return applyReplacePatch($targetFile, 'pvp-optimization:', $anchor, $insertion, 'better-pmmp anchor (block-cache-size) not found in pocketmine.yml');
}

function patchWorldPvpSkipLightUpdates(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\tpublic function updateAllLight(int \$x, int \$y, int \$z) : void{\n"
        . "\t\tif((\$chunk = \$this->getChunk(\$x >> Chunk::COORD_BIT_SIZE, \$z >> Chunk::COORD_BIT_SIZE)) === null || \$chunk->isLightPopulated() !== true){\n"
        . "\t\t\treturn;\n"
        . "\t\t}";

    $new = "\t/** [BetterPMMP-PATCH] PvP optimization: cached skip-light-updates flag */\n"
        . "\tprivate ?bool \$pvpSkipLightUpdates = null;\n"
        . "\n"
        . "\tpublic function updateAllLight(int \$x, int \$y, int \$z) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] PvP optimization: skip runtime light recalculation entirely */\n"
        . "\t\tif(\$this->pvpSkipLightUpdates ??= (bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.skip-light-updates', false)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\tif((\$chunk = \$this->getChunk(\$x >> Chunk::COORD_BIT_SIZE, \$z >> Chunk::COORD_BIT_SIZE)) === null || \$chunk->isLightPopulated() !== true){\n"
        . "\t\t\treturn;\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'pvpSkipLightUpdates', $old, $new, 'Failed to match updateAllLight() in World.php');
}

function patchWorldPvpXpOrbToggle(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\tpublic function dropExperience(Vector3 \$pos, int \$amount) : array{\n"
        . "\t\t\$orbs = [];";

    $new = "\tpublic function dropExperience(Vector3 \$pos, int \$amount) : array{\n"
        . "\t\t/** [BetterPMMP-PATCH] PvP optimization: XP orb spawn toggle */\n"
        . "\t\tif(!(bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.xp-orbs', true)){\n"
        . "\t\t\treturn [];\n"
        . "\t\t}\n"
        . "\t\t\$orbs = [];";

    return applyReplacePatch($targetFile, 'XP orb spawn toggle', $old, $new, 'Failed to match dropExperience() in World.php');
}

function patchWorldPvpItemDespawnTicks(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\t\t\$itemEntity->setPickupDelay(\$delay);\n"
        . "\t\t\$itemEntity->setMotion(\$motion ?? new Vector3(Utils::getRandomFloat() * 0.2 - 0.1, 0.2, Utils::getRandomFloat() * 0.2 - 0.1));";

    $new = "\t\t\$itemEntity->setPickupDelay(\$delay);\n"
        . "\t\t/** [BetterPMMP-PATCH] PvP optimization: configurable item despawn time */\n"
        . "\t\t\$pvpDespawnTicks = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.item-despawn-ticks', ItemEntity::DEFAULT_DESPAWN_DELAY);\n"
        . "\t\tif(\$pvpDespawnTicks > 0 && \$pvpDespawnTicks !== ItemEntity::DEFAULT_DESPAWN_DELAY){\n"
        . "\t\t\t\$itemEntity->setDespawnDelay(min(\$pvpDespawnTicks, ItemEntity::MAX_DESPAWN_DELAY));\n"
        . "\t\t}\n"
        . "\t\t\$itemEntity->setMotion(\$motion ?? new Vector3(Utils::getRandomFloat() * 0.2 - 0.1, 0.2, Utils::getRandomFloat() * 0.2 - 0.1));";

    return applyReplacePatch($targetFile, 'configurable item despawn time', $old, $new, 'Failed to match dropItem() in World.php');
}

function patchExplosionPvpBlockDestructionToggle(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/Explosion.php';

    $old = "\tpublic function explodeA() : bool{\n"
        . "\t\tif(\$this->radius < 0.1){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}";

    $new = "\tpublic function explodeA() : bool{\n"
        . "\t\tif(\$this->radius < 0.1){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t/** [BetterPMMP-PATCH] PvP optimization: explosion block destruction toggle - skips the\n"
        . "\t\t * ray-tracing block destruction pass entirely; entity damage/knockback in explodeB() still applies */\n"
        . "\t\tif(!(bool) \\pocketmine\\Server::getInstance()->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.explosion-block-destruction', true)){\n"
        . "\t\t\treturn true;\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'explosion block destruction toggle', $old, $new, 'Failed to match explodeA() in Explosion.php');
}

function patchItemEntityPvpMergeToggle(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/object/ItemEntity.php';

    $old = "\t\t\tif(\$this->hasMovementUpdate() && \$this->isMergeCandidate() && \$this->despawnDelay % self::MERGE_CHECK_PERIOD === 0){";

    $new = "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: item merging toggle */\n"
        . "\t\t\tif(\$this->hasMovementUpdate() && \$this->isMergeCandidate() && \$this->despawnDelay % self::MERGE_CHECK_PERIOD === 0\n"
        . "\t\t\t\t&& (bool) \\pocketmine\\Server::getInstance()->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.item-merging', true)){";

    return applyReplacePatch($targetFile, 'item merging toggle', $old, $new, 'Failed to match merge check in ItemEntity.php entityBaseTick()');
}

function patchPocketmineYmlPvpTickToggles(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    $anchor = "    item-despawn-ticks: 6000";
    $insertion = $anchor . "\n"
        . "    # --- recurring per-tick cost toggles ---\n"
        . "    # Send entity/player movement packets to viewers every N ticks instead of every tick (1 = vanilla).\n"
        . "    # Movement broadcasts are the largest outbound packet stream of a PvP server (every moving\n"
        . "    # player x every viewer x 20/s); 2 halves that encode+compress cost. Knockback motion,\n"
        . "    # teleports and the final stop position are always delivered, so combat feel is preserved.\n"
        . "    movement-broadcast-period: 1\n"
        . "    # Scan for pickups (items/arrows/xp orbs) around each player every N ticks instead of every\n"
        . "    # tick (1 = vanilla). The scan iterates every entity near every player - effectively O(n^2)\n"
        . "    # in clustered fights. Vanilla pickup delay is 10 ticks anyway, so 4 stays imperceptible.\n"
        . "    pickup-scan-period: 1\n"
        . "    # If true, worlds with zero players run only 1 tick in 100 (chunk unloading and provider GC\n"
        . "    # still happen on the slow ticks). Time, scheduled updates and leftover entities freeze until\n"
        . "    # a player enters. Big win for multi-world setups (lobby / duel arenas / mines).\n"
        . "    freeze-empty-worlds: false";

    return applyReplacePatch($targetFile, 'movement-broadcast-period:', $anchor, $insertion, 'pvp-optimization anchor (item-despawn-ticks) not found in pocketmine.yml');
}

function patchEntityPvpMovementBroadcastPeriod(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Entity.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'pvpMovementBroadcastPeriod')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldProperty = "\tprotected function updateMovement(bool \$teleport = false) : void{";
    $newProperty = "\t/** [BetterPMMP-PATCH] PvP optimization: cached movement broadcast period */\n"
        . "\tprivate ?int \$pvpMovementBroadcastPeriod = null;\n"
        . "\n"
        . "\tprotected function updateMovement(bool \$teleport = false) : void{";
    $content = str_replace($oldProperty, $newProperty, $content, $propCount);
    if ($propCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'updateMovement signature anchor not found in Entity.php');
    }

    $old = "\t\tif(\$teleport || \$diffPosition > 0.0001 || \$diffRotation > 1.0 || (!\$wasStill && \$still)){\n"
        . "\t\t\t\$this->lastLocation = \$this->location->asLocation();\n"
        . "\n"
        . "\t\t\t\$this->broadcastMovement(\$teleport);\n"
        . "\t\t}";

    $new = "\t\tif(\$teleport || \$diffPosition > 0.0001 || \$diffRotation > 1.0 || (!\$wasStill && \$still)){\n"
        . "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: movement broadcast period - skip off-cycle sends.\n"
        . "\t\t\t * lastLocation is left untouched on skip, so the accumulated diff re-enters this branch\n"
        . "\t\t\t * and the final position is still broadcast after the entity stops moving. */\n"
        . "\t\t\t\$pvpMovePeriod = \$this->pvpMovementBroadcastPeriod ??= (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.movement-broadcast-period', 1);\n"
        . "\t\t\tif(\$teleport || \$pvpMovePeriod <= 1 || ((\$this->server->getTick() + \$this->id) % \$pvpMovePeriod) === 0){\n"
        . "\t\t\t\t\$this->lastLocation = \$this->location->asLocation();\n"
        . "\n"
        . "\t\t\t\t\$this->broadcastMovement(\$teleport);\n"
        . "\t\t\t}\n"
        . "\t\t}";

    $content = str_replace($old, $new, $content, $broadcastCount);
    if ($broadcastCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match updateMovement() broadcast block in Entity.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Entity.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPlayerPvpMovementBroadcastPeriod(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'pvpMoveBroadcastPending')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldProperty = "\tprotected function processMostRecentMovements() : void{";
    $newProperty = "\t/** [BetterPMMP-PATCH] PvP optimization: movement broadcast skipped this cycle, flush pending */\n"
        . "\tprivate bool \$pvpMoveBroadcastPending = false;\n"
        . "\n"
        . "\t/** [BetterPMMP-PATCH] PvP optimization: cached movement broadcast period */\n"
        . "\tprivate ?int \$pvpMoveBroadcastPeriod = null;\n"
        . "\n"
        . "\tprotected function processMostRecentMovements() : void{";
    $content = str_replace($oldProperty, $newProperty, $content, $propCount);
    if ($propCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'processMostRecentMovements signature anchor not found in Player.php');
    }

    $oldBroadcast = "\t\t\t\$this->lastLocation = \$to;\n"
        . "\t\t\t\$this->broadcastMovement();";
    $newBroadcast = "\t\t\t\$this->lastLocation = \$to;\n"
        . "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: player movement broadcast period - PlayerMoveEvent and\n"
        . "\t\t\t * exhaustion above stay per-tick, only the packet send is decimated. */\n"
        . "\t\t\t\$pvpMovePeriod = \$this->pvpMoveBroadcastPeriod ??= (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.movement-broadcast-period', 1);\n"
        . "\t\t\tif(\$pvpMovePeriod <= 1 || ((\$this->server->getTick() + \$this->id) % \$pvpMovePeriod) === 0){\n"
        . "\t\t\t\t\$this->pvpMoveBroadcastPending = false;\n"
        . "\t\t\t\t\$this->broadcastMovement();\n"
        . "\t\t\t}else{\n"
        . "\t\t\t\t\$this->pvpMoveBroadcastPending = true;\n"
        . "\t\t\t}";
    $content = str_replace($oldBroadcast, $newBroadcast, $content, $broadcastCount);
    if ($broadcastCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'processMostRecentMovements broadcast anchor not found in Player.php');
    }

    $oldFlush = "\t\t\t\tif(\$this->nextChunkOrderRun > 20){\n"
        . "\t\t\t\t\t\$this->nextChunkOrderRun = 20;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\n"
        . "\t\tif(\$exceededRateLimit){";
    $newFlush = "\t\t\t\tif(\$this->nextChunkOrderRun > 20){\n"
        . "\t\t\t\t\t\$this->nextChunkOrderRun = 20;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}elseif(\$this->pvpMoveBroadcastPending){\n"
        . "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: flush the last skipped movement broadcast so the\n"
        . "\t\t\t * resting position viewers see is always exact */\n"
        . "\t\t\t\$this->pvpMoveBroadcastPending = false;\n"
        . "\t\t\t\$this->broadcastMovement();\n"
        . "\t\t}\n"
        . "\n"
        . "\t\tif(\$exceededRateLimit){";
    $content = str_replace($oldFlush, $newFlush, $content, $flushCount);
    if ($flushCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'processMostRecentMovements flush anchor not found in Player.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPlayerPvpPickupScanPeriod(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'pvpPickupScanPeriod')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldProperty = "\tpublic function onUpdate(int \$currentTick) : bool{";
    $newProperty = "\t/** [BetterPMMP-PATCH] PvP optimization: cached pickup scan period */\n"
        . "\tprivate ?int \$pvpPickupScanPeriod = null;\n"
        . "\n"
        . "\tpublic function onUpdate(int \$currentTick) : bool{";
    $content = str_replace($oldProperty, $newProperty, $content, $propCount);
    if ($propCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'onUpdate signature anchor not found in Player.php');
    }

    $old = "\t\t\tif(!\$this->isSpectator() && \$this->isAlive()){\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->startTiming();\n"
        . "\t\t\t\t\$this->checkNearEntities();\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->stopTiming();\n"
        . "\t\t\t}";

    $new = "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: pickup scan period - the nearby-entity sweep is\n"
        . "\t\t\t * O(entities around each player) every tick; vanilla pickup delay is 10 ticks anyway */\n"
        . "\t\t\t\$pvpScanPeriod = \$this->pvpPickupScanPeriod ??= (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.pickup-scan-period', 1);\n"
        . "\t\t\tif(!\$this->isSpectator() && \$this->isAlive() && (\$pvpScanPeriod <= 1 || ((\$currentTick + \$this->id) % \$pvpScanPeriod) === 0)){\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->startTiming();\n"
        . "\t\t\t\t\$this->checkNearEntities();\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->stopTiming();\n"
        . "\t\t\t}";

    $content = str_replace($old, $new, $content, $scanCount);
    if ($scanCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match checkNearEntities block in Player.php onUpdate()');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchWorldPvpFreezeEmptyWorlds(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    $old = "\tpublic function doTick(int \$currentTick) : void{\n"
        . "\t\tif(\$this->unloaded){\n"
        . "\t\t\tthrow new \\LogicException(\"Attempted to tick a world which has been closed\");\n"
        . "\t\t}";

    $new = "\tpublic function doTick(int \$currentTick) : void{\n"
        . "\t\tif(\$this->unloaded){\n"
        . "\t\t\tthrow new \\LogicException(\"Attempted to tick a world which has been closed\");\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t/** [BetterPMMP-PATCH] PvP optimization: freeze empty worlds - with no players present, run\n"
        . "\t\t * only 1 tick in 100 so chunk unloading and provider GC still happen eventually */\n"
        . "\t\tif(count(\$this->players) === 0\n"
        . "\t\t\t&& (\$currentTick % 100) !== 0\n"
        . "\t\t\t&& (bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.freeze-empty-worlds', false)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'freeze empty worlds', $old, $new, 'Failed to match doTick() in World.php');
}

function patchPocketmineYmlEventOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    $anchor = "    freeze-empty-worlds: false";
    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] Event engine optimization\n"
        . "  # The dispatch fast-path (skipping all timing wrappers while timings are disabled) is always on.\n"
        . "  # The toggles below decimate the hottest event call sites themselves - defaults are vanilla.\n"
        . "  event-optimization:\n"
        . "    # Fire PlayerMoveEvent every N ticks instead of every tick (1 = vanilla). Events carry the\n"
        . "    # accumulated from->to span so listeners still see a gapless movement chain; cancelling\n"
        . "    # reverts the whole span. With move listeners registered this is the hottest event of a\n"
        . "    # PvP server (every moving player x 20/s x every listener).\n"
        . "    move-event-period: 1\n"
        . "    # If true, DataPacketReceiveEvent is not fired for PlayerAuthInputPacket - it arrives 20/s\n"
        . "    # per player and is the overwhelming majority of inbound packets. All other packets still\n"
        . "    # fire the event normally. Only enable if no plugin inspects auth input via this event.\n"
        . "    skip-auth-input-receive-event: false\n"
        . "    # If true, DataPacketSendEvent is not fired for MoveActorAbsolutePacket / SetActorMotionPacket\n"
        . "    # broadcasts - the largest outbound packet stream (moving entities x viewers x 20/s).\n"
        . "    # All other packets still fire the event normally.\n"
        . "    skip-movement-send-event: false";

    return applyReplacePatch($targetFile, 'event-optimization:', $anchor, $insertion, 'pvp-optimization anchor (freeze-empty-worlds) not found in pocketmine.yml');
}

function patchEventCallFastPath(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/event/Event.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Event.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Event.php');
    }

    if (str_contains($content, 'callEventFast')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldUse = "use pocketmine\\timings\\Timings;";
    $newUse = "use pocketmine\\timings\\Timings;\nuse pocketmine\\timings\\TimingsHandler;";
    $content = str_replace($oldUse, $newUse, $content, $useCount);
    if ($useCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Timings use anchor not found in Event.php');
    }

    $old = "\t\t\$timings = Timings::getEventTimings(\$this);\n"
        . "\t\t\$timings->startTiming();\n"
        . "\n"
        . "\t\t\$handlers = HandlerListManager::global()->getHandlersFor(static::class);\n"
        . "\n"
        . "\t\t++self::\$eventCallDepth;\n"
        . "\t\ttry{\n"
        . "\t\t\tforeach(\$handlers as \$registration){\n"
        . "\t\t\t\t\$registration->callEvent(\$this);\n"
        . "\t\t\t}\n"
        . "\t\t}finally{\n"
        . "\t\t\t--self::\$eventCallDepth;\n"
        . "\t\t\t\$timings->stopTiming();\n"
        . "\t\t}";

    $new = "\t\t/** [BetterPMMP-PATCH] event engine fast-path: while timings are disabled (the normal production\n"
        . "\t\t * state), skip the per-call timings lookup/start/stop and the per-listener timing wrappers.\n"
        . "\t\t * With timings enabled the vanilla timed path below runs unchanged, so reports stay complete. */\n"
        . "\t\tif(!TimingsHandler::isEnabled()){\n"
        . "\t\t\t\$handlers = HandlerListManager::global()->getHandlersFor(static::class);\n"
        . "\t\t\tif(count(\$handlers) === 0){\n"
        . "\t\t\t\treturn;\n"
        . "\t\t\t}\n"
        . "\t\t\t++self::\$eventCallDepth;\n"
        . "\t\t\ttry{\n"
        . "\t\t\t\tforeach(\$handlers as \$registration){\n"
        . "\t\t\t\t\t\$registration->callEventFast(\$this);\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}finally{\n"
        . "\t\t\t\t--self::\$eventCallDepth;\n"
        . "\t\t\t}\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t\$timings = Timings::getEventTimings(\$this);\n"
        . "\t\t\$timings->startTiming();\n"
        . "\n"
        . "\t\t\$handlers = HandlerListManager::global()->getHandlersFor(static::class);\n"
        . "\n"
        . "\t\t++self::\$eventCallDepth;\n"
        . "\t\ttry{\n"
        . "\t\t\tforeach(\$handlers as \$registration){\n"
        . "\t\t\t\t\$registration->callEvent(\$this);\n"
        . "\t\t\t}\n"
        . "\t\t}finally{\n"
        . "\t\t\t--self::\$eventCallDepth;\n"
        . "\t\t\t\$timings->stopTiming();\n"
        . "\t\t}";

    $content = str_replace($old, $new, $content, $callCount);
    if ($callCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'call() body anchor not found in Event.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Event.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchRegisteredListenerFastPath(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/event/RegisteredListener.php';

    $old = "\tpublic function isHandlingCancelled() : bool{";

    $new = "\t/** [BetterPMMP-PATCH] event engine fast-path: untimed dispatch used by Event::call() while\n"
        . "\t * timings are disabled - drops two timing wrappers and one method call per listener per event\n"
        . "\t * @phpstan-param TEvent \$event\n"
        . "\t */\n"
        . "\tpublic function callEventFast(Event \$event) : void{\n"
        . "\t\tif(\$event instanceof Cancellable && \$event->isCancelled() && !\$this->handleCancelled){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t(\$this->handler)(\$event);\n"
        . "\t}\n"
        . "\n"
        . "\tpublic function isHandlingCancelled() : bool{";

    return applyReplacePatch($targetFile, 'callEventFast', $old, $new, 'isHandlingCancelled anchor not found in RegisteredListener.php');
}

function patchPlayerMoveEventPeriod(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'pvpMoveEventFrom')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldProperty = "\tprivate bool \$pvpMoveBroadcastPending = false;";
    $newProperty = "\tprivate bool \$pvpMoveBroadcastPending = false;\n"
        . "\t/** [BetterPMMP-PATCH] event engine: last position fired in a PlayerMoveEvent (for period accumulation) */\n"
        . "\tprivate ?Location \$pvpMoveEventFrom = null;";
    $content = str_replace($oldProperty, $newProperty, $content, $propCount);
    if ($propCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'pvpMoveBroadcastPending property anchor not found in Player.php (requires patchPlayerPvpMovementBroadcastPeriod)');
    }

    $oldEvent = "\t\tif(\$delta > 0.0001 || \$deltaAngle > 1.0){\n"
        . "\t\t\tif(PlayerMoveEvent::hasHandlers()){\n"
        . "\t\t\t\t\$ev = new PlayerMoveEvent(\$this, \$from, \$to);\n"
        . "\n"
        . "\t\t\t\t\$ev->call();\n"
        . "\n"
        . "\t\t\t\tif(\$ev->isCancelled()){\n"
        . "\t\t\t\t\t\$this->revertMovement(\$from);\n"
        . "\t\t\t\t\treturn;\n"
        . "\t\t\t\t}\n"
        . "\n"
        . "\t\t\t\tif(\$to->distanceSquared(\$ev->getTo()) > 0.01){ //If plugins modify the destination\n"
        . "\t\t\t\t\t\$this->teleport(\$ev->getTo());\n"
        . "\t\t\t\t\treturn;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}";

    $newEvent = "\t\tif(\$delta > 0.0001 || \$deltaAngle > 1.0){\n"
        . "\t\t\t/** [BetterPMMP-PATCH] event engine: PlayerMoveEvent period - fire every N ticks with the\n"
        . "\t\t\t * accumulated from, so listeners still see a gapless movement chain. Cancelling reverts\n"
        . "\t\t\t * the whole accumulated span. */\n"
        . "\t\t\tif(PlayerMoveEvent::hasHandlers()){\n"
        . "\t\t\t\t\$pvpMoveEvPeriod = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.event-optimization.move-event-period', 1);\n"
        . "\t\t\t\t\$this->pvpMoveEventFrom ??= \$from;\n"
        . "\t\t\t\tif(\$pvpMoveEvPeriod <= 1 || ((\$this->server->getTick() + \$this->id) % \$pvpMoveEvPeriod) === 0){\n"
        . "\t\t\t\t\t\$evFrom = \$pvpMoveEvPeriod <= 1 ? \$from : \$this->pvpMoveEventFrom;\n"
        . "\t\t\t\t\t\$this->pvpMoveEventFrom = null;\n"
        . "\t\t\t\t\t\$ev = new PlayerMoveEvent(\$this, \$evFrom, \$to);\n"
        . "\n"
        . "\t\t\t\t\t\$ev->call();\n"
        . "\n"
        . "\t\t\t\t\tif(\$ev->isCancelled()){\n"
        . "\t\t\t\t\t\t\$this->revertMovement(\$evFrom);\n"
        . "\t\t\t\t\t\t/** keep the vanilla position==lastLocation invariant - evFrom may be older than\n"
        . "\t\t\t\t\t\t * lastLocation when period > 1 - and resync viewers who already saw the reverted span */\n"
        . "\t\t\t\t\t\t\$this->lastLocation = \$evFrom;\n"
        . "\t\t\t\t\t\tif(\$pvpMoveEvPeriod > 1){\n"
        . "\t\t\t\t\t\t\t\$this->pvpMoveBroadcastPending = true;\n"
        . "\t\t\t\t\t\t}\n"
        . "\t\t\t\t\t\treturn;\n"
        . "\t\t\t\t\t}\n"
        . "\n"
        . "\t\t\t\t\tif(\$to->distanceSquared(\$ev->getTo()) > 0.01){ //If plugins modify the destination\n"
        . "\t\t\t\t\t\t\$this->teleport(\$ev->getTo());\n"
        . "\t\t\t\t\t\treturn;\n"
        . "\t\t\t\t\t}\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}";

    $content = str_replace($oldEvent, $newEvent, $content, $eventCount);
    if ($eventCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PlayerMoveEvent block anchor not found in Player.php');
    }

    $oldTeleport = "\t\t\t\$this->removeCurrentWindow();\n"
        . "\t\t\t\$this->stopSleep();";
    $newTeleport = "\t\t\t\$this->removeCurrentWindow();\n"
        . "\t\t\t\$this->stopSleep();\n"
        . "\t\t\t/** [BetterPMMP-PATCH] event engine: teleport breaks move-event accumulation */\n"
        . "\t\t\t\$this->pvpMoveEventFrom = null;";
    $content = str_replace($oldTeleport, $newTeleport, $content, $teleportCount);
    if ($teleportCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'teleport cleanup anchor not found in Player.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchNetworkSessionAuthInputReceiveEvent(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/NetworkSession.php';

    $old = "\t\t\tif(DataPacketReceiveEvent::hasHandlers()){\n"
        . "\t\t\t\t\$ev = new DataPacketReceiveEvent(\$this, \$packet);";

    $new = "\t\t\t/** [BetterPMMP-PATCH] event engine: optionally skip DataPacketReceiveEvent for\n"
        . "\t\t\t * PlayerAuthInputPacket - it arrives 20/s per player and dominates inbound event dispatches */\n"
        . "\t\t\tif(DataPacketReceiveEvent::hasHandlers()\n"
        . "\t\t\t\t&& !(\$packet instanceof \\pocketmine\\network\\mcpe\\protocol\\PlayerAuthInputPacket\n"
        . "\t\t\t\t\t&& (bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.event-optimization.skip-auth-input-receive-event', false))){\n"
        . "\t\t\t\t\$ev = new DataPacketReceiveEvent(\$this, \$packet);";

    return applyReplacePatch($targetFile, 'skip-auth-input-receive-event', $old, $new, 'DataPacketReceiveEvent anchor not found in NetworkSession.php');
}

function patchNetworkSessionMovementSendEvent(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/NetworkSession.php';

    $old = "\t\t\tif(DataPacketSendEvent::hasHandlers()){\n"
        . "\t\t\t\t\$ev = new DataPacketSendEvent([\$this], [\$packet]);";

    $new = "\t\t\t/** [BetterPMMP-PATCH] event engine: optionally skip DataPacketSendEvent for movement packets -\n"
        . "\t\t\t * the largest outbound packet stream (moving entities x viewers x 20/s) */\n"
        . "\t\t\tif(DataPacketSendEvent::hasHandlers()\n"
        . "\t\t\t\t&& !((\$packet instanceof \\pocketmine\\network\\mcpe\\protocol\\MoveActorAbsolutePacket || \$packet instanceof \\pocketmine\\network\\mcpe\\protocol\\SetActorMotionPacket)\n"
        . "\t\t\t\t\t&& (bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.event-optimization.skip-movement-send-event', false))){\n"
        . "\t\t\t\t\$ev = new DataPacketSendEvent([\$this], [\$packet]);";

    return applyReplacePatch($targetFile, 'skip DataPacketSendEvent for movement packets', $old, $new, 'DataPacketSendEvent anchor not found in NetworkSession.php');
}

function patchStandardBroadcasterMovementSendEvent(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/StandardPacketBroadcaster.php';

    $old = "\t\tif(DataPacketSendEvent::hasHandlers()){\n"
        . "\t\t\t\$ev = new DataPacketSendEvent(\$recipients, \$packets);";

    $new = "\t\t/** [BetterPMMP-PATCH] event engine: optionally skip DataPacketSendEvent for movement broadcasts -\n"
        . "\t\t * the largest outbound packet stream (moving entities x viewers x 20/s) */\n"
        . "\t\tif(DataPacketSendEvent::hasHandlers()\n"
        . "\t\t\t&& !(count(\$packets) === 1\n"
        . "\t\t\t\t&& ((\$packets[0] ?? null) instanceof \\pocketmine\\network\\mcpe\\protocol\\MoveActorAbsolutePacket || (\$packets[0] ?? null) instanceof \\pocketmine\\network\\mcpe\\protocol\\SetActorMotionPacket)\n"
        . "\t\t\t\t&& (bool) \$this->server->getConfigGroup()->getProperty('better-pmmp.event-optimization.skip-movement-send-event', false))){\n"
        . "\t\t\t\$ev = new DataPacketSendEvent(\$recipients, \$packets);";

    return applyReplacePatch($targetFile, 'skip DataPacketSendEvent for movement broadcasts', $old, $new, 'DataPacketSendEvent anchor not found in StandardPacketBroadcaster.php');
}

function patchAttributeMapNeedSend(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/AttributeMap.php';
    $fileLabel = basename($targetFile);

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "{$fileLabel} not found");
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to read {$fileLabel}");
    }

    if (str_contains($content, 'Manual needSend collect')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldBody = "\tpublic function needSend() : array{\n"
        . "\t\treturn array_filter(\$this->attributes, function(Attribute \$attribute) : bool{\n"
        . "\t\t\treturn \$attribute->isSyncable() && \$attribute->isDesynchronized();\n"
        . "\t\t});\n"
        . "\t}";

    $newBody = "\tpublic function needSend() : array{\n"
        . "\t\t/* [BetterPMMP-PATCH] Manual needSend collect: drop the array_filter closure (per-element zend_call)\n"
        . "\t\t * and share the empty array on the common zero-dirty case. Both consumers ignore keys, so the\n"
        . "\t\t * re-indexed list is observably identical to the key-preserved filtered map. */\n"
        . "\t\t\$dirty = [];\n"
        . "\t\tforeach(\$this->attributes as \$attribute){\n"
        . "\t\t\tif(\$attribute->isSyncable() && \$attribute->isDesynchronized()){\n"
        . "\t\t\t\t\$dirty[] = \$attribute;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\treturn \$dirty;\n"
        . "\t}";

    $newContent = str_replace($oldBody, $newBody, $content, $bodyCount);
    if ($bodyCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match needSend() body in AttributeMap.php');
    }

    $oldImport = "use function array_filter;\n\n";
    $newContent = str_replace($oldImport, '', $newContent, $importCount);
    if ($importCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match array_filter import in AttributeMap.php');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to write {$fileLabel}");
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchStandardBroadcasterVarintLength(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/StandardPacketBroadcaster.php';
    $fileLabel = basename($targetFile);

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "{$fileLabel} not found");
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to read {$fileLabel}");
    }

    if (str_contains($content, 'BetterPMMP-PATCH: inline varint length')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldCalc = "\t\t\t//varint length prefix + packet buffer\n"
        . "\t\t\t\$totalLength += (((int) log(strlen(\$buffer), 128)) + 1) + strlen(\$buffer);\n";

    $newCalc = "\t\t\t//varint length prefix + packet buffer\n"
        . "\t\t\t//[BetterPMMP-PATCH: inline varint length] replace libm log() with a branch-predicted\n"
        . "\t\t\t//bit-range lookup (byte-perfect parity with vanilla log-truncation across all reachable lengths)\n"
        . "\t\t\t\$len = strlen(\$buffer);\n"
        . "\t\t\t\$totalLength += (\$len <= 0x7F ? 1 : (\$len <= 0x3FFF ? 2 : (\$len <= 0x1FFFFF ? 3 : (\$len <= 0xFFFFFFF ? 4 : 5)))) + \$len;\n";

    $content = str_replace($oldCalc, $newCalc, $content, $calcCount);
    if ($calcCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'varint length anchor not found in StandardPacketBroadcaster.php');
    }

    $content = str_replace("use function log;\n", '', $content, $useCount);
    if ($useCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'use function log import not found in StandardPacketBroadcaster.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, "Failed to write {$fileLabel}");
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchServerSkipVanillaRecipes(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/Server.php';

    $old = "\t\t\t\$this->craftingManager = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);\n";

    $new = "\t\t\t/* [BetterPMMP-PATCH] vanilla-recipe-skip: gate the one-time recipe JSON deserialization behind a\n"
        . "\t\t\t * config toggle. Default true reproduces vanilla byte-for-byte (a missing key returns true). When\n"
        . "\t\t\t * false, skip the JsonMapper pass plus per-recipe base64+NBT decode and construct an empty\n"
        . "\t\t\t * CraftingManager - it still seeds empty per-type furnace managers, so getCraftingManager() and\n"
        . "\t\t\t * CraftingDataCache stay valid and every recipe lookup simply matches nothing. */\n"
        . "\t\t\tif(\$this->configGroup->getPropertyBool('better-pmmp.load-vanilla-recipes', true)){\n"
        . "\t\t\t\t\$this->craftingManager = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);\n"
        . "\t\t\t}else{\n"
        . "\t\t\t\t\$this->craftingManager = new CraftingManager();\n"
        . "\t\t\t}\n";

    return applyReplacePatch($targetFile, 'better-pmmp.load-vanilla-recipes', $old, $new, 'Failed to match craftingManager assignment in Server.php');
}

function patchPocketmineYmlSkipVanillaRecipes(string $sourceDir): PatchResult
{
    $anchor = '  block-cache-size: 8192';
    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] Vanilla crafting recipe registration toggle.\n"
        . "  # true (default) = vanilla: load all recipe JSON (crafting, furnace, brewing, campfire) at startup.\n"
        . "  # false = skip vanilla recipe JSON deserialization at startup and boot with an empty CraftingManager.\n"
        . "  # Plugins can still register their own recipes. Set false ONLY for a fully plugin-driven crafting/kit\n"
        . "  # server: this disables ALL vanilla recipes (crafting table, furnace smelting, brewing, campfire).\n"
        . "  # Benefit is one-time startup CPU saved on every restart (JsonMapper + per-recipe base64/NBT decode).\n"
        . "  load-vanilla-recipes: true";

    return applyReplacePatch(
        $sourceDir . '/resources/pocketmine.yml',
        'load-vanilla-recipes:',
        $anchor,
        $insertion,
        'better-pmmp anchor (block-cache-size) not found in pocketmine.yml'
    );
}

if (!isset($argv[1])) {
    fwrite(STDOUT, "BetterPMMP Patch Tool\nUsage: php patch_tool.php <source_directory_path>\n\n  <source_directory_path>  Path to the PMMP source directory to patch\n");
    exit(1);
}

$sourceDir = $argv[1];

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Error: Directory not found: {$sourceDir}\n");
    exit(1);
}

$sourceDir = realpath($sourceDir);
if ($sourceDir === false) {
    fwrite(STDERR, "Error: Cannot resolve path\n");
    exit(1);
}

if (basename($sourceDir) === 'src') {
    $sourceDir = dirname($sourceDir);
}
$baseDir = dirname($sourceDir);

fwrite(STDOUT, "BetterPMMP Patch Tool\n");
fwrite(STDOUT, "Target directory: {$sourceDir}\n");
fwrite(STDOUT, "\n");

$results = [];

$patchFunctions = [
    'patchStartCmd' => $baseDir,
    'patchComposerSyncCheck' => $sourceDir,
    'patchStartCmdBinPath' => $baseDir,
    'patchDataPath' => $sourceDir,
    'patchServerLogPath' => $sourceDir,
    'patchServerPaths' => $sourceDir,
    'patchCrashdumpsPath' => $sourceDir,
    'patchStartWarning' => $sourceDir,
    'patchGarbageCollectorLog' => $sourceDir,
    'patchServerStartLogs' => $sourceDir,
    'patchInfoPrefix' => $sourceDir,
    'patchBlockInputLag' => $sourceDir,
    'patchPluginManagerLazyDataFolder' => $sourceDir,
    'patchPluginBaseLazyDataFolder' => $sourceDir,
    'createRestartCommand' => $sourceDir,
    'patchSimpleCommandMapRestartCommand' => $sourceDir,
    'patchStartCmdRestartLoop' => $baseDir,
    'patchIronDoorNoInteract' => $sourceDir,
    'patchPocketmineYmlBetterPmmp' => $sourceDir,
    'patchWorldFixedLight' => $sourceDir,
    'patchWorldPerWorldChunkTicking' => $sourceDir,
    'patchWorldChunkOptimization' => $sourceDir,
    'patchPlayerPerWorldViewDistance' => $sourceDir,
    'patchWorldNeighbourUpdateThrottle' => $sourceDir,
    'patchWorldBlockCacheSize' => $sourceDir,
    'patchEntityMoveInPlace' => $sourceDir,
    'patchEntitySmartBlocksAroundCache' => $sourceDir,
    'patchPocketmineYmlCriticalHit' => $sourceDir,
    'patchPlayerCriticalHit' => $sourceDir,
    'patchPocketmineYmlPvpOptimization' => $sourceDir,
    'patchWorldPvpSkipLightUpdates' => $sourceDir,
    'patchWorldPvpXpOrbToggle' => $sourceDir,
    'patchWorldPvpItemDespawnTicks' => $sourceDir,
    'patchExplosionPvpBlockDestructionToggle' => $sourceDir,
    'patchItemEntityPvpMergeToggle' => $sourceDir,
    'patchPocketmineYmlPvpTickToggles' => $sourceDir,
    'patchEntityPvpMovementBroadcastPeriod' => $sourceDir,
    'patchPlayerPvpMovementBroadcastPeriod' => $sourceDir,
    'patchPlayerPvpPickupScanPeriod' => $sourceDir,
    'patchWorldPvpFreezeEmptyWorlds' => $sourceDir,
    'patchPocketmineYmlEventOptimization' => $sourceDir,
    'patchEventCallFastPath' => $sourceDir,
    'patchRegisteredListenerFastPath' => $sourceDir,
    'patchPlayerMoveEventPeriod' => $sourceDir,
    'patchNetworkSessionAuthInputReceiveEvent' => $sourceDir,
    'patchNetworkSessionMovementSendEvent' => $sourceDir,
    'patchStandardBroadcasterMovementSendEvent' => $sourceDir,
    'patchAttributeMapNeedSend' => $sourceDir,
    'patchStandardBroadcasterVarintLength' => $sourceDir,
    'patchServerSkipVanillaRecipes' => $sourceDir,
    'patchPocketmineYmlSkipVanillaRecipes' => $sourceDir,
];

foreach ($patchFunctions as $func => $dir) {
    try {
        $results[] = $func($dir);
    } catch (\Throwable $e) {
        $results[] = new PatchResult($func, PatchStatus::FAILED, $e->getMessage());
    }
}

fwrite(STDOUT, "\n=== Patch Results ===\n");

$counts = [PatchStatus::APPLIED->value => 0, PatchStatus::SKIPPED->value => 0, PatchStatus::FAILED->value => 0];
foreach ($results as $result) {
    $counts[$result->status->value]++;
    $line = "[{$result->status->value}] {$result->target}";
    if ($result->error !== null) {
        $line .= " - {$result->error}";
    }
    fwrite(STDOUT, $line . "\n");
}

fwrite(STDOUT, "\n=== Summary ===\n");
fwrite(STDOUT, "Applied: {$counts['APPLIED']}\n");
fwrite(STDOUT, "Skipped: {$counts['SKIPPED']}\n");
fwrite(STDOUT, "Failed:  {$counts['FAILED']}\n");
fwrite(STDOUT, "Total:   " . count($results) . "\n");

exit($counts['FAILED'] > 0 ? 1 : 0);
