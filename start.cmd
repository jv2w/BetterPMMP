@echo off
TITLE BetterPMMP By UserX0001
cd /d %~dp0

set PHP_BINARY=

where /q php.exe
if %ERRORLEVEL%==0 (
	set PHP_BINARY=php
)

if exist source\bin\php\php.exe (
	rem always use the local PHP binary if it exists
	set PHPRC=""
	set PHP_BINARY=source\bin\php\php.exe
)

if "%PHP_BINARY%"=="" (
	echo Couldn't find a PHP binary in system PATH or "%~dp0source\bin\php"
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

if not exist source\bin (
	echo source\bin folder not found
	pause
	exit 1
)

:betterpmmp_start
%PHP_BINARY% %POCKETMINE_FILE% %*
set BETTERPMMP_EXIT=%ERRORLEVEL%
if exist system\restart.flag (
	del system\restart.flag
	goto :betterpmmp_start
)
REM [BetterPMMP-PATCH] Keep the server's exit code. pause ran last and overwrote it with its own 0, so a
REM crashed server reported success to whatever launched this script, and start.sh already did this right.
if not "%BETTERPMMP_EXIT%"=="0" pause
exit /b %BETTERPMMP_EXIT%
