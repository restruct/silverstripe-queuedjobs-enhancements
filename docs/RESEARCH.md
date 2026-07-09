# Design Research & Rationale

This document distills the design thinking behind `restruct/silverstripe-queuedjobs-enhancements` so the module's backup is self-contained. It records **why** each delivered component exists, the alternatives that were considered and rejected, and the SS5→SS6 migration notes. For the roadmap (done vs. planned) see [../PLAN.md](../PLAN.md); for usage see [../README.md](../README.md).

## Sources

The design was driven by these local documents. **This module does not depend on any of them** — everything needed is captured in this file, `README.md` and `PLAN.md`. Verified inventory (2026-07-02):

**Primary research (Restruct SSKB)**
- `silverstripe/ideas/queuedjobs-enhancements.md` (~64 KB) — the research doc: problem statement, six proposed "pillars", prior-art survey, a Laravel-feature comparison, and full code sketches + the phased scope (Phases 1/2/5) the module was built from.
- `silverstripe/topics/queuedjobs.md` (~63 KB) — the SSKB QueuedJobs reference (job lifecycle, config, patterns; the `ss-queuedjobs` agent knowledge base). Source for the shutdown-function / IMMEDIATE-queue analysis in this doc.

**Plans**
- `PLAN.md` (this repo) — the canonical, evolved plan actually followed (adds `_config/config.yml`, the CLI-spawn `ImmediateJobRunner`).
- `~/.claude/plans/flickering-finding-sedgewick.md` — an earlier draft (used `fastcgi_finish_request()`, module at project root). Deleted from its original location, but **recovered verbatim from the session transcript** and preserved in this repo as [../PLAN-original-draft.md](../PLAN-original-draft.md) (with a provenance header). Fully superseded by `PLAN.md`.

**SSKB profiles that governed the workflow**
- `silverstripe/profiles/local-module-dev.md` — the `_dev/` path-repository workflow (the fix for the double-scan bug below).
- `silverstripe/profiles/composer.md` — path repos + version constraints (`dev-ss5-update`).
- `silverstripe/profiles/cli-commands.md` — when to flush vs. dev/build; `cli-script.php` over `sake` (cited in `ImmediateJobRunner`).

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

1. **Never replace or subclass core QueuedJobs classes.** Overriding `QueuedJobService` / `QueuedJobsAdmin` via Injector is a maintenance and compatibility hazard: upstream changes break the override silently, only one module can override a given class, and you inherit all of the parent's complexity. The Silverstripe-native answer is Extensions plus complementary services — which is what this module uses.
2. **Play well with existing infrastructure.** If `inotifywait` monitors the IMMEDIATE queue, use it. If a project already has `defaultJobs` YAML, leave it working. Respect the existing job runner rather than competing with it.
3. **CLI spawn over FPM tricks.** A detached `exec('nohup php … &')` process beats `fastcgi_finish_request()` on every axis that matters (see table below).
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

**Decision: spawn a background CLI process; never tie up an FPM worker.**

Why CLI spawn over `fastcgi_finish_request()`:

| | `fastcgi_finish_request()` | CLI spawn (`exec`) |
|-|---------------------------|-------------------|
| SAPI compatibility | FPM only | Any (web or CLI) |
| FPM worker | Stays occupied during the job | Released immediately |
| `pm.max_requests` risk | Can kill the process mid-job | None (separate process) |
| Calling-code surprises | Request marked finished early; shutdown handlers / output buffering can misbehave | Request completes normally |
| Memory/time limits | Shared with the request | Own CLI defaults |
| Isolation | Shares request state | Clean process |

The only cost is ~1–2 s of PHP bootstrap for the spawned process, negligible for background work.

A crucial finding from the research: **QueuedJobs' own `use_shutdown_function` does not help for web requests.** It is guarded by `!Director::is_cli()` in `QueuedJobService::__construct()`, so the shutdown handler only registers under CLI. Without `inotifywait` running, `QueuedJob::IMMEDIATE` jobs queued from a web request just wait for the next cron tick — identical to `QueuedJob::QUEUED`. That is exactly why this module spawns its own process instead of relying on the IMMEDIATE queue (unless `use_immediate_queue` is explicitly enabled for an inotify setup).

The job is **always queued first** as a safety net: if the spawn fails (e.g. `exec()` disabled), cron still runs it. The spawn is best-effort and logs a warning on failure rather than throwing.

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

The module must live under a directory that Silverstripe's `ModuleManifest` does **not** scan directly while it is *also* installed via the vendor symlink. Originally the module was placed at the project root (`/a-queuedjobs-enhancements/`); because that directory contained a `_config/` folder, the manifest scanned it **both** directly and again through the `vendor/` symlink, producing "two files containing the same class" errors on every `flush`. Moving it under `_dev/` (which SS does not scan) resolved this — the standard SSKB local-module workflow. If you see duplicate-class errors on flush, check for exactly this double-scan.

## Rejected / out-of-scope (recap)

- **Replacing core QueuedJobs classes** — maintenance/compat hazard; Extensions instead.
- **WebSockets / SSE for progress** — infrastructure cost and FPM-worker exhaustion; polling instead.
- **`fastcgi_finish_request()` for immediate execution** — SAPI-bound and ties up workers; CLI spawn instead.
- **`ThrottledQueuedJobService`** — redundant with the core `NotifiedBroken` flag.

## Related SSKB resources (for those with access)

- `silverstripe/topics/queuedjobs.md` — comprehensive QueuedJobs reference (see the "Immediate jobs" section for the shutdown-function analysis).
- `silverstripe/topics/health-monitoring.md` — queue health checks.
- `silverstripe/ideas/queuedjobs-enhancements.md` — the full original evaluation (source for this distillation).
