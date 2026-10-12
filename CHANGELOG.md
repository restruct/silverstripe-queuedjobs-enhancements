# Changelog

All notable changes to this module. Versions before this file existed are described by their
git tags (1.0.0 to 1.4.0).

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
