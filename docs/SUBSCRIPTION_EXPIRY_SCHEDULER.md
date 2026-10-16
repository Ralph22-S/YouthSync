# Subscription expiry scheduler

## What it does

Once a day, the scheduler runs the `subscription.expire` job. That job calls
`YouthSync\Org\PlanLimits::expireDue()`. That is the same rule used by the
admin "Run subscription cycle" action (`POST /admin/subscriptions/run-cycle`)
and by the lazy `applyExpiry` on SK/admin reads:

- `expires_at IS NOT NULL`
- `DATE(expires_at) < CURDATE()`
- `sub_status` not already `expired`, `cancelled`, or `payment_failed`

Matching organizations get `sub_status = 'expired'`, so Free plan limits apply.
This now happens even when no one uses the web app.

The scheduler is `YouthSync\Schedule\Scheduler`. Job codes live in the `scheduled_jobs` table. Only two handlers are registered in PHP: `subscription.expire` and `scheduler.heartbeat`. A row whose code is not one of those is skipped. The table cannot name a class or a shell command.

Two ways to run due jobs:

- CLI: `api/bin/scheduler.php` (Windows task `YouthSync-Scheduler`).
- HTTP: `POST /cron/run` with header `X-Cron-Secret`. A browser session is not accepted. If `CRON_SECRET` is missing or shorter than 16 characters, the route returns 503 and does not run jobs. A wrong secret returns 401.

`api/bin/expire_subscriptions.php` still runs only `subscription.expire`, including when that job is not yet due. It does not write `activity_logs`, because those rows need an acting admin. Runs from the admin UI still log there.

## Files

| Path | Purpose |
| --- | --- |
| `api/src/Schedule/Scheduler.php` | Seeds jobs, runs due jobs, records status and the next run. |
| `api/src/Controllers/CronController.php` | `POST /cron/run`. |
| `api/bin/scheduler.php` | CLI. Runs every due job. Exit 0 when none failed. |
| `api/bin/scheduler.bat` | Wrapper for Task Scheduler. Uses `C:\xampp\php\php.exe`. |
| `api/bin/expire_subscriptions.php` | CLI. Runs `subscription.expire` now. Exit 0 when that job returns ok. |
| `api/bin/expire_subscriptions.bat` | Wrapper for the older Task Scheduler task. |
| `api/logs/scheduler.log` | One line per scheduler run. |
| `api/logs/subscription_expiry.log` | One line per expiry-script run: `[ISO time] ok expired=N` or `ERROR ...` |
| `api/logs/subscription_expiry.err.log` | PHP stderr from the expiry .bat file. |

Both log files are ignored by git.

## Run manually

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\bin\expire_subscriptions.php
# or
C:\xampp\htdocs\YouthSync\api\bin\expire_subscriptions.bat
# or trigger the scheduled task now
schtasks /Run /TN "YouthSync-ExpireSubscriptions"
```

Example output: `Subscription expiry: ok expired=0`

MySQL (XAMPP) must be running. If it is not, the job logs `ERROR ...` and exits with code 1.

## Task Scheduler setup

The task was created as user Shane without elevation:

```powershell
schtasks /Create /TN "YouthSync-ExpireSubscriptions" /TR "C:\xampp\htdocs\YouthSync\api\bin\expire_subscriptions.bat" /SC DAILY /ST 01:15 /F

$s = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Set-ScheduledTask -TaskName "YouthSync-ExpireSubscriptions" -Settings $s
```

- Runs daily at 01:15 local time.
- With `StartWhenAvailable`, a run missed while the laptop was asleep or off happens as soon as possible afterward.
- The logon mode is "Interactive only", so the task runs only while Shane is logged in. To run it when no one is logged in, re-create the task from an elevated prompt with `/RU` and `/RP`, or choose "Run whether user is logged on or not" in Task Scheduler.

Check the task:

```powershell
schtasks /Query /TN "YouthSync-ExpireSubscriptions" /V /FO LIST
Get-Content C:\xampp\htdocs\YouthSync\api\logs\subscription_expiry.log -Tail 20
```

Remove the task:

```powershell
schtasks /Delete /TN "YouthSync-ExpireSubscriptions" /F
```

## Scheduler task

`YouthSync-Scheduler` runs every due job (expiry and heartbeat):

```powershell
schtasks /Create /TN "YouthSync-Scheduler" /TR "C:\xampp\htdocs\YouthSync\api\bin\scheduler.bat" /SC DAILY /ST 01:15 /F
```

`POST /cron/run` is the HTTP equivalent. Set `CRON_SECRET` in `api/.env` (see `api/.env.example`). Do not send the secret from the browser.

## Production (Linux)

```
15 1 * * * /usr/bin/php /path/to/YouthSync/api/bin/scheduler.php >> /path/to/YouthSync/api/logs/scheduler.err.log 2>&1
```

Or call `POST /cron/run` with the `X-Cron-Secret` header from the host's cron, never from a public page.