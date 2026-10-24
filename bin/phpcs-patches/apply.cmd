@echo off
REM DEV-54: wrapper to run apply.sh from cmd.exe / PowerShell.
REM Requires Git for Windows (bash on PATH).
REM Usage: bin\phpcs-patches\apply.cmd [tools-dir] [--check]
setlocal
set "HERE=%~dp0"
bash "%HERE%apply.sh" %*
exit /b %ERRORLEVEL%
