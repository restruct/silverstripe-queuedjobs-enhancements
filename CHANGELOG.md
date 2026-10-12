# Changelog

All notable changes to this module. Versions before this file existed are described by their
git tags (1.0.0 to 1.4.0).

## 1.6.0 (2026-10-12)

A Laravel-style queue worker (#5).

### Added

- `queuedjobs:work` (Silverstripe 6 sake command; Silverstripe 5: `dev/tasks/QueuedJobsWorkTask`):
  a long-running worker that starts due jobs within about a second. Options follow Laravel's
  `queue:work`: `queue` (comma list, in priority order; default `immediate`), `sleep`, `max-time`
  (default 3600), `max-jobs`, `memory` (default: the `QueuedJobService` memory limit), `job` and
  `stop-when-empty`. Each pass uses upstream's `getNextPendingJob()` and `runJob()`, so job
  selection, the per-descriptor mutex and memory/time parking are upstream's own. It exits cleanly
  on its limits, after a job is parked as `Waiting`, on SIGTERM/SIGINT/SIGQUIT (after the current
  job step, with the job parked for the next worker) and on a restart request. Between jobs it
  resets the subsite, Versioned reading mode, locale and current user, and clears the
  `DataObject` cache.
- `queuedjobs:restart` (Silverstripe 5: `dev/tasks/QueuedJobsRestartTask`): makes running workers
  exit after their current job, for the deploy script. The signal is a token in the database
  (new table `QueuedJobsWorkerRestartSignal`; run a database build).
- README: worker options, a Supervisor/Forge daemon example and the restart-on-deploy line.

### Changed

- `ImmediateJobRunner` spawns the worker for the one job (`queuedjobs:work --job=<ID> --max-jobs=1`,
  on Silverstripe 5 `dev/tasks/QueuedJobsWorkTask job=<ID> max-jobs=1`) instead of
  `ProcessJobQueueTask job=queuedjob-<ID>`. The worker also checks the job's status and
  `StartAfter` before running it, and runs nothing while the queue is paused.

### Fixed

- The spawn for one job ran every pending `IMMEDIATE` job as well. Any CLI process that creates
  `QueuedJobService` registers a shutdown handler that drains the immediate queue, and the
  `ProcessJobQueueTask` process was no exception (on queuedjobs 5.x even while the queue was
  paused). The worker switches that handler off for its own process. `ImmediateSpawnRunsOnlyItsJobTest`
  runs the real spawn command in a child process; it fails against 1.5.0.

## 1.5.0 (2026-10-12)

Silverstripe 6 support, in the same code line as Silverstripe 5.

### Added

- Silverstripe 6 / queuedjobs 6 support. `composer.json` now requires
  `silverstripe/framework ^5.4 || ^6` and `symbiote/silverstripe-queuedjobs ^5 || ^6`.
- CI: GitHub Actions workflow testing Silverstripe 5 (PHP 8.1, 8.3) and Silverstripe 6 (PHP 8.3),
  plus a boot, database build and run of both dev tasks on each.
- Tests for `CheckDefaultJobsTask` (report and YAML mode, on both majors).

### Fixed

- `CheckDefaultJobsTask` and `CleanupBrokenJobsTask` could not be declared on Silverstripe 6
  (typed `BuildTask::$title` / static `$description`), which took the whole application down on
  every flush. Each task now gets its version-specific entry point from a conditionally declared
  trait. On Silverstripe 6 they run as `sake tasks:check-default-jobs [--yaml]` and
  `sake tasks:cleanup-broken-jobs`.
- `ScheduledMethodCall::getJobType()` returned an `int`. On Silverstripe 6 every `schedule()` /
  `scheduleImmediate()` threw `ValidationException: Must be a string - fieldName: JobType`. It now
  returns the queue constant as a string (an `int` passed as the `jobType` option is normalised).
- `ImmediateJobRunner` never spawned on Silverstripe 6 (it only looked for `cli-script.php`, which
  Silverstripe 6 removed); it now runs `bin/sake tasks:ProcessJobQueueTask --job=queuedjob-<ID>`.
- `ImmediateJobRunner` resolved a relative `spawn_lock_file` under `TEMP_FOLDER`, which
  Silverstripe 6 no longer defines (a fatal wherever `flock` is installed). It uses `TEMP_PATH`,
  the same value on Silverstripe 5.
- `ImmediateJobRunner::queueAndRun($job, $startAfter)` with a future `$startAfter` spawned a
  process that ran the job at once, ignoring the start time (the single-job path in
  `ProcessJobQueueTask` never reads `StartAfter`). A future job is now left to the queue runner.
  The check reads the descriptor's own `StartAfter`, so it also covers `queueJob()` handing back an
  existing, deduplicated descriptor that was scheduled for later.
- README and `DefaultJobManager` docblock showed `private static int $default_job_type =
  QueuedJob::QUEUED;`, which is a compile-time fatal (the constant is a string). Now `string`.

### Changed (possibly breaking)

- `ScheduledMethodCall::getJobType()` is now typed `: string` instead of `: int`. A subclass that
  overrides it with `: int` must change its return type.
- Test stubs in `tests/Stubs/` return `string` from `getJobType()` for the same reason.

### Known, not changed

- A spawned process (and any CLI process) also drains the whole IMMEDIATE queue at exit through
  queuedjobs' shutdown handler, and on queuedjobs 5.x does so even while the maintenance lock is
  active. Documented in the README with the mitigation (`use_shutdown_function: false` plus an
  immediate-queue cron).
