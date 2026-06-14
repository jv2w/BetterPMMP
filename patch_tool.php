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

function patchPluginManager(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/plugin/PluginManager.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PluginManager.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read PluginManager.php');
    }

    $originalContent = $content;

    $useInsertions = [];
    foreach (['strlen', 'substr', 'rtrim'] as $fn) {
        if (!str_contains($content, "use function {$fn};")) {
            $useInsertions[] = "use function {$fn};";
        }
    }
    if (count($useInsertions) > 0) {
        $useList = implode("\n", $useInsertions);
        $useAnchor = 'use function strtolower;';
        if (str_contains($content, $useAnchor)) {
            $content = str_replace($useAnchor, $useAnchor . "\n" . $useList, $content);
        } elseif (preg_match('/^use\s+function\s+[^;]+;$/m', $content, $useMatch) === 1) {
            $content = str_replace($useMatch[0], $useMatch[0] . "\n" . $useList, $content);
        }
    }

    if (!str_contains($content, 'private PluginDependencyMap $dependencyMap;')) {
        $propertyDecl = "\n\tprivate PluginDependencyMap \$dependencyMap;\n";
        $propertyAnchor = "protected array \$fileAssociations = [];\n";
        if (str_contains($content, $propertyAnchor)) {
            $content = str_replace($propertyAnchor, $propertyAnchor . $propertyDecl, $content);
        } elseif (preg_match('/class\s+PluginManager\b[^{]*\{/', $content, $classMatch) === 1) {
            $content = str_replace($classMatch[0], $classMatch[0] . $propertyDecl, $content);
        }
    }

    if (!str_contains($content, '$this->dependencyMap = new PluginDependencyMap()')) {
        $initCode = "\n\t\t\$this->dependencyMap = new PluginDependencyMap();";
        $ctorAnchor = "\t\tprivate ?PluginGraylist \$graylist = null\n\t){";
        if (str_contains($content, $ctorAnchor)) {
            $content = str_replace($ctorAnchor, $ctorAnchor . $initCode, $content);
        } elseif (preg_match('/public\s+function\s+__construct\s*\([^{]*\{/', $content, $ctorMatch, PREG_OFFSET_CAPTURE) === 1) {
            $insertPos = $ctorMatch[0][1] + strlen($ctorMatch[0][0]);
            $content = substr($content, 0, $insertPos) . $initCode . substr($content, $insertPos);
        }
    }

    if (!str_contains($content, 'public function reloadPlugin')) {
        $reloadMethod = <<<'RELOADMETHOD'

	/** [BetterPMMP-PATCH] reloadPlugin method */
	public function reloadPlugin(Plugin $plugin): bool
	{
		$pluginName = $plugin->getDescription()->getName();
		$logger = $this->server->getLogger();

		$prefixedPath = (new \ReflectionClass(PluginBase::class))->getMethod('getFile')->invoke($plugin);
		if (!\is_string($prefixedPath)) {
			$logger->critical("Failed to reload plugin {$pluginName}: invalid plugin file path");
			return false;
		}
		$loader = $plugin->getPluginLoader();
		$protocol = $loader->getAccessProtocol();
		$rawPath = $prefixedPath;
		if ($protocol !== '' && str_starts_with($prefixedPath, $protocol)) {
			$rawPath = substr($prefixedPath, strlen($protocol));
		}
		$rawPath = rtrim($rawPath, '/' . DIRECTORY_SEPARATOR);

		if (!is_dir($rawPath)) {
			$logger->critical("Cannot hot-reload plugin {$pluginName}: only directory (source) plugins are reloadable; phar/script plugins require a full server restart");
			return false;
		}

		$newDescription = $loader->getPluginDescription($rawPath);
		if ($newDescription === null) {
			$logger->critical("Failed to reload plugin {$pluginName}: could not read plugin description from {$rawPath}");
			return false;
		}

		$disabledDependents = [];
		foreach (\array_reverse($this->dependencyMap->getTransitiveDependents($pluginName)) as $depName) {
			$depPlugin = $this->plugins[$depName] ?? null;
			if ($depPlugin !== null && $depPlugin->isEnabled()) {
				$disabledDependents[] = $depName;
				try {
					$this->disablePlugin($depPlugin);
				} catch (\Throwable $e) {
					$logger->warning("Exception disabling dependent {$depName}: " . $e->getMessage());
				}
			}
		}

		try {
			$this->disablePlugin($plugin);
		} catch (\Throwable $e) {
			$logger->warning("Exception during disable of {$pluginName}: " . $e->getMessage());
		}

		$commandMap = $this->server->getCommandMap();
		$commandsToRemove = [];
		foreach ($commandMap->getCommands() as $command) {
			if ($command instanceof PluginOwned && $command->getOwningPlugin() === $plugin) {
				$commandsToRemove[] = $command;
			}
		}
		foreach ($commandsToRemove as $command) {
			$commandMap->unregister($command);
		}

		$permManager = PermissionManager::getInstance();
		$opRoot = $permManager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$everyoneRoot = $permManager->getPermission(DefaultPermissions::ROOT_USER);
		foreach ($plugin->getDescription()->getPermissions() as $permsGroup) {
			foreach ($permsGroup as $perm) {
				$opRoot?->removeChild($perm->getName());
				$everyoneRoot?->removeChild($perm->getName());
				$permManager->removePermission($perm);
			}
		}

		unset($this->plugins[$pluginName], $this->enabledPlugins[$pluginName]);

		$restore = function () use ($plugin, $pluginName, $disabledDependents, $logger): void {
			$this->plugins[$pluginName] = $plugin;
			$this->registerReloadPermissions($plugin->getDescription());
			$commandMap = $this->server->getCommandMap();
			$staleCommands = [];
			foreach ($commandMap->getCommands() as $command) {
				if ($command instanceof PluginOwned && $command->getOwningPlugin()->getDescription()->getName() === $pluginName) {
					$staleCommands[] = $command;
				}
			}
			foreach ($staleCommands as $command) {
				$commandMap->unregister($command);
			}
			$commandReflect = new \ReflectionMethod(PluginBase::class, 'registerYamlCommands');
			$commandReflect->setAccessible(true);
			$commandReflect->invoke($plugin);
			try {
				$this->enablePlugin($plugin);
			} catch (\Throwable $e) {
				$logger->critical("Rollback failed to re-enable {$pluginName}: " . $e->getMessage());
			}
			foreach (\array_reverse($disabledDependents) as $depName) {
				$depPlugin = $this->plugins[$depName] ?? null;
				if ($depPlugin !== null && !$depPlugin->isEnabled()) {
					try {
						$this->enablePlugin($depPlugin);
					} catch (\Throwable $e) {
						$logger->critical("Rollback failed to re-enable dependent {$depName}: " . $e->getMessage());
					}
				}
			}
			$this->dependencyMap->rebuild($this->plugins);
		};

		$rootNamespace = ClassCacheInvalidator::detectRootNamespace($rawPath);
		$version = ClassCacheInvalidator::invalidate($rawPath, $rootNamespace);

		$newPlugin = null;
		if ($rootNamespace !== '') {
			$mainClass = $newDescription->getMain();
			$versionedMainClass = ClassCacheInvalidator::getVersionedClassName($mainClass, $rootNamespace, $version);

			if (class_exists($versionedMainClass, false) && is_a($versionedMainClass, Plugin::class, true)) {
				$this->registerReloadPermissions($newDescription);

				$dataFolder = $this->getDataDirectory($rawPath, $newDescription->getName());
				$prefixed = $protocol . $rawPath;
				$loader->loadPlugin($prefixed);

				try {
					$newPlugin = new $versionedMainClass($loader, $this->server, $newDescription, $dataFolder, $prefixed, new DiskResourceProvider($prefixed . '/resources/'));
				} catch (\Throwable $e) {
					$logger->critical("Failed to reload plugin {$pluginName}: " . $e->getMessage());
					$logger->logException($e);
					$restore();
					return false;
				}

				$this->plugins[$newPlugin->getDescription()->getName()] = $newPlugin;
				$logger->info("Plugin {$pluginName} reloaded (version {$version})");
			} else {
				$logger->warning("Versioned class {$versionedMainClass} unavailable for {$pluginName}, using standard reload");
				try {
					$newPlugin = $this->internalLoadPlugin($rawPath, $loader, $newDescription);
				} catch (\Throwable $e) {
					$logger->critical("Failed to reload {$pluginName}: " . $e->getMessage());
					$logger->logException($e);
					$restore();
					return false;
				}
			}
		} else {
			try {
				$newPlugin = $this->internalLoadPlugin($rawPath, $loader, $newDescription);
			} catch (\Throwable $e) {
				$logger->critical("Failed to reload plugin {$pluginName}: " . $e->getMessage());
				$logger->logException($e);
				$restore();
				return false;
			}
		}

		if ($newPlugin === null) {
			$logger->critical("Failed to reload plugin {$pluginName}: internalLoadPlugin returned null");
			$restore();
			return false;
		}

		try {
			if (!$this->enablePlugin($newPlugin)) {
				$logger->critical("Failed to enable plugin {$pluginName} after reload");
				unset($this->plugins[$newPlugin->getDescription()->getName()]);
				$restore();
				return false;
			}
		} catch (\Throwable $e) {
			$logger->critical("Exception enabling plugin {$pluginName}: " . $e->getMessage());
			$logger->logException($e);
			unset($this->plugins[$newPlugin->getDescription()->getName()]);
			$restore();
			return false;
		}

		foreach (\array_reverse($disabledDependents) as $depName) {
			$depPlugin = $this->plugins[$depName] ?? null;
			if ($depPlugin !== null && !$depPlugin->isEnabled()) {
				try {
					if (!$this->enablePlugin($depPlugin)) {
						$logger->critical("Failed to re-enable dependent {$depName} after reloading {$pluginName}");
					}
				} catch (\Throwable $e) {
					$logger->critical("Exception re-enabling dependent {$depName}: " . $e->getMessage());
					$logger->logException($e);
				}
			}
		}

		$this->dependencyMap->rebuild($this->plugins);

		return true;
	}

	/** [BetterPMMP-PATCH] re-register a reloading plugin's declared permissions */
	private function registerReloadPermissions(PluginDescription $description): void
	{
		$permManager = PermissionManager::getInstance();
		$opRoot = $permManager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$everyoneRoot = $permManager->getPermission(DefaultPermissions::ROOT_USER);
		foreach (Utils::stringifyKeys($description->getPermissions()) as $default => $perms) {
			foreach ($perms as $perm) {
				if ($permManager->getPermission($perm->getName()) !== null) {
					$permManager->removePermission($perm);
				}
				$permManager->addPermission($perm);
				if ($default === PermissionParser::DEFAULT_TRUE) {
					$everyoneRoot?->addChild($perm->getName(), true);
				} elseif ($default === PermissionParser::DEFAULT_OP) {
					$opRoot?->addChild($perm->getName(), true);
				} elseif ($default === PermissionParser::DEFAULT_NOT_OP) {
					$everyoneRoot?->addChild($perm->getName(), true);
					$opRoot?->addChild($perm->getName(), false);
				}
			}
		}
	}
RELOADMETHOD;

        $tickAnchor = 'public function tickSchedulers(int $currentTick) : void';
        if (str_contains($content, $tickAnchor)) {
            $content = str_replace($tickAnchor, $reloadMethod . "\n\n\t" . $tickAnchor, $content);
        } elseif (preg_match('/public\s+function\s+tickSchedulers\s*\(\s*int\s+\$currentTick\s*\)\s*:\s*void/', $content, $tickMatch) === 1) {
            $content = str_replace($tickMatch[0], $reloadMethod . "\n\n\t" . $tickMatch[0], $content);
        }
    }

    if (!str_contains($content, '[BetterPMMP-PATCH] loadplugins-buildmap')) {
        $buildMapCode = "\n\t\t/** [BetterPMMP-PATCH] loadplugins-buildmap */\n\t\t\$this->dependencyMap->rebuild(\$this->plugins);\n";
        $guardAnchor = '$this->loadPluginsGuard = false;';
        if (str_contains($content, $guardAnchor)) {
            $content = str_replace($guardAnchor, $guardAnchor . $buildMapCode, $content);
        } elseif (preg_match('/\$this\s*->\s*loadPluginsGuard\s*=\s*false\s*;/', $content, $guardMatch) === 1) {
            $content = str_replace($guardMatch[0], $guardMatch[0] . $buildMapCode, $content);
        }
    }

    if ($content === $originalContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched PluginManager.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function createReloadPluginCommand(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/command/defaults';
    $targetFile = $targetDir . '/ReloadPluginCommand.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Command defaults directory not found');
    }

    $commandContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function count;
use function implode;

/** [BetterPMMP-PATCH] */
class ReloadPluginCommand extends VanillaCommand{

	private static string $lastPluginName = '';

	public function __construct(){
		parent::__construct(
			"reload",
			"Reload a plugin",
			"/reload <pluginName>"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_RELOAD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if($sender instanceof Player && !$sender->getServer()->isOp($sender->getName())){
			$sender->sendMessage('§cYou don\'t have permission to reload.');
			return true;
		}

		$pluginName = count($args) > 0 ? implode(" ", $args) : self::$lastPluginName;

		if($pluginName === ''){
			$sender->sendMessage('§cUsage: /reload <pluginName>');
			return true;
		}

		$plugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

		if($plugin === null){
			$sender->sendMessage('§cCan\'t find plugin.');
			return true;
		}

		self::$lastPluginName = $pluginName;

		try{
			$result = $sender->getServer()->getPluginManager()->reloadPlugin($plugin);
		}catch(\Throwable $e){
			$sender->getServer()->getLogger()->logException($e);
			$result = false;
		}

		if($result){
			$sender->sendMessage('§aPlugin reloaded successfully.');
		}else{
			$sender->sendMessage('§cFailed to reload plugin.');
		}

		return true;
	}

}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $commandContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $commandContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write ReloadPluginCommand.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchSimpleCommandMap(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/command/SimpleCommandMap.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'SimpleCommandMap.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read SimpleCommandMap.php');
    }

    if (str_contains($content, 'new ReloadPluginCommand()')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (!str_contains($content, 'use pocketmine\command\defaults\ReloadPluginCommand;')) {
        $useAnchor = 'use pocketmine\command\defaults\PluginsCommand;';
        $content = str_replace(
            $useAnchor,
            $useAnchor . "\nuse pocketmine\\command\\defaults\\ReloadPluginCommand; /** [BetterPMMP-PATCH] */",
            $content,
            $useCount
        );
        if ($useCount !== 1) {
            return new PatchResult($targetFile, PatchStatus::FAILED, 'PluginsCommand use anchor not found in SimpleCommandMap.php');
        }
    }

    $registerAnchor = 'new PluginsCommand(),';
    $content = str_replace(
        $registerAnchor,
        $registerAnchor . "\n\t\t\tnew ReloadPluginCommand(),",
        $content,
        $registerCount
    );
    if ($registerCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'PluginsCommand registration anchor not found in SimpleCommandMap.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched SimpleCommandMap.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function createClassCacheInvalidator(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/plugin';
    $targetFile = $targetDir . '/ClassCacheInvalidator.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Plugin directory not found');
    }

    $classContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

/** [BetterPMMP-PATCH] */

namespace pocketmine\plugin;

use function count;
use function explode;
use function file_get_contents;
use function function_exists;
use function implode;
use function is_dir;
use function min;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

class ClassCacheInvalidator
{

	private static int $reloadVersion = 0;

	public static function invalidate(string $pluginPath, string $rootNamespace): int
	{
		$files = self::scanPhpFiles($pluginPath);
		if (count($files) === 0) {
			return self::$reloadVersion;
		}

		++self::$reloadVersion;
		$version = self::$reloadVersion;

		if (function_exists('opcache_invalidate')) {
			foreach ($files as $filePath) {
				opcache_invalidate($filePath, true);
			}
		}

		if ($rootNamespace !== '') {
			$remaining = $files;
			for ($pass = 0; $pass < 10 && count($remaining) > 0; $pass++) {
				$failed = [];
				foreach ($remaining as $filePath) {
					if (!self::evalWithVersionedNamespace($filePath, $version, $rootNamespace)) {
						$failed[] = $filePath;
					}
				}
				if (count($failed) === count($remaining)) {
					foreach ($failed as $failedPath) {
						\pocketmine\Server::getInstance()->getLogger()->warning(
							"[BetterPMMP] Failed to load versioned class from {$failedPath}"
						);
					}
					break;
				}
				$remaining = $failed;
			}
		}

		return $version;
	}

	public static function getVersionedClassName(string $originalClass, string $rootNamespace, int $version): string
	{
		if ($rootNamespace === '' || $version === 0) {
			return $originalClass;
		}
		$prefix = $rootNamespace . '\\';
		if (str_starts_with($originalClass, $prefix)) {
			return $rootNamespace . '\\v' . $version . '\\' . substr($originalClass, strlen($prefix));
		}
		if ($originalClass === $rootNamespace) {
			return $rootNamespace . '\\v' . $version;
		}
		return $originalClass;
	}

	public static function detectRootNamespace(string $pluginPath): string
	{
		$namespaces = [];
		foreach (self::scanPhpFiles($pluginPath) as $filePath) {
			$source = @file_get_contents($filePath);
			if ($source !== false && preg_match('/^\s*namespace\s+([^\s;{]+)/m', $source, $m) === 1) {
				$namespaces[] = $m[1];
			}
		}

		if (count($namespaces) === 0) {
			return '';
		}
		if (count($namespaces) === 1) {
			return $namespaces[0];
		}

		$commonParts = explode('\\', $namespaces[0]);
		foreach ($namespaces as $ns) {
			$parts = explode('\\', $ns);
			$newCommon = [];
			for ($i = 0; $i < min(count($commonParts), count($parts)); $i++) {
				if ($commonParts[$i] === $parts[$i]) {
					$newCommon[] = $commonParts[$i];
				} else {
					break;
				}
			}
			$commonParts = $newCommon;
			if (count($commonParts) === 0) {
				break;
			}
		}

		return implode('\\', $commonParts);
	}

	/** @return list<string> */
	private static function scanPhpFiles(string $pluginPath): array
	{
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;
		if (!is_dir($scanTarget)) {
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);
		foreach ($iterator as $filePath) {
			if (\is_string($filePath) && str_ends_with($filePath, '.php')) {
				$files[] = $filePath;
			}
		}

		return $files;
	}

	private static function evalWithVersionedNamespace(string $filePath, int $version, string $rootNamespace): bool
	{
		$source = file_get_contents($filePath);
		if ($source === false) {
			return true;
		}

		$source = self::applyVersionToSource($source, $version, $rootNamespace);

		$source = preg_replace('/^<\?php\s*/i', '', $source);
		if ($source === null) {
			return true;
		}

		try {
			eval($source);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private static function applyVersionToSource(string $source, int $version, string $rootNamespace): string
	{
		$insert = '\\v' . $version;
		$prefix = $rootNamespace . '\\';
		$result = '';
		$afterNamespace = false;
		foreach (\token_get_all($source) as $token) {
			if (!\is_array($token)) {
				$afterNamespace = false;
				$result .= $token;
				continue;
			}
			$id = $token[0];
			$text = $token[1];
			if ($id === \T_WHITESPACE || $id === \T_COMMENT || $id === \T_DOC_COMMENT) {
				$result .= $text;
				continue;
			}
			if ($id === \T_NAMESPACE) {
				$afterNamespace = true;
				$result .= $text;
				continue;
			}
			if ($id === \T_NAME_QUALIFIED || $id === \T_NAME_FULLY_QUALIFIED) {
				$result .= self::versionName($text, $rootNamespace, $prefix, $insert);
				$afterNamespace = false;
				continue;
			}
			if ($id === \T_STRING && $afterNamespace && $text === $rootNamespace) {
				$result .= $text . $insert;
				$afterNamespace = false;
				continue;
			}
			$afterNamespace = false;
			$result .= $text;
		}
		return $result;
	}

	private static function versionName(string $name, string $rootNamespace, string $prefix, string $insert): string
	{
		$leading = '';
		$bare = $name;
		if (str_starts_with($bare, '\\')) {
			$leading = '\\';
			$bare = substr($bare, 1);
		}
		if ($bare === $rootNamespace) {
			return $leading . $bare . $insert;
		}
		if (str_starts_with($bare, $prefix)) {
			return $leading . $rootNamespace . $insert . '\\' . substr($bare, strlen($prefix));
		}
		return $name;
	}
}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $classContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $classContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write ClassCacheInvalidator.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function createPluginDependencyMap(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/plugin';
    $targetFile = $targetDir . '/PluginDependencyMap.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Plugin directory not found');
    }

    $classContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

/** [BetterPMMP-PATCH] */

namespace pocketmine\plugin;

final class PluginDependencyMap
{

    /** @phpstan-var array<string, list<string>> */
    private array $reverseDependencyMap = [];

    /**
     * @param Plugin[] $plugins
     * @phpstan-param array<string, Plugin> $plugins
     */
    public function rebuild(array $plugins): void
    {
        $this->reverseDependencyMap = [];

        foreach ($plugins as $plugin) {
            $description = $plugin->getDescription();
            $pluginName = $description->getName();
            foreach ([...$description->getDepend(), ...$description->getSoftDepend()] as $depName) {
                $this->reverseDependencyMap[$depName][] = $pluginName;
            }
        }
    }

    /** @return list<string> */
    public function getTransitiveDependents(string $pluginName): array
    {
        $inSet = [];
        $queue = [$pluginName];
        for ($i = 0; $i < \count($queue); ++$i) {
            foreach ($this->reverseDependencyMap[$queue[$i]] ?? [] as $dependent) {
                if ($dependent !== $pluginName && !isset($inSet[$dependent])) {
                    $inSet[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }
        if (\count($inSet) === 0) {
            return [];
        }

        $indegree = [];
        foreach ($inSet as $name => $ignored) {
            $indegree[$name] = 0;
        }
        foreach ($indegree as $name => $ignored) {
            foreach ($this->reverseDependencyMap[$name] ?? [] as $dependent) {
                if (isset($indegree[$dependent])) {
                    ++$indegree[$dependent];
                }
            }
        }

        $ordered = [];
        $ready = [];
        foreach ($indegree as $name => $degree) {
            if ($degree === 0) {
                $ready[] = $name;
            }
        }
        for ($i = 0; $i < \count($ready); ++$i) {
            $name = $ready[$i];
            $ordered[] = $name;
            foreach ($this->reverseDependencyMap[$name] ?? [] as $dependent) {
                if (isset($indegree[$dependent])) {
                    --$indegree[$dependent];
                    if ($indegree[$dependent] === 0) {
                        $ready[] = $dependent;
                    }
                }
            }
        }

        foreach ($indegree as $name => $degree) {
            if ($degree > 0) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }
}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $classContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $classContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write PluginDependencyMap.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchReloadPermission(string $sourceDir): PatchResult
{
    $targets = [
        [
            $sourceDir . '/src/permission/DefaultPermissionNames.php',
            'COMMAND_RELOAD',
            'public const COMMAND_PLUGINS = "pocketmine.command.plugins";',
            "public const COMMAND_PLUGINS = \"pocketmine.command.plugins\";\n\tpublic const COMMAND_RELOAD = \"pocketmine.command.reload\";",
        ],
        [
            $sourceDir . '/src/permission/DefaultPermissions.php',
            'Names::COMMAND_RELOAD',
            'Names::COMMAND_PLUGINS,',
            "Names::COMMAND_PLUGINS,\n\t\t\tNames::COMMAND_RELOAD,",
        ],
        [
            $sourceDir . '/generated/lang/KnownTranslationKeys.php',
            'POCKETMINE_PERMISSION_COMMAND_RELOAD',
            'public const POCKETMINE_PERMISSION_COMMAND_PLUGINS = "pocketmine.permission.command.plugins";',
            "public const POCKETMINE_PERMISSION_COMMAND_PLUGINS = \"pocketmine.permission.command.plugins\";\n\tpublic const POCKETMINE_PERMISSION_COMMAND_RELOAD = \"pocketmine.permission.command.reload\";",
        ],
        [
            $sourceDir . '/generated/lang/KnownTranslationParameterInfo.php',
            'POCKETMINE_PERMISSION_COMMAND_RELOAD',
            'Keys::POCKETMINE_PERMISSION_COMMAND_PLUGINS => [],',
            "Keys::POCKETMINE_PERMISSION_COMMAND_PLUGINS => [],\n\t\tKeys::POCKETMINE_PERMISSION_COMMAND_RELOAD => [],",
        ],
        [
            $sourceDir . '/resources/translations/eng.ini',
            'pocketmine.permission.command.reload=',
            'pocketmine.permission.command.plugins=Allows the user to view the list of plugins',
            "pocketmine.permission.command.plugins=Allows the user to view the list of plugins\npocketmine.permission.command.reload=Allows the user to reload a plugin",
        ],
    ];

    $applied = false;
    foreach ($targets as [$file, $marker, $old, $new]) {
        $fileLabel = basename($file);
        if (!file_exists($file)) {
            return new PatchResult($file, PatchStatus::FAILED, "{$fileLabel} not found");
        }
        $content = patchRead($file);
        if ($content === false) {
            return new PatchResult($file, PatchStatus::FAILED, "Failed to read {$fileLabel}");
        }
        if (str_contains($content, $marker)) {
            continue;
        }
        $content = str_replace($old, $new, $content, $count);
        if ($count !== 1) {
            return new PatchResult($file, PatchStatus::FAILED, "Reload permission anchor not found in {$fileLabel}");
        }
        if (patchWrite($file, $content) === false) {
            return new PatchResult($file, PatchStatus::FAILED, "Failed to write {$fileLabel}");
        }
        $applied = true;
    }

    return new PatchResult($targets[0][0], $applied ? PatchStatus::APPLIED : PatchStatus::SKIPPED);
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

function patchYmlServerPropertiesBetterPmmp(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/generated/YmlServerProperties.php';

    $old = "\tpublic const WORLDS = 'worlds';\n}";

    $new = "\t/** [BetterPMMP-PATCH] BetterPMMP optimization config constants */\n"
        . "\tpublic const BETTER_PMMP = 'better-pmmp';\n"
        . "\tpublic const BETTER_PMMP_FIXED_LIGHT = 'better-pmmp.fixed-light';\n"
        . "\tpublic const BETTER_PMMP_FIXED_LIGHT_ENABLED = 'better-pmmp.fixed-light.enabled';\n"
        . "\tpublic const BETTER_PMMP_FIXED_LIGHT_LEVEL = 'better-pmmp.fixed-light.level';\n"
        . "\tpublic const BETTER_PMMP_PER_WORLD_VIEW_DISTANCE = 'better-pmmp.per-world-view-distance';\n"
        . "\tpublic const BETTER_PMMP_CHUNK_OPTIMIZATION = 'better-pmmp.chunk-optimization';\n"
        . "\tpublic const BETTER_PMMP_CHUNK_OPTIMIZATION_BATCH_RECHECK_LIMIT = 'better-pmmp.chunk-optimization.batch-recheck-limit';\n"
        . "\tpublic const BETTER_PMMP_PER_WORLD_CHUNK_TICKING = 'better-pmmp.per-world-chunk-ticking';\n"
        . "\tpublic const BETTER_PMMP_NEIGHBOUR_UPDATE_LIMIT = 'better-pmmp.neighbour-update-limit';\n"
        . "\tpublic const BETTER_PMMP_BLOCK_CACHE_SIZE = 'better-pmmp.block-cache-size';\n"
        . "\n"
        . "\tpublic const WORLDS = 'worlds';\n}";

    return applyReplacePatch($targetFile, 'BETTER_PMMP', $old, $new, 'Failed to match WORLDS constant pattern in YmlServerProperties.php');
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

    if (str_contains($content, 'lastBlockFloorX')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }
    $content = str_replace(
        "\tprotected ?array \$blocksAround = null;",
        "\tprotected ?array \$blocksAround = null;\n"
        . "\t/** [BetterPMMP-PATCH] Smart blocksAround cache tracking */\n"
        . "\tprivate int \$lastBlockFloorX = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockFloorY = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockFloorZ = PHP_INT_MIN;",
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

    $new = "\t\t/** [BetterPMMP-PATCH] Smart blocksAround cache - invalidate only when block grid position changes post-move */\n"
        . "\t\t\$newFloorX = (int) floor(\$this->location->x);\n"
        . "\t\t\$newFloorY = (int) floor(\$this->location->y);\n"
        . "\t\t\$newFloorZ = (int) floor(\$this->location->z);\n"
        . "\t\tif(\$newFloorX !== \$this->lastBlockFloorX || \$newFloorY !== \$this->lastBlockFloorY || \$newFloorZ !== \$this->lastBlockFloorZ){\n"
        . "\t\t\t\$this->blocksAround = null;\n"
        . "\t\t\t\$this->lastBlockFloorX = \$newFloorX;\n"
        . "\t\t\t\$this->lastBlockFloorY = \$newFloorY;\n"
        . "\t\t\t\$this->lastBlockFloorZ = \$newFloorZ;\n"
        . "\t\t}\n"
        . "\t\t\$this->getWorld()->onEntityMoved(\$this);\n"
        . "\t\t\$this->checkBlockIntersections();";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content || !str_contains($newContent, 'private int $lastBlockFloorX')) {
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
    # Minimum fall distance required for a critical hit. Vanilla: 0.5. Set to 0.0 to trigger even from ground.
    min-fall-distance: 0.5
YAML;

    return applyReplacePatch(
        $sourceDir . '/resources/pocketmine.yml',
        'ignore-sprint:',
        $anchor,
        $anchor . "\n" . $critBlock,
        'better-pmmp anchor (block-cache-size) not found in pocketmine.yml'
    );
}

function patchYmlServerPropertiesCriticalHit(string $sourceDir): PatchResult
{
    $old = "\tpublic const WORLDS = 'worlds';\n}";
    $new = "\t/** [BetterPMMP-PATCH] Critical hit config constants */\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT = 'better-pmmp.critical-hit';\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT_IGNORE_SPRINT = 'better-pmmp.critical-hit.ignore-sprint';\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT_MIN_FALL_DISTANCE = 'better-pmmp.critical-hit.min-fall-distance';\n"
        . "\n"
        . "\tpublic const WORLDS = 'worlds';\n}";

    return applyReplacePatch(
        $sourceDir . '/generated/YmlServerProperties.php',
        'BETTER_PMMP_CRITICAL_HIT_IGNORE_SPRINT',
        $old,
        $new,
        'Failed to match WORLDS constant in YmlServerProperties.php'
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
        . $indent . "\$critMinFall = (float) \$config->getProperty('better-pmmp.critical-hit.min-fall-distance', 0.5);\n"
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

function patchNetworkSessionSetHandlerGuard(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/NetworkSession.php';

    $old = "\tpublic function setHandler(?PacketHandler \$handler) : void{\n"
        . "\t\tif(\$this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases";

    $new = "\t/** [BetterPMMP-PATCH] setHandler disconnect guard - prevents handler assignment during disconnect cleanup */\n"
        . "\tpublic function setHandler(?PacketHandler \$handler) : void{\n"
        . "\t\tif(\$this->connected && !\$this->disconnectGuard){";

    return applyReplacePatch($targetFile, 'setHandler disconnect guard', $old, $new, 'Failed to match setHandler() in NetworkSession.php');
}

function patchPlayerRespawnLockReset(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'Respawn lock reset on disconnect')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $old1 = "\t\t\tfunction(Position \$safeSpawn) : void{\n"
        . "\t\t\t\tif(!\$this->isConnected()){\n"
        . "\t\t\t\t\treturn;\n"
        . "\t\t\t\t}";

    $new1 = "\t\t\tfunction(Position \$safeSpawn) : void{\n"
        . "\t\t\t\tif(!\$this->isConnected()){\n"
        . "\t\t\t\t\t/** [BetterPMMP-PATCH] Respawn lock reset on disconnect */\n"
        . "\t\t\t\t\t\$this->respawnLocked = false;\n"
        . "\t\t\t\t\treturn;\n"
        . "\t\t\t\t}";

    $newContent = str_replace($old1, $new1, $content);
    if ($newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match respawn success callback in Player.php');
    }

    $old2 = "\t\t\tfunction() : void{\n"
        . "\t\t\t\tif(\$this->isConnected()){\n"
        . "\t\t\t\t\t\$this->getNetworkSession()->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_respawn());";

    $new2 = "\t\t\tfunction() : void{\n"
        . "\t\t\t\t/** [BetterPMMP-PATCH] Respawn lock reset on error */\n"
        . "\t\t\t\t\$this->respawnLocked = false;\n"
        . "\t\t\t\tif(\$this->isConnected()){\n"
        . "\t\t\t\t\t\$this->getNetworkSession()->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_respawn());";

    $newContent = str_replace($old2, $new2, $newContent);
    if ($newContent === $content || !str_contains($newContent, 'Respawn lock reset on error')) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to match respawn error callback in Player.php');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php (respawn lock reset)');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchHandlerListMergePerformance(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/event/HandlerList.php';

    $old = "\t\t\$listenersByPriority = [];\n"
        . "\t\tforeach(\$handlerLists as \$currentList){\n"
        . "\t\t\tforeach(\$currentList->handlerSlots as \$priority => \$listeners){\n"
        . "\t\t\t\t\$listenersByPriority[\$priority] = array_merge(\$listenersByPriority[\$priority] ?? [], \$listeners);\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t//TODO: why on earth do the priorities have higher values for lower priority?\n"
        . "\t\tkrsort(\$listenersByPriority, SORT_NUMERIC);\n"
        . "\n"
        . "\t\treturn \$this->handlerCache->list = array_merge(...\$listenersByPriority);";

    $new = "\t\t/** [BetterPMMP-PATCH] Single-pass handler list merge - O(n) instead of O(n^2) */\n"
        . "\t\t\$listenersByPriority = [];\n"
        . "\t\tforeach(\$handlerLists as \$currentList){\n"
        . "\t\t\tforeach(\$currentList->handlerSlots as \$priority => \$listeners){\n"
        . "\t\t\t\t\$listenersByPriority[\$priority][] = \$listeners;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\n"
        . "\t\tkrsort(\$listenersByPriority, SORT_NUMERIC);\n"
        . "\n"
        . "\t\t\$result = [];\n"
        . "\t\tforeach(\$listenersByPriority as \$listenersArrays){\n"
        . "\t\t\tforeach(\$listenersArrays as \$listeners){\n"
        . "\t\t\t\tforeach(\$listeners as \$listener){\n"
        . "\t\t\t\t\t\$result[] = \$listener;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\treturn \$this->handlerCache->list = \$result;";

    return applyReplacePatch($targetFile, 'Single-pass handler list merge', $old, $new, 'Failed to match getListenerList() merge in HandlerList.php');
}

function patchNetworkSessionDisconnectGuardTiming(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/NetworkSession.php';

    $old = "\t\t\t\$this->disconnectGuard = true;\n"
        . "\t\t\t\$func();\n"
        . "\t\t\t\$this->disconnectGuard = false;\n"
        . "\t\t\t\$this->flushGamePacketQueue();";

    $new = "\t\t\t\$this->disconnectGuard = true;\n"
        . "\t\t\t\$func();\n"
        . "\t\t\t/** [BetterPMMP-PATCH] Keep disconnectGuard active through full cleanup - session is never reused */\n"
        . "\t\t\t\$this->flushGamePacketQueue();";

    return applyReplacePatch($targetFile, 'Keep disconnectGuard active through full cleanup', $old, $new, 'Failed to match tryDisconnect() guard reset in NetworkSession.php');
}

function patchClassMapAuthoritative(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/vendor/composer/autoload_real.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'autoload_real.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read autoload_real.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] classmap-authoritative disabled')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (!str_contains($content, '$loader->setClassMapAuthoritative(true);')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $newContent = str_replace(
        '$loader->setClassMapAuthoritative(true);',
        '/** [BetterPMMP-PATCH] classmap-authoritative disabled to allow PSR-4 fallback for patched classes */' . "\n        \$loader->setClassMapAuthoritative(false);",
        $content
    );

    if ($newContent === $content) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to patch autoload_real.php');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched autoload_real.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlFpsOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    $anchor = "  block-cache-size: 8192";
    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] Client-side FPS optimization\n"
        . "  # Reduces redundant/out-of-range packets sent to players to relieve client frame drops.\n"
        . "  # All filters preserve gameplay correctness - teleport and forced sync bypass every filter.\n"
        . "  fps-optimization:\n"
        . "    # Skip Entity broadcastMovement/broadcastMotion for viewers beyond motion-distance blocks.\n"
        . "    # Also suppresses redundant broadcasts when position/rotation/motion did not actually change.\n"
        . "    entity-broadcast:\n"
        . "      enabled: true\n"
        . "      motion-distance: 96\n"
        . "      position-epsilon: 0.001\n"
        . "      rotation-epsilon: 0.5\n"
        . "      motion-epsilon: 0.0001\n"
        . "    # World::addParticle / addSound viewer filtering by squared distance.\n"
        . "    particle-sound:\n"
        . "      enabled: true\n"
        . "      particle-distance: 48\n"
        . "      sound-distance: 32\n"
        . "    # Entity::broadcastAnimation viewer filtering.\n"
        . "    animation:\n"
        . "      enabled: true\n"
        . "      distance: 64\n"
        . "    # Smooth chunk delivery for the first ramp-up-ticks ticks after teleport/spawn.\n"
        . "    chunk-pacing:\n"
        . "      enabled: true\n"
        . "      initial-chunks-per-tick: 2\n"
        . "      ramp-up-ticks: 20\n"
        . "    # When a chunk has more than threshold-per-chunk ItemEntities, suppress broadcastMovement\n"
        . "    # for items that are stationary on the ground. Pickup and merge still work normally.\n"
        . "    item-entity:\n"
        . "      enabled: true\n"
        . "      threshold-per-chunk: 16";

    return applyReplacePatch($targetFile, 'fps-optimization:', $anchor, $insertion, 'better-pmmp anchor (block-cache-size) not found in pocketmine.yml');
}

function patchYmlServerPropertiesFpsOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/generated/YmlServerProperties.php';

    $anchor = "\tpublic const BETTER_PMMP_BLOCK_CACHE_SIZE = 'better-pmmp.block-cache-size';\n";
    $insert = $anchor
        . "\t/** [BetterPMMP-PATCH] FPS optimization config constants */\n"
        . "\tpublic const BETTER_PMMP_FPS_OPTIMIZATION = 'better-pmmp.fps-optimization';\n"
        . "\tpublic const BETTER_PMMP_FPS_ENTITY_BROADCAST_ENABLED = 'better-pmmp.fps-optimization.entity-broadcast.enabled';\n"
        . "\tpublic const BETTER_PMMP_FPS_ENTITY_BROADCAST_MOTION_DISTANCE = 'better-pmmp.fps-optimization.entity-broadcast.motion-distance';\n"
        . "\tpublic const BETTER_PMMP_FPS_ENTITY_BROADCAST_POSITION_EPSILON = 'better-pmmp.fps-optimization.entity-broadcast.position-epsilon';\n"
        . "\tpublic const BETTER_PMMP_FPS_ENTITY_BROADCAST_ROTATION_EPSILON = 'better-pmmp.fps-optimization.entity-broadcast.rotation-epsilon';\n"
        . "\tpublic const BETTER_PMMP_FPS_ENTITY_BROADCAST_MOTION_EPSILON = 'better-pmmp.fps-optimization.entity-broadcast.motion-epsilon';\n"
        . "\tpublic const BETTER_PMMP_FPS_PARTICLE_SOUND_ENABLED = 'better-pmmp.fps-optimization.particle-sound.enabled';\n"
        . "\tpublic const BETTER_PMMP_FPS_PARTICLE_SOUND_PARTICLE_DISTANCE = 'better-pmmp.fps-optimization.particle-sound.particle-distance';\n"
        . "\tpublic const BETTER_PMMP_FPS_PARTICLE_SOUND_SOUND_DISTANCE = 'better-pmmp.fps-optimization.particle-sound.sound-distance';\n"
        . "\tpublic const BETTER_PMMP_FPS_ANIMATION_ENABLED = 'better-pmmp.fps-optimization.animation.enabled';\n"
        . "\tpublic const BETTER_PMMP_FPS_ANIMATION_DISTANCE = 'better-pmmp.fps-optimization.animation.distance';\n"
        . "\tpublic const BETTER_PMMP_FPS_CHUNK_PACING_ENABLED = 'better-pmmp.fps-optimization.chunk-pacing.enabled';\n"
        . "\tpublic const BETTER_PMMP_FPS_CHUNK_PACING_INITIAL = 'better-pmmp.fps-optimization.chunk-pacing.initial-chunks-per-tick';\n"
        . "\tpublic const BETTER_PMMP_FPS_CHUNK_PACING_RAMP_TICKS = 'better-pmmp.fps-optimization.chunk-pacing.ramp-up-ticks';\n"
        . "\tpublic const BETTER_PMMP_FPS_ITEM_ENTITY_ENABLED = 'better-pmmp.fps-optimization.item-entity.enabled';\n"
        . "\tpublic const BETTER_PMMP_FPS_ITEM_ENTITY_THRESHOLD = 'better-pmmp.fps-optimization.item-entity.threshold-per-chunk';\n";

    return applyReplacePatch($targetFile, 'BETTER_PMMP_FPS_OPTIMIZATION', $anchor, $insert, 'BETTER_PMMP_BLOCK_CACHE_SIZE anchor not found');
}

function patchFpsEntityBroadcastOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Entity.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'filterFpsBroadcastViewers')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldMove = "\tprotected function broadcastMovement(bool \$teleport = false) : void{\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$this->hasSpawned, [MoveActorAbsolutePacket::create(\n"
        . "\t\t\t\$this->id,\n"
        . "\t\t\t\$this->getOffsetPosition(\$this->location),\n"
        . "\t\t\t\$this->location->pitch,\n"
        . "\t\t\t\$this->location->yaw,\n"
        . "\t\t\t\$this->location->yaw,\n"
        . "\t\t\t(\n"
        . "\t\t\t\t//TODO: We should be setting FLAG_TELEPORT here to disable client-side movement interpolation, but it\n"
        . "\t\t\t\t//breaks player teleporting (observers see the player rubberband back to the pre-teleport position while\n"
        . "\t\t\t\t//the teleported player sees themselves at the correct position), and does nothing whatsoever for\n"
        . "\t\t\t\t//non-player entities (movement is still interpolated). Both of these are client bugs.\n"
        . "\t\t\t\t//See https://github.com/pmmp/PocketMine-MP/issues/4394\n"
        . "\t\t\t\t(\$this->onGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)\n"
        . "\t\t\t)\n"
        . "\t\t)]);\n"
        . "\t}";

    $newMove = "\tprotected function broadcastMovement(bool \$teleport = false) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: redundancy check + distance filter */\n"
        . "\t\t\$fpsConfig = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\t\$fpsEnabled = (bool) \$fpsConfig->getProperty('better-pmmp.fps-optimization.entity-broadcast.enabled', true);\n"
        . "\t\t\$fpsGround = \$this->onGround;\n"
        . "\t\t\$fpsX = \$this->location->x; \$fpsY = \$this->location->y; \$fpsZ = \$this->location->z;\n"
        . "\t\t\$fpsYaw = \$this->location->yaw; \$fpsPitch = \$this->location->pitch;\n"
        . "\t\tif(!\$teleport && \$fpsEnabled && \$this->fpsRedundantMovementSuppressed(\$fpsConfig, \$fpsX, \$fpsY, \$fpsZ, \$fpsYaw, \$fpsPitch, \$fpsGround)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$fpsTargets = (\$fpsEnabled && !\$teleport) ? \$this->filterFpsBroadcastViewers(\$fpsConfig, \$this->hasSpawned) : \$this->hasSpawned;\n"
        . "\t\tif(count(\$fpsTargets) > 0){\n"
        . "\t\t\tNetworkBroadcastUtils::broadcastPackets(\$fpsTargets, [MoveActorAbsolutePacket::create(\n"
        . "\t\t\t\t\$this->id,\n"
        . "\t\t\t\t\$this->getOffsetPosition(\$this->location),\n"
        . "\t\t\t\t\$fpsPitch,\n"
        . "\t\t\t\t\$fpsYaw,\n"
        . "\t\t\t\t\$fpsYaw,\n"
        . "\t\t\t\t(\$fpsGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)\n"
        . "\t\t\t)]);\n"
        . "\t\t}\n"
        . "\t\t\$this->fpsLastBroadcastX = \$fpsX; \$this->fpsLastBroadcastY = \$fpsY; \$this->fpsLastBroadcastZ = \$fpsZ;\n"
        . "\t\t\$this->fpsLastBroadcastYaw = \$fpsYaw; \$this->fpsLastBroadcastPitch = \$fpsPitch; \$this->fpsLastBroadcastGround = \$fpsGround;\n"
        . "\t}";

    $content = str_replace($oldMove, $newMove, $content, $count1);
    if ($count1 !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'broadcastMovement match failed');
    }

    $oldMotion = "\tprotected function broadcastMotion() : void{\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$this->hasSpawned, [SetActorMotionPacket::create(\$this->id, \$this->getMotion(), tick: 0)]);\n"
        . "\t}";
    $newMotion = "\tprotected function broadcastMotion() : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: motion redundancy + distance filter */\n"
        . "\t\t\$fpsConfig = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\t\$fpsEnabled = (bool) \$fpsConfig->getProperty('better-pmmp.fps-optimization.entity-broadcast.enabled', true);\n"
        . "\t\t\$fpsMotion = \$this->getMotion();\n"
        . "\t\tif(\$fpsEnabled && \$this->fpsRedundantMotionSuppressed(\$fpsConfig, \$fpsMotion->x, \$fpsMotion->y, \$fpsMotion->z)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$fpsTargets = \$fpsEnabled ? \$this->filterFpsBroadcastViewers(\$fpsConfig, \$this->hasSpawned) : \$this->hasSpawned;\n"
        . "\t\t\$this->fpsLastBroadcastMotionX = \$fpsMotion->x; \$this->fpsLastBroadcastMotionY = \$fpsMotion->y; \$this->fpsLastBroadcastMotionZ = \$fpsMotion->z;\n"
        . "\t\tif(count(\$fpsTargets) === 0) return;\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$fpsTargets, [SetActorMotionPacket::create(\$this->id, \$fpsMotion, tick: 0)]);\n"
        . "\t}";
    $content = str_replace($oldMotion, $newMotion, $content, $count2);
    if ($count2 !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'broadcastMotion match failed');
    }

    $helperAnchor = "\tpublic function getOffsetPosition(Vector3 \$vector3) : Vector3{\n"
        . "\t\treturn \$vector3;\n"
        . "\t}";
    $helperBlock = $helperAnchor . "\n"
        . "\n"
        . "\t/** [BetterPMMP-PATCH] FPS optimization state */\n"
        . "\tprotected float \$fpsLastBroadcastX = NAN;\n"
        . "\tprotected float \$fpsLastBroadcastY = NAN;\n"
        . "\tprotected float \$fpsLastBroadcastZ = NAN;\n"
        . "\tprotected float \$fpsLastBroadcastYaw = NAN;\n"
        . "\tprotected float \$fpsLastBroadcastPitch = NAN;\n"
        . "\tprotected bool \$fpsLastBroadcastGround = false;\n"
        . "\tprotected float \$fpsLastBroadcastMotionX = 0.0;\n"
        . "\tprotected float \$fpsLastBroadcastMotionY = 0.0;\n"
        . "\tprotected float \$fpsLastBroadcastMotionZ = 0.0;\n"
        . "\n"
        . "\t/**\n"
        . "\t * @param array<int, \\pocketmine\\player\\Player> \$viewers\n"
        . "\t * @return array<int, \\pocketmine\\player\\Player>\n"
        . "\t */\n"
        . "\tprivate function filterFpsBroadcastViewers(\\pocketmine\\ServerConfigGroup \$config, array \$viewers) : array{\n"
        . "\t\tif(count(\$viewers) === 0){\n"
        . "\t\t\treturn \$viewers;\n"
        . "\t\t}\n"
        . "\t\t\$maxDist = (float) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.motion-distance', 96);\n"
        . "\t\tif(\$maxDist <= 0){\n"
        . "\t\t\treturn \$viewers;\n"
        . "\t\t}\n"
        . "\t\t\$maxSq = \$maxDist * \$maxDist;\n"
        . "\t\t\$ex = \$this->location->x;\n"
        . "\t\t\$ez = \$this->location->z;\n"
        . "\t\t\$out = [];\n"
        . "\t\tforeach(\$viewers as \$k => \$pl){\n"
        . "\t\t\t\$loc = \$pl->getLocation();\n"
        . "\t\t\t\$dx = \$loc->x - \$ex;\n"
        . "\t\t\t\$dz = \$loc->z - \$ez;\n"
        . "\t\t\tif(\$dx * \$dx + \$dz * \$dz <= \$maxSq){\n"
        . "\t\t\t\t\$out[\$k] = \$pl;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\treturn \$out;\n"
        . "\t}\n"
        . "\n"
        . "\tprivate function fpsRedundantMovementSuppressed(\\pocketmine\\ServerConfigGroup \$config, float \$x, float \$y, float \$z, float \$yaw, float \$pitch, bool \$ground) : bool{\n"
        . "\t\tif(is_nan(\$this->fpsLastBroadcastX) || \$ground !== \$this->fpsLastBroadcastGround){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}\n"
        . "\t\t\$posEps = (float) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.position-epsilon', 0.001);\n"
        . "\t\t\$rotEps = (float) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.rotation-epsilon', 0.5);\n"
        . "\t\t\$dx = \$x - \$this->fpsLastBroadcastX; \$dy = \$y - \$this->fpsLastBroadcastY; \$dz = \$z - \$this->fpsLastBroadcastZ;\n"
        . "\t\tif(\$dx * \$dx + \$dy * \$dy + \$dz * \$dz > \$posEps * \$posEps){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}\n"
        . "\t\t\$dyaw = \$yaw - \$this->fpsLastBroadcastYaw;\n"
        . "\t\t\$dpitch = \$pitch - \$this->fpsLastBroadcastPitch;\n"
        . "\t\tif(\$dyaw < 0) \$dyaw = -\$dyaw;\n"
        . "\t\tif(\$dpitch < 0) \$dpitch = -\$dpitch;\n"
        . "\t\treturn \$dyaw <= \$rotEps && \$dpitch <= \$rotEps;\n"
        . "\t}\n"
        . "\n"
        . "\tprivate function fpsRedundantMotionSuppressed(\\pocketmine\\ServerConfigGroup \$config, float \$mx, float \$my, float \$mz) : bool{\n"
        . "\t\t\$eps = (float) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.motion-epsilon', 0.0001);\n"
        . "\t\t\$dx = \$mx - \$this->fpsLastBroadcastMotionX;\n"
        . "\t\t\$dy = \$my - \$this->fpsLastBroadcastMotionY;\n"
        . "\t\t\$dz = \$mz - \$this->fpsLastBroadcastMotionZ;\n"
        . "\t\tif(\$dx < 0) \$dx = -\$dx; if(\$dy < 0) \$dy = -\$dy; if(\$dz < 0) \$dz = -\$dz;\n"
        . "\t\tif(\$dx > \$eps || \$dy > \$eps || \$dz > \$eps) return false;\n"
        . "\t\t\$mxA = \$mx; if(\$mxA < 0) \$mxA = -\$mxA;\n"
        . "\t\t\$myA = \$my; if(\$myA < 0) \$myA = -\$myA;\n"
        . "\t\t\$mzA = \$mz; if(\$mzA < 0) \$mzA = -\$mzA;\n"
        . "\t\treturn \$mxA <= \$eps && \$myA <= \$eps && \$mzA <= \$eps;\n"
        . "\t}";

    $content = str_replace($helperAnchor, $helperBlock, $content, $count3);
    if ($count3 !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'helper insertion anchor (getOffsetPosition) not matched');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Entity.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchFpsActorAnimationDistanceFilter(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Entity.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'filterFpsAnimationViewers')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $old = "\tpublic function broadcastAnimation(Animation \$animation, ?array \$targets = null) : void{\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$targets ?? \$this->getViewers(), \$animation->encode());\n"
        . "\t}";
    $new = "\tpublic function broadcastAnimation(Animation \$animation, ?array \$targets = null) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: animation viewer distance filter */\n"
        . "\t\t\$fpsTargets = \$targets ?? \$this->getViewers();\n"
        . "\t\tif(\$targets === null){\n"
        . "\t\t\t\$fpsTargets = \$this->filterFpsAnimationViewers(\$fpsTargets);\n"
        . "\t\t}\n"
        . "\t\tif(count(\$fpsTargets) === 0) return;\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$fpsTargets, \$animation->encode());\n"
        . "\t}\n"
        . "\n"
        . "\t/**\n"
        . "\t * @param array<int, \\pocketmine\\player\\Player> \$viewers\n"
        . "\t * @return array<int, \\pocketmine\\player\\Player>\n"
        . "\t */\n"
        . "\tprivate function filterFpsAnimationViewers(array \$viewers) : array{\n"
        . "\t\tif(count(\$viewers) === 0) return \$viewers;\n"
        . "\t\t\$config = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.animation.enabled', true)) return \$viewers;\n"
        . "\t\t\$maxDist = (float) \$config->getProperty('better-pmmp.fps-optimization.animation.distance', 64);\n"
        . "\t\tif(\$maxDist <= 0) return \$viewers;\n"
        . "\t\t\$maxSq = \$maxDist * \$maxDist;\n"
        . "\t\t\$ex = \$this->location->x; \$ez = \$this->location->z;\n"
        . "\t\t\$out = [];\n"
        . "\t\tforeach(\$viewers as \$k => \$pl){\n"
        . "\t\t\t\$loc = \$pl->getLocation();\n"
        . "\t\t\t\$dx = \$loc->x - \$ex; \$dz = \$loc->z - \$ez;\n"
        . "\t\t\tif(\$dx * \$dx + \$dz * \$dz <= \$maxSq) \$out[\$k] = \$pl;\n"
        . "\t\t}\n"
        . "\t\treturn \$out;\n"
        . "\t}";

    $newContent = str_replace($old, $new, $content, $count);
    if ($count !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'broadcastAnimation match failed');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Entity.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchFpsParticleSoundDistanceFilter(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'World.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read World.php');
    }

    if (str_contains($content, 'filterFpsViewersByDistanceSq')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldSound = "\tpublic function addSound(Vector3 \$pos, Sound \$sound, ?array \$players = null) : void{\n"
        . "\t\t\$players ??= \$this->getViewersForPosition(\$pos);";
    $newSound = "\tpublic function addSound(Vector3 \$pos, Sound \$sound, ?array \$players = null) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: sound distance filter */\n"
        . "\t\t\$fpsImplicit = \$players === null;\n"
        . "\t\t\$players ??= \$this->getViewersForPosition(\$pos);\n"
        . "\t\tif(\$fpsImplicit){\n"
        . "\t\t\t\$players = \$this->filterFpsViewersByDistanceSq(\$pos, \$players, 'sound-distance', 32.0);\n"
        . "\t\t\tif(count(\$players) === 0) return;\n"
        . "\t\t}";

    $content = str_replace($oldSound, $newSound, $content, $cs);
    if ($cs !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'addSound anchor not matched');
    }

    $oldParticle = "\tpublic function addParticle(Vector3 \$pos, Particle \$particle, ?array \$players = null) : void{\n"
        . "\t\t\$players ??= \$this->getViewersForPosition(\$pos);";
    $newParticle = "\tpublic function addParticle(Vector3 \$pos, Particle \$particle, ?array \$players = null) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: particle distance filter */\n"
        . "\t\t\$fpsImplicit = \$players === null;\n"
        . "\t\t\$players ??= \$this->getViewersForPosition(\$pos);\n"
        . "\t\tif(\$fpsImplicit){\n"
        . "\t\t\t\$players = \$this->filterFpsViewersByDistanceSq(\$pos, \$players, 'particle-distance', 48.0);\n"
        . "\t\t\tif(count(\$players) === 0) return;\n"
        . "\t\t}";

    $content = str_replace($oldParticle, $newParticle, $content, $cp);
    if ($cp !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'addParticle anchor not matched');
    }

    $helperAnchor = "\tpublic function getViewersForPosition(Vector3 \$pos) : array{\n"
        . "\t\treturn \$this->getChunkPlayers(\$pos->getFloorX() >> Chunk::COORD_BIT_SIZE, \$pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);\n"
        . "\t}";
    $helperBlock = $helperAnchor . "\n"
        . "\n"
        . "\t/**\n"
        . "\t * [BetterPMMP-PATCH] FPS optimization: filter viewers by squared distance from a position.\n"
        . "\t * @param array<int, \\pocketmine\\player\\Player> \$viewers\n"
        . "\t * @return array<int, \\pocketmine\\player\\Player>\n"
        . "\t */\n"
        . "\tprivate function filterFpsViewersByDistanceSq(Vector3 \$pos, array \$viewers, string \$key, float \$default) : array{\n"
        . "\t\tif(count(\$viewers) === 0) return \$viewers;\n"
        . "\t\t\$config = \$this->server->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.particle-sound.enabled', true)) return \$viewers;\n"
        . "\t\t\$maxDist = (float) \$config->getProperty('better-pmmp.fps-optimization.particle-sound.' . \$key, \$default);\n"
        . "\t\tif(\$maxDist <= 0) return \$viewers;\n"
        . "\t\t\$maxSq = \$maxDist * \$maxDist;\n"
        . "\t\t\$px = \$pos->x; \$pz = \$pos->z;\n"
        . "\t\t\$out = [];\n"
        . "\t\tforeach(\$viewers as \$k => \$pl){\n"
        . "\t\t\t\$loc = \$pl->getLocation();\n"
        . "\t\t\t\$dx = \$loc->x - \$px; \$dz = \$loc->z - \$pz;\n"
        . "\t\t\tif(\$dx * \$dx + \$dz * \$dz <= \$maxSq) \$out[\$k] = \$pl;\n"
        . "\t\t}\n"
        . "\t\treturn \$out;\n"
        . "\t}";

    $content = str_replace($helperAnchor, $helperBlock, $content, $ch);
    if ($ch !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'helper anchor (getViewersForPosition) not matched');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched World.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchFpsChunkSendPacing(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/player/Player.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Player.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read Player.php');
    }

    if (str_contains($content, 'fpsChunkPacingTicks')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldLimit = "\t\t\$limit = \$this->chunksPerTick - count(\$this->activeChunkGenerationRequests);";
    $newLimit = "\t\t/** [BetterPMMP-PATCH] FPS optimization: chunk send pacing (smooth ramp-up) */\n"
        . "\t\t\$fpsChunksPerTick = \$this->chunksPerTick;\n"
        . "\t\t\$fpsConfig = \$this->server->getConfigGroup();\n"
        . "\t\tif((bool) \$fpsConfig->getProperty('better-pmmp.fps-optimization.chunk-pacing.enabled', true)){\n"
        . "\t\t\t\$fpsRamp = (int) \$fpsConfig->getProperty('better-pmmp.fps-optimization.chunk-pacing.ramp-up-ticks', 20);\n"
        . "\t\t\t\$fpsInitial = (int) \$fpsConfig->getProperty('better-pmmp.fps-optimization.chunk-pacing.initial-chunks-per-tick', 2);\n"
        . "\t\t\tif(\$fpsRamp > 0 && \$this->fpsChunkPacingTicks < \$fpsRamp){\n"
        . "\t\t\t\t\$fpsProgress = \$this->fpsChunkPacingTicks / \$fpsRamp;\n"
        . "\t\t\t\t\$fpsChunksPerTick = max(\$fpsInitial, (int) round(\$fpsInitial + (\$this->chunksPerTick - \$fpsInitial) * \$fpsProgress));\n"
        . "\t\t\t\t\$this->fpsChunkPacingTicks++;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\t\$limit = \$fpsChunksPerTick - count(\$this->activeChunkGenerationRequests);";

    $content = str_replace($oldLimit, $newLimit, $content, $cl);
    if ($cl !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'requestChunks limit anchor not matched');
    }

    $propAnchor = "\tprotected int \$chunksPerTick;";
    $propNew = $propAnchor . "\n"
        . "\t/** [BetterPMMP-PATCH] FPS optimization: chunk pacing tick counter */\n"
        . "\tprotected int \$fpsChunkPacingTicks = 0;";
    $content = str_replace($propAnchor, $propNew, $content, $cprop);
    if ($cprop !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'chunksPerTick property anchor not matched');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched Player.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchFpsItemEntitySuppression(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/object/ItemEntity.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'ItemEntity.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read ItemEntity.php');
    }

    if (str_contains($content, 'fpsShouldSuppressBroadcast')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $anchor = "class ItemEntity extends Entity{\n";
    $insert = $anchor
        . "\n"
        . "\t/** [BetterPMMP-PATCH] FPS optimization: suppress redundant motion broadcast for stationary items in dense chunks */\n"
        . "\tprotected function broadcastMovement(bool \$teleport = false) : void{\n"
        . "\t\tif(!\$teleport && \$this->fpsShouldSuppressBroadcast()){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\tparent::broadcastMovement(\$teleport);\n"
        . "\t}\n"
        . "\n"
        . "\tprotected function broadcastMotion() : void{\n"
        . "\t\tif(\$this->fpsShouldSuppressBroadcast()){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\tparent::broadcastMotion();\n"
        . "\t}\n"
        . "\n"
        . "\tprivate function fpsShouldSuppressBroadcast() : bool{\n"
        . "\t\tif(!\$this->onGround) return false;\n"
        . "\t\t\$m = \$this->motion;\n"
        . "\t\tif(\$m->x !== 0.0 || \$m->y !== 0.0 || \$m->z !== 0.0) return false;\n"
        . "\t\t\$config = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.item-entity.enabled', true)) return false;\n"
        . "\t\t\$threshold = (int) \$config->getProperty('better-pmmp.fps-optimization.item-entity.threshold-per-chunk', 16);\n"
        . "\t\tif(\$threshold <= 0) return false;\n"
        . "\t\t\$cx = \$this->location->getFloorX() >> 4;\n"
        . "\t\t\$cz = \$this->location->getFloorZ() >> 4;\n"
        . "\t\t\$count = 0;\n"
        . "\t\tforeach(\$this->getWorld()->getChunkEntities(\$cx, \$cz) as \$e){\n"
        . "\t\t\tif(\$e instanceof ItemEntity){\n"
        . "\t\t\t\t\$count++;\n"
        . "\t\t\t\tif(\$count > \$threshold) return true;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\treturn false;\n"
        . "\t}\n";

    $newContent = str_replace($anchor, $insert, $content, $count);
    if ($count !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'ItemEntity class anchor not matched');
    }

    if (patchWrite($targetFile, $newContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched ItemEntity.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlPvpOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/resources/pocketmine.yml';

    $anchor = "      threshold-per-chunk: 16";
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

    return applyReplacePatch($targetFile, 'pvp-optimization:', $anchor, $insertion, 'fps-optimization anchor (threshold-per-chunk) not found in pocketmine.yml');
}

function patchYmlServerPropertiesPvpOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/generated/YmlServerProperties.php';

    $anchor = "\tpublic const BETTER_PMMP_FPS_ITEM_ENTITY_THRESHOLD = 'better-pmmp.fps-optimization.item-entity.threshold-per-chunk';\n";
    $insert = $anchor
        . "\t/** [BetterPMMP-PATCH] PvP optimization config constants */\n"
        . "\tpublic const BETTER_PMMP_PVP_OPTIMIZATION = 'better-pmmp.pvp-optimization';\n"
        . "\tpublic const BETTER_PMMP_PVP_SKIP_LIGHT_UPDATES = 'better-pmmp.pvp-optimization.skip-light-updates';\n"
        . "\tpublic const BETTER_PMMP_PVP_XP_ORBS = 'better-pmmp.pvp-optimization.xp-orbs';\n"
        . "\tpublic const BETTER_PMMP_PVP_EXPLOSION_BLOCK_DESTRUCTION = 'better-pmmp.pvp-optimization.explosion-block-destruction';\n"
        . "\tpublic const BETTER_PMMP_PVP_ITEM_MERGING = 'better-pmmp.pvp-optimization.item-merging';\n"
        . "\tpublic const BETTER_PMMP_PVP_ITEM_DESPAWN_TICKS = 'better-pmmp.pvp-optimization.item-despawn-ticks';\n";

    return applyReplacePatch($targetFile, 'BETTER_PMMP_PVP_OPTIMIZATION', $anchor, $insert, 'BETTER_PMMP_FPS_ITEM_ENTITY_THRESHOLD anchor not found in YmlServerProperties.php');
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

function patchYmlServerPropertiesPvpTickToggles(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/generated/YmlServerProperties.php';

    $anchor = "\tpublic const BETTER_PMMP_PVP_ITEM_DESPAWN_TICKS = 'better-pmmp.pvp-optimization.item-despawn-ticks';\n";
    $insert = $anchor
        . "\tpublic const BETTER_PMMP_PVP_MOVEMENT_BROADCAST_PERIOD = 'better-pmmp.pvp-optimization.movement-broadcast-period';\n"
        . "\tpublic const BETTER_PMMP_PVP_PICKUP_SCAN_PERIOD = 'better-pmmp.pvp-optimization.pickup-scan-period';\n"
        . "\tpublic const BETTER_PMMP_PVP_FREEZE_EMPTY_WORLDS = 'better-pmmp.pvp-optimization.freeze-empty-worlds';\n";

    return applyReplacePatch($targetFile, 'BETTER_PMMP_PVP_MOVEMENT_BROADCAST_PERIOD', $anchor, $insert, 'BETTER_PMMP_PVP_ITEM_DESPAWN_TICKS anchor not found in YmlServerProperties.php');
}

function patchEntityPvpMovementBroadcastPeriod(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/Entity.php';

    $old = "\t\tif(\$teleport || \$diffPosition > 0.0001 || \$diffRotation > 1.0 || (!\$wasStill && \$still)){\n"
        . "\t\t\t\$this->lastLocation = \$this->location->asLocation();\n"
        . "\n"
        . "\t\t\t\$this->broadcastMovement(\$teleport);\n"
        . "\t\t}";

    $new = "\t\tif(\$teleport || \$diffPosition > 0.0001 || \$diffRotation > 1.0 || (!\$wasStill && \$still)){\n"
        . "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: movement broadcast period - skip off-cycle sends.\n"
        . "\t\t\t * lastLocation is left untouched on skip, so the accumulated diff re-enters this branch\n"
        . "\t\t\t * and the final position is still broadcast after the entity stops moving. */\n"
        . "\t\t\t\$pvpMovePeriod = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.movement-broadcast-period', 1);\n"
        . "\t\t\tif(\$teleport || \$pvpMovePeriod <= 1 || ((\$this->server->getTick() + \$this->id) % \$pvpMovePeriod) === 0){\n"
        . "\t\t\t\t\$this->lastLocation = \$this->location->asLocation();\n"
        . "\n"
        . "\t\t\t\t\$this->broadcastMovement(\$teleport);\n"
        . "\t\t\t}\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'movement broadcast period - skip off-cycle', $old, $new, 'Failed to match updateMovement() broadcast block in Entity.php');
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
        . "\t\t\t\$pvpMovePeriod = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.movement-broadcast-period', 1);\n"
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

    $old = "\t\t\tif(!\$this->isSpectator() && \$this->isAlive()){\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->startTiming();\n"
        . "\t\t\t\t\$this->checkNearEntities();\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->stopTiming();\n"
        . "\t\t\t}";

    $new = "\t\t\t/** [BetterPMMP-PATCH] PvP optimization: pickup scan period - the nearby-entity sweep is\n"
        . "\t\t\t * O(entities around each player) every tick; vanilla pickup delay is 10 ticks anyway */\n"
        . "\t\t\t\$pvpScanPeriod = (int) \$this->server->getConfigGroup()->getProperty('better-pmmp.pvp-optimization.pickup-scan-period', 1);\n"
        . "\t\t\tif(!\$this->isSpectator() && \$this->isAlive() && (\$pvpScanPeriod <= 1 || ((\$currentTick + \$this->id) % \$pvpScanPeriod) === 0)){\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->startTiming();\n"
        . "\t\t\t\t\$this->checkNearEntities();\n"
        . "\t\t\t\tTimings::\$playerCheckNearEntities->stopTiming();\n"
        . "\t\t\t}";

    return applyReplacePatch($targetFile, 'pickup scan period', $old, $new, 'Failed to match checkNearEntities block in Player.php onUpdate()');
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

function patchYmlServerPropertiesEventOptimization(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/generated/YmlServerProperties.php';

    $anchor = "\tpublic const BETTER_PMMP_PVP_FREEZE_EMPTY_WORLDS = 'better-pmmp.pvp-optimization.freeze-empty-worlds';\n";
    $insert = $anchor
        . "\t/** [BetterPMMP-PATCH] Event engine optimization config constants */\n"
        . "\tpublic const BETTER_PMMP_EVENT_OPTIMIZATION = 'better-pmmp.event-optimization';\n"
        . "\tpublic const BETTER_PMMP_EVENT_MOVE_EVENT_PERIOD = 'better-pmmp.event-optimization.move-event-period';\n"
        . "\tpublic const BETTER_PMMP_EVENT_SKIP_AUTH_INPUT_RECEIVE_EVENT = 'better-pmmp.event-optimization.skip-auth-input-receive-event';\n"
        . "\tpublic const BETTER_PMMP_EVENT_SKIP_MOVEMENT_SEND_EVENT = 'better-pmmp.event-optimization.skip-movement-send-event';\n";

    return applyReplacePatch($targetFile, 'BETTER_PMMP_EVENT_OPTIMIZATION', $anchor, $insert, 'BETTER_PMMP_PVP_FREEZE_EMPTY_WORLDS anchor not found in YmlServerProperties.php');
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

function patchTaskHandlerFastPath(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/scheduler/TaskHandler.php';

    $old = "\tpublic function run() : void{\n"
        . "\t\t\$this->timings->startTiming();\n"
        . "\t\ttry{\n"
        . "\t\t\t\$this->task->onRun();\n"
        . "\t\t}catch(CancelTaskException \$e){\n"
        . "\t\t\t\$this->cancel();\n"
        . "\t\t}finally{\n"
        . "\t\t\t\$this->timings->stopTiming();\n"
        . "\t\t}\n"
        . "\t}";

    $new = "\tpublic function run() : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] event engine fast-path: skip per-run timing wrappers while timings are\n"
        . "\t\t * disabled - repeating interval tasks pay this on every single run */\n"
        . "\t\tif(!TimingsHandler::isEnabled()){\n"
        . "\t\t\ttry{\n"
        . "\t\t\t\t\$this->task->onRun();\n"
        . "\t\t\t}catch(CancelTaskException \$e){\n"
        . "\t\t\t\t\$this->cancel();\n"
        . "\t\t\t}\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$this->timings->startTiming();\n"
        . "\t\ttry{\n"
        . "\t\t\t\$this->task->onRun();\n"
        . "\t\t}catch(CancelTaskException \$e){\n"
        . "\t\t\t\$this->cancel();\n"
        . "\t\t}finally{\n"
        . "\t\t\t\$this->timings->stopTiming();\n"
        . "\t\t}\n"
        . "\t}";

    return applyReplacePatch($targetFile, 'skip per-run timing wrappers', $old, $new, 'run() anchor not found in TaskHandler.php');
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

function createTradeInventory(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/inventory';
    $targetFile = $targetDir . '/TradeInventory.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Inventory directory not found');
    }

    $fileContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\UpdateTradePacket;
use pocketmine\player\Player;
use function count;

/**
 * [BetterPMMP-PATCH] Plugin-openable Mojang Trade V2 window backed by a real 2-slot inventory.
 * Backported mechanism from pmmp/PocketMine-MP#6310 (custom-trade subset only).
 */
final class TradeInventory extends SimpleInventory{

	/** @param list<array{0: Item, 1: Item|null, 2: Item}> $recipes [buyA, buyB, sell] */
	public function __construct(
		private readonly string $displayName,
		private readonly Living $holder,
		private readonly array $recipes
	){
		parent::__construct(2);
	}

	public function getHolder() : Living{
		return $this->holder;
	}

	/** @return array{0: Item, 1: Item|null, 2: Item}|null */
	public function getRecipe(int $id) : ?array{
		return $this->recipes[$id] ?? null;
	}

	public function onOpen(Player $who) : void{
		parent::onOpen($who);
		//Send the trading-player binding only to the opener instead of mutating the shared entity metadata,
		//so a second player opening the same entity does not clobber other traders' windows.
		$this->holder->sendData([$who], [EntityMetadataProperties::TRADING_PLAYER_EID => new LongMetadataProperty($who->getId())]);
	}

	public function onClose(Player $who) : void{
		foreach($this->getContents() as $item){
			foreach($who->getInventory()->addItem($item) as $drop)
				$who->getWorld()->dropItem($who->getPosition(), $drop);
		}
		$this->clearAll();
		$this->holder->sendData([$who], [EntityMetadataProperties::TRADING_PLAYER_EID => new LongMetadataProperty(-1)]);
		parent::onClose($who);
	}

	/** @phpstan-return CacheableNbt<\pocketmine\nbt\tag\CompoundTag> */
	public function buildOffers() : CacheableNbt{
		$recipesTag = [];
		foreach($this->recipes as $i => $recipe){
			[$buyA, $buyB, $sell] = $recipe;
			$tag = CompoundTag::create()
				->setTag("buyA", $buyA->nbtSerialize())
				->setInt("buyCountA", $buyA->getCount())
				->setInt("buyCountB", $buyB?->getCount() ?? 0)
				->setTag("sell", $sell->nbtSerialize())
				->setInt("uses", 0)
				->setInt("maxUses", 9999999)
				->setByte("rewardExp", 0)
				->setInt("demand", 0)
				->setInt("tier", 0)
				->setInt("traderExp", 0)
				->setFloat("priceMultiplierA", 0.0)
				->setFloat("priceMultiplierB", 0.0)
				->setInt("netId", $i + 1);
			if($buyB !== null)
				$tag->setTag("buyB", $buyB->nbtSerialize());
			$recipesTag[] = $tag;
		}

		$nbt = CompoundTag::create()
			->setTag("Recipes", new ListTag($recipesTag, NBT::TAG_Compound))
			->setTag("TierExpRequirements", new ListTag([], NBT::TAG_Compound));
		return new CacheableNbt($nbt);
	}

	public function buildOpenPacket(int $windowId) : UpdateTradePacket{
		return UpdateTradePacket::create(
			$windowId,
			WindowTypes::TRADING,
			0,
			0,
			$this->holder->getId(),
			-1,
			$this->displayName,
			true,
			true,
			$this->buildOffers()
		);
	}

	public function recipeCount() : int{
		return count($this->recipes);
	}
}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $fileContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $fileContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write TradeInventory.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function createTradingTransaction(string $sourceDir): PatchResult
{
    $targetDir = $sourceDir . '/src/inventory/transaction';
    $targetFile = $targetDir . '/TradingTransaction.php';

    if (!is_dir($targetDir)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Inventory transaction directory not found');
    }

    $fileContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;

/**
 * [BetterPMMP-PATCH] Validates and executes a single Trade V2 exchange.
 * Backported mechanism from pmmp/PocketMine-MP#6310 (custom-trade subset only).
 */
final class TradingTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly Item $recipeBuyA,
		private readonly ?Item $recipeBuyB,
		private readonly Item $recipeSell
	){
		parent::__construct($source);
	}

	public function validate() : void{
		if(count($this->actions) < 1)
			throw new TransactionValidationException("Transaction must have at least one action to be executable");

		/** @var Item[] $inputs */
		$inputs = [];
		/** @var Item[] $outputs */
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		$needA = $this->recipeBuyA->getCount();
		$needB = $this->recipeBuyB?->getCount() ?? 0;

		if($this->recipeBuyB !== null && $this->recipeBuyA->canStackWith($this->recipeBuyB)){
			//buyA and buyB are the same item type (e.g. two identical armour pieces); the client splits them across
			//both cost slots, so verify the combined total instead of greedily assigning everything to buyA.
			$total = 0;
			foreach($inputs as $input){
				if(!$this->recipeBuyA->canStackWith($input))
					throw new TransactionValidationException("Unexpected input item");
				$total += $input->getCount();
			}
			if($total < $needA + $needB)
				throw new TransactionValidationException("Invalid buy item count");
		}else{
			$buyACount = 0;
			$buyBCount = 0;
			foreach($inputs as $input){
				if($this->recipeBuyA->canStackWith($input))
					$buyACount += $input->getCount();
				elseif($this->recipeBuyB !== null && $this->recipeBuyB->canStackWith($input))
					$buyBCount += $input->getCount();
				else
					throw new TransactionValidationException("Unexpected input item");
			}
			if($buyACount < $needA)
				throw new TransactionValidationException("Invalid buyA item");
			if($this->recipeBuyB !== null && $buyBCount < $needB)
				throw new TransactionValidationException("Invalid buyB item");
		}

		$outputCount = 0;
		foreach($outputs as $output){
			if(!$this->recipeSell->canStackWith($output))
				throw new TransactionValidationException("Invalid output item");
			$outputCount += $output->getCount();
		}
		if($outputCount !== $this->recipeSell->getCount())
			throw new TransactionValidationException("Invalid output count");
	}
}
PHPFILE;

    if (file_exists($targetFile) && patchRead($targetFile) === $fileContent) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    if (patchWrite($targetFile, $fileContent) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write TradingTransaction.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchInventoryManagerTrade(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/InventoryManager.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'InventoryManager.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read InventoryManager.php');
    }

    if (str_contains($content, 'instanceof TradeInventory')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $edits = [
        [
            'use pocketmine\inventory\Inventory;' . "\n"
                . 'use pocketmine\inventory\transaction\action\SlotChangeAction;',
            'use pocketmine\inventory\Inventory;' . "\n"
                . 'use pocketmine\inventory\TradeInventory;' . "\n"
                . 'use pocketmine\inventory\transaction\action\SlotChangeAction;',
            'Inventory use block anchor not found in InventoryManager.php',
        ],
        [
            'use pocketmine\network\mcpe\protocol\PlayerEnchantOptionsPacket;' . "\n"
                . 'use pocketmine\network\mcpe\protocol\types\BlockPosition;',
            'use pocketmine\network\mcpe\protocol\PlayerEnchantOptionsPacket;' . "\n"
                . 'use pocketmine\network\mcpe\protocol\UpdateTradePacket;' . "\n"
                . 'use pocketmine\network\mcpe\protocol\types\BlockPosition;',
            'Protocol use block anchor not found in InventoryManager.php',
        ],
        [
            "\t\treturn match(true){\n"
                . "\t\t\t\$inventory instanceof AnvilInventory => UIInventorySlotOffset::ANVIL,",
            "\t\treturn match(true){\n"
                . "\t\t\t/** [BetterPMMP-PATCH] Trade V2 window support */\n"
                . "\t\t\t\$inventory instanceof TradeInventory => UIInventorySlotOffset::TRADE2_INGREDIENT,\n"
                . "\t\t\t\$inventory instanceof AnvilInventory => UIInventorySlotOffset::ANVIL,",
            'Complex slot mapping anchor not found in InventoryManager.php',
        ],
        [
            "\t\t\t\t\t\tif(\$pk instanceof ContainerOpenPacket){\n"
                . "\t\t\t\t\t\t\t//workaround useless bullshit in 1.21 - ContainerClose requires a type now for some reason\n"
                . "\t\t\t\t\t\t\t\$windowType = \$pk->windowType;\n"
                . "\t\t\t\t\t\t}",
            "\t\t\t\t\t\tif(\$pk instanceof ContainerOpenPacket){\n"
                . "\t\t\t\t\t\t\t//workaround useless bullshit in 1.21 - ContainerClose requires a type now for some reason\n"
                . "\t\t\t\t\t\t\t\$windowType = \$pk->windowType;\n"
                . "\t\t\t\t\t\t}elseif(\$pk instanceof UpdateTradePacket){\n"
                . "\t\t\t\t\t\t\t\$windowType = WindowTypes::TRADING;\n"
                . "\t\t\t\t\t\t}",
            'Window type anchor not found in InventoryManager.php',
        ],
        [
            "\t\t\treturn [ContainerOpenPacket::blockInv(\$id, \$windowType, \$blockPosition)];\n"
                . "\t\t}\n"
                . "\t\treturn null;",
            "\t\t\treturn [ContainerOpenPacket::blockInv(\$id, \$windowType, \$blockPosition)];\n"
                . "\t\t}\n"
                . "\t\tif(\$inv instanceof TradeInventory){\n"
                . "\t\t\treturn [\$inv->buildOpenPacket(\$id)];\n"
                . "\t\t}\n"
                . "\t\treturn null;",
            'Container open anchor not found in InventoryManager.php',
        ],
    ];

    foreach ($edits as [$old, $new, $matchError]) {
        $newContent = str_replace($old, $new, $content);
        if ($newContent === $content) {
            return new PatchResult($targetFile, PatchStatus::FAILED, $matchError);
        }
        $content = $newContent;
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched InventoryManager.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchItemStackRequestExecutorTrade(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/network/mcpe/handler/ItemStackRequestExecutor.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'ItemStackRequestExecutor.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read ItemStackRequestExecutor.php');
    }

    if (str_contains($content, 'beginTrading')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $mergeBlock = "\t\tif(\$mergeWithExisting){\n"
        . "\t\t\t//Created/result items (e.g. villager trade output) must stack onto an existing matching partial stack first,\n"
        . "\t\t\t//like vanilla. The client picks the destination from its own prediction and may choose an empty slot even\n"
        . "\t\t\t//when a matching stack exists, so redirect to the first matching slot with room. The client predicted a\n"
        . "\t\t\t//different slot, so a full resync is requested to reconcile both slots after the transaction executes.\n"
        . "\t\t\t\$maxStackSize = min(\$inventory->getMaxStackSize(), \$item->getMaxStackSize());\n"
        . "\t\t\t\$target = \$inventory->getItem(\$slot);\n"
        . "\t\t\tif(\$target->isNull() || !\$target->canStackWith(\$item) || \$target->getCount() + \$count > \$maxStackSize){\n"
        . "\t\t\t\tfor(\$i = 0, \$size = \$inventory->getSize(); \$i < \$size; ++\$i){\n"
        . "\t\t\t\t\t\$candidate = \$inventory->getItem(\$i);\n"
        . "\t\t\t\t\tif(!\$candidate->isNull() && \$candidate->canStackWith(\$item) && \$candidate->getCount() + \$count <= \$maxStackSize){\n"
        . "\t\t\t\t\t\t\$slot = \$i;\n"
        . "\t\t\t\t\t\t\$this->inventoryManager->requestSyncAll();\n"
        . "\t\t\t\t\t\tbreak;\n"
        . "\t\t\t\t\t}\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}\n\n";

    $beginTrading = "\t/**\n"
        . "\t * [BetterPMMP-PATCH] Trade V2 window support\n"
        . "\t * @throws ItemStackRequestProcessException\n"
        . "\t */\n"
        . "\tprotected function beginTrading(TradeInventory \$window, int \$recipeId, int \$repetitions) : void{\n"
        . "\t\tif(\$this->specialTransaction !== null){\n"
        . "\t\t\tthrow new ItemStackRequestProcessException(\"Another special transaction is already in progress\");\n"
        . "\t\t}\n"
        . "\t\tif(\$repetitions < 1){\n"
        . "\t\t\tthrow new ItemStackRequestProcessException(\"Cannot trade a recipe less than 1 time\");\n"
        . "\t\t}\n"
        . "\t\tif(\$repetitions > 256){\n"
        . "\t\t\tthrow new ItemStackRequestProcessException(\"Cannot trade a recipe more than 256 times\");\n"
        . "\t\t}\n"
        . "\t\t\$recipe = \$window->getRecipe(\$recipeId - 1);\n"
        . "\t\tif(\$recipe === null){\n"
        . "\t\t\tthrow new ItemStackRequestProcessException(\"No such trade recipe index: \" . (\$recipeId - 1));\n"
        . "\t\t}\n"
        . "\t\t[\$buyA, \$buyB, \$sell] = \$recipe;\n"
        . "\t\t//Shift-click sends a single request with repetitions > 1 to buy the maximum affordable amount at once.\n"
        . "\t\t\$scaledBuyA = (clone \$buyA)->setCount(\$buyA->getCount() * \$repetitions);\n"
        . "\t\t\$scaledBuyB = \$buyB !== null ? (clone \$buyB)->setCount(\$buyB->getCount() * \$repetitions) : null;\n"
        . "\t\t\$scaledSell = (clone \$sell)->setCount(\$sell->getCount() * \$repetitions);\n"
        . "\t\t\$this->specialTransaction = new TradingTransaction(\$this->player, \$scaledBuyA, \$scaledBuyB, \$scaledSell);\n"
        . "\t\t\$this->setNextCreatedItem(\$scaledSell);\n"
        . "\t}\n\n";

    $edits = [
        [
            'use pocketmine\inventory\transaction\action\DropItemAction;' . "\n"
                . 'use pocketmine\inventory\transaction\CraftingTransaction;',
            'use pocketmine\inventory\transaction\action\DropItemAction;' . "\n"
                . 'use pocketmine\inventory\TradeInventory;' . "\n"
                . 'use pocketmine\inventory\transaction\CraftingTransaction;',
            'DropItemAction use anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            'use pocketmine\inventory\transaction\InventoryTransaction;' . "\n"
                . 'use pocketmine\inventory\transaction\TransactionBuilder;',
            'use pocketmine\inventory\transaction\InventoryTransaction;' . "\n"
                . 'use pocketmine\inventory\transaction\TradingTransaction;' . "\n"
                . 'use pocketmine\inventory\transaction\TransactionBuilder;',
            'InventoryTransaction use anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "use function count;\nuse function spl_object_id;",
            "use function count;\nuse function min;\nuse function spl_object_id;",
            'Function imports anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\tprotected function transferItems(ItemStackRequestSlotInfo \$source, ItemStackRequestSlotInfo \$destination, int \$count) : void{\n"
                . "\t\t\$removed = \$this->removeItemFromSlot(\$source, \$count);\n"
                . "\t\t\$this->addItemToSlot(\$destination, \$removed, \$count);\n"
                . "\t}",
            "\tprotected function transferItems(ItemStackRequestSlotInfo \$source, ItemStackRequestSlotInfo \$destination, int \$count) : void{\n"
                . "\t\t/** [BetterPMMP-PATCH] Trade V2 window support */\n"
                . "\t\t\$fromTradeOutput =\n"
                . "\t\t\t\$source->getContainerName()->getContainerId() === ContainerUIIds::CREATED_OUTPUT &&\n"
                . "\t\t\t\$source->getSlotId() === UIInventorySlotOffset::CREATED_ITEM_OUTPUT &&\n"
                . "\t\t\t\$this->player->getCurrentWindow() instanceof TradeInventory;\n"
                . "\t\t\$removed = \$this->removeItemFromSlot(\$source, \$count);\n"
                . "\t\t\$this->addItemToSlot(\$destination, \$removed, \$count, \$fromTradeOutput);\n"
                . "\t}",
            'transferItems anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\tprotected function addItemToSlot(ItemStackRequestSlotInfo \$slotInfo, Item \$item, int \$count) : void{",
            "\tprotected function addItemToSlot(ItemStackRequestSlotInfo \$slotInfo, Item \$item, int \$count, bool \$mergeWithExisting = false) : void{",
            'addItemToSlot signature anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\t\t\$existingItem = \$inventory->getItem(\$slot);\n"
                . "\t\tif(!\$existingItem->isNull() && !\$existingItem->canStackWith(\$item)){",
            $mergeBlock
                . "\t\t\$existingItem = \$inventory->getItem(\$slot);\n"
                . "\t\tif(!\$existingItem->isNull() && !\$existingItem->canStackWith(\$item)){",
            'addItemToSlot body anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\t/**\n"
                . "\t * @throws ItemStackRequestProcessException\n"
                . "\t */\n"
                . "\tprotected function beginCrafting(int \$recipeId, int \$repetitions) : void{",
            $beginTrading
                . "\t/**\n"
                . "\t * @throws ItemStackRequestProcessException\n"
                . "\t */\n"
                . "\tprotected function beginCrafting(int \$recipeId, int \$repetitions) : void{",
            'beginCrafting anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\t\tif(!\$this->specialTransaction instanceof CraftingTransaction && !\$this->specialTransaction instanceof EnchantingTransaction){",
            "\t\tif(!\$this->specialTransaction instanceof CraftingTransaction && !\$this->specialTransaction instanceof EnchantingTransaction && !\$this->specialTransaction instanceof TradingTransaction){",
            'assertDoingCrafting anchor not found in ItemStackRequestExecutor.php',
        ],
        [
            "\t\t\t}else{\n"
                . "\t\t\t\t\$this->beginCrafting(\$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t\t}\n"
                . "\t\t}elseif(\$action instanceof CraftRecipeAutoStackRequestAction){\n"
                . "\t\t\t\$this->beginCrafting(\$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t}elseif(\$action instanceof CraftingConsumeInputStackRequestAction){",
            "\t\t\t}elseif(\$window instanceof TradeInventory){\n"
                . "\t\t\t\t\$this->beginTrading(\$window, \$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t\t}else{\n"
                . "\t\t\t\t\$this->beginCrafting(\$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t\t}\n"
                . "\t\t}elseif(\$action instanceof CraftRecipeAutoStackRequestAction){\n"
                . "\t\t\t\$window = \$this->player->getCurrentWindow();\n"
                . "\t\t\tif(\$window instanceof TradeInventory){\n"
                . "\t\t\t\t\$this->beginTrading(\$window, \$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t\t}else{\n"
                . "\t\t\t\t\$this->beginCrafting(\$action->getRecipeId(), \$action->getRepetitions());\n"
                . "\t\t\t}\n"
                . "\t\t}elseif(\$action instanceof CraftingConsumeInputStackRequestAction){",
            'CraftRecipe action anchor not found in ItemStackRequestExecutor.php',
        ],
    ];

    foreach ($edits as [$old, $new, $matchError]) {
        $newContent = str_replace($old, $new, $content);
        if ($newContent === $content) {
            return new PatchResult($targetFile, PatchStatus::FAILED, $matchError);
        }
        $content = $newContent;
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched ItemStackRequestExecutor.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
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

function patchExperienceOrbPvpTargetScanInterval(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/entity/object/ExperienceOrb.php';

    $old = "\t\tif(\$this->lookForTargetTime >= 20){\n"
        . "\t\t\tif(\$currentTarget === null){\n"
        . "\t\t\t\t\$newTarget = \$this->getWorld()->getNearestEntity(\$this->location, self::MAX_TARGET_DISTANCE, Human::class);\n"
        . "\n"
        . "\t\t\t\tif(\$newTarget instanceof Human && !(\$newTarget instanceof Player && \$newTarget->isSpectator()) && \$newTarget->getXpManager()->canAttractXpOrbs()){\n"
        . "\t\t\t\t\t\$currentTarget = \$newTarget;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\n"
        . "\t\t\t\$this->lookForTargetTime = 0;\n"
        . "\t\t}else{\n"
        . "\t\t\t\$this->lookForTargetTime += \$tickDiff;\n"
        . "\t\t}";

    $new = "\t\t/* [BetterPMMP-PATCH] PvP optimization: configurable target-scan interval. getNearestEntity()\n"
        . "\t\t * scans every Human in a 1-4 chunk window per scan and runs for every targetless orb; raising the\n"
        . "\t\t * interval thins those bulk-kill scans. Clamped to >= 1 so the scan can never be disabled.\n"
        . "\t\t * Default 20 reproduces vanilla exactly. getPropertyInt returns int (no mixed cast). */\n"
        . "\t\t\$pvpOrbScanInterval = max(1, \$this->server->getConfigGroup()->getPropertyInt('better-pmmp.pvp-optimization.xp-orb-scan-interval', 20));\n"
        . "\t\tif(\$this->lookForTargetTime >= \$pvpOrbScanInterval){\n"
        . "\t\t\tif(\$currentTarget === null){\n"
        . "\t\t\t\t\$newTarget = \$this->getWorld()->getNearestEntity(\$this->location, self::MAX_TARGET_DISTANCE, Human::class);\n"
        . "\n"
        . "\t\t\t\tif(\$newTarget instanceof Human && !(\$newTarget instanceof Player && \$newTarget->isSpectator()) && \$newTarget->getXpManager()->canAttractXpOrbs()){\n"
        . "\t\t\t\t\t\$currentTarget = \$newTarget;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\n"
        . "\t\t\t\$this->lookForTargetTime = 0;\n"
        . "\t\t}else{\n"
        . "\t\t\t\$this->lookForTargetTime += \$tickDiff;\n"
        . "\t\t}";

    return applyReplacePatch($targetFile, 'xp-orb-scan-interval', $old, $new, 'Failed to match lookForTargetTime scan block in ExperienceOrb.php');
}

function patchPocketmineYmlPvpXpOrbScanInterval(string $sourceDir): PatchResult
{
    $anchor = '    freeze-empty-worlds: false';
    $insertion = $anchor . "\n"
        . "    # How often (in ticks) each XP orb that has no target re-scans for the nearest player (vanilla: 20).\n"
        . "    # The scan walks every Human entity in a 1-4 chunk window and runs per targetless orb, so in bulk\n"
        . "    # kills the orbs cluster in exactly the dense chunks they must scan. Raising this thins that cost\n"
        . "    # at the price of slower orb homing (e.g. 40 delays acquisition by up to ~1 extra second). Orbs\n"
        . "    # already locked onto a player are unaffected. Clamped to >= 1; ignored entirely when xp-orbs is false.\n"
        . "    xp-orb-scan-interval: 20";

    return applyReplacePatch(
        $sourceDir . '/resources/pocketmine.yml',
        'xp-orb-scan-interval:',
        $anchor,
        $insertion,
        'pvp-optimization anchor (freeze-empty-worlds) not found in pocketmine.yml'
    );
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

function patchWorldDisableRandomBlockTicking(string $sourceDir): PatchResult
{
    $targetFile = $sourceDir . '/src/world/World.php';

    if (!file_exists($targetFile)) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'World.php not found');
    }

    $content = patchRead($targetFile);
    if ($content === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to read World.php');
    }

    if (str_contains($content, 'disable-random-block-ticking')) {
        return new PatchResult($targetFile, PatchStatus::SKIPPED);
    }

    $oldInit = "\tprivate function initRandomTickBlocksFromConfig(ServerConfigGroup \$cfg) : void{\n"
        . "\t\t\$dontTickBlocks = [];";
    $newInit = "\tprivate function initRandomTickBlocksFromConfig(ServerConfigGroup \$cfg) : void{\n"
        . "\t\t/* [BetterPMMP-PATCH] disable-random-block-ticking: when enabled, leave randomTickBlocks empty so\n"
        . "\t\t * tickChunk()'s per-subchunk random-tick selection loop is skipped entirely (see the count() guard\n"
        . "\t\t * there). Default false reproduces vanilla exactly. Removes the per-ticking-chunk-per-tick\n"
        . "\t\t * mt_rand + getBlockStateId + isset loop on static PvP maps where crop growth, leaf decay and\n"
        . "\t\t * ice/fire spread are irrelevant; entity onRandomUpdate and the chunk-tick radius are unaffected. */\n"
        . "\t\tif(\$cfg->getPropertyBool('better-pmmp.disable-random-block-ticking', false)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$dontTickBlocks = [];";

    $content = str_replace($oldInit, $newInit, $content, $initCount);
    if ($initCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'initRandomTickBlocksFromConfig anchor not found in World.php');
    }

    $oldTick = "\t\tforeach(\$this->getChunkEntities(\$chunkX, \$chunkZ) as \$entity){\n"
        . "\t\t\t\$entity->onRandomUpdate();\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t\$blockFactory = \$this->blockStateRegistry;";
    $newTick = "\t\tforeach(\$this->getChunkEntities(\$chunkX, \$chunkZ) as \$entity){\n"
        . "\t\t\t\$entity->onRandomUpdate();\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t/* [BetterPMMP-PATCH] disable-random-block-ticking: with no block type registered for random\n"
        . "\t\t * ticking the selection loop below can only ever miss (its isset() never hits), so skipping it is\n"
        . "\t\t * byte-for-byte vanilla-equivalent. better-pmmp.disable-random-block-ticking empties randomTickBlocks\n"
        . "\t\t * to take this path; the entity onRandomUpdate iteration above still runs. */\n"
        . "\t\tif(count(\$this->randomTickBlocks) === 0){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\n"
        . "\t\t\$blockFactory = \$this->blockStateRegistry;";

    $content = str_replace($oldTick, $newTick, $content, $tickCount);
    if ($tickCount !== 1) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'tickChunk random-tick loop anchor not found in World.php');
    }

    if (patchWrite($targetFile, $content) === false) {
        return new PatchResult($targetFile, PatchStatus::FAILED, 'Failed to write patched World.php');
    }

    return new PatchResult($targetFile, PatchStatus::APPLIED);
}

function patchPocketmineYmlDisableRandomBlockTicking(string $sourceDir): PatchResult
{
    $anchor = '  neighbour-update-limit: 512';
    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] Disable random block ticking (crop growth, leaf decay, ice/snow melt, fire\n"
        . "  # spread, grass/mycelium spread, sapling and sugarcane growth, etc.). When true, the per-subchunk\n"
        . "  # random-tick selection loop in every ticking chunk is skipped each tick; entity item/xp updates and\n"
        . "  # the chunk-tick radius are unaffected. Default false = vanilla. Set true on static PvP/arena maps\n"
        . "  # where no block needs to grow or spread - removes a recurring per-tick CPU cost.\n"
        . "  disable-random-block-ticking: false";

    return applyReplacePatch(
        $sourceDir . '/resources/pocketmine.yml',
        'disable-random-block-ticking:',
        $anchor,
        $insertion,
        'better-pmmp anchor (neighbour-update-limit) not found in pocketmine.yml'
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
    'patchPluginManager' => $sourceDir,
    'createReloadPluginCommand' => $sourceDir,
    'patchReloadPermission' => $sourceDir,
    'patchSimpleCommandMap' => $sourceDir,
    'createClassCacheInvalidator' => $sourceDir,
    'createPluginDependencyMap' => $sourceDir,
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
    'patchYmlServerPropertiesBetterPmmp' => $sourceDir,
    'patchWorldFixedLight' => $sourceDir,
    'patchWorldPerWorldChunkTicking' => $sourceDir,
    'patchWorldChunkOptimization' => $sourceDir,
    'patchPlayerPerWorldViewDistance' => $sourceDir,
    'patchWorldNeighbourUpdateThrottle' => $sourceDir,
    'patchWorldBlockCacheSize' => $sourceDir,
    'patchEntityMoveInPlace' => $sourceDir,
    'patchEntitySmartBlocksAroundCache' => $sourceDir,
    'patchPocketmineYmlCriticalHit' => $sourceDir,
    'patchYmlServerPropertiesCriticalHit' => $sourceDir,
    'patchPlayerCriticalHit' => $sourceDir,
    'patchNetworkSessionSetHandlerGuard' => $sourceDir,
    'patchPlayerRespawnLockReset' => $sourceDir,
    'patchHandlerListMergePerformance' => $sourceDir,
    'patchNetworkSessionDisconnectGuardTiming' => $sourceDir,
    'patchClassMapAuthoritative' => $sourceDir,
    'patchPocketmineYmlFpsOptimization' => $sourceDir,
    'patchYmlServerPropertiesFpsOptimization' => $sourceDir,
    'patchFpsEntityBroadcastOptimization' => $sourceDir,
    'patchFpsActorAnimationDistanceFilter' => $sourceDir,
    'patchFpsParticleSoundDistanceFilter' => $sourceDir,
    'patchFpsChunkSendPacing' => $sourceDir,
    'patchFpsItemEntitySuppression' => $sourceDir,
    'patchPocketmineYmlPvpOptimization' => $sourceDir,
    'patchYmlServerPropertiesPvpOptimization' => $sourceDir,
    'patchWorldPvpSkipLightUpdates' => $sourceDir,
    'patchWorldPvpXpOrbToggle' => $sourceDir,
    'patchWorldPvpItemDespawnTicks' => $sourceDir,
    'patchExplosionPvpBlockDestructionToggle' => $sourceDir,
    'patchItemEntityPvpMergeToggle' => $sourceDir,
    'patchPocketmineYmlPvpTickToggles' => $sourceDir,
    'patchYmlServerPropertiesPvpTickToggles' => $sourceDir,
    'patchEntityPvpMovementBroadcastPeriod' => $sourceDir,
    'patchPlayerPvpMovementBroadcastPeriod' => $sourceDir,
    'patchPlayerPvpPickupScanPeriod' => $sourceDir,
    'patchWorldPvpFreezeEmptyWorlds' => $sourceDir,
    'patchPocketmineYmlEventOptimization' => $sourceDir,
    'patchYmlServerPropertiesEventOptimization' => $sourceDir,
    'patchEventCallFastPath' => $sourceDir,
    'patchRegisteredListenerFastPath' => $sourceDir,
    'patchTaskHandlerFastPath' => $sourceDir,
    'patchPlayerMoveEventPeriod' => $sourceDir,
    'patchNetworkSessionAuthInputReceiveEvent' => $sourceDir,
    'patchNetworkSessionMovementSendEvent' => $sourceDir,
    'patchStandardBroadcasterMovementSendEvent' => $sourceDir,
    'createTradeInventory' => $sourceDir,
    'createTradingTransaction' => $sourceDir,
    'patchInventoryManagerTrade' => $sourceDir,
    'patchItemStackRequestExecutorTrade' => $sourceDir,
    'patchAttributeMapNeedSend' => $sourceDir,
    'patchStandardBroadcasterVarintLength' => $sourceDir,
    'patchExperienceOrbPvpTargetScanInterval' => $sourceDir,
    'patchPocketmineYmlPvpXpOrbScanInterval' => $sourceDir,
    'patchServerSkipVanillaRecipes' => $sourceDir,
    'patchPocketmineYmlSkipVanillaRecipes' => $sourceDir,
    'patchWorldDisableRandomBlockTicking' => $sourceDir,
    'patchPocketmineYmlDisableRandomBlockTicking' => $sourceDir,
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
