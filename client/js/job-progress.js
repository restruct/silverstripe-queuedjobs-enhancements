/**
 * JobProgress — Vanilla JS polling component for QueuedJob progress tracking.
 *
 * Usage (programmatic):
 *   const tracker = new JobProgress(descriptorId, {
 *     interval: 2000,
 *     onProgress: (data) => console.log(data.percent + '%'),
 *     onComplete: (data) => alert('Done: ' + data.status),
 *     onError: (err) => console.error(err),
 *     element: document.querySelector('.my-progress'),
 *   });
 *   tracker.start();
 *   // tracker.stop(); // to cancel polling
 *
 * Usage (declarative — auto-discovered):
 *   <div class="job-progress" data-job-id="123">
 *     <div class="progress"><div class="progress-bar" role="progressbar"></div></div>
 *     <span class="job-progress__label">Starting...</span>
 *   </div>
 *
 * The auto-discovery runs on DOMContentLoaded and also observes new elements
 * added to the DOM (for AJAX-loaded content in the CMS).
 */
class JobProgress {
    constructor(descriptorId, options = {}) {
        this.id = descriptorId;
        this.interval = options.interval || 2000;
        this.onProgress = options.onProgress || null;
        this.onComplete = options.onComplete || null;
        this.onError = options.onError || null;
        this.element = options.element || null;
        this._timer = null;
        this._stopped = false;
    }

    start() {
        this._stopped = false;
        this._poll();
        return this;
    }

    stop() {
        this._stopped = true;
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
    }

    async _poll() {
        if (this._stopped) return;

        try {
            const response = await fetch(`/api/job-progress/${this.id}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            const data = await response.json();

            // Fire progress callback
            if (this.onProgress) {
                this.onProgress(data);
            }

            // Update DOM element if provided
            if (this.element) {
                this._updateElement(data);
            }

            if (data.complete) {
                // Job reached terminal status
                if (this.onComplete) {
                    this.onComplete(data);
                }
                // Don't poll anymore
                return;
            }

            // Schedule next poll
            this._timer = setTimeout(() => this._poll(), this.interval);
        } catch (err) {
            if (this.onError) {
                this.onError(err);
            }
            // On error, retry with backoff (double interval, max 30s)
            const backoff = Math.min(this.interval * 2, 30000);
            this._timer = setTimeout(() => this._poll(), backoff);
        }
    }

    /**
     * Update a progress bar element with current job data.
     * Expects Bootstrap-compatible markup:
     *   .progress > .progress-bar  (width set to percent)
     *   .job-progress__label       (text set to message or percent)
     */
    _updateElement(data) {
        const bar = this.element.querySelector('.progress-bar');
        if (bar) {
            bar.style.width = `${data.percent}%`;
            bar.setAttribute('aria-valuenow', data.percent);
        }

        const label = this.element.querySelector('.job-progress__label');
        if (label) {
            label.textContent = data.message || `${data.percent}%`;
        }

        // Add status class for styling
        this.element.dataset.jobStatus = data.status;

        // Add completion class
        if (data.complete) {
            this.element.classList.add('job-progress--complete');
            this.element.classList.toggle('job-progress--success', data.status === 'Complete');
            this.element.classList.toggle('job-progress--broken', data.status === 'Broken');
            this.element.classList.toggle('job-progress--cancelled', data.status === 'Cancelled');
        }
    }
}

/**
 * Auto-discover [data-job-id] elements and start polling.
 * Only targets elements that aren't already being tracked.
 */
function initJobProgressElements(root = document) {
    const elements = root.querySelectorAll('[data-job-id]:not([data-job-tracking])');
    elements.forEach((el) => {
        const jobId = parseInt(el.dataset.jobId, 10);
        if (!jobId) return;

        // Mark as tracking to prevent double-init
        el.dataset.jobTracking = 'true';

        const tracker = new JobProgress(jobId, {
            element: el,
            interval: parseInt(el.dataset.jobInterval, 10) || 2000,
        });
        tracker.start();
    });
}

// Auto-init on DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initJobProgressElements());
} else {
    initJobProgressElements();
}

// Observe DOM for dynamically added elements (CMS AJAX content)
const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                // Check the added node itself and its children
                if (node.matches?.('[data-job-id]:not([data-job-tracking])')) {
                    initJobProgressElements(node.parentElement);
                } else if (node.querySelector?.('[data-job-id]:not([data-job-tracking])')) {
                    initJobProgressElements(node);
                }
            }
        }
    }
});
observer.observe(document.body || document.documentElement, {
    childList: true,
    subtree: true,
});

// Export for programmatic use
if (typeof window !== 'undefined') {
    window.JobProgress = JobProgress;
    window.initJobProgressElements = initJobProgressElements;
}
