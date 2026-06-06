<?php

declare(strict_types=1);

/**
 * [BetterPMMP-PATCH] Patch Tool
 * Usage: php patch_tool.php <source_directory_path>
 */

function makePatchResult(string $targetFile, bool $applied, bool $skipped, ?string $errorMsg = null): array
{
    return [
        'target' => $targetFile,
        'applied' => $applied,
        'skipped' => $skipped,
        'error' => $errorMsg,
    ];
}

function isAlreadyPatched(string $filePath): bool
{
    if (!file_exists($filePath))
        return false;
    $content = file_get_contents($filePath);
    if ($content === false)
        return false;
    return str_contains($content, '[BetterPMMP-PATCH]') || str_contains($content, '[PMMP-SOURCE-RELOAD-PATCH]');
}

function patchStartCmd(string $baseDir): array
{
    $targetFile = $baseDir . DIRECTORY_SEPARATOR . 'start.cmd';

    if (isAlreadyPatched($targetFile)) {
        return makePatchResult($targetFile, false, true);
    }

    if (file_exists($targetFile)) {
        $content = file_get_contents($targetFile);
        if ($content !== false && (str_contains($content, 'source\src\PocketMine.php') || str_contains($content, 'source\\src\\PocketMine.php'))) {
            return makePatchResult($targetFile, false, true);
        }
    }

    if (file_exists($targetFile)) {
        $content = file_get_contents($targetFile);
        if ($content === false) {
            return makePatchResult($targetFile, false, false, 'Failed to read start.cmd');
        }

        if (str_contains($content, 'PocketMine-MP.phar')) {
            $newBlock = <<<'BAT'
REM [BetterPMMP-PATCH]
if exist source\src\PocketMine.php (
	set POCKETMINE_FILE=source\src\PocketMine.php
) else (
	echo source 폴더를 찾을 수 없습니다
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
                return makePatchResult($targetFile, false, false, 'Failed to replace phar block in start.cmd');
            }

            if (file_put_contents($targetFile, $newContent) === false) {
                return makePatchResult($targetFile, false, false, 'Failed to write patched start.cmd');
            }

            return makePatchResult($targetFile, true, false);
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
	echo source 폴더를 찾을 수 없습니다
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

    if (file_put_contents($targetFile, $startCmdContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to create start.cmd');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPluginManager(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'PluginManager.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'PluginManager.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read PluginManager.php');
    }

    $useStatements = <<<'PHP'
use function strlen;
PHP;

    $additionalUses = <<<'PHP'
use function strlen;
use function substr;
use function rtrim;
PHP;

    if (str_contains($content, 'use function rtrim;')) {
        $additionalUses = $useStatements;
    }

    if (str_contains($content, $useStatements)) {
        $content = str_replace($useStatements, $additionalUses, $content);
    } else {
        $useInsertions = [];
        if (!str_contains($content, 'use function strlen;')) {
            $useInsertions[] = 'use function strlen;';
        }
        if (!str_contains($content, 'use function substr;')) {
            $useInsertions[] = 'use function substr;';
        }
        if (!str_contains($content, 'use function rtrim;')) {
            $useInsertions[] = 'use function rtrim;';
        }
        if (count($useInsertions) > 0) {
            $lastUsePattern = '/^(use\s+function\s+[^;]+;)\s*$/m';
            if (preg_match_all($lastUsePattern, $content, $allUseMatches)) {
                $lastUseMatch = end($allUseMatches[0]);
                $content = str_replace(
                    $lastUseMatch,
                    $lastUseMatch . "\n" . implode("\n", $useInsertions),
                    $content
                );
            } elseif (preg_match('/^(use\s+[^;]+;)\s*$/m', $content, $anyUseMatch)) {
                $content = str_replace(
                    $anyUseMatch[0],
                    $anyUseMatch[0] . "\n" . implode("\n", $useInsertions),
                    $content
                );
            }
        }
    }

    if (!str_contains($content, 'private PluginResourceIndex $resourceIndex;')) {
        $inserted = false;
        $propertyAnchors = [
            "protected array \$fileAssociations = [];\n",
            "private bool \$loadPluginsGuard = false;\n",
        ];
        foreach ($propertyAnchors as $anchor) {
            if (str_contains($content, $anchor)) {
                $content = str_replace(
                    $anchor,
                    $anchor . "\n\tprivate PluginResourceIndex \$resourceIndex;\n",
                    $content
                );
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $classLine = 'class PluginManager';
            if (preg_match('/^(.*' . preg_quote($classLine, '/') . '.*\n\{)/m', $content, $m)) {
                $content = str_replace(
                    $m[0],
                    $m[0] . "\n\tprivate PluginResourceIndex \$resourceIndex;\n",
                    $content
                );
            }
        }
    }

    if (!str_contains($content, '$this->resourceIndex = new PluginResourceIndex()')) {
        $initInserted = false;
        if (preg_match('/public\s+function\s+__construct\s*\(/', $content, $m, PREG_OFFSET_CAPTURE)) {
            $searchStart = $m[0][1];
            $depth = 0;
            $parenFound = false;
            $len = strlen($content);
            for ($i = $searchStart; $i < $len; $i++) {
                if ($content[$i] === '(') {
                    $depth++;
                    $parenFound = true;
                } elseif ($content[$i] === ')') {
                    $depth--;
                    if ($parenFound && $depth === 0) {
                        break;
                    }
                }
            }
            $bracePos = strpos($content, '{', $i);
            if ($bracePos !== false) {
                $insertPos = $bracePos + 1;
                $content = substr($content, 0, $insertPos)
                    . "\n\t\t\$this->resourceIndex = new PluginResourceIndex();"
                    . substr($content, $insertPos);
                $initInserted = true;
            }
        }
        if (!$initInserted) {
            $fallbackSearch = 'if ($this->pluginDataDirectory !== null) {';
            if (str_contains($content, $fallbackSearch)) {
                $content = str_replace(
                    $fallbackSearch,
                    "\$this->resourceIndex = new PluginResourceIndex();\n\t\t" . $fallbackSearch,
                    $content
                );
            } elseif (preg_match('/if\s*\(\s*\$this->pluginDataDirectory\s*!==\s*null\s*\)\s*\{/', $content, $m2)) {
                $content = str_replace(
                    $m2[0],
                    "\$this->resourceIndex = new PluginResourceIndex();\n\t\t" . $m2[0],
                    $content
                );
            }
        }
    }

    if (!str_contains($content, 'public function getResourceIndex()')) {
        $getPluginsMethod = 'public function getPlugins(): array';
        $resourceIndexGetter = <<<'PHP'
public function getResourceIndex(): PluginResourceIndex
	{
		return $this->resourceIndex;
	}

	public function getPlugins(): array
PHP;
        if (str_contains($content, $getPluginsMethod)) {
            $content = str_replace($getPluginsMethod, $resourceIndexGetter, $content);
        } elseif (preg_match('/public\s+function\s+getPlugins\s*\(\s*\)\s*:\s*array/', $content, $gpMatch)) {
            $resourceIndexGetterDynamic = "public function getResourceIndex(): PluginResourceIndex\n\t{\n\t\treturn \$this->resourceIndex;\n\t}\n\n\t" . $gpMatch[0];
            $content = str_replace($gpMatch[0], $resourceIndexGetterDynamic, $content);
        }
    }

    if (!str_contains($content, 'public function reloadPlugin')) {
        $reloadMethod = <<<'RELOADMETHOD'

	/** [BetterPMMP-PATCH] reloadPlugin method */
	public function reloadPlugin(Plugin $plugin): bool
	{
		$pluginName = $plugin->getDescription()->getName();
		$logger = $this->server->getLogger();

		$reflect = new \ReflectionClass(PluginBase::class);
		$prefixedPath = $reflect->getMethod('getFile')->invoke($plugin);
		$loader = $plugin->getPluginLoader();
		$protocol = $loader->getAccessProtocol();
		$rawPath = $prefixedPath;
		if ($protocol !== '' && str_starts_with($prefixedPath, $protocol)) {
			$rawPath = substr($prefixedPath, strlen($protocol));
		}
		$rawPath = rtrim($rawPath, '/' . DIRECTORY_SEPARATOR);

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

		unset($this->plugins[$pluginName]);
		unset($this->enabledPlugins[$pluginName]);
		$this->resourceIndex->removePlugin($pluginName);

		$newDescription = $loader->getPluginDescription($rawPath);
		if ($newDescription === null) {
			$logger->critical("Failed to reload plugin {$pluginName}: could not read plugin description from {$rawPath}");
			return false;
		}

		$rootNamespace = ClassCacheInvalidator::detectRootNamespace($rawPath);
		$result = ClassCacheInvalidator::invalidateChanged($rawPath, [], $this->server->getLoader(), $rootNamespace);
		$this->resourceIndex->updateMtimeSnapshot($pluginName, $result['mtimes']);

		$newPlugin = null;
		if ($rootNamespace !== '') {
			$mainClass = $newDescription->getMain();
			$versionedMainClass = ClassCacheInvalidator::getVersionedClassName($mainClass, $rootNamespace, $result['version']);

			if (class_exists($versionedMainClass, false) && is_a($versionedMainClass, Plugin::class, true)) {
				foreach (Utils::stringifyKeys($newDescription->getPermissions()) as $default => $perms) {
					foreach ($perms as $perm) {
						if ($permManager->getPermission($perm->getName()) !== null) {
							$permManager->removePermission($perm);
						}
						$permManager->addPermission($perm);
						match ($default) {
							PermissionParser::DEFAULT_TRUE => $everyoneRoot?->addChild($perm->getName(), true),
							PermissionParser::DEFAULT_OP => $opRoot?->addChild($perm->getName(), true),
							PermissionParser::DEFAULT_NOT_OP => [$everyoneRoot?->addChild($perm->getName(), true), $opRoot?->addChild($perm->getName(), false)],
							default => null,
						};
					}
				}

				$dataFolder = $this->getDataDirectory($rawPath, $newDescription->getName());
				/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Data folder creation deferred to first use */

				$prefixed = $loader->getAccessProtocol() . $rawPath;
				$loader->loadPlugin($prefixed);

				try {
					$newPlugin = new $versionedMainClass($loader, $this->server, $newDescription, $dataFolder, $prefixed, new DiskResourceProvider($prefixed . '/resources/'));
				} catch (\Throwable $e) {
					$logger->critical("Failed to reload plugin {$pluginName}: " . $e->getMessage());
					$logger->logException($e);
					return false;
				}

				$this->plugins[$newPlugin->getDescription()->getName()] = $newPlugin;
				$logger->info("Plugin {$pluginName} reloaded (version {$result['version']})");
			} else {
				$logger->warning("Versioned class {$versionedMainClass} unavailable for {$pluginName}, using standard reload");
				try {
					$newPlugin = $this->internalLoadPlugin($rawPath, $loader, $newDescription);
				} catch (\Throwable $e) {
					$logger->critical("Failed to reload {$pluginName}: " . $e->getMessage());
					$logger->logException($e);
					return false;
				}
			}
		} else {
			try {
				$newPlugin = $this->internalLoadPlugin($rawPath, $loader, $newDescription);
			} catch (\Throwable $e) {
				$logger->critical("Failed to reload plugin {$pluginName}: " . $e->getMessage());
				$logger->logException($e);
				return false;
			}
		}

		if ($newPlugin === null) {
			$logger->critical("Failed to reload plugin {$pluginName}: internalLoadPlugin returned null");
			return false;
		}

		try {
			if (!$this->enablePlugin($newPlugin)) {
				$logger->critical("Failed to enable plugin {$pluginName} after reload");
				unset($this->plugins[$newPlugin->getDescription()->getName()]);
				return false;
			}
		} catch (\Throwable $e) {
			$logger->critical("Exception enabling plugin {$pluginName}: " . $e->getMessage());
			$logger->logException($e);
			unset($this->plugins[$newPlugin->getDescription()->getName()]);
			return false;
		}

		$descriptions = [];
		foreach ($this->plugins as $p) {
			$descriptions[] = $p->getDescription();
		}
		$this->resourceIndex->buildReverseDependencyMap($descriptions);

		return true;
	}
RELOADMETHOD;

        $tickSchedulersSearch = 'public function tickSchedulers(int $currentTick): void';
        if (str_contains($content, $tickSchedulersSearch)) {
            $content = str_replace(
                $tickSchedulersSearch,
                $reloadMethod . "\n\n\t" . $tickSchedulersSearch,
                $content
            );
        } else {
            $clearPluginsSearch = 'public function clearPlugins(): void';
            if (str_contains($content, $clearPluginsSearch)) {
                $content = str_replace(
                    $clearPluginsSearch,
                    $reloadMethod . "\n\n\t" . $clearPluginsSearch,
                    $content
                );
            } else {
                if (preg_match('/public\s+function\s+tickSchedulers\s*\(\s*int\s+\$currentTick\s*\)\s*:\s*void/', $content, $tsMatch)) {
                    $content = str_replace(
                        $tsMatch[0],
                        $reloadMethod . "\n\n\t" . $tsMatch[0],
                        $content
                    );
                } elseif (preg_match('/public\s+function\s+clearPlugins\s*\(\s*\)\s*:\s*void/', $content, $cpMatch)) {
                    $content = str_replace(
                        $cpMatch[0],
                        $reloadMethod . "\n\n\t" . $cpMatch[0],
                        $content
                    );
                }
            }
        }
    }

    if (!str_contains($content, 'resourceIndex->trackPlugin')) {
        $enabledPluginsLine = "\$this->enabledPlugins[\$plugin->getDescription()->getName()] = \$plugin;";
        $hasEnabledPluginsLine = str_contains($content, $enabledPluginsLine);
        if (!$hasEnabledPluginsLine) {
            if (preg_match('/\$this->enabledPlugins\s*\[\s*\$plugin->getDescription\(\)->getName\(\)\s*\]\s*=\s*\$plugin\s*;/', $content, $epMatch)) {
                $enabledPluginsLine = $epMatch[0];
                $hasEnabledPluginsLine = true;
            }
        }

        if ($hasEnabledPluginsLine) {
            $trackingCode = <<<'PHP'

				$handlers = [];
				foreach (HandlerListManager::global()->getAll() as $handlerList) {
					foreach (EventPriority::ALL as $priority) {
						foreach ($handlerList->getListenersByPriority($priority) as $listener) {
							if ($listener->getPlugin() === $plugin) {
								$handlers[] = $listener;
							}
						}
					}
				}

				$commands = [];
				foreach ($this->server->getCommandMap()->getCommands() as $command) {
					if ($command instanceof PluginOwned && $command->getOwningPlugin() === $plugin) {
						$commands[] = $command;
					}
				}

				$permissions = [];
				foreach ($plugin->getDescription()->getPermissions() as $permsGroup) {
					foreach ($permsGroup as $perm) {
						$permissions[] = $perm;
					}
				}

				$this->resourceIndex->trackPlugin($plugin->getDescription()->getName(), $handlers, $commands, $permissions);
PHP;

            $oldEnableBlock = '(new PluginEnableEvent($plugin))->call();';
            $hasEnableBlock = str_contains($content, $oldEnableBlock);
            if (!$hasEnableBlock) {
                if (preg_match('/\(\s*new\s+PluginEnableEvent\s*\(\s*\$plugin\s*\)\s*\)\s*->\s*call\s*\(\s*\)\s*;/', $content, $eeMatch)) {
                    $oldEnableBlock = $eeMatch[0];
                    $hasEnableBlock = true;
                }
            }

            if ($hasEnableBlock && !str_contains($content, 'resourceIndex->trackPlugin')) {
                $content = str_replace(
                    $oldEnableBlock,
                    $oldEnableBlock . "\n" . $trackingCode,
                    $content
                );
            }
        }
    }

    if (!str_contains($content, 'resourceIndex->buildReverseDependencyMap')) {
        $loadPluginsGuardFalse = '$this->loadPluginsGuard = false;';
        $buildMapCode = <<<'PHP'

		$descriptions = [];
		foreach($this->plugins as $p){
			$descriptions[] = $p->getDescription();
		}
		$this->resourceIndex->buildReverseDependencyMap($descriptions);

PHP;
        if (str_contains($content, $loadPluginsGuardFalse)) {
            $content = str_replace(
                $loadPluginsGuardFalse,
                $loadPluginsGuardFalse . "\n" . $buildMapCode,
                $content
            );
        } elseif (preg_match('/\$this\s*->\s*loadPluginsGuard\s*=\s*false\s*;/', $content, $lgMatch)) {
            $content = str_replace(
                $lgMatch[0],
                $lgMatch[0] . "\n" . $buildMapCode,
                $content
            );
        }
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched PluginManager.php');
    }

    return makePatchResult($targetFile, true, false);
}

function createReloadPluginCommand(string $sourceDir): array
{
    $targetDir = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'defaults';
    $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'ReloadPluginCommand.php';

    if (!is_dir($targetDir)) {
        return makePatchResult($targetFile, false, false, 'Command defaults directory not found');
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

    if (file_exists($targetFile) && file_get_contents($targetFile) === $commandContent) {
        return makePatchResult($targetFile, false, true);
    }
    if (file_exists($targetFile)) {
    }

    if (file_put_contents($targetFile, $commandContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write ReloadPluginCommand.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchSimpleCommandMap(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'SimpleCommandMap.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'SimpleCommandMap.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return makePatchResult($targetFile, false, true);
    }
    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read SimpleCommandMap.php');
    }

    if (!str_contains($content, 'use pocketmine\command\defaults\ReloadPluginCommand;')) {
        $useInsertPoint = 'use pocketmine\command\defaults\PluginsCommand;';
        if (str_contains($content, $useInsertPoint)) {
            $content = str_replace(
                $useInsertPoint,
                $useInsertPoint . "\nuse pocketmine\\command\\defaults\\ReloadPluginCommand; /** [BetterPMMP-PATCH] */",
                $content
            );
        }
    }

    if (!str_contains($content, 'new ReloadPluginCommand()')) {
        $insertAfter = 'new PluginsCommand(),';
        if (str_contains($content, $insertAfter)) {
            $content = str_replace(
                $insertAfter,
                $insertAfter . "\n\t\t\tnew ReloadPluginCommand(),",
                $content
            );
        }
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched SimpleCommandMap.php');
    }

    return makePatchResult($targetFile, true, false);
}

function createClassCacheInvalidator(string $sourceDir): array
{
    $targetDir = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin';
    $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'ClassCacheInvalidator.php';

    if (!is_dir($targetDir)) {
        return makePatchResult($targetFile, false, false, 'Plugin directory not found');
    }

    $classContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

/** [BetterPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\thread\ThreadSafeClassLoader;
use function array_key_exists;
use function array_keys;
use function str_replace;
use function count;
use function explode;
use function file_get_contents;
use function filemtime;
use function function_exists;
use function implode;
use function is_dir;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function preg_replace;
use function str_ends_with;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

class ClassCacheInvalidator
{

	private static int $reloadVersion = 0;

	/**
	 * @param array<string, int> $previousMtimes
	 * @return array{mtimes: array<string, int>, changed: bool, version: int}
	 */
	public static function invalidateChanged(string $pluginPath, array $previousMtimes, ThreadSafeClassLoader $autoloader, string $rootNamespace = ''): array
	{
		$currentMtimes = self::scanMtimes($pluginPath);
		$changedFiles = self::detectChangedFiles($currentMtimes, $previousMtimes);

		if (count($changedFiles) === 0) {
			return ['mtimes' => $currentMtimes, 'changed' => false, 'version' => self::$reloadVersion];
		}

		++self::$reloadVersion;
		$version = self::$reloadVersion;

		$opcacheInvalidate = function_exists('opcache_invalidate') ? 'opcache_invalidate' : null;
		$allFiles = array_keys($currentMtimes);
		foreach ($changedFiles as $filePath) {
			if ($opcacheInvalidate !== null) {
				$opcacheInvalidate($filePath, true);
			}
		}

		if ($rootNamespace === '') {
			$rootNamespace = self::detectRootNamespace($pluginPath);
		}

		if ($rootNamespace !== '') {
			$remaining = $allFiles;
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

		return ['mtimes' => $currentMtimes, 'changed' => true, 'version' => $version];
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
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;
		if (!is_dir($scanTarget)) {
			return '';
		}

		$namespaces = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);
		foreach ($iterator as $filePath) {
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$source = @file_get_contents($filePath);
			if ($source === false) {
				continue;
			}
			if (preg_match('/^\s*namespace\s+([^\s;{]+)/m', $source, $m)) {
				$namespaces[] = $m[1];
			}
		}

		if (count($namespaces) === 0) {
			return '';
		}
		if (count($namespaces) === 1) {
			return $namespaces[0];
		}

		$firstParts = explode('\\', $namespaces[0]);
		$commonParts = $firstParts;
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

	/**
	 * @return array<string, int>
	 */
	private static function scanMtimes(string $pluginPath): array
	{
		$mtimes = [];
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;

		if (!is_dir($scanTarget)) {
			return $mtimes;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);

		foreach ($iterator as $filePath) {
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$mtime = filemtime($filePath);
			if ($mtime !== false) {
				$mtimes[$filePath] = $mtime;
			}
		}

		return $mtimes;
	}

	/**
	 * @param array<string, int> $currentMtimes
	 * @param array<string, int> $previousMtimes
	 * @return list<string>
	 */
	private static function detectChangedFiles(array $currentMtimes, array $previousMtimes): array
	{
		$changed = [];
		foreach ($currentMtimes as $filePath => $mtime) {
			if (!array_key_exists($filePath, $previousMtimes)) {
				$changed[] = $filePath;
				continue;
			}
			if ($previousMtimes[$filePath] !== $mtime) {
				$changed[] = $filePath;
			}
		}
		return $changed;
	}

	private static function evalWithVersionedNamespace(string $filePath, int $version, string $rootNamespace): bool
	{
		$source = file_get_contents($filePath);
		if ($source === false) {
			return true;
		}

		$source = preg_replace('/^<\?php\s*/i', '', $source);
		if ($source === null) {
			return true;
		}

		$escaped = preg_quote($rootNamespace, '/');

		$source = str_replace($rootNamespace . '\\', $rootNamespace . '\\v' . $version . '\\', $source);

		$source = preg_replace_callback(
			'/^(\s*namespace\s+)(' . $escaped . ')(\s*[;{])/m',
			static function (array $matches) use ($version): string {
				return $matches[1] . $matches[2] . '\\v' . $version . $matches[3];
			},
			$source
		);
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

	public static function getCurrentVersion(): int
	{
		return self::$reloadVersion;
	}
}
PHPFILE;

    if (file_exists($targetFile) && file_get_contents($targetFile) === $classContent) {
        return makePatchResult($targetFile, false, true);
    }
    if (file_exists($targetFile)) {
    }

    if (file_put_contents($targetFile, $classContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write ClassCacheInvalidator.php');
    }

    return makePatchResult($targetFile, true, false);
}

function createPluginResourceIndex(string $sourceDir): array
{
    $targetDir = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin';
    $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'PluginResourceIndex.php';

    if (!is_dir($targetDir)) {
        return makePatchResult($targetFile, false, false, 'Plugin directory not found');
    }

    $classContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

/** [BetterPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\command\Command;
use pocketmine\event\RegisteredListener;
use pocketmine\permission\Permission;

final class PluginResourceIndex
{

    /** @phpstan-var array<string, PluginResources> */
    private array $resourceMap = [];

    /** @phpstan-var array<string, string[]> */
    private array $reverseDependencyMap = [];

    /** @phpstan-var array<string, array<string, int>> */
    private array $mtimeSnapshots = [];

    /**
     * @param RegisteredListener[] $handlers
     * @param Command[]            $commands
     * @param Permission[]         $permissions
     */
    public function trackPlugin(string $pluginName, array $handlers, array $commands, array $permissions): void
    {
        $this->resourceMap[$pluginName] = new PluginResources($handlers, $commands, $permissions);
    }

    public function getResources(string $pluginName): PluginResources
    {
        return $this->resourceMap[$pluginName] ?? new PluginResources();
    }

    /** @return string[] */
    public function getDependents(string $pluginName): array
    {
        return $this->reverseDependencyMap[$pluginName] ?? [];
    }

    /** @return array<string, int> */
    public function getMtimeSnapshot(string $pluginName): array
    {
        return $this->mtimeSnapshots[$pluginName] ?? [];
    }

    /** @param array<string, int> $snapshot */
    public function updateMtimeSnapshot(string $pluginName, array $snapshot): void
    {
        $this->mtimeSnapshots[$pluginName] = $snapshot;
    }

    public function removePlugin(string $pluginName): void
    {
        unset($this->resourceMap[$pluginName]);
        unset($this->mtimeSnapshots[$pluginName]);

        foreach ($this->reverseDependencyMap as $target => $dependents) {
            $filtered = [];
            foreach ($dependents as $dep) {
                if ($dep !== $pluginName) {
                    $filtered[] = $dep;
                }
            }
            if (\count($filtered) === 0) {
                unset($this->reverseDependencyMap[$target]);
            } else {
                $this->reverseDependencyMap[$target] = $filtered;
            }
        }

        unset($this->reverseDependencyMap[$pluginName]);
    }

    /** @param PluginDescription[] $pluginDescriptions */
    public function buildReverseDependencyMap(array $pluginDescriptions): void
    {
        $this->reverseDependencyMap = [];

        foreach ($pluginDescriptions as $description) {
            $pluginName = $description->getName();
            $allDeps = [...$description->getDepend(), ...$description->getSoftDepend()];

            foreach ($allDeps as $depName) {
                if (!isset($this->reverseDependencyMap[$depName])) {
                    $this->reverseDependencyMap[$depName] = [];
                }
                $this->reverseDependencyMap[$depName][] = $pluginName;
            }
        }
    }
}
PHPFILE;

    if (file_exists($targetFile) && file_get_contents($targetFile) === $classContent) {
        return makePatchResult($targetFile, false, true);
    }
    if (file_exists($targetFile)) {
    }

    if (file_put_contents($targetFile, $classContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write PluginResourceIndex.php');
    }

    return makePatchResult($targetFile, true, false);
}

function createPluginResources(string $sourceDir): array
{
    $targetDir = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin';
    $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'PluginResources.php';

    if (!is_dir($targetDir)) {
        return makePatchResult($targetFile, false, false, 'Plugin directory not found');
    }

    $classContent = <<<'PHPFILE'
<?php

declare(strict_types=1);

/** [BetterPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\command\Command;
use pocketmine\event\RegisteredListener;
use pocketmine\permission\Permission;
use pocketmine\scheduler\TaskHandler;

final class PluginResources{

	/**
	 * @param RegisteredListener[] $handlers
	 * @param Command[]            $commands
	 * @param Permission[]         $permissions
	 * @param TaskHandler[]        $schedulerTasks
	 */
	public function __construct(
		private array $handlers = [],
		private array $commands = [],
		private array $permissions = [],
		private array $schedulerTasks = []
	){}

	/** @return RegisteredListener[] */
	public function getHandlers() : array{
		return $this->handlers;
	}

	/** @return Command[] */
	public function getCommands() : array{
		return $this->commands;
	}

	/** @return Permission[] */
	public function getPermissions() : array{
		return $this->permissions;
	}

	/** @return TaskHandler[] */
	public function getSchedulerTasks() : array{
		return $this->schedulerTasks;
	}

	/** @param TaskHandler[] $tasks */
	public function setSchedulerTasks(array $tasks) : void{
		$this->schedulerTasks = $tasks;
	}
}
PHPFILE;

    if (file_exists($targetFile) && file_get_contents($targetFile) === $classContent) {
        return makePatchResult($targetFile, false, true);
    }
    if (file_exists($targetFile)) {
    }

    if (file_put_contents($targetFile, $classContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write PluginResources.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchReloadPermission(string $sourceDir): array
{
    $permNamesFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'permission' . DIRECTORY_SEPARATOR . 'DefaultPermissionNames.php';

    if (!file_exists($permNamesFile)) {
        return makePatchResult($permNamesFile, false, false, 'DefaultPermissionNames.php not found');
    }

    $permNamesContent = file_get_contents($permNamesFile);
    if ($permNamesContent === false) {
        return makePatchResult($permNamesFile, false, false, 'Failed to read DefaultPermissionNames.php');
    }

    if (str_contains($permNamesContent, 'COMMAND_RELOAD')) {
        return makePatchResult($permNamesFile, false, true);
    }
    $permNamesContent = str_replace(
        'public const COMMAND_PLUGINS = "pocketmine.command.plugins";',
        "public const COMMAND_PLUGINS = \"pocketmine.command.plugins\";\n\tpublic const COMMAND_RELOAD = \"pocketmine.command.reload\";",
        $permNamesContent
    );
    file_put_contents($permNamesFile, $permNamesContent);

    $permFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'permission' . DIRECTORY_SEPARATOR . 'DefaultPermissions.php';
    if (file_exists($permFile)) {
        $permContent = file_get_contents($permFile);
        if ($permContent !== false && strpos($permContent, 'Names::COMMAND_RELOAD') === false) {

            $permContent = str_replace(
                'Names::COMMAND_PLUGINS,',
                "Names::COMMAND_PLUGINS,\n\t\t\tNames::COMMAND_RELOAD,",
                $permContent
            );
            file_put_contents($permFile, $permContent);
        }
    }

    $keysFile = $sourceDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'KnownTranslationKeys.php';
    if (file_exists($keysFile)) {
        $keysContent = file_get_contents($keysFile);
        if ($keysContent !== false && strpos($keysContent, 'POCKETMINE_PERMISSION_COMMAND_RELOAD') === false) {

            $keysContent = str_replace(
                'public const POCKETMINE_PERMISSION_COMMAND_PLUGINS = "pocketmine.permission.command.plugins";',
                "public const POCKETMINE_PERMISSION_COMMAND_PLUGINS = \"pocketmine.permission.command.plugins\";\n\tpublic const POCKETMINE_PERMISSION_COMMAND_RELOAD = \"pocketmine.permission.command.reload\";",
                $keysContent
            );
            file_put_contents($keysFile, $keysContent);
        }
    }

    $paramInfoFile = $sourceDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'KnownTranslationParameterInfo.php';
    if (file_exists($paramInfoFile)) {
        $paramContent = file_get_contents($paramInfoFile);
        if ($paramContent !== false && strpos($paramContent, 'POCKETMINE_PERMISSION_COMMAND_RELOAD') === false) {

            $paramContent = str_replace(
                'Keys::POCKETMINE_PERMISSION_COMMAND_PLUGINS => [],',
                "Keys::POCKETMINE_PERMISSION_COMMAND_PLUGINS => [],\n\t\tKeys::POCKETMINE_PERMISSION_COMMAND_RELOAD => [],",
                $paramContent
            );
            file_put_contents($paramInfoFile, $paramContent);
        }
    }

    $engIniFile = $sourceDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'translations' . DIRECTORY_SEPARATOR . 'eng.ini';
    if (file_exists($engIniFile)) {
        $engContent = file_get_contents($engIniFile);
        if ($engContent !== false && strpos($engContent, 'pocketmine.permission.command.reload') === false) {

            $engContent = str_replace(
                'pocketmine.permission.command.plugins=Allows the user to view the list of plugins',
                "pocketmine.permission.command.plugins=Allows the user to view the list of plugins\npocketmine.permission.command.reload=Allows the user to reload a plugin",
                $engContent
            );
            file_put_contents($engIniFile, $engContent);
        }
    }

    return makePatchResult($permNamesFile, true, false);
}

function patchComposerSyncCheck(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'PocketMine.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'PocketMine.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return makePatchResult($targetFile, false, true);
    }
    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read PocketMine.php');
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
        return makePatchResult($targetFile, false, false, 'Composer sync check block not found in PocketMine.php');
    }

    $newContent = str_replace($searchBlock, '', $content);

    if ($newContent === $content) {
        $newContent = preg_replace(
            '/\$composerGitHash\s*=\s*InstalledVersions::getReference.*?(?:exit\(1\);\s*\}\s*\})/s',
            "/** [BetterPMMP-PATCH] Composer sync check bypassed for source folder execution */",
            $content
        );
        if ($newContent === null || $newContent === $content) {
            return makePatchResult($targetFile, false, false, 'Failed to patch Composer sync check');
        }
    } else {
        $newContent = str_replace(
            "require_once(\$bootstrap);\n",
            "require_once(\$bootstrap);\n\n\t\t/** [BetterPMMP-PATCH] Composer sync check bypassed for source folder execution */\n",
            $newContent
        );
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched PocketMine.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchStartCmdBinPath(string $baseDir): array
{
    $targetFile = $baseDir . DIRECTORY_SEPARATOR . 'start.cmd';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'start.cmd not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read start.cmd');
    }

    if (str_contains($content, 'source\\bin\\php\\php.exe') || str_contains($content, 'source\bin\php\php.exe')) {
        return makePatchResult($targetFile, false, true);
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
    );

    $content = str_replace(
        "Couldn't find a PHP binary in system PATH or \"%~dp0bin\\php\"",
        "Couldn't find a PHP binary in system PATH or \"%~dp0source\\bin\\php\"",
        $content
    );

    if (str_contains($content, "if exist bin\\mintty.exe (")) {
        $nl = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $sourceBinErrorBlock = "if not exist source\\bin (" . $nl . "\techo source\\bin 폴더를 찾을 수 없습니다" . $nl . "\tpause" . $nl . "\texit 1" . $nl . ")" . $nl . $nl;
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

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched start.cmd');
    }

    return makePatchResult($targetFile, true, false);
}

function patchDataPath(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'PocketMine.php';

    if (!file_exists($targetFile))
        return makePatchResult($targetFile, false, false, 'PocketMine.php not found');

    $content = file_get_contents($targetFile);
    if ($content === false)
        return makePatchResult($targetFile, false, false, 'Failed to read PocketMine.php');

    if (str_contains($content, '[BetterPMMP-PATCH] Create system subdirectory'))
        return makePatchResult($targetFile, false, true);
    $oldMkdirBlock = 'if(!@mkdir($dataPath, 0777, true) && !is_dir($dataPath)){';
    $newMkdirBlock = '/** [BetterPMMP-PATCH] Create system subdirectory */' . "\n\t\t"
        . '@mkdir($dataPath . DIRECTORY_SEPARATOR . "system", 0777, true);' . "\n\t\t"
        . 'if(!@mkdir($dataPath, 0777, true) && !is_dir($dataPath)){';

    $newContent = str_replace($oldMkdirBlock, $newMkdirBlock, $content);

    $newContent = str_replace(
        'Path::join($dataPath, \'server.lock\')',
        'Path::join($dataPath, "system", \'server.lock\')',
        $newContent
    );

    $newContent = str_replace(
        'Path::join($dataPath, "log_archive")',
        'Path::join($dataPath, "system", "log_archive")',
        $newContent
    );

    if ($newContent === $content)
        return makePatchResult($targetFile, false, false, 'Failed to patch data path in PocketMine.php');

    if (file_put_contents($targetFile, $newContent) === false)
        return makePatchResult($targetFile, false, false, 'Failed to write patched PocketMine.php');

    return makePatchResult($targetFile, true, false);
}

function patchServerPaths(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Server.php';

    if (!file_exists($targetFile))
        return makePatchResult($targetFile, false, false, 'Server.php not found');

    $content = file_get_contents($targetFile);
    if ($content === false)
        return makePatchResult($targetFile, false, false, 'Failed to read Server.php');

    if (str_contains($content, '"system", "players"'))
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'Failed to patch server paths in Server.php');

    if (file_put_contents($targetFile, $newContent) === false)
        return makePatchResult($targetFile, false, false, 'Failed to write patched Server.php');

    return makePatchResult($targetFile, true, false);
}

function patchStartWarning(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'PocketMine.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'PocketMine.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read PocketMine.php');
    }

    if (str_contains($content, '§aBetterPMMP By UserX0001')) {
        return makePatchResult($targetFile, false, true);
    }

    if (!str_contains($content, 'Non-packaged installation detected')) {
        return makePatchResult($targetFile, false, false, 'Non-packaged installation warning not found in PocketMine.php');
    }
    $replacement = '/** [BetterPMMP-PATCH] Start warning replaced */' . "\n\t\t"
        . '$logger->info("§aBetterPMMP By UserX0001");';

    $oldPattern = '$logger->warning("Non-packaged installation detected. This will degrade autoloading speed and make startup times longer.");';

    $newContent = str_replace($oldPattern, $replacement, $content);

    if ($newContent === null || $newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to replace Non-packaged installation warning');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched PocketMine.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchGarbageCollectorLog(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'GarbageCollectorManager.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'GarbageCollectorManager.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read GarbageCollectorManager.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] GC log output removed')) {
        return makePatchResult($targetFile, false, true);
    }
    $newContent = preg_replace(
        '/\s*\$this->logger->info\(sprintf\(\s*"Run #%d.*?\)\);/s',
        "\n\t\t\t/** [BetterPMMP-PATCH] GC log output removed */",
        $content
    );

    if ($newContent === null || $newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to remove GC log output');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched GarbageCollectorManager.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchServerStartLogs(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Server.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Server.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Server.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] Default game mode log removed')) {
        return makePatchResult($targetFile, false, true);
    }
    $hasDefaultGameMode = str_contains($content, 'pocketmine_server_defaultGameMode');
    $hasLinkBlock = str_contains($content, '$highlight = TextFormat::AQUA;');

    if (!$hasDefaultGameMode && !$hasLinkBlock) {
        return makePatchResult($targetFile, false, true);
    }
    $newContent = $content;

    if ($hasDefaultGameMode) {
        $newContent = preg_replace(
            '/\s*\$this->logger->info\(\$this->language->translate\(\s*KnownTranslationFactory::pocketmine_server_defaultGameMode\(.*?\)\s*\)\);/s',
            "\n\t\t/** [BetterPMMP-PATCH] Default game mode log removed */",
            $newContent
        );
    }

    if ($hasLinkBlock) {
        $newContent = preg_replace(
            '/\s*\$highlight\s*=\s*TextFormat::AQUA;.*?\$this->logger->info\(\$splash\);/s',
            "\n\t\t/** [BetterPMMP-PATCH] Start link logs removed */",
            $newContent
        );
    }

    if ($newContent === null || $newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to remove server start logs');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Server.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchInfoPrefix(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'MainLogger.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'MainLogger.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read MainLogger.php');
    }

    if (str_contains($content, '$prefix === "INFO"')) {
        return makePatchResult($targetFile, false, true);
    }

    $oldSprintf = '$message = sprintf($this->format, $time->format("H:i:s.v"), $color, $threadName, $prefix, TextFormat::addBase($color, TextFormat::clean($message, false)));';

    if (!str_contains($content, $oldSprintf)) {
        return makePatchResult($targetFile, false, false, 'sprintf format line not found in MainLogger.php send()');
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

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched MainLogger.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchBlockInputLag(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'network' . DIRECTORY_SEPARATOR . 'mcpe' . DIRECTORY_SEPARATOR . 'handler' . DIRECTORY_SEPARATOR . 'InGamePacketHandler.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'InGamePacketHandler.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return makePatchResult($targetFile, false, true);
    }
    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read InGamePacketHandler.php');
    }

    $changeCount = 0;

    // Add imports
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

    // Replace syncBlocksNearby method (including its preceding docblock if present)
    $syncSig = 'private function syncBlocksNearby(Vector3 $blockPos, ?int $face) : void{';
    if (str_contains($content, $syncSig)) {
        $syncSigPos = strpos($content, $syncSig);
        $startPos = $syncSigPos;

        // Extend backward to include any preceding docblock (/** ... */)
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
		$snapshot = [];
		$hash = World::blockHash((int) $blockPos->x, (int) $blockPos->y, (int) $blockPos->z);
		$snapshot[$hash] = $world->getBlockAt((int) $blockPos->x, (int) $blockPos->y, (int) $blockPos->z)->getStateId();
		foreach ($blockPos->sidesArray() as $side) {
			$hash = World::blockHash((int) $side->x, (int) $side->y, (int) $side->z);
			$snapshot[$hash] = $world->getBlockAt((int) $side->x, (int) $side->y, (int) $side->z)->getStateId();
		}
		$sidePos = $blockPos->getSide($face);
		$hash = World::blockHash((int) $sidePos->x, (int) $sidePos->y, (int) $sidePos->z);
		$snapshot[$hash] = $world->getBlockAt((int) $sidePos->x, (int) $sidePos->y, (int) $sidePos->z)->getStateId();
		foreach ($sidePos->sidesArray() as $side) {
			$hash = World::blockHash((int) $side->x, (int) $side->y, (int) $side->z);
			$snapshot[$hash] = $world->getBlockAt((int) $side->x, (int) $side->y, (int) $side->z)->getStateId();
		}
		return $snapshot;
	}
NEWSYNC;

            $content = substr($content, 0, $startPos) . $newSyncAndCapture . substr($content, $nextMethodPos);
            $changeCount++;
        }
    }

    // Replace block interaction code
    $interactAnchor = '$this->player->interactBlock($vBlockPos, $data->getFace(), $clickPos);';
    $predictAnchor = '$data->getClientInteractPrediction() === PredictedResult::SUCCESS';
    if (str_contains($content, $interactAnchor) && str_contains($content, $predictAnchor)) {
        $interactPos = strpos($content, $interactAnchor);
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

    if ($changeCount === 0) {
        return makePatchResult($targetFile, false, false, 'Failed to match any block lag fix patterns in InGamePacketHandler.php');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched InGamePacketHandler.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPluginManagerLazyDataFolder(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'PluginManager.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'PluginManager.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read PluginManager.php');
    }

    $markerCount = substr_count($content, '[BETTERPMMP-PATCH-LAZY-DATAFOLDER]');
    $reloadMethodMarkerCount = 0;
    if (str_contains($content, 'public function reloadPlugin')) {
        $reloadStart = strpos($content, 'public function reloadPlugin');
        if ($reloadStart !== false) {
            $depth = 0;
            $braceFound = false;
            $len = strlen($content);
            $reloadEnd = $len;
            for ($i = $reloadStart; $i < $len; $i++) {
                if ($content[$i] === '{') {
                    $depth++;
                    $braceFound = true;
                } elseif ($content[$i] === '}') {
                    $depth--;
                    if ($braceFound && $depth === 0) {
                        $reloadEnd = $i + 1;
                        break;
                    }
                }
            }
            $reloadBody = substr($content, $reloadStart, $reloadEnd - $reloadStart);
            $reloadMethodMarkerCount = substr_count($reloadBody, '[BETTERPMMP-PATCH-LAZY-DATAFOLDER]');
        }
    }
    if ($markerCount > $reloadMethodMarkerCount) {
        return makePatchResult($targetFile, false, true);
    }
    $changed = false;

    $patterns = [
        '/(\t+)if\s*\(\s*!\s*(?:file_exists|is_dir)\s*\(\s*\$dataFolder\s*\)\s*\)\s*\{\s*\r?\n\s*@?mkdir\s*\(\s*\$dataFolder\s*,\s*0777\s*,\s*true\s*\)\s*;\s*\r?\n\s*\}/m',
        '/(\t+)if\s*\(\s*!\s*(?:file_exists|is_dir)\s*\(\s*\$dataFolder\s*\)\s*\)\s*@?mkdir\s*\(\s*\$dataFolder\s*,\s*0777\s*,\s*true\s*\)\s*;/m',
    ];

    $newContent = $content;
    foreach ($patterns as $pattern) {
        $replaced = preg_replace(
            $pattern,
            '$1/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Data folder creation deferred to first use */',
            $newContent
        );
        if ($replaced !== null && $replaced !== $newContent) {
            $newContent = $replaced;
            $changed = true;
        }
    }

    if (!$changed) {
        return makePatchResult($targetFile, false, true);
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched PluginManager.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPluginBaseLazyDataFolder(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'PluginBase.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'PluginBase.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read PluginBase.php');
    }

    if (str_contains($content, '[BETTERPMMP-PATCH-LAZY-DATAFOLDER]')) {
        return makePatchResult($targetFile, false, true);
    }
    $changed = false;

    $insertionAnchors = [
        'public function saveResource(',
        'public function getConfig(',
        'public function saveConfig(',
        'public function getDataFolder(',
        'public function onLoad(',
        'public function onEnable(',
    ];
    $methodInserted = false;
    foreach ($insertionAnchors as $anchor) {
        if (str_contains($content, $anchor)) {
            $ensureMethod = "\t/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Lazy data folder creation */\n"
                . "\tprivate function ensureDataFolderExists(): void\n"
                . "\t{\n"
                . "\t\tif (!is_dir(\$this->dataFolder)) {\n"
                . "\t\t\t@mkdir(\$this->dataFolder, 0777, true);\n"
                . "\t\t}\n"
                . "\t}\n\n\t";
            $content = str_replace($anchor, $ensureMethod . $anchor, $content);
            $changed = true;
            $methodInserted = true;
            break;
        }
    }

    if (!$methodInserted) {
        if (preg_match('/class\s+PluginBase\s+[^{]*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
            $bracePos = strpos($content, '{', $m[0][1]);
            if ($bracePos !== false) {
                $ensureMethod = "\n\t/** [BETTERPMMP-PATCH-LAZY-DATAFOLDER] Lazy data folder creation */\n"
                    . "\tprivate function ensureDataFolderExists(): void\n"
                    . "\t{\n"
                    . "\t\tif (!is_dir(\$this->dataFolder)) {\n"
                    . "\t\t\t@mkdir(\$this->dataFolder, 0777, true);\n"
                    . "\t\t}\n"
                    . "\t}\n";
                $content = substr($content, 0, $bracePos + 1) . $ensureMethod . substr($content, $bracePos + 1);
                $changed = true;
                $methodInserted = true;
            }
        }
    }

    if (!$methodInserted) {
        return makePatchResult($targetFile, false, false, 'Failed to insert ensureDataFolderExists method');
    }
    if (preg_match('/public\s+function\s+saveResource\s*\([^)]*\)\s*:\s*bool\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $bracePos = strpos($content, '{', $m[0][1]);
        if ($bracePos !== false) {
            $afterBrace = $bracePos + 1;
            if (!preg_match('/\$this->ensureDataFolderExists\(\);/', substr($content, $afterBrace, 200))) {
                $content = substr($content, 0, $afterBrace)
                    . "\n\t\t\$this->ensureDataFolderExists();"
                    . substr($content, $afterBrace);
                $changed = true;
            }
        }
    }

    if (preg_match('/public\s+function\s+saveConfig\s*\(\s*\)\s*:\s*void\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $bracePos = strpos($content, '{', $m[0][1]);
        if ($bracePos !== false) {
            $afterBrace = $bracePos + 1;
            if (!preg_match('/\$this->ensureDataFolderExists\(\);/', substr($content, $afterBrace, 200))) {
                $content = substr($content, 0, $afterBrace)
                    . "\n\t\t\$this->ensureDataFolderExists();"
                    . substr($content, $afterBrace);
                $changed = true;
            }
        }
    }

    if (preg_match('/public\s+function\s+getDataFolder\s*\(\s*\)\s*:\s*string\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $bracePos = strpos($content, '{', $m[0][1]);
        if ($bracePos !== false) {
            $afterBrace = $bracePos + 1;
            if (!preg_match('/\$this->ensureDataFolderExists\(\);/', substr($content, $afterBrace, 200))) {
                $content = substr($content, 0, $afterBrace)
                    . "\n\t\t\$this->ensureDataFolderExists();"
                    . substr($content, $afterBrace);
                $changed = true;
            }
        }
    }

    if (preg_match('/public\s+function\s+getConfig\s*\(\s*\)\s*:\s*Config\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $bracePos = strpos($content, '{', $m[0][1]);
        if ($bracePos !== false) {
            $afterBrace = $bracePos + 1;
            if (!preg_match('/\$this->ensureDataFolderExists\(\);/', substr($content, $afterBrace, 200))) {
                $content = substr($content, 0, $afterBrace)
                    . "\n\t\t\$this->ensureDataFolderExists();"
                    . substr($content, $afterBrace);
                $changed = true;
            }
        }
    }

    if (!$changed) {
        return makePatchResult($targetFile, false, false, 'Failed to patch PluginBase.php for lazy data folder');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched PluginBase.php');
    }

    return makePatchResult($targetFile, true, false);
}

function createRestartCommand(string $sourceDir): array
{
    $targetDir = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'defaults';
    $targetFile = $targetDir . DIRECTORY_SEPARATOR . 'RestartCommand.php';

    if (!is_dir($targetDir)) {
        return makePatchResult($targetFile, false, false, 'Command defaults directory not found');
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
		// Use the directory 5 levels above this file (source/src/command/defaults/ → server root)
		// to ensure the flag is always written to the same place start.cmd checks.
		$restartFlag = dirname(__FILE__, 5) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'restart.flag';
		file_put_contents($restartFlag, '1');

		Command::broadcastCommandMessage($sender, "§eServer is restarting...");

		$sender->getServer()->shutdown();
		return true;
	}
}
PHPFILE;

    if (file_exists($targetFile) && file_get_contents($targetFile) === $commandContent) {
        return makePatchResult($targetFile, false, true);
    }
    if (file_exists($targetFile)) {
    }

    if (file_put_contents($targetFile, $commandContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write RestartCommand.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchSimpleCommandMapRestartCommand(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'SimpleCommandMap.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'SimpleCommandMap.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read SimpleCommandMap.php');
    }

    if (str_contains($content, 'new RestartCommand()')) {
        return makePatchResult($targetFile, false, true);
    }
    // Add use statement after StopCommand use (preferred) or PluginsCommand use (fallback)
    if (!str_contains($content, 'use pocketmine\command\defaults\RestartCommand;')) {
        $useInsertCandidates = [
            'use pocketmine\command\defaults\StopCommand;',
            'use pocketmine\command\defaults\PluginsCommand;',
        ];
        foreach ($useInsertCandidates as $candidate) {
            if (str_contains($content, $candidate)) {
                $content = str_replace(
                    $candidate,
                    $candidate . "\nuse pocketmine\\command\\defaults\\RestartCommand; /** [BetterPMMP-PATCH] */",
                    $content
                );
                break;
            }
        }
    }

    // Register command after new StopCommand() (preferred) or new SaveCommand() (fallback)
    $insertCandidates = [
        'new StopCommand(),',
        'new SaveCommand(),',
    ];
    $inserted = false;
    foreach ($insertCandidates as $candidate) {
        if (str_contains($content, $candidate)) {
            $content = str_replace(
                $candidate,
                $candidate . "\n\t\t\tnew RestartCommand(),",
                $content
            );
            $inserted = true;
            break;
        }
    }

    if (!$inserted) {
        return makePatchResult($targetFile, false, false, 'Failed to find insertion point in SimpleCommandMap.php');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched SimpleCommandMap.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchStartCmdRestartLoop(string $baseDir): array
{
    $targetFile = $baseDir . DIRECTORY_SEPARATOR . 'start.cmd';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'start.cmd not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read start.cmd');
    }

    // Correctly patched state: has the restart loop, no mintty block (which bypasses the loop
    // via "start "" + goto :EOF"), and no goto :EOF remnant from the old broken patch.
    $hasLoop = str_contains($content, ':betterpmmp_start');
    $hasMintty = (bool) preg_match('/\nif exist [^\r\n]*mintty\.exe \(/', $content);
    $hasGotoEOF = str_contains($content, 'goto :EOF');
    $hasSystemFlag = str_contains($content, 'system\\restart.flag') || str_contains($content, 'system/restart.flag');

    if ($hasLoop && !$hasMintty && !$hasGotoEOF && $hasSystemFlag) {
        return makePatchResult($targetFile, false, true);
    }
    $nl = str_contains($content, "\r\n") ? "\r\n" : "\n";

    // Always run PHP directly (no mintty) so the restart loop can reliably
    // detect restart.flag after PHP exits. mintty uses "start """ which is
    // asynchronous — the batch process exits immediately and the restart loop
    // is never reached.
    $restartLoop =
        ":betterpmmp_start" . $nl
        . "%PHP_BINARY% %POCKETMINE_FILE% %*" . $nl
        . "if exist system\\restart.flag (" . $nl
        . "\tdel system\\restart.flag" . $nl
        . "\tgoto :betterpmmp_start" . $nl
        . ")" . $nl
        . "if errorlevel 1 pause";

    // Strategy: find where the mintty-if block (or any previous restart section) starts,
    // cut everything from that point, and append the clean restart loop.
    // This handles: (a) fresh start.cmd with mintty if/else, (b) old broken patch
    // with goto :EOF + separate :betterpmmp_start, (c) else-only start.cmd.
    $cutPos = null;

    // Priority 1: mintty if-block — present in normal and broken-old-patch cases
    if (preg_match('/(\r?\n)if exist [^\r\n]*mintty\.exe \(/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $cutPos = $m[0][1];
    }

    // Priority 2: previous (broken) restart loop without mintty
    if (!$hasMintty && preg_match('/(\r?\n):betterpmmp_start(\r?\n)/', $content, $m2, PREG_OFFSET_CAPTURE)) {
        $pos2 = $m2[0][1];
        if ($cutPos === null || $pos2 < $cutPos) {
            $cutPos = $pos2;
        }
    }

    // Priority 3: else-only block (no mintty, just ") else (" with %PHP_BINARY%)
    if ($cutPos === null && preg_match('/(\r?\n)\) else \(\r?\n[^\r\n]*\r?\n[^\r\n]*%PHP_BINARY%/', $content, $m3, PREG_OFFSET_CAPTURE)) {
        $cutPos = $m3[0][1];
    }

    if ($cutPos !== null) {
        $newContent = rtrim(substr($content, 0, $cutPos)) . $nl . $nl . $restartLoop . $nl;
    } else {
        // Fallback: generic regex to replace any if/else execution block at end of file
        $newContent = preg_replace(
            '/\r?\nif exist [^\r\n]*\.exe \([^\r\n]*\r?\n[^\r\n]*\r?\n\) else \(\r?\n[^\r\n]*\r?\n[^\r\n]*\r?\n\)[\r\n]*$/s',
            $nl . $nl . $restartLoop . $nl,
            $content
        );
        if ($newContent === null || $newContent === $content) {
            return makePatchResult($targetFile, false, false, 'Failed to inject restart loop into start.cmd');
        }
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched start.cmd');
    }

    return makePatchResult($targetFile, true, false);
}

function patchIronDoorNoInteract(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'block' . DIRECTORY_SEPARATOR . 'Door.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Door.php not found');
    }

    if (isAlreadyPatched($targetFile)) {
        return makePatchResult($targetFile, false, true);
    }
    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Door.php');
    }

    // Add BlockTypeIds import if missing
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
        return makePatchResult($targetFile, false, false, 'Failed to match onInteract pattern in Door.php');
    }

    $newOnInteract = '/** [BetterPMMP-PATCH] Iron door: block onInteract completely */' . "\n"
        . "\t" . 'public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{' . "\n"
        . "\t\t" . 'if($this->getTypeId() === BlockTypeIds::IRON_DOOR){' . "\n"
        . "\t\t\t" . 'return true;' . "\n"
        . "\t\t" . '}' . "\n"
        . "\t\t" . '$this->open = !$this->open;';

    $content = str_replace($oldOnInteract, $newOnInteract, $content);

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Door.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPocketmineYmlBetterPmmp(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'pocketmine.yml';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'pocketmine.yml not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read pocketmine.yml');
    }

    if (str_contains($content, 'better-pmmp:')) {
        return makePatchResult($targetFile, false, true);
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

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched pocketmine.yml');
    }

    return makePatchResult($targetFile, true, false);
}

function patchYmlServerPropertiesBetterPmmp(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'YmlServerProperties.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'YmlServerProperties.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read YmlServerProperties.php');
    }

    if (str_contains($content, 'BETTER_PMMP')) {
        return makePatchResult($targetFile, false, true);
    }
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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match WORLDS constant pattern in YmlServerProperties.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched YmlServerProperties.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldFixedLight(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Fixed light values bypass')) {
        return makePatchResult($targetFile, false, true);
    }
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
        . "\t\t\t\tforeach(\$targetChunk->getSubChunks() as \$subY => \$subChunk){\n"
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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match orderLightPopulation pattern in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (fixed light)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldPerWorldChunkTicking(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Per-world chunk ticking override')) {
        return makePatchResult($targetFile, false, true);
    }
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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match chunk ticking config pattern in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (per-world chunk ticking)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldChunkOptimization(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Batch recheck limit for chunk tick optimization')) {
        return makePatchResult($targetFile, false, true);
    }
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

    $newContent = str_replace($oldTickChunks, $newTickChunks, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match chunk optimization patterns in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (chunk optimization)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPlayerPerWorldViewDistance(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . 'Player.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Player.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Player.php');
    }

    if (str_contains($content, 'Per-world view distance override')) {
        return makePatchResult($targetFile, false, true);
    }

    $oldField = "\tprotected int \$viewDistance = -1;";
    $newField = "\tprotected int \$viewDistance = -1;\n"
        . "\t/** [BetterPMMP-PATCH] Original view-distance requested by client (pre-clamp, pre-override). Used to re-apply per-world override on world change. */\n"
        . "\tprotected int \$requestedViewDistance = -1;";
    $newContent = str_replace($oldField, $newField, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match $viewDistance field declaration in Player.php');
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
        . "\t\t\t\$currentWorld = \$this->getWorld();\n"
        . "\t\t\tif(\$currentWorld !== null){\n"
        . "\t\t\t\t\$worldFolder = \$currentWorld->getFolderName();\n"
        . "\t\t\t\tif(isset(\$perWorldViewDistance[\$worldFolder])){\n"
        . "\t\t\t\t\t\$newViewDistance = max(2, (int) \$perWorldViewDistance[\$worldFolder]);\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\n"
        . "\t\tif(\$newViewDistance !== \$this->viewDistance){";

    $newContent2 = str_replace($oldSetView, $newSetView, $newContent);
    if ($newContent2 === $newContent) {
        return makePatchResult($targetFile, false, false, 'Failed to match setViewDistance pattern in Player.php');
    }
    $newContent = $newContent2;

    $oldChunks = "\t\t\t\$this->server->getAllowedViewDistance(\$this->viewDistance),";
    $newChunks = "\t\t\t\$this->viewDistance,";
    $newContent = str_replace($oldChunks, $newChunks, $newContent);

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

    $newContent = str_replace($oldTeleport, $newTeleport, $newContent);

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Player.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchServerLogPath(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'PocketMine.php';

    if (!file_exists($targetFile))
        return makePatchResult($targetFile, false, false, 'PocketMine.php not found');

    $content = file_get_contents($targetFile);
    if ($content === false)
        return makePatchResult($targetFile, false, false, 'Failed to read PocketMine.php');

    if (str_contains($content, '"system", "server.log"'))
        return makePatchResult($targetFile, false, true);
    $newContent = str_replace(
        'Path::join($dataPath, "server.log")',
        'Path::join($dataPath, "system", "server.log")',
        $content
    );

    if ($newContent === $content)
        return makePatchResult($targetFile, false, false, 'Failed to patch server.log path in PocketMine.php');

    if (file_put_contents($targetFile, $newContent) === false)
        return makePatchResult($targetFile, false, false, 'Failed to write patched PocketMine.php');

    return makePatchResult($targetFile, true, false);
}

function patchCrashdumpsPath(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Server.php';

    if (!file_exists($targetFile))
        return makePatchResult($targetFile, false, false, 'Server.php not found');

    $content = file_get_contents($targetFile);
    if ($content === false)
        return makePatchResult($targetFile, false, false, 'Failed to read Server.php');

    if (str_contains($content, '"system", "crashdumps"'))
        return makePatchResult($targetFile, false, true);
    $newContent = str_replace(
        'Path::join($this->dataPath, "crashdumps")',
        'Path::join($this->dataPath, "system", "crashdumps")',
        $content
    );

    if ($newContent === $content)
        return makePatchResult($targetFile, false, false, 'Failed to patch crashdumps path in Server.php');

    if (file_put_contents($targetFile, $newContent) === false)
        return makePatchResult($targetFile, false, false, 'Failed to write patched Server.php');

    return makePatchResult($targetFile, true, false);
}

function patchWorldNeighbourUpdateThrottle(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Neighbour block update throttle')) {
        return makePatchResult($targetFile, false, true);
    }
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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match neighbour update loop in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (neighbour update throttle)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldBlockCacheSize(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'blockCacheSizeCap')) {
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'Failed to patch block cache size in World.php - anchor mismatch');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (block cache size)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchEntityMoveInPlace(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'In-place location update')) {
        return makePatchResult($targetFile, false, true);
    }
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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match Location construction in Entity.php move()');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php (move in-place)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchEntitySmartBlocksAroundCache(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'lastBlockFloorX')) {
        return makePatchResult($targetFile, false, true);
    }
    $content = str_replace(
        "\tprotected ?array \$blocksAround = null;",
        "\tprotected ?array \$blocksAround = null;\n"
        . "\t/** [BetterPMMP-PATCH] Smart blocksAround cache tracking */\n"
        . "\tprivate int \$lastBlockFloorX = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockFloorY = PHP_INT_MIN;\n"
        . "\tprivate int \$lastBlockFloorZ = PHP_INT_MIN;",
        $content
    );

    $content = str_replace(
        "\tprotected function move(float \$dx, float \$dy, float \$dz) : void{\n"
        . "\t\t\$this->blocksAround = null;\n"
        . "\n"
        . "\t\tTimings::\$entityMove->startTiming();",
        "\tprotected function move(float \$dx, float \$dy, float \$dz) : void{\n"
        . "\t\tTimings::\$entityMove->startTiming();",
        $content
    );

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
        return makePatchResult($targetFile, false, false, 'Failed to patch smart blocksAround cache in Entity.php - anchor mismatch');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php (smart blocksAround cache)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchEntityMotionEpsilonCleanup(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'Motion epsilon cleanup')) {
        return makePatchResult($targetFile, false, true);
    }
    $old = "\t\t\$this->motion = new Vector3(\$this->motion->x * \$friction, \$mY, \$this->motion->z * \$friction);\n"
        . "\t}";

    $new = "\t\t/** [BetterPMMP-PATCH] Motion epsilon cleanup - zeroes sub-threshold components after friction */\n"
        . "\t\t\$mX = \$this->motion->x * \$friction;\n"
        . "\t\t\$mZ = \$this->motion->z * \$friction;\n"
        . "\t\t\$this->motion->x = abs(\$mX) < 1.0E-6 ? 0.0 : \$mX;\n"
        . "\t\t\$this->motion->y = abs(\$mY) < 1.0E-6 ? 0.0 : \$mY;\n"
        . "\t\t\$this->motion->z = abs(\$mZ) < 1.0E-6 ? 0.0 : \$mZ;\n"
        . "\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match tryChangeMovement() end in Entity.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php (motion epsilon cleanup)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPocketmineYmlCriticalHit(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'pocketmine.yml';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'pocketmine.yml not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read pocketmine.yml');
    }

    if (str_contains($content, 'ignore-sprint:')) {
        return makePatchResult($targetFile, false, true);
    }

    $newCritBlock = <<<'YAML'
  # Critical hit settings
  critical-hit:
    # If true, critical hits can land even while sprinting. Vanilla requires not sprinting.
    ignore-sprint: false
    # Minimum fall distance required for a critical hit. Vanilla: 0.5. Set to 0.0 to trigger even from ground.
    min-fall-distance: 0.5
YAML;

    if (str_contains($content, 'critical-hit:')) {
        $newContent = str_replace(
            "    # Whether vanilla critical hit logic is active. false = critical hits never trigger.\n    enabled: true\n",
            "    # If true, critical hits can land even while sprinting. Vanilla requires not sprinting.\n    ignore-sprint: false\n",
            $content
        );
        if ($newContent === $content) {
            return makePatchResult($targetFile, false, false, 'Failed to update existing critical-hit block in pocketmine.yml');
        }
    } else {
        $anchor = '  block-cache-size: 8192';
        $newContent = str_contains($content, $anchor)
            ? str_replace($anchor, $anchor . "\n" . $newCritBlock, $content)
            : rtrim($content) . "\n" . $newCritBlock . "\n";
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched pocketmine.yml');
    }

    return makePatchResult($targetFile, true, false);
}

function patchYmlServerPropertiesCriticalHit(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'YmlServerProperties.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'YmlServerProperties.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read YmlServerProperties.php');
    }

    if (str_contains($content, 'BETTER_PMMP_CRITICAL_HIT_IGNORE_SPRINT')) {
        return makePatchResult($targetFile, false, true);
    }

    if (str_contains($content, 'BETTER_PMMP_CRITICAL_HIT_ENABLED')) {
        $newContent = str_replace(
            "public const BETTER_PMMP_CRITICAL_HIT_ENABLED = 'better-pmmp.critical-hit.enabled';",
            "public const BETTER_PMMP_CRITICAL_HIT_IGNORE_SPRINT = 'better-pmmp.critical-hit.ignore-sprint';",
            $content
        );
        if ($newContent === $content) {
            return makePatchResult($targetFile, false, false, 'Failed to update BETTER_PMMP_CRITICAL_HIT_ENABLED in YmlServerProperties.php');
        }
        if (file_put_contents($targetFile, $newContent) === false) {
            return makePatchResult($targetFile, false, false, 'Failed to write patched YmlServerProperties.php');
        }
        return makePatchResult($targetFile, true, false);
    }

    $old = "\tpublic const WORLDS = 'worlds';\n}";
    $new = "\t/** [BetterPMMP-PATCH] Critical hit config constants */\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT = 'better-pmmp.critical-hit';\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT_IGNORE_SPRINT = 'better-pmmp.critical-hit.ignore-sprint';\n"
        . "\tpublic const BETTER_PMMP_CRITICAL_HIT_MIN_FALL_DISTANCE = 'better-pmmp.critical-hit.min-fall-distance';\n"
        . "\n"
        . "\tpublic const WORLDS = 'worlds';\n}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match WORLDS constant in YmlServerProperties.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched YmlServerProperties.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPlayerCriticalHit(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . 'Player.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Player.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Player.php');
    }

    if (str_contains($content, 'better-pmmp.critical-hit.ignore-sprint')) {
        return makePatchResult($targetFile, false, true);
    }

    $pattern = '/^([ \t]*)if\s*\(\s*!\$this->isSprinting\(\)\s*&&\s*!\$this->isFlying\(\)\s*&&\s*\$this->fallDistance\s*>\s*0\s*&&\s*!\$this->effectManager->has\(\s*VanillaEffects::BLINDNESS\(\)\s*\)\s*&&\s*!\$this->isUnderwater\(\)\s*\)\s*\{[^\n]*\n([ \t]*)\$ev->setModifier\(\s*\$ev->getFinalDamage\(\)\s*\/\s*2\s*,\s*EntityDamageEvent::MODIFIER_CRITICAL\s*\)\s*;[^\n]*\n[ \t]*\}/m';

    if (!preg_match($pattern, $content, $matches)) {
        return makePatchResult($targetFile, false, false, 'Failed to match critical hit condition in Player.php');
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
        return makePatchResult($targetFile, false, false, 'Failed to replace critical hit condition in Player.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Player.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldEntityTickClose(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Entity tick close guard')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\t\t//Update entities that need update\n"
        . "\t\tforeach(\$this->updateEntities as \$id => \$entity){\n"
        . "\t\t\tif(\$entity->isClosed() || \$entity->isFlaggedForDespawn() || !\$entity->onUpdate(\$currentTick)){\n"
        . "\t\t\t\tunset(\$this->updateEntities[\$id]);\n"
        . "\t\t\t}\n"
        . "\t\t\tif(\$entity->isFlaggedForDespawn()){\n"
        . "\t\t\t\t\$entity->close();\n"
        . "\t\t\t}\n"
        . "\t\t}";

    $new = "\t\t/** [BetterPMMP-PATCH] Entity tick close guard - prevents redundant close() on already-closed entities */\n"
        . "\t\tforeach(\$this->updateEntities as \$id => \$entity){\n"
        . "\t\t\tif(\$entity->isClosed() || \$entity->isFlaggedForDespawn() || !\$entity->onUpdate(\$currentTick)){\n"
        . "\t\t\t\tunset(\$this->updateEntities[\$id]);\n"
        . "\t\t\t}\n"
        . "\t\t\tif(!\$entity->isClosed() && \$entity->isFlaggedForDespawn()){\n"
        . "\t\t\t\t\$entity->close();\n"
        . "\t\t\t}\n"
        . "\t\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match entity tick loop in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (entity tick close)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldUnloadEntityIteration(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Safe entity iteration during world unload')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\t\tforeach(\$this->entitiesByChunk as \$chunkHash => \$entities){\n"
        . "\t\t\tself::getXZ(\$chunkHash, \$chunkX, \$chunkZ);\n"
        . "\n"
        . "\t\t\t\$leakedEntities = 0;\n"
        . "\t\t\tforeach(\$entities as \$entity){\n"
        . "\t\t\t\tif(!\$entity->isFlaggedForDespawn()){\n"
        . "\t\t\t\t\t\$leakedEntities++;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\t\$entity->close();\n"
        . "\t\t\t}\n"
        . "\t\t\tif(\$leakedEntities !== 0){\n"
        . "\t\t\t\t\$this->logger->warning(\"\$leakedEntities leaked entities found in ungenerated chunk \$chunkX \$chunkZ during unload, they won't be saved!\");\n"
        . "\t\t\t}\n"
        . "\t\t}";

    $new = "\t\t/** [BetterPMMP-PATCH] Safe entity iteration during world unload - snapshot keys to avoid modification during iteration */\n"
        . "\t\t\$chunkHashes = array_keys(\$this->entitiesByChunk);\n"
        . "\t\tforeach(\$chunkHashes as \$chunkHash){\n"
        . "\t\t\tif(!isset(\$this->entitiesByChunk[\$chunkHash])){\n"
        . "\t\t\t\tcontinue;\n"
        . "\t\t\t}\n"
        . "\t\t\t\$entities = \$this->entitiesByChunk[\$chunkHash];\n"
        . "\t\t\tself::getXZ(\$chunkHash, \$chunkX, \$chunkZ);\n"
        . "\n"
        . "\t\t\t\$leakedEntities = 0;\n"
        . "\t\t\tforeach(\$entities as \$entity){\n"
        . "\t\t\t\tif(!\$entity->isFlaggedForDespawn()){\n"
        . "\t\t\t\t\t\$leakedEntities++;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\tif(!\$entity->isClosed()){\n"
        . "\t\t\t\t\t\$entity->close();\n"
        . "\t\t\t\t}\n"
        . "\t\t\t}\n"
        . "\t\t\tif(\$leakedEntities !== 0){\n"
        . "\t\t\t\t\$this->logger->warning(\"\$leakedEntities leaked entities found in ungenerated chunk \$chunkX \$chunkZ during unload, they won't be saved!\");\n"
        . "\t\t\t}\n"
        . "\t\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match entitiesByChunk unload loop in World.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (unload entity iteration)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchNetworkSessionSetHandlerGuard(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'network' . DIRECTORY_SEPARATOR . 'mcpe' . DIRECTORY_SEPARATOR . 'NetworkSession.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'NetworkSession.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read NetworkSession.php');
    }

    if (str_contains($content, 'setHandler disconnect guard')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\tpublic function setHandler(?PacketHandler \$handler) : void{\n"
        . "\t\tif(\$this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases";

    $new = "\t/** [BetterPMMP-PATCH] setHandler disconnect guard - prevents handler assignment during disconnect cleanup */\n"
        . "\tpublic function setHandler(?PacketHandler \$handler) : void{\n"
        . "\t\tif(\$this->connected && !\$this->disconnectGuard){";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match setHandler() in NetworkSession.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched NetworkSession.php (setHandler guard)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchHandlerListRegisterAllCache(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'event' . DIRECTORY_SEPARATOR . 'HandlerList.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'HandlerList.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read HandlerList.php');
    }

    if (str_contains($content, 'Batch register listeners')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\tpublic function registerAll(array \$listeners) : void{\n"
        . "\t\tforeach(\$listeners as \$listener){\n"
        . "\t\t\t\$this->register(\$listener);\n"
        . "\t\t}\n"
        . "\t\t\$this->invalidateAffectedCaches();\n"
        . "\t}";

    $new = "\t/** [BetterPMMP-PATCH] Batch register listeners - single cache invalidation instead of N+1 */\n"
        . "\tpublic function registerAll(array \$listeners) : void{\n"
        . "\t\tforeach(\$listeners as \$listener){\n"
        . "\t\t\tif(isset(\$this->handlerSlots[\$listener->getPriority()][spl_object_id(\$listener)])){\n"
        . "\t\t\t\tthrow new \\InvalidArgumentException(\"This listener is already registered to priority {\$listener->getPriority()} of event {\$this->class}\");\n"
        . "\t\t\t}\n"
        . "\t\t\t\$this->handlerSlots[\$listener->getPriority()][spl_object_id(\$listener)] = \$listener;\n"
        . "\t\t}\n"
        . "\t\t\$this->invalidateAffectedCaches();\n"
        . "\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match registerAll() in HandlerList.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched HandlerList.php (registerAll cache)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchEntityHealthFloatComparison(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'Float-safe health comparison')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\tpublic function setHealth(float \$amount) : void{\n"
        . "\t\tif(\$amount === \$this->health){\n"
        . "\t\t\treturn;\n"
        . "\t\t}";

    $new = "\tpublic function setHealth(float \$amount) : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] Float-safe health comparison using epsilon */\n"
        . "\t\tif(abs(\$amount - \$this->health) < 1e-6){\n"
        . "\t\t\treturn;\n"
        . "\t\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match setHealth() in Entity.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php (health float comparison)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPlayerRespawnLockReset(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . 'Player.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Player.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Player.php');
    }

    if (str_contains($content, 'Respawn lock reset on disconnect')) {
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'Failed to match respawn success callback in Player.php');
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
        return makePatchResult($targetFile, false, false, 'Failed to match respawn error callback in Player.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Player.php (respawn lock reset)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchHandlerListMergePerformance(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'event' . DIRECTORY_SEPARATOR . 'HandlerList.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'HandlerList.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read HandlerList.php');
    }

    if (str_contains($content, 'Single-pass handler list merge')) {
        return makePatchResult($targetFile, false, true);
    }

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

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match getListenerList() merge in HandlerList.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched HandlerList.php (merge performance)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchServerRemoveOnlinePlayerSnapshot(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Server.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Server.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Server.php');
    }

    if (str_contains($content, 'Snapshot playerList before notification')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\tpublic function removeOnlinePlayer(Player \$player) : void{\n"
        . "\t\tif(isset(\$this->playerList[\$rawUUID = \$player->getUniqueId()->getBytes()])){\n"
        . "\t\t\tunset(\$this->playerList[\$rawUUID]);\n"
        . "\t\t\tforeach(\$this->playerList as \$p){\n"
        . "\t\t\t\t\$p->getNetworkSession()->onPlayerRemoved(\$player);\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t}";

    $new = "\t/** [BetterPMMP-PATCH] Snapshot playerList before notification loop to prevent nested iteration */\n"
        . "\tpublic function removeOnlinePlayer(Player \$player) : void{\n"
        . "\t\tif(isset(\$this->playerList[\$rawUUID = \$player->getUniqueId()->getBytes()])){\n"
        . "\t\t\tunset(\$this->playerList[\$rawUUID]);\n"
        . "\t\t\t\$onlinePlayers = \$this->playerList;\n"
        . "\t\t\tforeach(\$onlinePlayers as \$p){\n"
        . "\t\t\t\t\$p->getNetworkSession()->onPlayerRemoved(\$player);\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t}";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match removeOnlinePlayer() in Server.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Server.php (removeOnlinePlayer snapshot)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchPlayerDestroyCyclesCleanup(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . 'Player.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Player.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Player.php');
    }

    if (str_contains($content, 'Defensive reference cleanup')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\tprotected function destroyCycles() : void{\n"
        . "\t\t\$this->networkSession = null;\n"
        . "\t\tunset(\$this->cursorInventory);\n"
        . "\t\tunset(\$this->craftingGrid);\n"
        . "\t\t\$this->spawnPosition = null;";

    $new = "\t/** [BetterPMMP-PATCH] Defensive reference cleanup - clear singleton reference for GC */\n"
        . "\tprotected function destroyCycles() : void{\n"
        . "\t\t\$this->networkSession = null;\n"
        . "\t\tunset(\$this->cursorInventory);\n"
        . "\t\tunset(\$this->craftingGrid);\n"
        . "\t\tunset(\$this->creativeInventory);\n"
        . "\t\t\$this->spawnPosition = null;";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match destroyCycles() in Player.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Player.php (destroyCycles cleanup)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchWorldUnloadChunkEntityClose(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'Guard against double-close during chunk unload')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\t\t\t\tforeach(\$this->getChunkEntities(\$x, \$z) as \$entity){\n"
        . "\t\t\t\t\tif(\$entity instanceof Player){\n"
        . "\t\t\t\t\t\tcontinue;\n"
        . "\t\t\t\t\t}\n"
        . "\t\t\t\t\t\$entity->close();\n"
        . "\t\t\t\t}";

    $old3tab = "\t\t\tforeach(\$this->getChunkEntities(\$x, \$z) as \$entity){\n"
        . "\t\t\t\tif(\$entity instanceof Player){\n"
        . "\t\t\t\t\tcontinue;\n"
        . "\t\t\t\t}\n"
        . "\t\t\t\t\$entity->close();\n"
        . "\t\t\t}";

    $matched = false;
    foreach ([$old, $old3tab] as $anchor) {
        if (str_contains($content, $anchor)) {
            $indent = ($anchor === $old) ? "\t\t\t\t" : "\t\t\t";
            $inner = $indent . "\t";
            $innerInner = $inner . "\t";
            $new = $indent . "/** [BetterPMMP-PATCH] Guard against double-close during chunk unload */\n"
                . $indent . "foreach(\$this->getChunkEntities(\$x, \$z) as \$entity){\n"
                . $inner . "if(\$entity instanceof Player){\n"
                . $innerInner . "continue;\n"
                . $inner . "}\n"
                . $inner . "if(!\$entity->isClosed()){\n"
                . $innerInner . "\$entity->close();\n"
                . $inner . "}\n"
                . $indent . "}";
            $content = str_replace($anchor, $new, $content);
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        return makePatchResult($targetFile, false, false, 'Failed to match chunk entity close loop in World.php');
    }

    $newContent = $content;

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php (chunk entity close guard)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchNetworkSessionDisconnectGuardTiming(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'network' . DIRECTORY_SEPARATOR . 'mcpe' . DIRECTORY_SEPARATOR . 'NetworkSession.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'NetworkSession.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read NetworkSession.php');
    }

    if (str_contains($content, 'Keep disconnectGuard active through full cleanup')) {
        return makePatchResult($targetFile, false, true);
    }

    $old = "\t\t\t\$this->disconnectGuard = true;\n"
        . "\t\t\t\$func();\n"
        . "\t\t\t\$this->disconnectGuard = false;\n"
        . "\t\t\t\$this->flushGamePacketQueue();";

    $new = "\t\t\t\$this->disconnectGuard = true;\n"
        . "\t\t\t\$func();\n"
        . "\t\t\t/** [BetterPMMP-PATCH] Keep disconnectGuard active through full cleanup - session is never reused */\n"
        . "\t\t\t\$this->flushGamePacketQueue();";

    $newContent = str_replace($old, $new, $content);
    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to match tryDisconnect() guard reset in NetworkSession.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched NetworkSession.php (disconnect guard timing)');
    }

    return makePatchResult($targetFile, true, false);
}

function patchClassMapAuthoritative(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_real.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'autoload_real.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read autoload_real.php');
    }

    if (str_contains($content, '[BetterPMMP-PATCH] classmap-authoritative disabled')) {
        return makePatchResult($targetFile, false, true);
    }

    if (!str_contains($content, '$loader->setClassMapAuthoritative(true);')) {
        return makePatchResult($targetFile, false, true);
    }

    $newContent = str_replace(
        '$loader->setClassMapAuthoritative(true);',
        '/** [BetterPMMP-PATCH] classmap-authoritative disabled to allow PSR-4 fallback for patched classes */' . "\n        \$loader->setClassMapAuthoritative(false);",
        $content
    );

    if ($newContent === $content) {
        return makePatchResult($targetFile, false, false, 'Failed to patch autoload_real.php');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched autoload_real.php');
    }

    return makePatchResult($targetFile, true, false);
}

function printUsage(): void
{
    fwrite(STDOUT, "BetterPMMP Patch Tool\n");
    fwrite(STDOUT, "Usage: php patch_tool.php <source_directory_path>\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "  <source_directory_path>  Path to the PMMP source directory to patch\n");
}

function printSummary(array $results): int
{
    $applied = 0;
    $skipped = 0;
    $failed = 0;

    fwrite(STDOUT, "\n=== Patch Results ===\n");

    foreach ($results as $result) {
        if ($result['applied'] === true) {
            $status = 'APPLIED';
            $applied++;
        } elseif ($result['skipped'] === true) {
            $status = 'SKIPPED';
            $skipped++;
        } else {
            $status = 'FAILED';
            $failed++;
        }

        $line = "[{$status}] {$result['target']}";
        if ($result['error'] !== null) {
            $line .= " - {$result['error']}";
        }
        fwrite(STDOUT, $line . "\n");
    }

    fwrite(STDOUT, "\n=== Summary ===\n");
    fwrite(STDOUT, "Applied: {$applied}\n");
    fwrite(STDOUT, "Skipped: {$skipped}\n");
    fwrite(STDOUT, "Failed:  {$failed}\n");
    fwrite(STDOUT, "Total:   " . count($results) . "\n");

    return $failed;
}

function patchPocketmineYmlFpsOptimization(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'pocketmine.yml';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'pocketmine.yml not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read pocketmine.yml');
    }

    if (str_contains($content, 'fps-optimization:')) {
        return makePatchResult($targetFile, false, true);
    }

    $anchor = "  block-cache-size: 8192";
    if (!str_contains($content, $anchor)) {
        return makePatchResult($targetFile, false, false, 'better-pmmp anchor (block-cache-size) not found in pocketmine.yml');
    }

    $insertion = $anchor . "\n"
        . "  # [BetterPMMP-PATCH] Client-side FPS optimization\n"
        . "  # Reduces redundant/out-of-range packets sent to players to relieve client frame drops.\n"
        . "  # All filters preserve gameplay correctness — teleport and forced sync bypass every filter.\n"
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

    $newContent = str_replace($anchor, $insertion, $content, $count);
    if ($count !== 1) {
        return makePatchResult($targetFile, false, false, 'Failed to insert fps-optimization block');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched pocketmine.yml');
    }

    return makePatchResult($targetFile, true, false);
}

function patchYmlServerPropertiesFpsOptimization(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'YmlServerProperties.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'YmlServerProperties.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read YmlServerProperties.php');
    }

    if (str_contains($content, 'BETTER_PMMP_FPS_OPTIMIZATION')) {
        return makePatchResult($targetFile, false, true);
    }

    $anchor = "\tpublic const BETTER_PMMP_BLOCK_CACHE_SIZE = 'better-pmmp.block-cache-size';\n";
    if (!str_contains($content, $anchor)) {
        return makePatchResult($targetFile, false, false, 'BETTER_PMMP_BLOCK_CACHE_SIZE anchor not found');
    }

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

    $newContent = str_replace($anchor, $insert, $content, $count);
    if ($count !== 1) {
        return makePatchResult($targetFile, false, false, 'Failed to insert FPS const block');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched YmlServerProperties.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchFpsEntityBroadcastOptimization(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'filterFpsBroadcastViewers')) {
        return makePatchResult($targetFile, false, true);
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
        . "\t\t\$fpsGround = \$this->onGround;\n"
        . "\t\t\$fpsX = \$this->location->x; \$fpsY = \$this->location->y; \$fpsZ = \$this->location->z;\n"
        . "\t\t\$fpsYaw = \$this->location->yaw; \$fpsPitch = \$this->location->pitch;\n"
        . "\t\tif(!\$teleport && \$this->fpsRedundantMovementSuppressed(\$fpsX, \$fpsY, \$fpsZ, \$fpsYaw, \$fpsPitch, \$fpsGround)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$fpsTargets = \$this->filterFpsBroadcastViewers(\$this->hasSpawned, \$teleport);\n"
        . "\t\tif(count(\$fpsTargets) === 0){\n"
        . "\t\t\t\$this->fpsLastBroadcastX = \$fpsX; \$this->fpsLastBroadcastY = \$fpsY; \$this->fpsLastBroadcastZ = \$fpsZ;\n"
        . "\t\t\t\$this->fpsLastBroadcastYaw = \$fpsYaw; \$this->fpsLastBroadcastPitch = \$fpsPitch; \$this->fpsLastBroadcastGround = \$fpsGround;\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$fpsTargets, [MoveActorAbsolutePacket::create(\n"
        . "\t\t\t\$this->id,\n"
        . "\t\t\t\$this->getOffsetPosition(\$this->location),\n"
        . "\t\t\t\$fpsPitch,\n"
        . "\t\t\t\$fpsYaw,\n"
        . "\t\t\t\$fpsYaw,\n"
        . "\t\t\t(\$fpsGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)\n"
        . "\t\t)]);\n"
        . "\t\t\$this->fpsLastBroadcastX = \$fpsX; \$this->fpsLastBroadcastY = \$fpsY; \$this->fpsLastBroadcastZ = \$fpsZ;\n"
        . "\t\t\$this->fpsLastBroadcastYaw = \$fpsYaw; \$this->fpsLastBroadcastPitch = \$fpsPitch; \$this->fpsLastBroadcastGround = \$fpsGround;\n"
        . "\t}";

    $content = str_replace($oldMove, $newMove, $content, $count1);
    if ($count1 !== 1) {
        return makePatchResult($targetFile, false, false, 'broadcastMovement match failed');
    }

    $oldMotion = "\tprotected function broadcastMotion() : void{\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$this->hasSpawned, [SetActorMotionPacket::create(\$this->id, \$this->getMotion(), tick: 0)]);\n"
        . "\t}";
    $newMotion = "\tprotected function broadcastMotion() : void{\n"
        . "\t\t/** [BetterPMMP-PATCH] FPS optimization: motion redundancy + distance filter */\n"
        . "\t\t\$fpsMotion = \$this->getMotion();\n"
        . "\t\tif(\$this->fpsRedundantMotionSuppressed(\$fpsMotion->x, \$fpsMotion->y, \$fpsMotion->z)){\n"
        . "\t\t\treturn;\n"
        . "\t\t}\n"
        . "\t\t\$fpsTargets = \$this->filterFpsBroadcastViewers(\$this->hasSpawned, false);\n"
        . "\t\t\$this->fpsLastBroadcastMotionX = \$fpsMotion->x; \$this->fpsLastBroadcastMotionY = \$fpsMotion->y; \$this->fpsLastBroadcastMotionZ = \$fpsMotion->z;\n"
        . "\t\tif(count(\$fpsTargets) === 0) return;\n"
        . "\t\tNetworkBroadcastUtils::broadcastPackets(\$fpsTargets, [SetActorMotionPacket::create(\$this->id, \$fpsMotion, tick: 0)]);\n"
        . "\t}";
    $content = str_replace($oldMotion, $newMotion, $content, $count2);
    if ($count2 !== 1) {
        return makePatchResult($targetFile, false, false, 'broadcastMotion match failed');
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
        . "\tprivate function filterFpsBroadcastViewers(array \$viewers, bool \$teleport) : array{\n"
        . "\t\tif(\$teleport || count(\$viewers) === 0){\n"
        . "\t\t\treturn \$viewers;\n"
        . "\t\t}\n"
        . "\t\t\$config = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.enabled', true)){\n"
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
        . "\tprivate function fpsRedundantMovementSuppressed(float \$x, float \$y, float \$z, float \$yaw, float \$pitch, bool \$ground) : bool{\n"
        . "\t\t\$config = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.enabled', true)){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}\n"
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
        . "\tprivate function fpsRedundantMotionSuppressed(float \$mx, float \$my, float \$mz) : bool{\n"
        . "\t\t\$config = \\pocketmine\\Server::getInstance()->getConfigGroup();\n"
        . "\t\tif(!(bool) \$config->getProperty('better-pmmp.fps-optimization.entity-broadcast.enabled', true)){\n"
        . "\t\t\treturn false;\n"
        . "\t\t}\n"
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
        return makePatchResult($targetFile, false, false, 'helper insertion anchor (getOffsetPosition) not matched');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchFpsActorAnimationDistanceFilter(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'Entity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Entity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Entity.php');
    }

    if (str_contains($content, 'filterFpsAnimationViewers')) {
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'broadcastAnimation match failed');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Entity.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchFpsParticleSoundDistanceFilter(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'world' . DIRECTORY_SEPARATOR . 'World.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'World.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read World.php');
    }

    if (str_contains($content, 'filterFpsViewersByDistanceSq')) {
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'addSound anchor not matched');
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
        return makePatchResult($targetFile, false, false, 'addParticle anchor not matched');
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
        return makePatchResult($targetFile, false, false, 'helper anchor (getViewersForPosition) not matched');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched World.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchFpsChunkSendPacing(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . 'Player.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'Player.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read Player.php');
    }

    if (str_contains($content, 'fpsChunkPacingTicks')) {
        return makePatchResult($targetFile, false, true);
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
        . "\t\t\t\t\$fpsChunksPerTick = (int) max(\$fpsInitial, (int) round(\$fpsInitial + (\$this->chunksPerTick - \$fpsInitial) * \$fpsProgress));\n"
        . "\t\t\t\t\$this->fpsChunkPacingTicks++;\n"
        . "\t\t\t}\n"
        . "\t\t}\n"
        . "\t\t\$limit = \$fpsChunksPerTick - count(\$this->activeChunkGenerationRequests);";

    $content = str_replace($oldLimit, $newLimit, $content, $cl);
    if ($cl !== 1) {
        return makePatchResult($targetFile, false, false, 'requestChunks limit anchor not matched');
    }

    $propAnchor = "\tprotected int \$chunksPerTick;";
    $propNew = $propAnchor . "\n"
        . "\t/** [BetterPMMP-PATCH] FPS optimization: chunk pacing tick counter */\n"
        . "\tprotected int \$fpsChunkPacingTicks = 0;";
    $content = str_replace($propAnchor, $propNew, $content, $cprop);
    if ($cprop !== 1) {
        return makePatchResult($targetFile, false, false, 'chunksPerTick property anchor not matched');
    }

    if (file_put_contents($targetFile, $content) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched Player.php');
    }

    return makePatchResult($targetFile, true, false);
}

function patchFpsItemEntitySuppression(string $sourceDir): array
{
    $targetFile = $sourceDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'entity' . DIRECTORY_SEPARATOR . 'object' . DIRECTORY_SEPARATOR . 'ItemEntity.php';

    if (!file_exists($targetFile)) {
        return makePatchResult($targetFile, false, false, 'ItemEntity.php not found');
    }

    $content = file_get_contents($targetFile);
    if ($content === false) {
        return makePatchResult($targetFile, false, false, 'Failed to read ItemEntity.php');
    }

    if (str_contains($content, 'fpsShouldSuppressBroadcast')) {
        return makePatchResult($targetFile, false, true);
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
        return makePatchResult($targetFile, false, false, 'ItemEntity class anchor not matched');
    }

    if (file_put_contents($targetFile, $newContent) === false) {
        return makePatchResult($targetFile, false, false, 'Failed to write patched ItemEntity.php');
    }

    return makePatchResult($targetFile, true, false);
}

if (!isset($argv[1])) {
    printUsage();
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

$baseDir = dirname($sourceDir);
if (basename($sourceDir) === 'src') {
    $baseDir = dirname($sourceDir);
    $sourceDir = $baseDir;
}

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
    'createPluginResourceIndex' => $sourceDir,
    'createPluginResources' => $sourceDir,
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
    'patchEntityMotionEpsilonCleanup' => $sourceDir,
    'patchPocketmineYmlCriticalHit' => $sourceDir,
    'patchYmlServerPropertiesCriticalHit' => $sourceDir,
    'patchPlayerCriticalHit' => $sourceDir,
    'patchWorldEntityTickClose' => $sourceDir,
    'patchWorldUnloadEntityIteration' => $sourceDir,
    'patchNetworkSessionSetHandlerGuard' => $sourceDir,
    'patchHandlerListRegisterAllCache' => $sourceDir,
    'patchEntityHealthFloatComparison' => $sourceDir,
    'patchPlayerRespawnLockReset' => $sourceDir,
    'patchHandlerListMergePerformance' => $sourceDir,
    'patchServerRemoveOnlinePlayerSnapshot' => $sourceDir,
    'patchPlayerDestroyCyclesCleanup' => $sourceDir,
    'patchWorldUnloadChunkEntityClose' => $sourceDir,
    'patchNetworkSessionDisconnectGuardTiming' => $sourceDir,
    'patchClassMapAuthoritative' => $sourceDir,
    'patchPocketmineYmlFpsOptimization' => $sourceDir,
    'patchYmlServerPropertiesFpsOptimization' => $sourceDir,
    'patchFpsEntityBroadcastOptimization' => $sourceDir,
    'patchFpsActorAnimationDistanceFilter' => $sourceDir,
    'patchFpsParticleSoundDistanceFilter' => $sourceDir,
    'patchFpsChunkSendPacing' => $sourceDir,
    'patchFpsItemEntitySuppression' => $sourceDir,
];

foreach ($patchFunctions as $func => $dir) {
    try {
        $results[] = $func($dir);
    } catch (\Throwable $e) {
        $results[] = makePatchResult($func, false, false, $e->getMessage());
    }
}

exit(printSummary($results) > 0 ? 1 : 0);
