# Silverstripe QueuedJobs Enhancements

Progress tracking, immediate execution, and consolidation utilities for [symbiote/silverstripe-queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs).

`symbiote/silverstripe-queuedjobs` is solid for background processing, but the developer and end-user experience around it has some rough edges: a queued job gives no live feedback (the browser only learns it finished on the next page reload), jobs wait for the next cron tick before they start, and there is no first-class way to queue a one-off method call or send a composed Email asynchronously. This module adds those pieces as small, independent components that sit **alongside** the core module — it never replaces or subclasses core QueuedJobs classes.

## Requirements

- PHP `^8.1` (Silverstripe 6 itself needs 8.3)
- `silverstripe/framework` `^5.4 || ^6`
- `symbiote/silverstripe-queuedjobs` `^5 || ^6`

`composer.json` is the source of truth. One code line serves both majors:

| Branch | Module version | Silverstripe | queuedjobs | PHP |
|---|---|---|---|---|
| `ss6` (unreleased) | next | 5.4+ and 6 | 5 and 6 | 8.1+ (SS5), 8.3+ (SS6) |
| `main` | 1.x up to 1.4.0 | 5 only | 5 | 8.1+ |

Where the two majors differ, the module detects which one is installed and does the right thing:
the dev tasks have a separate entry point per major (see `src/Tasks/*EntryPoint.php`), and the
CLI spawn runs `cli-script.php` on Silverstripe 5 and `bin/sake` on Silverstripe 6.

## Installation

```bash
composer require restruct/silverstripe-queuedjobs-enhancements
```

Then flush the manifest - visit any URL with `?flush=1`, or:

```bash
# Silverstripe 5
php vendor/silverstripe/framework/cli-script.php dev/build flush=1
# Silverstripe 6
vendor/bin/sake db:build --flush
```

The module registers:

- A route `api/job-progress//$ID` → `JobProgressController` (via `_config/routes.yml`).
- Two dev tasks: `check-default-jobs` and `cleanup-broken-jobs` (`dev/tasks/<name>` on Silverstripe 5, `sake tasks:<name>` on Silverstripe 6).
- Exposes `client/` (for `client/js/job-progress.js`) via `extra.expose` in `composer.json`.

It registers no extensions and adds no runtime hooks — nothing runs unless you call it.

## Features

Each feature is independently useful — use only what you need.

### 1. Job Progress Tracking

A REST endpoint plus a dependency-free JS polling component for showing job progress in the browser.

**Endpoint:** `GET /api/job-progress/{descriptorID}`

Returns JSON:

```json
{
    "id": 42,
    "status": "Running",
    "title": "My import job",
    "progress": 15,
    "total": 100,
    "percent": 15,
    "message": "Processing row 15...",
    "complete": false,
    "started": "2025-01-15 10:30:00",
    "finished": null
}
```

`progress`/`total` come from the descriptor's `StepsProcessed`/`TotalSteps`; `percent` is derived (0 when total is 0). `message` is the last job message as plain text (HTML stripped). `complete` is `true` once the job reaches a terminal status (`Complete`, `Broken`, or `Cancelled`), at which point `finished` is populated from `JobFinished`.

**Authentication and permission:** the caller must be logged in (otherwise `401`). Access is granted if `$descriptor->canView($member)` returns true, or — as a fallback — if the member's ID matches the descriptor's `RunAsID` (so the user who queued a job can always watch it). Missing ID → `400`; unknown descriptor → `404`; not permitted → `403`. Responses are sent with `Cache-Control: no-store` so progress is never cached.

**Declarative use (auto-discovered):**

```html
<div class="job-progress" data-job-id="42">
    <div class="progress"><div class="progress-bar" role="progressbar"></div></div>
    <span class="job-progress__label">Starting...</span>
</div>
```

Elements with `[data-job-id]` are discovered on `DOMContentLoaded` and also via a `MutationObserver` (so AJAX-loaded CMS content is picked up). Each element is marked with `data-job-tracking` to avoid double-initialisation. An optional `data-job-interval` (ms) overrides the default 2000 ms poll interval. The component updates `.progress-bar` width + `aria-valuenow` and `.job-progress__label` text (Bootstrap-compatible markup), and on completion adds `job-progress--complete` plus one of `--success` / `--broken` / `--cancelled`.

**Programmatic use:**

```js
const tracker = new JobProgress(42, {
    interval: 2000,
    onProgress: (data) => console.log(data.percent + '%'),
    onComplete: (data) => alert('Done: ' + data.status),
    onError: (err) => console.error(err),
    element: document.querySelector('.my-progress'), // optional
});
tracker.start();
// tracker.stop(); // cancel polling
```

Polling stops automatically on terminal status. On a fetch error it backs off (interval doubles, capped at 30 s) and keeps retrying. `window.JobProgress` and `window.initJobProgressElements` are exported for reuse.

**Note:** polling runs in the current page context and does **not** survive page navigation or refresh. This is fine for single-page CMS actions (GridField buttons, modal dialogs) where the user stays on the page. Cross-page persistence (e.g. via `sessionStorage`) is future work.

#### The client component: `client/js/job-progress.js`

Vanilla JS, no framework dependency; works in both the CMS admin and frontend templates. Exposed via `extra.expose`, so once the module is installed and `?flush`ed the file is available under the exposed `_resources/` path for that vendor module. Include it on any page that renders `[data-job-id]` markup, or use `window.JobProgress` directly.

### 2. Immediate Job Execution

Skip the cron wait — queue a job and trigger it to run immediately in a background CLI process.

```php
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;

$descriptorId = ImmediateJobRunner::queueAndRun($job);
// optional second arg: $startAfter (a datetime string, e.g. 'Y-m-d H:i:s')
```

A `$startAfter` in the future queues the job but does **not** spawn a process for it: the job is
left to the queue runner, which starts it once that time has passed. (Up to 1.4.0 it was spawned
anyway, and the spawned process ran it at once, ignoring the start time.)

**How it works** — the job is *always* queued first (a safety net: if the spawn fails, cron still picks it up), then execution is triggered according to this detection order:

| Priority | Condition | Behaviour |
|----------|-----------|-----------|
| 1 | `use_immediate_queue: true` | Queue as `QueuedJob::IMMEDIATE` (for setups where `inotifywait` monitors that queue) |
| 2 | `exec()` available and not running under CLI | Spawn a detached background PHP process for this specific job |
| 3 | Fallback | Job stays queued; the normal cron runner handles it (typically within 60 s) |

The background process is spawned as:

```bash
# Silverstripe 5
nohup <php> vendor/silverstripe/framework/cli-script.php dev/tasks/ProcessJobQueueTask job=queuedjob-<ID> > /dev/null 2>&1 &
# Silverstripe 6 (cli-script.php no longer exists; sake is a PHP script there)
nohup <php> vendor/silverstripe/framework/bin/sake tasks:ProcessJobQueueTask --job=queuedjob-<ID> > /dev/null 2>&1 &
```

`<php>` is a real CLI binary: `PHP_BINARY` when the caller is itself CLI, otherwise the
version-matched `phpX.Y` (or `php`) next to `PHP_BINDIR`, because under PHP-FPM `PHP_BINARY` is
the FPM daemon, which cannot run a script. Spawn failures are logged as warnings and never break
the request - the job remains safely queued. No `fastcgi_finish_request()` tricks and no core
class replacement.

> **The spawned process also runs the whole IMMEDIATE queue as it exits.** Every CLI process that
> creates `QueuedJobService` registers its shutdown handler (`use_shutdown_function`, default
> `true`), so a spawn started for one job also runs every due `IMMEDIATE` job before it exits.
> On queuedjobs 5.x that drain ignores the maintenance lock: a spawn during maintenance runs
> IMMEDIATE jobs even though its own job is held back (fixed upstream in queuedjobs 6.2.2).
> To stop both, set `QueuedJobService.use_shutdown_function: false` and run the immediate queue
> from its own cron (`queue=immediate`), which upstream recommends anyway and plans to require
> (symbiote/silverstripe-queuedjobs#506).

> **The `queuedjob-` prefix is mandatory.** `ProcessJobQueueTask` only enters single-job mode when the `job` parameter contains a dash. A bare `job=42` silently falls through to draining the entire *Queued* queue.

Because the spawn is a separate CLI process, it does **not** occupy a PHP-FPM worker (that is the failure mode of `fastcgi_finish_request()`, which this module deliberately avoids). The real cost is that each dispatch bootstraps Silverstripe in a new process — so spawns are serialised behind a `flock` lock by default (see below).

**Concurrency (`flock`).** Without a lock, a burst of dispatches forks an unbounded number of PHP processes, each bootstrapping the whole framework. `use_spawn_lock` (on by default) wraps the spawn in `flock`, so at most one spawned runner executes at a time — the same property the `inotifywait` watcher gets for free, since `inotifywait … | sh` feeds commands into a single shell. Waiting is the default rather than failing fast: the process is already detached, so blocking costs nothing and no dispatch is dropped. If the wait times out the job simply stays queued and cron picks it up.

`flock` comes from util-linux and is **absent on macOS**; when no binary is found the module logs a warning and spawns without a lock rather than emitting a command that cannot run.

**Configuration:**

```yaml
Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner:
  # Set true only if inotifywait (or similar) monitors the IMMEDIATE queue.
  use_immediate_queue: false

  # Serialise spawned runners so a burst cannot fork unbounded PHP processes.
  use_spawn_lock: true
  # Relative paths resolve under TEMP_PATH. Pin an absolute path if you want the
  # lock shared across deployments (TEMP_PATH is derived from BASE_PATH, which
  # changes per release on zero-downtime deploys).
  spawn_lock_file: 'queuedjobs-enhancements-spawn.lock'
  # Seconds to wait for the lock before giving up (job stays queued). 0 = fail fast (flock -n).
  spawn_lock_timeout: 300
```

> **`use_immediate_queue: true` has a prerequisite.** The trigger file that `inotifywait` watches is
> only written when the job's `JobType` is `IMMEDIATE` **and** `QueuedJobService.use_shutdown_function`
> is `false` (see `QueuedJobDescriptor::activateOnQueue()`). That setting defaults to `true`, so you must
> also set:
>
> ```yaml
> Symbiote\QueuedJobs\Services\QueuedJobService:
>   use_shutdown_function: false
> ```
>
> The module logs a warning if you enable `use_immediate_queue` without this. Note that disabling the
> shutdown function also removes the behaviour where a CLI cron run drains the IMMEDIATE queue as it
> exits — so keep a `queue=immediate` cron entry as a safety net if you rely on that.

### 3. Default-Job Diagnostics (`$is_default_job`)

> **Scheduling is core's job, not this module's.** `QueuedJobService::runQueue()` calls
> `checkDefaultJobs()` on *every* invocation, and `ProcessJobQueueTask` calls `runQueue()` —
> so a plain YAML `defaultJobs` block is already re-checked on every cron tick, with **zero**
> extra setup, plus core's alert emails and health-check integration. This module therefore
> **does not create or schedule default jobs**. It only reports what's declared, and can
> generate the YAML for you.
>
> (It cannot hand discovered jobs to core either: `QueuedJobService::$defaultJobs` is a public
> property populated via Injector `properties:`, and neither `runQueue()` nor `checkDefaultJobs()`
> exposes an extension hook. Re-implementing core's creation logic to work around that is what
> earlier versions did — and it bought nothing but a second, less capable mechanism.)

Declare a job's schedule next to the job itself:

```php
class DataSyncJob extends AbstractQueuedJob implements QueuedJob
{
    private static bool $is_default_job = true;
    private static string $default_schedule = '+5 minutes';

    // Optional:
    private static string $default_job_type = QueuedJob::QUEUED; // adds `jobType` to the emitted config
    private static array $default_construct = [];             // constructor args
}
```

`DefaultJobManager` discovers every concrete `AbstractQueuedJob` subclass with `$is_default_job = true` (via `ClassInfo::subclassesFor()`, skipping abstract classes) and reports whether each has an *active* instance in the queue — "active" meaning a descriptor in a non-terminal status (`New`, `Initialising`, `Running`, `Waiting`, `Paused`).

**Report what's declared and whether it's queued:**

```bash
sake dev/tasks/check-default-jobs     # Silverstripe 5
sake tasks:check-default-jobs         # Silverstripe 6
```

**Generate the core `defaultJobs` YAML to paste into your project config:**

```bash
sake dev/tasks/check-default-jobs yaml=1    # Silverstripe 5
sake tasks:check-default-jobs --yaml        # Silverstripe 6
```

That emits exactly the structure core's `checkDefaultJobs()` expects (`type`, `filter`, `recreate`, `construct`, `startDateFormat`, `startTimeString`, and `jobType` when `$default_job_type` is set). Paste it into e.g. `app/_config/queuedjobs.yml` and core takes over — creation, alert emails, health checks. You get the co-location benefit while authoring, without a second runtime mechanism to maintain.

> `jobType` restricts recreation to runs of *that* queue, so if you set `$default_job_type`, make sure a runner processes that queue.

### 4. ScheduledMethodCall

Queue any method call as a background job — the bridge between "I have a method" and "I want it queued." Instance calls store the object's ID + class and re-fetch the `DataObject` when the job runs; static/other-object calls store the class name.

```php
use Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall;

// Instance method (object reloaded by ID when the job runs)
ScheduledMethodCall::schedule($myObject, 'processData', [$arg1], [
    'description' => 'Process data for item #' . $myObject->ID,
]);

// Static method call
ScheduledMethodCall::schedule(MyService::class, 'cleanup');

// Immediate execution (CLI spawn via ImmediateJobRunner)
ScheduledMethodCall::scheduleImmediate($myObject, 'sendEmail', [$recipient], [
    'description' => 'Send notification email',
]);

// Schedule for later (strtotime-compatible)
ScheduledMethodCall::schedule($myObject, 'archive', [], [], '+1 hour');
```

For instance calls, the running job instance is exposed to the target object as `$object->scheduled_job_instance`, so the called method can add job messages, advance steps, etc. If the method throws, the job is marked `Broken` with the exception detail added as a message.

> **For Emails, use [SendEmailJob](#5-sendemailjob) — ScheduledMethodCall cannot carry them.** An `Email` is not a `DataObject`: this job persists class+ID and re-fetches, so a composed Email cannot ride along (the naive attempt ends up calling `Email::send()` statically on an empty singleton).

**Options (4th argument):**

| Option | Default | Description |
|--------|---------|-------------|
| `description` | `'[ no description ]'` | Title shown in the QueuedJobs admin |
| `jobType` | `QueuedJob::QUEUED` | Queue type |
| `totalSteps` | `1` | Total steps (for multi-step processing) |
| `ignoreIdentical` | `false` | When `true`, appends a random signature so identical jobs can coexist in the queue |

### 5. SendEmailJob

Send a composed `Email` asynchronously. This is the counterpart to [ScheduledMethodCall](#4-scheduledmethodcall) for the one thing it structurally cannot do: an `Email` is not a `DataObject`, so it can't be persisted as class+ID and re-fetched. `SendEmailJob` instead serialises the email's parts into `jobData` via `hydrate()` and rebuilds a fresh `Email` in `process()`.

```php
use Restruct\QueuedJobsEnhancements\Jobs\SendEmailJob;
use SilverStripe\Control\Email\Email;
use Symbiote\QueuedJobs\Services\QueuedJobService;

$email = Email::create()
    ->setTo(['user@example.com' => 'User Name'])
    ->setFrom(['noreply@example.com' => 'My Site'])
    ->setSubject('Your request was received')
    ->setBody($htmlBody);

$job = new SendEmailJob();
$job->hydrate($email);          // serialise To/From/Subject/HTML body/CC/BCC/Reply-To into jobData
QueuedJobService::singleton()->queueJob($job);
// ...cron (or ImmediateJobRunner::queueAndRun($job)) rebuilds and sends it
```

The implementation embodies three hard-won SS5/Symfony traps — each broke production at some point, and each is comment-documented in the class:

1. Addresses are stored as `[email => name]` maps — `getTo()` returns Symfony `Address` *objects*, and feeding those back into `setTo()` double-wraps them (`new Address($addressObject)` → TypeError).
2. The body is read via `getHtmlBody()` — the assembled `getBody()` returns a Symfony `TextPart` that is **not** string-castable.
3. Reply-to uses `setReplyTo(string $address, string $name)` (not a map), unpacked from a **local copy** of the jobData property — `reset()` on the overloaded magic property raises "Indirect modification of overloaded property".

> **Limitation:** the round-trip carries **To / From / Subject / HTML body / CC / BCC / Reply-To only** — **no attachments and no custom headers**. That's fine for notification mail; do not assume full `Email` fidelity.

A job whose recipient data was lost completes gracefully with a "skipping" message rather than breaking the queue runner.

### 6. CleanupBrokenJobsTask

Delete every queued job whose status is `Broken`:

```bash
sake dev/tasks/cleanup-broken-jobs     # Silverstripe 5
sake tasks:cleanup-broken-jobs         # Silverstripe 6
```

Only `Broken` jobs are removed; all other statuses are left untouched.

### 7. Concurrency Guard (`$prevent_concurrent_runs`)

Symbiote's own `grabMutex()` is strictly per-DESCRIPTOR: it stops the *same* descriptor
running twice, but two scheduled descriptors of the same job class happily run concurrently
in separate DB sessions (typical trigger: a slow recurring job overlapping its own next
scheduled run). For jobs that write shared data, that overlap is a duplicate-writes window.

Opt a job class in:

```php
class MySlowSyncJob extends AbstractQueuedJob implements QueuedJob
{
    private static bool $prevent_concurrent_runs = true;
}
```

…and enable the service swap **per project** (the module deliberately does not register it):

```yaml
SilverStripe\Core\Injector\Injector:
  Symbiote\QueuedJobs\Services\QueuedJobService:
    class: Restruct\QueuedJobsEnhancements\Services\EnhancedQueuedJobService
```

An existing `properties:` block (defaultJobs etc.) on that injector key keeps applying —
`class:` and `properties:` merge into the one spec.

When a guarded job's descriptor is about to start while ANOTHER descriptor of the same
`Implementation` is active (`Initialising`/`Running`/`Waiting`, or holding a committed
`Worker` claim), it is **deferred**: its claim is released and `StartAfter` is pushed
forward (`ConcurrencyGuard.defer_seconds`, default 300). No job lifecycle fires for a
deferred run — no `setup()`, no `afterComplete()`, no mails — it simply starts once the
active run has finished. `Waiting` (memory/time-parked) siblings deliberately count as
active: a parked job is half-done and about to resume in a fresh process.

**Race-safety (claim-then-verify):** the guard piggybacks on `grabMutex()` — each contender
first COMMITS its own per-descriptor Worker-token claim, then verifies for active siblings.
Two contenders can therefore never both proceed (each would have to verify before the
other's committed claim); worst case both back off and the next runner tick resolves it.
This also covers every start path — new jobs, `Waiting` resumes, stall restarts — and both
engines (`QueueRunner` in-process, `DoormanRunner` via its child task), since all of them
funnel through `QueuedJobService::runJob()` → `grabMutex()`.

**Already running your own `QueuedJobService` subclass?** PHP's single inheritance means two
subclasses can't be merged — instead add `use ConcurrencyGuardedMutex;` to your subclass (the
trait carries the whole override; all tunables live on `ConcurrencyGuard`, a real class,
because config declared on a trait does not merge), or call `ConcurrencyGuard` yourself from
your own `grabMutex()`.

## Dev Tasks

| Task | SS5 segment / SS6 command | Description |
|------|---------|-------------|
| Check Default Jobs | `check-default-jobs` / `tasks:check-default-jobs` | Report declared default jobs and whether they're queued (`yaml=1`, or `--yaml` on SS6, emits the core `defaultJobs` config). Creates nothing. |
| Cleanup Broken Jobs | `cleanup-broken-jobs` / `tasks:cleanup-broken-jobs` | Delete all broken jobs |

## Module Structure

```
src/
├── Controllers/
│   └── JobProgressController.php       # REST API for job progress
├── Jobs/
│   ├── ScheduledMethodCall.php         # Queue method calls
│   └── SendEmailJob.php                # Send a composed Email asynchronously
├── Services/
│   ├── ConcurrencyGuard.php            # Per-job-class concurrency decision logic + tunables
│   ├── ConcurrencyGuardedMutex.php     # grabMutex() claim-then-verify override (trait)
│   ├── DefaultJobManager.php           # Default-job discovery + diagnostics + YAML emitter
│   ├── EnhancedQueuedJobService.php    # The single opt-in Injector swap target
│   └── ImmediateJobRunner.php          # CLI spawn (flock-serialised) for immediate execution
└── Tasks/
    ├── CheckDefaultJobsTask.php        # Report default jobs / emit defaultJobs YAML
    ├── CheckDefaultJobsTaskEntryPoint.php   # run() on SS5 / execute() on SS6
    ├── CleanupBrokenJobsTask.php       # Delete broken jobs
    └── CleanupBrokenJobsTaskEntryPoint.php  # run() on SS5 / execute() on SS6

client/js/
└── job-progress.js                     # Vanilla JS polling component
```

## Testing

Run from a host project that requires the module as a symlinked path repository (`/tests` must
be present, so not a dist install):

```bash
# Silverstripe 5 (PHPUnit 9): flush=1 only works after an explicit test path
vendor/bin/phpunit vendor/restruct/silverstripe-queuedjobs-enhancements/tests flush=1
# Silverstripe 6 (PHPUnit 11): the flush is an environment variable
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-queuedjobs-enhancements/tests
# expected on both: OK (57 tests); 146 assertions on SS5, 147 on SS6 (one test asserts the
# version-specific spawn command)
```

CI (`.github/workflows/ci.yml`) runs the suite on Silverstripe 5 (PHP 8.1 and 8.3) and
Silverstripe 6 (PHP 8.3), then boots each host, builds the database and runs both dev tasks.

The suite covers the controller (auth/permission/response shape), `ScheduledMethodCall`, `DefaultJobManager` (discovery, diagnostics, emitted-YAML round-trip, and that it never creates jobs), `ImmediateJobRunner` (queue/`RunAsID` regression tests, the `queuedjob-` spawn contract, `flock` wrapping, and the IMMEDIATE trigger-file contract), `CleanupBrokenJobsTask`, and the concurrency guard (defer/run matrix through the real `processJobQueue()`/`runJob()` path, including the claim-then-verify window and the inactive-sibling statuses).

The `flock` tests stub the binary lookup, so the lock-wrapping and no-flock branches are both exercised on macOS *and* Linux rather than being skipped on whichever platform lacks `flock`.

> **Note for contributors:** `AbstractQueuedJob` does **not** use the `Injectable` trait, so job subclasses cannot be created with `::create()`. Construct them with `new ClassName(...)` or `Injector::inst()->create()` — the tests follow this convention deliberately.

> **Stale manifest tip:** if tests fail with `getItemPath returned null … Try adding flush=1`, the class manifest cache is stale. Flush it (`?flush=1`, or clear the `silverstripe-cache-*` dir) and re-run.

## Design Principles

1. **Never replace or subclass core QueuedJobs classes — unless there is no hook, and then only opt-in.** Extensions where hooks exist, complementary services alongside. The one exception is `EnhancedQueuedJobService` (concurrency guard): `QueuedJobService` exposes no extension point at job selection or `grabMutex()`, so a subclass is the only non-fork option — it overrides that single method, is inert unless a job opts in, and the Injector swap is never registered by the module itself (each project enables it explicitly).
2. **Play well with existing infrastructure.** If `inotifywait` is set up, use the IMMEDIATE queue (note: `inotifywait` is not installed by default — it comes from the `inotify-tools` package). If `defaultJobs` YAML exists, it keeps working. Respect the existing job runner.
3. **CLI spawn over FPM tricks.** Background processes via `exec()` are more robust and portable than `fastcgi_finish_request()`, and — unlike it — do not hold a PHP-FPM worker for the duration of the job.
4. **Traits over base classes.** (Future: a `MultiStepJob` trait for sequential/parallel/dynamic step processing with flow control.)

## Migration from admintweaks

`ScheduledMethodCall` and `CleanupBrokenJobsTask` were consolidated from `restruct/silverstripe-admintweaks`. If you used them from there, update the namespace — the API is identical, only the `use` statement changes:

| Old (admintweaks) | New (this module) |
|---|---|
| `Restruct\Silverstripe\AdminTweaks\Jobs\ScheduledMethodCall` | `Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall` |
| `Restruct\Silverstripe\AdminTweaks\Dev\CleanupBrokenJobsTask` | `Restruct\QueuedJobsEnhancements\Tasks\CleanupBrokenJobsTask` |

## Background & research

This module was designed and evaluated in a longer research document before implementation. It realizes **Phases 1, 2 and 5** of that research (progress tracking, immediate execution, self-declaring default jobs, and the admintweaks consolidation). The design rationale, alternatives that were considered and rejected (WebSockets/SSE, FPM shutdown tricks, replacing core classes), and the SS5→SS6 closure-library migration notes are distilled in [docs/RESEARCH.md](docs/RESEARCH.md).

The full original evaluation is archived in the Restruct SSKB at `silverstripe/ideas/queuedjobs-enhancements.md`. That path is a pointer only — this module does not depend on it, and [docs/RESEARCH.md](docs/RESEARCH.md) is self-contained.

## Roadmap

See [PLAN.md](PLAN.md) for what is implemented versus planned. Planned (not yet built):

- **Push notifications** — Web Push on job completion (e.g. via `minishlink/web-push`).
- **`MultiStepJob` trait** — sequential/parallel/dynamic multi-step processing with a `StepResult` flow-control value object (`next()`, `repeat()`, `goto()`, `spawn()`, `complete()`).
- **`JobDispatchAction` trait** — CMS action buttons that dispatch jobs with one click and switch into progress-polling mode.

## License

MIT
