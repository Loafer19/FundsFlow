---
title: Queue worker and alerts
description: Run a FundsFlow queue worker next to the API, and get Telegram alerts when a job fails or the worker stops.
---

Without a worker, FundsFlow runs background work right after the HTTP response: Telegram updates, monobank webhooks,
import commits and widget refreshes. That works, but a refresh can slow down the next request and a crash is lost.
A worker takes Telegram widget refreshes off the web container, retries them, and keeps failures in `failed_jobs`.

## Run the worker

Use the **same image** as the API (same repository, branch and `docker/backend` Dockerfile) as a second service with
no domain and no ports, and this command:

```sh
php artisan queue:work --tries=3 --max-time=3600
```

- `--max-time=3600` makes the worker exit after an hour so it starts fresh; the container's restart policy
  starts it again. Keep the default restart policy (*any*).
- Migrations don't run in this container: the entrypoint runs them only for the web server command.
- The image's health check probes the web server, which isn't running here. Replace it with one that checks the
  worker process, or turn it off, or the orchestrator will keep restarting the worker:

  ```json
  { "Test": ["CMD-SHELL", "pgrep -f queue:work > /dev/null || exit 1"], "Interval": 30000000000, "Timeout": 10000000000, "StartPeriod": 30000000000, "Retries": 3 }
  ```

- Redeploy the worker together with the API, so it runs the same code.

## Environment

The worker needs the same environment as the API (database, Redis, Telegram token, xAI key). On **both** services set:

| Variable | Value | Why |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `database` | Jobs go to the `jobs` table |
| `TELEGRAM_WIDGETS_QUEUE` | `true` | Widget refreshes go to the queue instead of running after the response. Also turns on the heartbeat |
| `TELEGRAM_ADMIN_CHAT_ID` | your Telegram chat id | Where alerts go. Empty: every admin with a linked Telegram |
| `CACHE_STORE` | `redis` | Must be shared by the API, the worker and the scheduler: heartbeats, alert throttling and job uniqueness live there. Not `file` or `array` |

Turn the worker on first and set `TELEGRAM_WIDGETS_QUEUE=true` on the API after it runs; otherwise the heartbeat
check alerts while nothing takes jobs.

## Alerts

All alerts are Telegram messages from the bot to `TELEGRAM_ADMIN_CHAT_ID` (or the admins).

- **⚠️ Queue job failed** — after a job used up its tries: the job, the exception class and message, the queue.
  The same job with the same exception class is reported at most once per 10 minutes
  (`QUEUE_FAILURE_ALERT_THROTTLE_MINUTES`).
- **🔴 Queue worker looks down** — the scheduler queues a small heartbeat job every minute
  (`queue:heartbeat`) and checks every 5 minutes (`queue:heartbeat --check`). When the last heartbeat is older
  than 5 minutes (`QUEUE_HEARTBEAT_STALE_MINUTES`), you get this alert with the number of waiting jobs, repeated
  every hour while it lasts (`QUEUE_DOWN_REPEAT_MINUTES`).
- **✅ Queue worker recovered** — once heartbeats come back.

The heartbeat runs only when `TELEGRAM_WIDGETS_QUEUE=true`, or when `QUEUE_MONITOR=true` is set explicitly. It relies
on the scheduler (`php artisan schedule:run` every minute), like the other FundsFlow schedules.

## Failed jobs in the admin panel

**/admin → Failed jobs** lists `failed_jobs` with the job, the error and the queue, and a badge with their number.
**Details** shows the full exception; **Retry** puts a job back on its queue; **Delete** removes it. Both work on
several selected jobs too and are written to the audit log. From a shell: `php artisan queue:failed`,
`php artisan queue:retry all`, `php artisan queue:flush`.
