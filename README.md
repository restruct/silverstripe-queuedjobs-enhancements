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
| `main` | 1.5.0 and later | 5.4+ and 6 | 5 and 6 | 8.1+ (SS5), 8.3+ (SS6) |
| tags up to `1.4.0` | 1.0.0 to 1.4.0 | 5 only | 5 | 8.1+ |

Where the two majors differ, the module detects which one is installed and does the right thing:
the dev tasks have a separate entry point per major (see `src/Tasks/*EntryPoint.php`), and the
drain spawn runs `cli-script.php` on Silverstripe 5 and `bin/sake` on Silverstripe 6.

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
- Three dev tasks: `check-default-jobs`, `cleanup-broken-jobs` and `qjob-profile` (dev-only) (`dev/tasks/<name>` on Silverstripe 5, `sake tasks:<name>` on Silverstripe 6).
- The drain runner: `sake queuedjobs:work` on Silverstripe 6, `dev/tasks/QueuedJobsWorkTask` on
  Silverstripe 5 (see [§8](#8-drain-runner-queuedjobswork)). It adds no tables.
- Exposes `client/` (for `client/js/job-progress.js`) via `extra.expose` in `composer.json`.
- Three extensions on `QueuedJobDescriptor`: `QueuedJobDescriptorDrainExtension` (every new job on the
  `IMMEDIATE` queue starts a background drain, see [§2](#2-immediate-job-execution); switch it off
  with `DrainSpawner.enabled: false`), `QueuedJobContextExtension` (records the running job in
  `$_SERVER` for error reports, see [§9](#9-job-context-for-error-reports)) and
  `QueuedJobDescriptorPermissionExtension` (`canView()` for the member a job runs as, see
  [§1](#1-job-progress-tracking)).

<!-- It registers no extensions and adds no runtime hooks — nothing runs unless you call it. -->
<!-- Apart from the drain spawn it adds no runtime hooks: nothing else runs unless you call it. -->
<!-- Apart from these two descriptor hooks it adds no runtime hooks: nothing else runs unless you call it. -->
Apart from the two descriptor write hooks it adds no runtime hooks: nothing else runs unless you call it.

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

<!-- **Authentication and permission:** the caller must be logged in (otherwise `401`). Access is granted if `$descriptor->canView($member)` returns true, or — as a fallback — if the member's ID matches the descriptor's `RunAsID` (so the user who queued a job can always watch it). Missing ID → `400`; unknown descriptor → `404`; not permitted → `403`. Responses are sent with `Cache-Control: no-store` so progress is never cached. -->
**Authentication and permission:** the caller must be logged in (otherwise `401`). Access is
`$descriptor->canView($member)`: upstream allows ADMIN, and the module's
`QueuedJobDescriptorPermissionExtension` adds the member whose ID is the descriptor's `RunAsID` (so
the user who queued a job can always watch it). The rule lives on the model, not in the
controller, so every caller of `canView()` gets the same answer; it only ever adds a `true` for that
member, so a `false` from another extension still wins. Missing ID → `400`; unknown descriptor, or
one the member may not view → `404` (the same response, so IDs cannot be probed; up to 1.7.0 the
latter was a `403`). For a `Broken` job a non-admin gets `"message": "Job failed"` instead of the
last message, which is often the exception text that broke the job; ADMIN gets the real message.
Responses are sent with `Cache-Control: no-store` so progress is never cached.

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

Skip the cron wait: every job queued on the `IMMEDIATE` queue starts within about a second, in a
background CLI process. Nothing stays resident (no Supervisor program, no `inotifywait`, no
polling), and it works for every way a job reaches that queue:

```php
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

QueuedJobService::singleton()->queueJob($job, null, null, QueuedJob::IMMEDIATE);
// or a job whose getJobType() returns QueuedJob::IMMEDIATE, or (same thing, for existing callers):
$descriptorId = ImmediateJobRunner::queueAndRun($job);   // optional 2nd arg: $startAfter
```

**How it works (the drain spawn).** `QueuedJobDescriptorDrainExtension` watches
`QueuedJobDescriptor` writes. When a **new** descriptor on the `IMMEDIATE` queue has been written,
`DrainSpawner` touches a marker file and starts the module's wrapper detached:

```bash
cd <project>; nohup bash vendor/restruct/silverstripe-queuedjobs-enhancements/bin/qje-drain.sh \
    <marker> <lock> <php> <runner...> > /dev/null 2>&1 &
```

The runner is the drain runner of §8 on the immediate queue:

```bash
# Silverstripe 6
<php> vendor/silverstripe/framework/bin/sake queuedjobs:work --queue=immediate --stop-when-empty
# Silverstripe 5
<php> vendor/silverstripe/framework/cli-script.php dev/tasks/QueuedJobsWorkTask queue=immediate stop-when-empty=1
```

`bin/qje-drain.sh` loops: take the lock without waiting (held by another drain: exit at once,
before PHP starts); holding it, remove the marker and run the runner; after the runner exits, go
again if the marker exists (a job was queued after the runner started) or the runner exited `75`
(it stopped early with work left, see §8); otherwise exit. The descriptor row is written **before**
the marker is touched, so a job queued after the runner's last look at the queue always leaves a
marker behind for the wrapper to see. During a burst only the first dispatch starts PHP; the
others cost a short-lived `bash` and lock attempt.

The loop is bounded, so a broken environment cannot make it start PHP for ever: a marker that
cannot be removed (folder not writable, read-only filesystem) stops the wrapper before the runner
starts; a runner or lock tool that fails (e.g. a lock file that cannot be opened) gets one more
pass for a job queued meanwhile, and a second failure stops it; every pass after the first waits
0.2 s; and a wrapper runs at most `QJE_DRAIN_MAX_PASSES` runners (default 50), after which it
leaves the marker for the next dispatch or the cron. Each of these stops exits `70` with the
reason on stderr (see `DrainSpawner.log_file` below to keep it).

The runner lingers before it exits: once the queue is empty it keeps looking for a new due job
every 0.5 s for `QueueWorker.linger` seconds (default 2), so a trickle of dispatches is served by
one process instead of a boot per job.

What does **not** trigger a drain: updates of an existing descriptor, jobs on the `Queued` or
`Large` queue, and a `queueJob()` that finds an existing job with the same signature (it writes no
new row). A future `StartAfter` is not checked: the runner's `getNextPendingJob()` skips a job that
is not due yet, so that drain exits after its linger and the cron picks the job up when it is due.

**Requirements**

| | |
|---|---|
| `exec()` | Not in `disable_functions`. Without it nothing is spawned (logged once per process) and the job waits in the `IMMEDIATE` queue for the cron or another runner, as with plain upstream. |
| `bash` | `/bin/bash`, `/usr/bin/bash` or on `PATH`. |
| A lock tool | util-linux `flock` with `-E` (every current Ubuntu/Debian, Forge servers), else `perl`'s `flock()` (macOS has perl but no `flock`). With neither, the wrapper runs unlocked and warns on stderr: upstream's per-job mutex still stops a job running twice, but a burst can start one PHP process per dispatch. |
| A CLI php binary | Under PHP-FPM `PHP_BINARY` is the FPM daemon, which cannot run a script. The binary is, in this order: the `QJE_PHP_CLI` environment variable (read through `Environment::getEnv()`, so a `.env` entry works; FPM normally clears the real environment), the `DrainSpawner.php_cli` config, then automatic: `PHP_BINARY` under the CLI, else the version-exact `phpX.Y` (or `php`) next to `PHP_BINDIR`. A configured `php-fpm` value is ignored with a warning; php-fpm is never used. |
| A cron | Keep `ProcessJobQueueTask` crons (also `queue=immediate`) as the safety net: health checks, default jobs, jobs whose spawn could not run, and `StartAfter` jobs. |

**Configuration** (defaults shown):

```yaml
Restruct\QueuedJobsEnhancements\Services\DrainSpawner:
  # false on a site that runs the immediate queue another way (an inotifywait watcher);
  # jobs are queued as IMMEDIATE either way. Only an explicit false switches it off; a missing
  # value (e.g. a config cache built before this setting existed) counts as on
  enabled: true
  # CLI php binary; QJE_PHP_CLI wins over this
  php_cli: ''
  # append the wrapper's and runners' output here, for debugging (relative = under TEMP_PATH)
  log_file: ''
Restruct\QueuedJobsEnhancements\Worker\QueueWorker:
  # seconds a drain runner keeps looking for new jobs before it exits; 0 = exit at once
  linger: 2
```

```bash
# .env, when the automatic resolution does not find the right binary
QJE_PHP_CLI="/usr/bin/php8.3"
```

The marker (`.qje-spawn/pending`) and the lock (`.qje-spawn/lock`) live in a subfolder of
upstream's job folder (`QueuedJobService.cache_dir`, relative under `TEMP_PATH`;
`ImmediateJobRunner::triggerFolder()`), so they follow the same configuration as the
`queuedjob-<ID>` trigger files while a watcher on the job folder itself never sees them. `QueuedJobService.use_shutdown_function` is left at
upstream's default; the drain does not depend on it, and the runner switches it off for its own
process. Where it is false, upstream writes a `queuedjob-<ID>` trigger file per immediate job and
only deletes it when the job completes; the runner deletes the file of every job it ran, whatever
the outcome (§8), so broken or parked jobs leave none behind.

**Transactions.** A job queued inside an open database transaction is invisible to the runner
until the commit. The drain then runs twice: at once (which covers a commit within the linger) and
once more at the end of the process (`register_shutdown_function`). So a request or task that
commits and ends gets its job run; one that commits and then keeps running for a long time gets it
at its exit, or from the cron.

**Tests never spawn.** A process on a temporary test database (`SapphireTest`) never starts a
drain: the runner would boot against the site's real database from `.env`.

**Running `inotifywait` as well?** Set `DrainSpawner.enabled: false`, or the immediate queue is run
twice over (harmless, through upstream's mutex, but double the processes). The marker sits in the
`.qje-spawn/` subfolder so that upstream's (non-recursive) inotifywait recipe does not see it being
touched: in the job folder itself it made the recipe run `ProcessJobQueueTask job=<marker name>`,
which Silverstripe 6 rejects and Silverstripe 5 logs as an error.

> **History.** Up to 1.6.0 `queueAndRun()` spawned one process per job (`queuedjobs:work --job=<ID>
> --max-jobs=1`, before that `ProcessJobQueueTask job=queuedjob-<ID>`, which also drained the whole
> immediate queue as it exited), skipped the spawn under the CLI and for a future `$startAfter`, and
> with `use_immediate_queue: true` relied on an `inotifywait` watcher instead. The drain spawn
> deprecated that per-job spawn, and 1.7.0 removed it: the spawn helpers, the runner's `--job`
> option and the `use_immediate_queue`, `use_spawn_lock`, `spawn_lock_file` and
> `spawn_lock_timeout` settings. Nothing reads `use_immediate_queue` any more; a YAML line that
> sets it can be deleted.

> **Calling `ProcessJobQueueTask` yourself? The `queuedjob-` prefix is mandatory there.** It only
> enters single-job mode when the `job` parameter contains a dash (on Silverstripe 5, and on 6 up
> to queuedjobs 6.2.0). A bare `job=42` silently falls through to draining the entire *Queued*
> queue. (The runner's own `job` option, which took either form, was removed in 1.7.0.)

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

**Not in the Jobs admin's "Create job of type" form.** That upstream form passes its textarea's
lines to the job's constructor, which for this job is (class, method): a way to call any static
method. Since 1.7.1 `QueuedJobsAdminJobTypesExtension` removes `ScheduledMethodCall` (and its
subclasses) from the dropdown, which also makes the form refuse a crafted POST naming it (the
dropdown is validated against its options). Queuing it from code is unaffected. Further classes
can be hidden the same way:

```yaml
Restruct\QueuedJobsEnhancements\Extensions\QueuedJobsAdminJobTypesExtension:
  hidden_job_types:
    - App\Jobs\MyGenericJob
```

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

### 8. Drain Runner (`queuedjobs:work`)

The one-shot runner that `bin/qje-drain.sh` starts (§2): it runs the due jobs of the given
queue(s) in series, in the style of Laravel's `queue:work --stop-when-empty`, and exits when they
are empty. You do not normally start it yourself; the drain spawn does.

```bash
# Silverstripe 6
php vendor/bin/sake queuedjobs:work --queue=immediate --stop-when-empty
# Silverstripe 5: run cli-script.php with php directly, NOT the `sake` shell wrapper, which runs PHP
# as a child process that a stop signal never reaches
php vendor/silverstripe/framework/cli-script.php dev/tasks/QueuedJobsWorkTask queue=immediate stop-when-empty=1
```

| Option (SS6 `--name=`, SS5 `name=`) | Default | Meaning |
|---|---|---|
| `queue` | `immediate` | Queue(s) to work, comma separated, highest priority first. Every pass takes the next job from the first queue that has one. |
| `max-time` | `3600` | Exit after this many seconds (`0` = no limit). |
| `max-jobs` | `0` | Exit after this many jobs (`0` = no limit). |
| `memory` | the `QueuedJobService.memory_limit` | Exit once peak memory reaches this (`512M`, `1G`; a bare number is MB, as in Laravel). |
| `linger` | `QueueWorker.linger` (2) | Once empty, keep looking for a due job every 0.5 s for this many seconds before exiting. `0` = exit at once. |
| `stop-when-empty` | | No effect since 1.7.0: stopping when empty is the only behaviour. Still accepted, because `bin/qje-drain.sh` passes it. |

**How it picks jobs.** Each pass calls upstream's own `QueuedJobService::getNextPendingJob()` and
runs the job through `QueuedJobService::runJob()`: the same selection (`Waiting` jobs first, then
due `New` jobs), the same per-descriptor mutex (`grabMutex()`), RunAs handling and memory/time
parking as `ProcessJobQueueTask`. It is the body of `processJobQueue()`'s loop, one job at a time,
so the runner can check its exit conditions between two jobs. A paused queue (maintenance lock)
starts nothing: the runner exits.

**When it exits:**

- The queues are empty, after the linger.
- `max-time`, `max-jobs` or `memory` is reached.
- A job was left `Waiting`: `runJob()` parks a job when the process passes the service's memory or
  time limit, and that job needs a fresh process to continue. The next runner resumes it first.
- `SIGTERM`, `SIGINT` or `SIGQUIT` arrives (needs `ext-pcntl`, standard on Linux CLI PHP). The
  current job **step** finishes, then the job is parked as `Waiting` with its lock released and the
  runner exits; the next runner continues at the next step. This goes through upstream's own
  time-limit path, so the job log shows "Queue has passed time limit and will restart before
  continuing". Whatever sends the signal must wait longer than your longest job step, or the
  process is killed mid-step and the job waits for the health check to restart it.

**Exit codes:** `0` when the queue is empty (after the linger), the queue is paused, or it stopped
before running any job; `75` (`QueueWorker::EXIT_MORE_WORK`) when it stopped early after running
jobs, with work possibly left: `max-jobs`, `max-time`, `memory`, or a job parked as `Waiting`.
`bin/qje-drain.sh` starts a new runner on `75`. A stop signal (SIGTERM/SIGINT/SIGQUIT) exits `0`:
the runner was asked to go away, so the wrapper does not replace it; a job it left `Waiting` is run
by the next dispatch or the cron. The stop-before-the-first-job case is `0` because it
would stop the same way every time, and `75` would make the wrapper loop.

**Between jobs** it resets what a job may change and `runJob()` does not restore: the subsite (as
`processJobQueue()` does), the Versioned reading mode, the locale and the current user, and it
clears the `DataObject` cache (on Silverstripe 5 `runJob()` reads the descriptor through the
caching `DataObject::get_by_id()`, so a process that runs several jobs would otherwise serve it
stale). It also deletes upstream's trigger file `<job folder>/queuedjob-<ID>` of the job it just
ran, whatever the outcome (complete, broken or parked `Waiting`; a parked job is found again in
the database, not through its file). Upstream only deletes it for a completed job. The files of
jobs the runner did not run are left alone.

**Keep the crons.** The runner is an addition, not a replacement:

- `ProcessJobQueueTask` cron entries keep doing what the runner does not: the job health check
  (which restarts jobs left `Running` by a killed process) and `defaultJobs` creation.
- Keep the `queue=immediate` cron as a fallback for when no drain could start. A runner and a cron
  never run the same descriptor twice: `grabMutex()` makes the claim atomic.
- `use_shutdown_function` can stay at its default; the runner does not depend on it, and switches
  it off for its own process.

> **Removed in 1.7.0.** Up to 1.6.0 `queuedjobs:work` was also a resident, polling worker for
> Supervisor (`--sleep`, no `--stop-when-empty`), with `queuedjobs:restart` /
> `QueuedJobsRestartTask` for the deploy script and a `QueuedJobsWorkerRestartSignal` table. The
> drain spawn replaces it. An existing `QueuedJobsWorkerRestartSignal` table is left alone by the
> database build and can be dropped. A Supervisor program or deploy line that still calls the old
> commands must be removed: `--sleep` and `queuedjobs:restart` no longer exist. The single-job
> `--job=<ID>` mode, which `ImmediateJobRunner` spawned per job up to 1.6.0, was removed in 1.7.0
> as well.

### 9. Job context for error reports

When a queued job dies of a PHP fatal (typically an OOM), the error report names only the runner
(`GET dev/tasks/ProcessJobQueueTask`), not the job, and the job never gets to log anything itself.
`QueuedJobContextExtension` (registered on `QueuedJobDescriptor`, always on) records the job being
processed in `$_SERVER` after every write of its descriptor, which upstream's service does after
every step:

```
QUEUED_JOB         #123 App\Jobs\SyncJob | Nightly sync | step 41/200 | queue 2
QUEUED_JOB_MEMORY  used 312.4 MB / peak 340.1 MB | php.ini memory_limit 512M
```

Both keys are cleared as soon as the descriptor is no longer `Running`, so a later error in the
same runner process is not blamed on a finished job. `$_SERVER` on purpose: it survives into the
shutdown handler that renders the report, and reading it needs no allocation, which matters when
the failure is memory exhaustion. The memory figures are from the last completed step, so a single
step that jumps from well under the limit to a fatal shows exactly that.

restruct/silverstripe-admintweaks' `EnhancedErrorFormatter` renders both keys (they are in its
default `server_vars`); any other error reporter can read them from `$_SERVER`.

### 10. Queued job profiler (`qjob-profile`)

Runs a job's `setup()` + `process()` loop in-process and prints, per `process()` call, memory
(+ delta), peak, the real `currentStep`, the serialised `jobData` size and the message count, then
a summary. It answers "does this job accumulate, and where?". **Dev-only**: it really runs the job,
so it writes whatever the job writes; outside `dev` it refuses. Over HTTP it also needs ADMIN
(since 1.7.1): in `dev` mode the task runner is open to any visitor, so the CLI or an administrator
only.

```bash
# Silverstripe 6 (options; the same spelling works in the browser task runner)
php -d memory_limit=512M vendor/bin/sake tasks:qjob-profile --class='App\Jobs\SyncJob' --maxcalls=50
# Silverstripe 5 (CLI arguments are space-separated, not a query string)
php -d memory_limit=512M vendor/silverstripe/framework/cli-script.php dev/tasks/qjob-profile class='App\Jobs\SyncJob' maxcalls=50
```

Without a class it lists the job classes it can profile; `maxcalls` defaults to 500. Run it with
the memory limit the cron has, or the result means nothing. Reading it: a delta of about 0 across
calls means the job does not leak (if it still dies, look at the data, e.g. one query fetching too
many rows); `jobData` or `msgs` climbing is the accumulator, because both are re-serialised and
written after every step (see [§11](#11-bounded-job-messages)).

### 11. Bounded job messages

Upstream's `copyJobToDescriptor()` serialises a job's whole message log and writes it to the
descriptor after every step, so a job that logs inside a loop does work that grows with
messages x steps: in one production case 21,106 messages (4.29 MB) were re-serialised 3,088 times
until the runner ran out of memory at about 390 MB. The opt-in `BoundedJobMessages` trait keeps the
log bounded: the first messages (the setup and context) and the last ones (what the job was doing
when it stopped), with one marker in between that counts what was dropped.

```php
use Restruct\QueuedJobsEnhancements\Jobs\BoundedJobMessages;

class SyncJob extends AbstractQueuedJob
{
    use BoundedJobMessages;
}
```

Settings are read from the **job class** (a `private static` on it, or YAML keyed to it; a subclass
inherits them). YAML keyed to the trait name has no effect. Defaults shown:

```yaml
App\Jobs\SyncJob:
  # 1. bounding, on once the trait is used
  bound_messages: true
  keep_first_messages: 10
  keep_last_messages: 200
  # %d = the number of messages dropped so far (a text without %d is used as it is)
  omitted_messages_marker: '... %d earlier message(s) omitted to keep the job log bounded ...'
  # 2. severity filter, off: e.g. WARNING drops NOTICE, INFO and DEBUG (an unknown severity counts as INFO)
  min_message_severity: ''
  # 3. append " [current/peakMB]" to every kept message, off
  message_memory_usage: false
```

- The omitted count is kept in the job data, so it survives between steps (and processes), and the
  previous marker is recognised on the next trim, so the counts add up.
- Filtered messages are discarded, not counted as omitted. The filter also applies to the messages
  upstream's service adds to the job.
- A job that declares its own `addMessage()` overrides the trait's: call
  `$this->addBoundedMessage($message, $severity)` from it instead of `parent::addMessage()`.
- The descriptor log becomes a summary. Send what must be kept in full to a PSR logger as well.

## Dev Tasks

| Task | SS5 segment / SS6 command | Description |
|------|---------|-------------|
| Check Default Jobs | `check-default-jobs` / `tasks:check-default-jobs` | Report declared default jobs and whether they're queued (`yaml=1`, or `--yaml` on SS6, emits the core `defaultJobs` config). Creates nothing. |
| Cleanup Broken Jobs | `cleanup-broken-jobs` / `tasks:cleanup-broken-jobs` | Delete all broken jobs |
| Drain runner | `QueuedJobsWorkTask` / `queuedjobs:work` | One-shot runner started by the drain spawn (§8). CLI only. |
| Profile a queued job | `qjob-profile` / `tasks:qjob-profile` | Run a job in-process and report memory, step, `jobData` size and message count per `process()` call (§10). Dev-only. |

## Module Structure

```
src/
├── Cli/                                # Silverstripe 6 sake commands
│   └── QueueWorkCommand.php            # queuedjobs:work
├── Controllers/
│   └── JobProgressController.php       # REST API for job progress
├── Extensions/
│   ├── QueuedJobContextExtension.php   # running job -> $_SERVER QUEUED_JOB(_MEMORY)
│   └── QueuedJobDescriptorDrainExtension.php  # new IMMEDIATE descriptor -> drain spawn
├── Jobs/
│   ├── BoundedJobMessages.php          # Opt-in trait: bounded message log
│   ├── ScheduledMethodCall.php         # Queue method calls
│   └── SendEmailJob.php                # Send a composed Email asynchronously
├── Services/
│   ├── ConcurrencyGuard.php            # Per-job-class concurrency decision logic + tunables
│   ├── ConcurrencyGuardedMutex.php     # grabMutex() claim-then-verify override (trait)
│   ├── DefaultJobManager.php           # Default-job discovery + diagnostics + YAML emitter
│   ├── EnhancedQueuedJobService.php    # The single opt-in Injector swap target
│   ├── DrainSpawner.php                # Drain spawn: marker + detached bin/qje-drain.sh
│   └── ImmediateJobRunner.php          # queueAndRun(), CLI php binary, job folder
├── Tasks/
│   ├── CheckDefaultJobsTask.php        # Report default jobs / emit defaultJobs YAML
│   ├── CheckDefaultJobsTaskEntryPoint.php   # run() on SS5 / execute() on SS6
│   ├── CleanupBrokenJobsTask.php       # Delete broken jobs
│   ├── CleanupBrokenJobsTaskEntryPoint.php  # run() on SS5 / execute() on SS6
│   ├── QueuedJobProfileTask.php        # Profile a job in-process (dev-only)
│   ├── QueuedJobProfileTaskEntryPoint.php   # run() on SS5 / execute() on SS6
│   ├── QueuedJobsWorkTask.php          # SS5 entry point of queuedjobs:work
│   └── WorkerTaskEntryPoint.php        # run() on SS5 / disabled stub on SS6
└── Worker/
    └── QueueWorker.php                 # The one-shot drain runner (version-neutral)

client/js/
└── job-progress.js                     # Vanilla JS polling component

bin/
└── qje-drain.sh                        # Drain wrapper: lock, marker, one-shot runner loop
```

## Testing

Run from a host project that requires the module as a symlinked path repository (`/tests` must
be present, so not a dist install):

```bash
# Silverstripe 5 (PHPUnit 9): flush=1 only works after an explicit test path
vendor/bin/phpunit vendor/restruct/silverstripe-queuedjobs-enhancements/tests flush=1
# Silverstripe 6 (PHPUnit 11): the flush is an environment variable
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-queuedjobs-enhancements/tests
# expected: OK (107 tests, 319 assertions) on SS5, OK (107 tests, 324 assertions) on SS6
# (the profiler task's exit codes exist, and are asserted, on SS6 only)

# The drain wrapper, no PHP or database needed (every lock tool available here); --mutants checks
# that seven broken copies of the wrapper each fail it
bash vendor/restruct/silverstripe-queuedjobs-enhancements/tests/shell/qje-drain-test.sh
bash vendor/restruct/silverstripe-queuedjobs-enhancements/tests/shell/qje-drain-test.sh --mutants
```

CI (`.github/workflows/ci.yml`) runs the suite on Silverstripe 5 (PHP 8.1 and 8.3) and
Silverstripe 6 (PHP 8.3), then boots each host, builds the database and runs both dev tasks.

The suite covers the controller (auth/permission/response shape), `ScheduledMethodCall`, `DefaultJobManager` (discovery, diagnostics, emitted-YAML round-trip, and that it never creates jobs), `ImmediateJobRunner` (queue/`RunAsID` regression tests, a future `StartAfter` kept, a drain requested per new job, the CLI php binary under the CLI, and the IMMEDIATE trigger-file contract), `CleanupBrokenJobsTask`, `QueuedJobProfileTask` (a stub job profiled to completion, `maxcalls`, the job list, the dev-only refusal), `QueuedJobContextExtension` (the exact `$_SERVER` keys and format, cleared off `Running`), `BoundedJobMessages` (head and tail with one marker, counts across trims and step restores, a run through the real service, defaults and the opt-ins), the drain runner (priority order, every exit condition and the exit codes, linger, trigger-file deletion; `QueueWorkerTest`), the drain spawn (which writes spawn, the command, the CLI php binary precedence and that php-fpm is never used, no exec, transactions, the test-database guard; `DrainSpawnerTest`), and the concurrency guard (defer/run matrix through the real `processJobQueue()`/`runJob()` path, including the claim-then-verify window and the inactive-sibling statuses).

> **Note for contributors:** `AbstractQueuedJob` does **not** use the `Injectable` trait, so job subclasses cannot be created with `::create()`. Construct them with `new ClassName(...)` or `Injector::inst()->create()` — the tests follow this convention deliberately.

> **Stale manifest tip:** if tests fail with `getItemPath returned null … Try adding flush=1`, the class manifest cache is stale. Flush it (`?flush=1`, or clear the `silverstripe-cache-*` dir) and re-run.

## Design Principles

1. **Never replace or subclass core QueuedJobs classes — unless there is no hook, and then only opt-in.** Extensions where hooks exist, complementary services alongside. The one exception is `EnhancedQueuedJobService` (concurrency guard): `QueuedJobService` exposes no extension point at job selection or `grabMutex()`, so a subclass is the only non-fork option — it overrides that single method, is inert unless a job opts in, and the Injector swap is never registered by the module itself (each project enables it explicitly).
2. **Play well with existing infrastructure.** If `inotifywait` is set up, switch the drain spawn off (`DrainSpawner.enabled: false`) and let it run the IMMEDIATE queue (note: `inotifywait` is not installed by default — it comes from the `inotify-tools` package). If `defaultJobs` YAML exists, it keeps working. Respect the existing job runner.
3. **CLI spawn over FPM tricks.** Background processes via `exec()` are more robust and portable than `fastcgi_finish_request()`, and — unlike it — do not hold a PHP-FPM worker for the duration of the job.
4. **Traits over base classes.** (Future: a `MultiStepJob` trait for sequential/parallel/dynamic step processing with flow control.)

## Migration from admintweaks

`ScheduledMethodCall` and `CleanupBrokenJobsTask` were consolidated from `restruct/silverstripe-admintweaks`; `QueuedJobContextExtension` and `QueuedJobProfileTask` followed in 1.7.0. If you used them from there, update the namespace — the API is identical, only the `use` statement changes:

| Old (admintweaks) | New (this module) |
|---|---|
| `Restruct\Silverstripe\AdminTweaks\Jobs\ScheduledMethodCall` | `Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall` |
| `Restruct\Silverstripe\AdminTweaks\Dev\CleanupBrokenJobsTask` | `Restruct\QueuedJobsEnhancements\Tasks\CleanupBrokenJobsTask` |
| `Restruct\Silverstripe\AdminTweaks\Extensions\QueuedJobContextExtension` | `Restruct\QueuedJobsEnhancements\Extensions\QueuedJobContextExtension` |
| `Restruct\Silverstripe\AdminTweaks\Dev\QueuedJobProfileTask` | `Restruct\QueuedJobsEnhancements\Tasks\QueuedJobProfileTask` |

The extension and the profiler need no change in a project: admintweaks (from the releases that
mark them deprecated) stops registering its extension and disables its task when this module's
classes exist, so each runs once, under the same task name (`qjob-profile`). With an older
admintweaks both extensions run; they write the same keys with the same values, which is harmless.

## Background & research

This module was designed and evaluated in a longer research document before implementation. It realizes **Phases 1, 2 and 5** of that research (progress tracking, immediate execution, self-declaring default jobs, and the admintweaks consolidation). The design rationale, alternatives that were considered and rejected (WebSockets/SSE, FPM shutdown tricks, replacing core classes), and the SS5→SS6 closure-library migration notes are distilled in [docs/RESEARCH.md](docs/RESEARCH.md).

The full original evaluation is kept in internal research notes. This module does not depend on them, and [docs/RESEARCH.md](docs/RESEARCH.md) is self-contained.

## Roadmap

Planned (not yet built):

- **Push notifications** — Web Push on job completion (e.g. via `minishlink/web-push`).
- **`MultiStepJob` trait** — sequential/parallel/dynamic multi-step processing with a `StepResult` flow-control value object (`next()`, `repeat()`, `goto()`, `spawn()`, `complete()`).
- **`JobDispatchAction` trait** — CMS action buttons that dispatch jobs with one click and switch into progress-polling mode.

## License

MIT
