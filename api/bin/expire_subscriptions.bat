@echo off
rem YouthSync daily subscription expiry (run by Task Scheduler).
rem The PHP script appends its summary to api\logs\subscription_expiry.log.
rem STDERR (PHP warnings/fatals) goes to a separate file so the two writers
rem never lock the same log on Windows.
cd /d "%~dp0"
"C:\xampp\php\php.exe" "%~dp0expire_subscriptions.php" 2>> "%~dp0..\logs\subscription_expiry.err.log"
exit /b %ERRORLEVEL%