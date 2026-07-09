# Silverstripe QueuedJobs Enhancements

Progress tracking, immediate execution, self-declaring default jobs, and consolidation utilities for [symbiote/silverstripe-queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs).

`symbiote/silverstripe-queuedjobs` is solid for background processing, but the developer and end-user experience around it has some rough edges: a queued job gives no live feedback (the browser only learns it finished on the next page reload), jobs wait for the next cron tick before they start, auto-recreating "default" jobs need a verbose block of YAML per job, and there is no first-class way to queue a one-off method call or closure. This module adds those pieces as small, independent components that sit **alongside** the core module — it never replaces or subclasses core QueuedJobs classes.

## Requirements

- PHP `^8.1`
- `silverstripe/framework` `^5`
- `symbiote/silverstripe-queuedjobs` `^5`
- For `ClosureJob` only: a closure-serialization library must be present —
  `silverstripe/closure` on SS5. It is normally already in the dependency tree
  (pulled in transitively by `silverstripe/doorman`), so no extra `require` is
  usually needed. See [ClosureJob](#5-closurejob) for the SS6 import note.

> **SS6 is not supported yet.** Earlier releases advertised `^6`, but the module cannot
> boot on SS6: `SilverStripe\Dev\BuildTask` there extends `PolyCommand` with an
> incompatible signature (`execute(InputInterface, PolyOutput): int`, plus a `static`
> `$description`), so `CheckDefaultJobsTask` and `CleanupBrokenJobsTask` fatal on class
> load. `cli-script.php` (used by `ImmediateJobRunner`) was also removed in SS6, and
> `ClosureJob` needs `laravel/serializable-closure` instead of the abandoned Opis library.
> The constraint is narrowed to `^5` until proper SS6 support is built.

These are declared as `suggest` (not hard `require`) in `composer.json` because they only matter if you use `ClosureJob`.

## Installation

```bash
composer require restruct/silverstripe-queuedjobs-enhancements
```

Then flush the manifest — visit any URL with `?flush=1`, or:

```bash
php vendor/silverstripe/framework/cli-script.php dev/build flush=1
```

The module registers:

- A route `api/job-progress//$ID` → `JobProgressController` (via `_config/routes.yml`).
- An extension on `QueuedJobsAdmin` that ensures self-declaring default jobs exist on admin visits (via `_config/config.yml`).
- Exposes `client/` (for `client/js/job-progress.js`) via `extra.expose` in `composer.json`.

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

### 2. Immediate Job Execution

Skip the cron wait — queue a job and trigger it to run immediately in a background CLI process.

```php
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;

$descriptorId = ImmediateJobRunner::queueAndRun($job);
// optional second arg: $startAfter (Rfc2822 datetime string)
```

**How it works** — the job is *always* queued first (a safety net: if the spawn fails, cron still picks it up), then execution is triggered according to this detection order:

| Priority | Condition | Behaviour |
|----------|-----------|-----------|
| 1 | `use_immediate_queue: true` | Queue as `QueuedJob::IMMEDIATE` (for setups where `inotifywait` monitors that queue) |
| 2 | `exec()` available and not running under CLI | Spawn a detached background PHP process for this specific job |
| 3 | Fallback | Job stays queued; the normal cron runner handles it (typically within 60 s) |

The background process is spawned as `nohup <PHP_BINARY> vendor/silverstripe/framework/cli-script.php dev/tasks/ProcessJobQueueTask job=queuedjob-<ID> > /dev/null 2>&1 &`. It uses `cli-script.php` directly (more reliable than `sake`) and `PHP_BINARY` (the exact binary running the current request). Spawn failures are logged as warnings and never break the request — the job remains safely queued. No `fastcgi_finish_request()` tricks and no core class replacement.

> **The `queuedjob-` prefix is mandatory.** `ProcessJobQueueTask` only enters single-job mode when the `job` parameter contains a dash. A bare `job=42` silently falls through to draining the entire *Queued* queue.

Because the spawn is a separate CLI process, it does **not** occupy a PHP-FPM worker (that is the failure mode of `fastcgi_finish_request()`, which this module deliberately avoids). The real cost is that each dispatch bootstraps Silverstripe in a new process, and nothing caps how many run concurrently.

**Configuration:**

```yaml
# Set true only if inotifywait (or similar) monitors the IMMEDIATE queue.
Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner:
  use_immediate_queue: false
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

### 3. Self-Declaring Default Jobs

Replace the verbose `defaultJobs` YAML block with a single declaration on the job class itself.

**Before** (YAML `defaultJobs` — many lines per job):

```yaml
SilverStripe\Core\Injector\Injector:
  Symbiote\QueuedJobs\Services\QueuedJobService:
    properties:
      defaultJobs:
        DataSyncJob:
          type: 'App\Jobs\DataSyncJob'
          filter:
            JobTitle: 'App\Jobs\DataSyncJob'
          recreate: 1
          startDateFormat: 'Y-m-d H:i:s'
          startTimeString: '+5 minutes'
          email: ''
          construct: {}
```

**After** (on the job class — 2 lines, plus optional overrides):

```php
class DataSyncJob extends AbstractQueuedJob implements QueuedJob
{
    private static bool $is_default_job = true;
    private static string $default_schedule = '+5 minutes';

    // Optional:
    private static int $default_job_type = QueuedJob::QUEUED; // omit → queueJob() default
    private static array $default_construct = [];             // constructor args
}
```

`DefaultJobManager` discovers every concrete `AbstractQueuedJob` subclass with `$is_default_job = true` (via `ClassInfo::subclassesFor()`, skipping abstract classes), and ensures each has an *active* instance in the queue. "Active" means a descriptor in a non-terminal status (`New`, `Initialising`, `Running`, `Waiting`, or `Paused`); if none exists it creates one, scheduled per `$default_schedule` (default `+5 minutes`). Creation failures are caught and logged, not thrown.

This works **independently** of the core `defaultJobs` mechanism — the two coexist. `DefaultJobManager` creates jobs directly; it does not modify `defaultJobs` config.

**Triggering:**

- **Admin visits:** `DefaultJobServiceExtension` (applied to `QueuedJobsAdmin`) calls `DefaultJobManager::ensureDefaultJobs()` on `onBeforeInit()`, i.e. whenever someone opens the QueuedJobs admin.
- **Cron:** run the `check-default-jobs` task on a schedule.
- **Manual:** `sake dev/tasks/check-default-jobs`
- **Dry run (report only, creates nothing):** `sake dev/tasks/check-default-jobs dry-run=1`

**Cron setup (alongside the existing job runner):**

```bash
# Existing: process the job queue every minute
* * * * * php /path/to/vendor/silverstripe/framework/cli-script.php dev/tasks/ProcessJobQueueTask queue=queued

# New: ensure self-declaring default jobs exist
*/5 * * * * php /path/to/vendor/silverstripe/framework/cli-script.php dev/tasks/check-default-jobs
```

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

**Options (4th argument):**

| Option | Default | Description |
|--------|---------|-------------|
| `description` | `'[ no description ]'` | Title shown in the QueuedJobs admin |
| `jobType` | `QueuedJob::QUEUED` | Queue type |
| `totalSteps` | `1` | Total steps (for multi-step processing) |
| `ignoreIdentical` | `false` | When `true`, appends a random signature so identical jobs can coexist in the queue |

### 5. ClosureJob

Queue a closure for background execution — no dedicated job class needed.

```php
use Restruct\QueuedJobsEnhancements\Jobs\ClosureJob;

// Simple dispatch
ClosureJob::dispatch(
    function () use ($itemId) {
        $item = MyModel::get()->byID($itemId);
        $item->doExpensiveWork();
    },
    description: 'Process item #' . $itemId
);

// Immediate execution (CLI spawn)
ClosureJob::dispatchImmediate(
    fn() => MyService::singleton()->rebuildCache(),
    'Rebuild cache'
);

// Scheduled (strtotime-compatible)
ClosureJob::dispatch(
    fn() => Email::create()->setTo('admin@example.com')->send(),
    description: 'Send daily report',
    when: '+1 day'
);
```

If the closure returns a value, it is added as a job message (strings verbatim, otherwise JSON-encoded).

**Capture IDs, not objects.** Closures are serialized at dispatch time. Capture scalar values (IDs, strings) in `use()` and re-fetch `DataObject`s inside the closure body — `DataObject`s are not safely serializable and would be stale by the time the job runs.

**How serialization works.** The closure is wrapped in `SerializableClosure` and `serialize()`'d to a plain string *before* it enters the job data. When QueuedJobs later serializes the saved job data, it only ever sees a string — no closure in the serialization path.

**SS5 vs SS6.** The current source imports `Opis\Closure\SerializableClosure` (provided by `silverstripe/closure` on SS5). For SS6, swap the import to `Laravel\SerializableClosure\SerializableClosure` (from `laravel/serializable-closure`) — the API is identical. See [RESEARCH.md](docs/RESEARCH.md) for the background on this migration.

### 6. CleanupBrokenJobsTask

Delete every queued job whose status is `Broken`:

```bash
sake dev/tasks/cleanup-broken-jobs
```

Only `Broken` jobs are removed; all other statuses are left untouched.

### 7. CheckDefaultJobsTask

The trigger/diagnostic task for [Self-Declaring Default Jobs](#3-self-declaring-default-jobs). Without arguments it ensures missing default jobs are created and prints a per-job report (`OK (active)` / `CREATED` / `ERROR`). With `dry-run=1` it only reports the discovered jobs and their queue status without creating anything.

```bash
sake dev/tasks/check-default-jobs           # ensure + report
sake dev/tasks/check-default-jobs dry-run=1 # report only
```

### 8. `client/js/job-progress.js`

The vanilla-JS polling component behind [Job Progress Tracking](#1-job-progress-tracking) — no framework dependency, works in both the CMS admin and frontend templates. Exposed via `extra.expose`, so once the module is installed and `?flush`ed the file is available under the exposed `_resources/` path for that vendor module. Include it on any page that renders `[data-job-id]` markup, or use `window.JobProgress` directly.

## Dev Tasks

| Task | Segment | Description |
|------|---------|-------------|
| Check Default Jobs | `check-default-jobs` | Ensure self-declaring default jobs are queued (`dry-run=1` to report only) |
| Cleanup Broken Jobs | `cleanup-broken-jobs` | Delete all broken jobs |

## Module Structure

```
src/
├── Controllers/
│   └── JobProgressController.php       # REST API for job progress
├── Extensions/
│   └── DefaultJobServiceExtension.php  # Ensures default jobs on QueuedJobsAdmin visit
├── Jobs/
│   ├── ClosureJob.php                  # Queue closures
│   └── ScheduledMethodCall.php         # Queue method calls
├── Services/
│   ├── DefaultJobManager.php           # Self-declaring job discovery + creation
│   └── ImmediateJobRunner.php          # CLI spawn for immediate execution
└── Tasks/
    ├── CheckDefaultJobsTask.php        # Ensure default jobs are queued
    └── CleanupBrokenJobsTask.php       # Delete broken jobs

client/js/
└── job-progress.js                     # Vanilla JS polling component
```

## Testing

```bash
vendor/bin/phpunit vendor/restruct/silverstripe-queuedjobs-enhancements/tests/
# expected: OK (32 tests, 75 assertions)
```

The suite covers the controller (auth/permission/response shape), `ScheduledMethodCall`, `ClosureJob` (including serialize→unserialize→process round-trip), `DefaultJobManager`, and `CleanupBrokenJobsTask`.

> **Note for contributors:** `AbstractQueuedJob` does **not** use the `Injectable` trait, so job subclasses cannot be created with `::create()`. Construct them with `new ClassName(...)` or `Injector::inst()->create()` — the tests follow this convention deliberately.

> **Stale manifest tip:** if tests fail with `getItemPath returned null … Try adding flush=1`, the class manifest cache is stale. Flush it (`?flush=1`, or clear the `silverstripe-cache-*` dir) and re-run.

## Design Principles

1. **Never replace or subclass core QueuedJobs classes.** No custom `QueuedJobService`, no Injector overrides. Extensions where hooks exist, complementary services alongside.
2. **Play well with existing infrastructure.** If `inotifywait` is set up, use the IMMEDIATE queue. If `defaultJobs` YAML exists, it keeps working. Respect the existing job runner.
3. **CLI spawn over FPM tricks.** Background processes via `exec()` are more robust and portable than `fastcgi_finish_request()`.
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
