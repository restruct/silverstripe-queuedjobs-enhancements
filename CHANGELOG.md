# Changelog

All notable changes to this module. Versions before this file existed are described by their
git tags (1.0.0 to 1.4.0).

## Unreleased

### Added

- `ImmediateJobRunner::queueAndStart($job, $startAfter = null)`: queue a job on its own queue
  (normally `Queued`) and start it at once in the background, without moving it to the immediate
  queue. For long jobs: on the immediate queue, whose cron usually runs every minute, upstream's
  health check restarts a job whose step takes longer than that interval once its lock
  (`worker_ttl`, 5 minutes) has expired. The opt-in is per queued job; the same class queued with
  `queueJob()` waits for its cron. A second lane of the drain spawn (`DrainSpawner::requestStart()`,
  request files in `.qje-spawn/start/`, its own marker and lock) runs the requests one after the
  other with the runner's new `requested` mode (`queuedjobs:work --requested`, SS5 `requested=1`).
- Lock refresh, off by default (`QueuedJobLeaseRefreshExtension.enabled: true`): after each step of
  a running job, the process that holds its lock moves `Expiry` to now + `worker_ttl`, so the
  health check restarts a job that made no step for `worker_ttl` instead of any job running longer
  than that. Throttled by `min_interval` (30 s). Remove it once upstream refreshes the lock itself
  (it does not, as of 6.2.3).

### Docs

- README: the drain runs immediate jobs one at a time, but the `queue=immediate` cron can start
  another immediate job beside a busy drain.

## 1.7.1 (2026-10-22)

Security fixes from a review of 1.7.0.

### Security

- Stored XSS in the browser task runner: `CleanupBrokenJobsTask` printed job titles unescaped
  (Silverstripe 6 through `OUTPUT_RAW`, which skips the HTML formatter; Silverstripe 5 through a
  plain echo into the HTML page). `CheckDefaultJobsTask` wrote its report the same way. Both now
  escape console tags and write `NORMAL` on Silverstripe 6, and HTML-escape browser output on
  Silverstripe 5.
- `QueuedJobProfileTask` checked `Director::isDev()` only, and in dev mode `dev/tasks` is open to
  any visitor, so an anonymous request could run any job class. It now also requires the CLI or
  ADMIN. Its browser output is HTML-escaped (Silverstripe 5 reflected the `class` parameter raw).
- `ScheduledMethodCall` could be created from upstream's "Create job of type" form in the Jobs
  admin with (class, method) as constructor lines, which called any static method.
  `QueuedJobsAdminJobTypesExtension` removes it (and subclasses) from that dropdown, which also
  makes the form reject a crafted POST. More classes: `hidden_job_types`.
- Progress endpoint (`api/job-progress/<ID>`): a non-admin now gets `"message": "Job failed"` for
  a `Broken` job instead of its last message, which is often exception text; ADMIN still gets the
  real message. A job the member may not view answers `404` like a missing one, not `403`, so IDs
  cannot be probed. The `RunAsID` rule moved from the controller into
  `QueuedJobDescriptorPermissionExtension::canView()` on the model (returns `true` for the RunAs
  member, `null` otherwise, so it never overrides a `false` and leaves the rest to upstream's
  ADMIN default).
- `bin/qje-drain.sh` could loop for ever: an undeletable marker (folder not writable, read-only
  filesystem) started a PHP runner on every pass, and a lock file that could not be opened made it
  hot-spin. A marker that cannot be removed now stops it before the runner starts; a failure exit
  gets one more pass for a job queued meanwhile, then stops; every further pass waits 0.2 s; and
  `QJE_DRAIN_MAX_PASSES` (default 50) caps the runs. Each stop exits 70 with the reason on stderr.
- CI: the workflow token is `contents: read`, and the actions are pinned to commit SHAs
  (`actions/checkout` v7.0.1, `shivammathur/setup-php` 2.40.0).

### Changed (possibly breaking)

- `api/job-progress/<ID>` answers `404` instead of `403` for a job the member may not view, and
  `message` is `"Job failed"` for a `Broken` job seen by a non-admin. A client that told the two
  cases apart by status code can no longer do so.
- `bin/qje-drain.sh` can exit `70` (see above); before it only exited `0` or `64`.
- README: a note for servers shared with other accounts (an absolute `QueuedJobService.cache_dir`
  outside `/tmp`, a private `DrainSpawner.log_file`). README, `docs/RESEARCH.md` and code
  comments no longer point at internal research notes by path; they cite upstream source instead.

## 1.7.0 (2026-10-21)

Drain spawn: the immediate queue runs within about a second of a
dispatch, with nothing resident. The resident worker mode of 1.6.0 is removed.

### Added

- `QueuedJobDescriptorDrainExtension` and `DrainSpawner`: every new descriptor on the `IMMEDIATE`
  queue (however it was queued) touches a marker and starts `bin/qje-drain.sh` detached. The wrapper
  takes a lock without waiting, so a burst starts one PHP runner; it runs the worker one-shot on the
  immediate queue and starts another runner when the marker was touched during the run or the
  runner exited 75. Marker `.qje-spawn/pending` and lock `.qje-spawn/lock` live in a subfolder
  of upstream's job folder. Config: `DrainSpawner.enabled` (default true; only an explicit
  `false` switches it off, a missing value such as from a config cache built before the setting
  existed counts as on), `php_cli`, `log_file`.
- CLI php binary for the runner: the `QJE_PHP_CLI` environment variable (or `.env` entry), then
  `DrainSpawner.php_cli`, then the automatic resolution of 1.6.0. php-fpm is never used.
- Locking in the wrapper: util-linux `flock` with `-E`, else perl's `flock()` (macOS), else
  unlocked with a warning.
- Drain runner (`queuedjobs:work` / `QueuedJobsWorkTask`, now one-shot only): exit code 75
  (`QueueWorker::EXIT_MORE_WORK`) when it stops early after running jobs (max-jobs, max-time,
  memory, a job parked `Waiting`; a stop signal exits 0), and `linger` (config
  `QueueWorker.linger`, default 2 s; option `linger`): keep looking for due jobs every 0.5 s before
  exiting.
- The runner deletes upstream's trigger file `queuedjob-<ID>` of every job it ran, whatever the
  outcome (complete, broken or parked `Waiting`). Upstream only deletes it for a completed job.
- `ImmediateJobRunner::triggerFolder()` and `ImmediateJobRunner::cliPhpBinary()`.
- `tests/shell/qje-drain-test.sh`: the wrapper against a fake runner, with must-fail mutants.
- `QueuedJobContextExtension` on `QueuedJobDescriptor`, moved from restruct/silverstripe-admintweaks:
  while a job's descriptor is `Running`, `$_SERVER['QUEUED_JOB']` (`#ID class | title | step x/y |
  queue n`) and `$_SERVER['QUEUED_JOB_MEMORY']` (used / peak / memory_limit) name the job, so a
  fatal in the runner can be attributed to it; cleared on any other status. Same keys and format
  as before, so admintweaks' `EnhancedErrorFormatter` renders them unchanged.
- `QueuedJobProfileTask` (`dev/tasks/qjob-profile` on SS5, `sake tasks:qjob-profile` on SS6),
  moved from restruct/silverstripe-admintweaks: runs a job in-process and reports memory, step,
  `jobData` size and message count per `process()` call. Dev-only. Admintweaks disables its own copy
  when this class exists.
- `BoundedJobMessages`, an opt-in trait for jobs: keeps the first `keep_first_messages` (10) and the
  last `keep_last_messages` (200) messages with one marker counting the dropped middle (the count
  survives between steps), so logging in a loop no longer re-serialises an ever larger log after
  every step. Also opt-in: `min_message_severity` and `message_memory_usage`. Settings are read from
  the job class.

### Changed

- `ImmediateJobRunner::queueAndRun()` queues the job on the `IMMEDIATE` queue
  (`queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE)`) and lets the drain spawn run it. The
  per-job `--job=<ID> --max-jobs=1` spawn is gone; jobs queued from the CLI are drained too.
- Without `exec()` nothing is spawned (logged once per process); the job waits in the `IMMEDIATE`
  queue for the cron or another runner.
- A dispatch inside an open database transaction also drains once at the end of the process, so
  a commit after the runner's linger does not leave the job for the cron.

### Removed

- The resident (long-running, polling) worker mode of `queuedjobs:work` / `QueuedJobsWorkTask`,
  and its `sleep` option. The command is now only the one-shot drain runner. No project used the
  resident mode (mic's decision, 2026-10-15).
- `queuedjobs:restart` (`QueueRestartCommand`) and `dev/tasks/QueuedJobsRestartTask`, the restart
  signal for the deploy script.
- `WorkerRestartSignal` and its table `QueuedJobsWorkerRestartSignal` (the database build leaves an
  existing table in place; it can be dropped).
- `_config/worker.yml`: the `queuedjobs:restart` command registration and the
  `QueuedJobsRestartTask.is_enabled: false` entry.
- README: the resident worker, Supervisor/daemon example and restart-on-deploy sections.
- The per-job spawn helpers of `ImmediateJobRunner` (`spawnJobProcess()`, `buildSpawnCommand()`,
  `wrapWithLock()`, `resolveFlockBinary()`, `isDue()`), unused since the drain spawn. Deprecated
  earlier in this release, removed rather than kept until the next major, because no project uses
  them (mic's decision, 2026-10-17).
- The settings `ImmediateJobRunner.use_immediate_queue` (no effect since the drain spawn; a site
  with its own watcher sets `DrainSpawner.enabled: false`), `use_spawn_lock`, `spawn_lock_file` and
  `spawn_lock_timeout`, and the `use_immediate_queue` entry in `_config/config.yml`. Nothing reads
  them any more; a project YAML line that sets one can be deleted.
- The single-job mode of the runner: the `job` option of `queuedjobs:work` / `QueuedJobsWorkTask`
  (`QueueWorker::runSingleJob()`, `QueueWorker::parseJobId()`). Only the per-job spawn used it.
  `sake queuedjobs:work --job=<ID>` is now rejected as an unknown option.
- Tests of the removed code: the spawn command and `flock` tests, the `--job` and `parseJobId()`
  tests, and `ImmediateSpawnRunsOnlyItsJobTest`.

The `stop-when-empty` flag is still accepted (the drain wrapper passes it) and has no effect:
stopping when empty is the only behaviour.

## 1.6.0 (2026-10-12)

A Laravel-style queue worker.

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
