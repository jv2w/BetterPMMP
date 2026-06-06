@echo off
chcp 65001 >nul 2>&1
TITLE BetterPMMP Builder
cd /d %~dp0

echo ============================================
echo   BetterPMMP Builder
echo ============================================
echo.

set PHP_BINARY=

where /q php.exe
if %ERRORLEVEL%==0 set PHP_BINARY=php

if exist bin\php\php.exe (
	set PHPRC=""
	set PHP_BINARY=bin\php\php.exe
)

if exist source\bin\php\php.exe (
	set PHPRC=""
	set PHP_BINARY=source\bin\php\php.exe
)

if "%PHP_BINARY%"=="" (
	echo [ERROR] PHP not found.
	echo bin\php\php.exe or php.exe in the system PATH is required.
	pause
	exit /b 1
)

if not exist source\src\PocketMine.php (
	echo [ERROR] source\src\PocketMine.php not found.
	echo Place the PMMP source in the source folder first.
	pause
	exit /b 1
)

if not exist patch_tool.php (
	echo [ERROR] patch_tool.php not found.
	echo Copy patch_tool.php into this folder.
	pause
	exit /b 1
)

echo [1/2] Moving bin folder to source\bin...
if exist bin (
	if not exist source\bin (
		move bin source\bin >nul
		echo [OK] bin folder moved
		set PHPRC=""
		set PHP_BINARY=source\bin\php\php.exe
	) else (
		echo [SKIP] source\bin folder already exists.
	)
) else (
	if not exist source\bin (
		echo [ERROR] bin folder not found.
		pause
		exit /b 1
	) else (
		echo [SKIP] bin folder is already in source\bin.
	)
)

echo.
echo [2/2] Applying BetterPMMP patches...
echo.

%PHP_BINARY% patch_tool.php source

if %ERRORLEVEL% NEQ 0 (
	echo.
	echo ============================================
	echo   An error occurred during patching.
	echo   Check the log above.
	echo ============================================
	pause
	exit /b 1
)

echo.
echo ============================================
echo   BetterPMMP build complete!
echo ============================================
echo.
echo Next steps:
echo   1. Start the server with start.cmd.
echo   2. Use the /reload command to reload plugins.
echo   3. Use the /restart command to restart the server.
echo.

pause
