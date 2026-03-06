# Parser Daemon Audit

**Date:** 2026-03-06

---

## Components

### ParserDaemonJob

- **File:** `app/Jobs/ParserDaemonJob.php`
- **Logic:**
  1. Check `parser_daemon_enabled` — if false, return
  2. Check for running/pending ParserJob — if exists, dispatch self with 60s delay, return
  3. Create ParserJob (type=full), dispatch RunParserJob
  4. Exit (next iteration scheduled by ScheduleNextParserDaemon)

- **Infinite loops:** No. Job exits after each iteration.
- **Self-dispatch:** Only when run already in progress (throttle).
- **Queue:** `parser` (same as RunParserJob, ParseCategoryJob, ParseProductJob).

### ScheduleNextParserDaemon Listener

- **File:** `app/Listeners/ScheduleNextParserDaemon.php`
- **Event:** `ParserFinished`
- **Logic:**
  1. Check `parser_daemon_enabled` — if false, return
  2. Check job type === 'full' — if not, return
  3. Dispatch ParserDaemonJob with 60s delay

- **Implements ShouldQueue:** Yes — runs on queue (default).
- **Potential issue:** Listener uses default queue; if workers listen only on `parser`, listener job may not be processed unless default queue is also consumed.

### ParserFinished Event

- **Fired from:**
  - ParseCategoryJob (when last category completes: parsed_categories >= total_categories)
  - DatabaseParserService (for menu_only, category, seller types)
- **Broadcasts:** Yes (ShouldBroadcast) — channel `parser`.

### Scheduler Tasks

- **routes/console.php:** No parser-related scheduled tasks.
- **Kernel/schedule:** Not inspected — no cron-based parser triggers.

### ParserJob Model

- **Statuses:** pending, running, completed, failed, stopped, cancelled
- **No daemon-specific fields.**

---

## Potential Issues

### 1. ScheduleNextParserDaemon Queue

The listener implements `ShouldQueue`. Laravel dispatches it to the default queue. If workers only listen on `parser` and `photos`, the listener job goes to `default` and may never run. **Fix:** Ensure workers also process `default`, or make the listener use the `parser` queue.

### 2. Multiple Daemon Runs

- ParserDaemonJob checks for running job before creating a new one.
- ParserController::start does not set `parser_daemon_enabled`; startDaemon does.
- If user clicks "Start" (manual) and daemon is enabled, both could run — but manual start creates one RunParserJob; daemon also creates one when idle. They would not overlap (daemon checks for running).

### 3. Race Condition

Between "check running" and "create ParserJob" in ParserDaemonJob, another process could create a job. Low probability with single daemon instance.

### 4. Deadlocks

No obvious deadlocks. Jobs are independent; no circular dispatch chain.

### 5. Listener Queue Mismatch

**Critical:** ScheduleNextParserDaemon is queued on the default queue. If production workers use `--queue=parser` and `--queue=photos`, the default queue is never processed. The daemon would run once, finish, fire ParserFinished, but the listener job would sit in `default` forever. **Next run would never be scheduled.**
