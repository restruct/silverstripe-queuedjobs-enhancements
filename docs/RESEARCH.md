# Design Research & Rationale

This document distills the design thinking behind `restruct/silverstripe-queuedjobs-enhancements` so the module's backup is self-contained. It records **why** each delivered component exists, the alternatives that were considered and rejected, and the SS5→SS6 migration notes. For usage and the roadmap see [../README.md](../README.md).

## Sources

The design was driven by these local documents. **This module does not depend on any of them** — everything needed is captured in this file and `README.md`. Verified inventory (2026-07-02):

**Primary research (internal research notes, not published)**
- The original evaluation (~64 KB): problem statement, six proposed "pillars", prior-art survey, a Laravel-feature comparison, and full code sketches + the phased scope (Phases 1/2/5) the module was built from.
- An internal QueuedJobs reference (~63 KB): job lifecycle, config, patterns. Source for the shutdown-function / IMMEDIATE-queue analysis in this doc, which can be checked against upstream's `QueuedJobService::__construct()` (registers `onShutdown()`) and `onShutdown()` (runs `processJobQueue(QueuedJob::IMMEDIATE)`).

**Plans**
- The implementation plans were internal working documents and are not part of the published repository.

**Internal development guidelines that governed the workflow**
- Local module development: the `_dev/` path-repository workflow (the fix for the double-scan bug below).
- Composer: path repos + version constraints (`dev-ss5-update`).
- CLI commands: when to flush vs. dev/build; `cli-script.php` over `sake` on SS5 (`DrainSpawner` runs the task through `cli-script.php` on SS5 and through `bin/sake` on SS6).

**Consolidation origins (code moved into this module)**
- `restruct/silverstripe-admintweaks: src/Jobs/ScheduledMethodCall.php` — origin of this module's `ScheduledMethodCall` (present in the working admintweaks copy).
- `restruct/silverstripe-admintweaks: src/Dev/CleanupBrokenJobsTask.php` — origin of `CleanupBrokenJobsTask`. **Gap:** not present in the working admintweaks copy consulted (its `Dev/` only had `FocusPointInvertYaxisTask.php`), likely a different admintweaks version/branch. To diff the consolidated task against its source, locate that origin from another admintweaks checkout.

**Integration status**
- `<site>/docs/queuedjobs-enhancements-module-status.md` — how the module was wired into a site (a `_dev/` path repository), the crash-recovery fixes, and the `_dev` vs project-root manifest gotcha (see below).

## The problem this module solves

`symbiote/silverstripe-queuedjobs` is a solid background-processing engine, but the experience around it is dated:

1. **No live feedback** — a queued job gives no progress until the page is manually reloaded.
2. **Cron latency** — user-triggered jobs wait for the next cron tick (up to ~60 s) before starting.
3. **Verbose default-job config** — auto-recreating "default" jobs need a multi-key YAML block per job, scattered away from the job class.
4. **No one-off dispatch** — running a single method call or a bit of inline logic in the background means writing a whole dedicated job class.
5. **Scattered utilities** — small QueuedJobs helpers lived in `restruct/silverstripe-admintweaks`, mixed in with unrelated admin tweaks.

Each delivered component targets one of these.

## Overarching design principles

These four principles constrained every decision:

1. **Never replace or subclass core QueuedJobs classes unless there is no hook, and then only opt-in.** Overriding `QueuedJobService` / `QueuedJobsAdmin` via Injector is a maintenance and compatibility hazard: upstream changes break the override silently, only one module can override a given class, and you inherit all of the parent's complexity. The Silverstripe-native answer is Extensions plus complementary services — which is what this module uses. The one exception is `EnhancedQueuedJobService` (the 1.4.0 concurrency guard): `grabMutex()` has no extension point, so it overrides that single method, does nothing unless a job opts in, and is swapped in by a project's own Injector config, never by the module. The 1.7.0 drain spawn keeps to the rule: it is an extension on `QueuedJobDescriptor`, not an Injector replacement.
2. **Play well with existing infrastructure.** If a site already runs the IMMEDIATE queue with `inotifywait`, the drain spawn is switched off with one setting (`DrainSpawner.enabled: false`). If a project already has `defaultJobs` YAML, leave it working. Respect the existing job runner rather than competing with it.
3. **CLI spawn over FPM tricks.** A detached `exec('nohup php … &')` process beats `fastcgi_finish_request()` on every axis that matters (see the table in §2). Since 1.7.0 it is one drain runner per burst, not one process per job.
4. **Traits over base classes.** Jobs already extend `AbstractQueuedJob` (single inheritance is spent); trait-based composition avoids hierarchy conflicts. This principle mainly governs the *planned* `MultiStepJob` / `JobDispatchAction` work.

## Component rationale

### 1. Job progress tracking (Pillar 1)

**Decision: polling, not WebSockets/SSE.**

| Rejected approach | Why rejected |
|-------------------|--------------|
| WebSockets (Reverb/Ratchet) | Needs a separate long-running process, custom port, reverse-proxy config, Supervisor. Infrastructure overkill for admin-only job monitoring. |
| Server-Sent Events (SSE) | Holds a PHP-FPM worker per open listener for the whole job duration. With a typical 5–20 worker pool, a handful of listeners can exhaust it. |
| SaaS WebSocket (Pusher/Ably) | Monthly cost + external dependency for marginal benefit over polling. |

A 2–3 second poll delay is imperceptible for jobs that run for minutes. Notably, even Laravel Horizon's "real-time" dashboard uses polling rather than WebSockets — if polling is good enough there, it is good enough here.

The JS component is deliberately **framework-free** (no React/jQuery/Vue dependency) so it works identically in the CMS admin and on frontend templates. Prior art (`fullscreeninteractive/silverstripe-queuedjob-progressfield`, `edwilde/silverstripe-queuedjobs-live`) confirmed polling is a proven pattern but was tied to QueuedJobs ^4 / unmaintained, hence a fresh, minimal implementation.

**Known limitation (accepted):** polling does not survive page navigation. The primary use case (a GridField action button where the user stays on the page) doesn't need it. `sessionStorage`-based persistence is future work.

### 2. Immediate execution (Pillar 2)

**Decision (1.7.0): a drain spawn. Every newly queued IMMEDIATE job makes sure one short-lived CLI runner is draining the immediate queue; nothing stays resident between bursts.**

#### Why not upstream's own routes

Upstream offers two ways to run the IMMEDIATE queue without waiting for cron, and neither covers a web request on a standard host:

- **`use_shutdown_function`** registers its shutdown handler only under the CLI (`QueuedJobService::__construct()` checks `Director::is_cli()`). An IMMEDIATE job queued from a web request therefore waits for the next CLI process to exit, in practice the next cron tick, exactly like `QueuedJob::QUEUED`.
- **`inotifywait`** watches the trigger-file folder and starts a runner per event. It needs the `inotify-tools` package (not installed on Forge servers; installing it needs root) and a resident watcher. Upstream's recipe (`inotifywait --monitor ... | sh`) is not a fork bomb under a burst: a single shell reads the pipe, so the runners start one after another and the cost is one full PHP boot per job, in series. It becomes one when the command is backgrounded (`&`) or each event gets its own lock waiter. Not measured here; whether events are lost when the kernel's inotify queue overflows (`fs.inotify.max_queued_events`) is unverified.

#### Options compared

Measured on a Forge test server (Ubuntu 24.04, 1 CPU, PHP 8.3, MySQL 8.4), on an SS5 and an SS6 site, with the job table seeded to 11k rows and cron off:

| Option | Resident while idle | Single-job delay (median, SS5 / SS6) | Burst of 1000 trivial jobs |
|---|---|---|---|
| **BASE**, 1.6.0 and earlier: one `nohup flock -w 300 php ... --job=<ID>` per job | nothing | 316 / 387 ms | 1 runner + ~965 lock waiters, ~1.8 GB; **only 432 / 450 jobs ran**: the waiters gave up after the 300 s lock timeout and their jobs stayed New |
| **A**, 1.6.0 resident worker (`queuedjobs:work --sleep=1` / `3`, polls the DB) | one 64-66 MB PHP process, 60-180 queries/min, 1-3 CPU s per 10 min | 538 / 635 ms (sleep 1) | all 1000, one process |
| **B**, trigger-folder watcher (bash, 2 s poll, spawns a `--stop-when-empty` runner) | one 3.6 MB bash process, no queries | 1.3 / 1.5 s | all 1000, one process |
| **C**, drain spawn (1.7.0) | nothing | 346 / 428 ms | all 1000 in 96 / 111 s, one runner process (2 starts) |

Why C over the others:

- **BASE does not scale and loses work.** One process per job behind a lock means a burst piles up lock waiters, and every waiter that times out strands its job until cron.
- **A pays forever for a latency it barely beats.** A resident PHP process and a query every 0.3-1 s on every site, around the clock, to save a few hundred milliseconds over C, plus a Supervisor program and a restart line in every deploy script.
- **B has two gaps with real failure modes.** A stopped runner strands its job (the watcher had already deleted the trigger file), and the trigger folder sits under the release directory, so after a zero-downtime deploy the watcher watches the old folder until it is restarted (measured: new jobs stayed New).
- **C keeps BASE's latency and A's single process**, with nothing resident. The cost per dispatch is one background shell (about 20-30 ms on that 1-CPU box); a dispatch that finds a runner already working starts no PHP at all.

#### How the drain spawn works

1. `QueuedJobDescriptorDrainExtension::onAfterWrite()` fires on the insert of an IMMEDIATE descriptor. The insert is detected with `isChanged('ID')`: the ID is marked changed by the base-record write and the changed fields are cleared only after `onAfterWrite()` (checked in the SS5 and SS6 `DataObject` source). `onAfterWrite()` also fires on every later status write, hence the check. The extension point is the descriptor, not `queueJob()`: `updateJobDescriptorBeforeQueued` runs before the write (no ID yet), and a deduplicated `queueJob()` returns early without starting anything, which is right because the existing job already triggered a drain.
2. `DrainSpawner` touches a marker file (`.qje-spawn/pending` under the job folder, in a subfolder so upstream's non-recursive `inotifywait` recipe does not see it) and starts `bin/qje-drain.sh` with `nohup ... &`. Under PHP-FPM `PHP_BINARY` is the FPM daemon, so the CLI binary is resolved separately (`QJE_PHP_CLI`, the `php_cli` config, then `phpX.Y` next to `PHP_BINDIR`); php-fpm is never used.
3. The wrapper takes a non-blocking lock (`flock -n`). If another drain holds it, the wrapper exits at once: that runner will pick the new job up, because the marker was touched before the wrapper started.
4. With the lock, the wrapper removes the marker and runs one runner (`queuedjobs:work --queue=immediate`) that drains the queue and exits. It loops while the marker has come back (a job queued during the run) or when the runner exits **75**, meaning it stopped early at a limit (max-jobs, max-time, memory) after running jobs. The runner lingers 2 s on an empty queue, so a trickle of jobs is served by one boot.

The exit code 75 closes the gap the 1.6.0 worker had: it returned 0 for every stop, so a drain built on it stranded the rest of the queue when a runner hit a limit (measured: 150 of 300 jobs left New with `max-jobs=50`). With 1.7.0 the same run completes in 7 runner starts with nothing left.

The job is **always queued first**, so cron remains the safety net: if the spawn cannot run (`exec()` disabled, or a transaction that commits long after the dispatch, see the README), or a runner is stopped by a signal, the job waits for the next spawn or the next `ProcessJobQueueTask` tick. A runner stopped by SIGTERM finishes its current step, leaves the job Waiting and exits 0; the next runner resumes it at the next step (measured).

Deploys need nothing: no resident process to restart. A drain still running in the old release finishes beside the new release's drain, and upstream's job lock keeps a job from running twice (measured during a release switch: work shared between the two runners, nothing run twice).

#### Why CLI spawn over `fastcgi_finish_request()`

Unchanged since the first version:

| | `fastcgi_finish_request()` | CLI spawn (`exec`) |
|-|---------------------------|-------------------|
| SAPI compatibility | FPM only | Any (web or CLI) |
| FPM worker | Stays occupied during the job | Released immediately |
| `pm.max_requests` risk | Can kill the process mid-job | None (separate process) |
| Calling-code surprises | Request marked finished early; shutdown handlers / output buffering can misbehave | Request completes normally |
| Memory/time limits | Shared with the request | Own CLI defaults |
| Isolation | Shares request state | Clean process |

The cost is one PHP boot per drain: measured at 0.25 s CPU, 0.29 s wall, 64-67 MB.

#### Out of scope: exact-second jobs

An IMMEDIATE job with a future `StartAfter` (say, something that must run at 14:00:00 exactly) is not served by any upstream immediate route, and the drain runner skips it like upstream's `getNextPendingJob()` does: it runs at the first cron tick after its `StartAfter`. Only a resident DB-polling worker, like 1.6.0's, can hit an exact second, at that worker's idle cost. If a project needs it, that worker could come back as an opt-in.

> **1.6.0 and earlier** spawned one process per job (`--job=<ID>` behind a blocking `flock -w`), offered a resident worker (`--sleep`) with a restart command for deploys, and an opt-in `use_immediate_queue` that only queued on the IMMEDIATE queue for an `inotifywait` setup. All three were removed in 1.7.0; the bench above is why.

### 3. Self-declaring default jobs (Pillar 5)

**Decision: config on the job class, discovered at runtime, coexisting with core.**

The verbose `defaultJobs` YAML (redundant `type`/`filter`, required-but-empty `email`/`construct` keys, repeated per job) is replaced by `$is_default_job = true` + `$default_schedule` on the class itself, so scheduling lives with the job (single responsibility).

**Implementation note — this was reworked in 1.2.0, and the original sketch was right.** The research doc first proposed that a `DefaultJobServiceExtension` *merge* discovered jobs into the core `QueuedJobService.defaultJobs` config (letting the existing recreation + email mechanism do the work), with the BuildTask being diagnostic-only. Versions 1.0–1.1 instead took a self-contained route: `DefaultJobManager::ensureDefaultJobs()` created missing jobs directly, triggered from `QueuedJobsAdmin`'s init hook.

That was a mistake, for two reasons discovered later:

1. **Core already does this.** `QueuedJobService::runQueue()` calls `checkDefaultJobs()` on *every* invocation, and `ProcessJobQueueTask` calls `runQueue()`. A plain YAML `defaultJobs` block is therefore re-checked on every cron tick — with alert emails (`EmailService::createMissingDefaultJobReport()`) and health-check integration that the bolt-on re-implementation did not have.
2. **The trigger was unsound.** `Controller::doInit()` fires `onBeforeInit` *before* `$this->init()`, and `LeftAndMain`'s `canView()` check lives inside `init()` — so an unauthenticated request to `/admin/queuedjobs` ran the ensure logic (a potential write) before being redirected to login.

The original merge-into-`defaultJobs` idea also turns out to be **impossible without overriding core**: `QueuedJobService::$defaultJobs` is a public property populated via Injector `properties:`, and neither `runQueue()` nor `checkDefaultJobs()` exposes an extension hook. (`QueuedJobService` *is* `Extensible`, but only around `queueJob` and the exception/completion paths.)

So 1.2.0 removed the extension and the creation logic entirely. `DefaultJobManager` is now discovery + diagnostics, and can emit the core `defaultJobs` YAML block for you to paste (`sake dev/tasks/check-default-jobs yaml=1`). You keep the co-location benefit while authoring; core keeps ownership of scheduling.

### 4. `ScheduledMethodCall` (dispatch helper)

Removes the need to write a dedicated job class for small async work.

- **`ScheduledMethodCall`** — "call method X on object/class Y in the background." Clear and debuggable (the class, method and args are visible in the DB), and instance calls re-fetch the `DataObject` by ID at run time so no stale object is serialized.

> **`ClosureJob` was removed in 1.2.0.** It queued an arbitrary closure by wrapping it in a
> `SerializableClosure` and pre-serializing it to a string before it entered job data (PHP's native
> `serialize()`, which QueuedJobs uses for saved job data, refuses to serialize closures).
>
> It was dropped because: nothing used it; it was the module's only dependency on the **abandoned**
> `silverstripe/closure` (Opis) library, which emits deprecation notices on PHP 8.3; and it was a
> blocker for SS6, which needs `laravel/serializable-closure` instead.
>
> The two jobs also differ semantically in a way that made merging them unattractive:
> `ScheduledMethodCall` is **by reference** (stores class + ID, re-fetches fresh at run time) while
> `ClosureJob` was **by value** (captured state frozen at dispatch). If it returns, a unified
> `CallableJob` accepting `Closure | [$obj,'method'] | ['Class','method']` is the shape to aim for.

### 6. `CleanupBrokenJobsTask`

A one-liner utility (`sake dev/tasks/cleanup-broken-jobs`) to delete `Broken` jobs after fixing a root cause. General-purpose for any QueuedJobs project; consolidated here from admintweaks.

## SS5 → SS6 migration notes

### Closure library (`silverstripe/closure` → `laravel/serializable-closure`)

The closure-serialization library changed across major versions:

| Library | Used by | Status |
|---------|---------|--------|
| `opis/closure` 3.x | Original upstream | Maintained, but 4.x rewrote on FFI (not web-safe) |
| `silverstripe/closure` 3.x | **SS5** / doorman 4.x | Fork of opis 3.x; **archived / abandoned** on Packagist |
| `laravel/serializable-closure` ^2 | **SS6** / doorman 5.x | Fork of opis 3.x; the official replacement |

The migration happened upstream in `symbiote/silverstripe-queuedjobs#454` and `silverstripe/doorman#49`. Because doorman pulls whichever library is appropriate for the framework version, the correct library is already available — the only code change needed for SS6 is the import in `ClosureJob`:

```php
// SS5 (current source):
use Opis\Closure\SerializableClosure;

// SS6:
use Laravel\SerializableClosure\SerializableClosure;
```

The API (`new SerializableClosure($closure)` → `serialize()` → `unserialize()` → `->getClosure()`) is identical, so nothing else changes. `composer.json` lists both as `suggest` rather than `require`, since they only matter when `ClosureJob` is used.

### Framework / PHP

The module is greenfield SS5-native (typed properties, PHP 8.1+ syntax, no SS3/SS4 compat shims), which is why `composer.json` targets `silverstripe/framework ^5 || ^6` and PHP `^8.1`.

## Consolidation from admintweaks (status & follow-up)

`ScheduledMethodCall` and `CleanupBrokenJobsTask` were **moved** here from `restruct/silverstripe-admintweaks` and cleaned up (typed parameters, added `scheduleImmediate()`). A third admintweaks class, `ThrottledQueuedJobService`, was **deliberately not** consolidated: it is redundant now that QueuedJobs' core `checkJobHealth()` handles broken-job notification dedup via a `NotifiedBroken` DB flag (each broken job notified exactly once). Re-implementing that in a subclass would be counterproductive and would fight the parent's newer stalled-job detection.

**Follow-up (downstream, not yet done):** the code lives here, but consuming projects have not switched over. The namespace change is:

| Old (admintweaks) | New (this module) |
|---|---|
| `Restruct\Silverstripe\AdminTweaks\Jobs\ScheduledMethodCall` | `Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall` |
| `Restruct\Silverstripe\AdminTweaks\Dev\CleanupBrokenJobsTask` | `Restruct\QueuedJobsEnhancements\Tasks\CleanupBrokenJobsTask` |

As of writing, one site still imports the admintweaks versions — it installs this module (a `_dev/` path repository referenced from `composer.json`) but does not yet reference it from `app/` code. Switching the `use` statements, and opting existing jobs into `$is_default_job = true`, are intentional follow-up steps to take deliberately (turning on a default job changes runtime queue behaviour).

## Integration gotcha: `_dev/` vs project-root placement

Recorded here because it will bite anyone who relocates the module during local development:

The module must live under a directory that Silverstripe's `ModuleManifest` does **not** scan directly while it is *also* installed via the vendor symlink. Originally the module was placed at the project root (`/a-queuedjobs-enhancements/`); because that directory contained a `_config/` folder, the manifest scanned it **both** directly and again through the `vendor/` symlink, producing "two files containing the same class" errors on every `flush`. Moving it under `_dev/` (which SS does not scan) resolved this: the usual local module-development layout. If you see duplicate-class errors on flush, check for exactly this double-scan.

## Rejected / out-of-scope (recap)

- **Replacing core QueuedJobs classes** — maintenance/compat hazard; Extensions instead.
- **WebSockets / SSE for progress** — infrastructure cost and FPM-worker exhaustion; polling instead.
- **`fastcgi_finish_request()` for immediate execution** — SAPI-bound and ties up workers; CLI spawn instead.
- **A resident worker or watcher for the immediate queue**: a process and (for the worker) a DB poll on every site around the clock, plus a deploy restart; the drain spawn instead (§2). A resident worker remains the only way to run exact-second jobs, a possible later opt-in.
- **`ThrottledQueuedJobService`** — redundant with the core `NotifiedBroken` flag.

## Related upstream sources

- `symbiote/silverstripe-queuedjobs` `src/Services/QueuedJobService.php`: `__construct()` and `onShutdown()` for the shutdown-function drain of the IMMEDIATE queue; `checkJobHealth()` and `checkDefaultJobs()` for queue health checks and default jobs.
- The full original evaluation this document distills is kept in internal research notes; this file is self-contained without it.
