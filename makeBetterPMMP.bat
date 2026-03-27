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
	echo [ERROR] PHP를 찾을 수 없습니다.
	echo bin\php\php.exe 또는 시스템 PATH에 php.exe가 필요합니다.
	pause
	exit /b 1
)

if not exist source\src\PocketMine.php (
	echo [ERROR] source\src\PocketMine.php를 찾을 수 없습니다.
	echo source 폴더에 PMMP 소스를 먼저 배치해 주세요.
	pause
	exit /b 1
)

if not exist patch_tool.php (
	echo [ERROR] patch_tool.php를 찾을 수 없습니다.
	echo patch_tool.php를 이 폴더에 복사해 주세요.
	pause
	exit /b 1
)

echo [1/2] bin 폴더를 source\bin으로 이동합니다...
if exist bin (
	if not exist source\bin (
		move bin source\bin >nul
		echo [OK] bin 폴더 이동 완료
		set PHPRC=""
		set PHP_BINARY=source\bin\php\php.exe
	) else (
		echo [SKIP] source\bin 폴더가 이미 존재합니다.
	)
) else (
	if not exist source\bin (
		echo [ERROR] bin 폴더를 찾을 수 없습니다.
		pause
		exit /b 1
	) else (
		echo [SKIP] bin 폴더가 이미 source\bin에 있습니다.
	)
)

echo.
echo [2/2] BetterPMMP 패치를 적용합니다...
echo.

%PHP_BINARY% patch_tool.php source

if %ERRORLEVEL% NEQ 0 (
	echo.
	echo ============================================
	echo   패치 중 오류가 발생했습니다.
	echo   위의 로그를 확인해 주세요.
	echo ============================================
	pause
	exit /b 1
)

echo.
echo ============================================
echo   BetterPMMP 구축이 완료되었습니다!
echo ============================================
echo.
echo 다음 단계:
echo   1. start.cmd로 서버를 시작하세요.
echo   2. /reload 명령어로 플러그인을 리로드할 수 있습니다.
echo   3. /restart 명령어로 서버를 재시작할 수 있습니다.
echo.

pause
