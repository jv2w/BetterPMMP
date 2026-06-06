@echo off
setlocal
cd /d "%~dp0"

set "MSG=%*"
if "%MSG%"=="" (
	set /p "MSG=Commit message: "
)
if "%MSG%"=="" (
	echo No commit message entered. Aborting.
	pause
	exit /b 1
)

git add -A
git commit -m "%MSG%"
if errorlevel 1 (
	echo Nothing to commit or commit failed.
	pause
	exit /b 1
)

git push
if errorlevel 1 (
	echo Push failed.
	pause
	exit /b 1
)

echo Done: "%MSG%"
pause
