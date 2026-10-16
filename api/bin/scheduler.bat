@echo off
rem YouthSync scheduler (run by Task Scheduler).
rem The PHP script appends its summary to api\logs\scheduler.log.
cd /d "%~dp0"
"C:\xampp\php\php.exe" "%~dp0scheduler.php" 2>> "%~dp0..\logs\scheduler.err.log"
exit /b %ERRORLEVEL%
