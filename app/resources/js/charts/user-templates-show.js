/*
 * /templates/{id} — Meta status auto-sync.
 *
 * Polls POST /templates/{id}/refresh every 30 s WHILE the template
 * is PENDING AND the page is visible. Stops on any terminal status
 * (APPROVED / REJECTED / PAUSED / DISABLED) and reloads to show the
 * updated banner + actions.
 *
 * The "Refresh now" button manually triggers the same endpoint
 * (server-side cache lock = 15 s, so a hammered button is a no-op
 * but still updates the "last synced" hint).
 *
 * Cadence rationale: 30 s matches Meta's typical 1–5 min auto-
 * approval window — user sees the green pill flip ~live without us
 * hammering Graph. The Page Visibility API stops the timer on
 * backgrounded tabs so we don't burn the WABA's GET quota when
 * nobody is watching.
 */

const POLL_MS = 30_000;
let pollTimer = null;
let inflight  = false;

function getCsrf() {
    return document.querySelector('meta[name=csrf-token]')?.content || '';
}

function root() {
    return document.querySelector('[data-tpl-show]');
}

function isTerminal(status) {
    return ['APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'LIMIT_EXCEEDED', 'FLAGGED', 'DELETED'].includes(status);
}

async function refresh() {
    const el = root();
    if (!el || inflight) return;

    const url = el.dataset.tplRefreshUrl;
    if (!url) return;

    inflight = true;
    try {
        const resp = await fetch(url, {
            method:  'POST',
            headers: {
                'X-CSRF-TOKEN':     getCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
        });
        if (!resp.ok) return;
        const j = await resp.json();
        if (!j.ok) return;

        applyStatus(j);

        if (isTerminal(j.meta_status)) {
            stopPolling();
            // Full reload to surface the banner/CTA changes (approved
            // shows "Use in broadcast", rejected shows the reason
            // panel — both live in PHP-rendered blocks).
            window.location.reload();
        }
    } catch (e) {
        // Network blip — keep polling; nothing to do here.
    } finally {
        inflight = false;
    }
}

function applyStatus(payload) {
    const el = root();
    if (!el) return;

    if (payload.meta_status) {
        el.dataset.tplMetaStatus = payload.meta_status;
        document.querySelectorAll('[data-meta-status]').forEach(n => n.textContent = payload.meta_status);
    }
    if (payload.quality_score) {
        document.querySelectorAll('[data-quality-text]').forEach(n => n.textContent = payload.quality_score);
        document.querySelectorAll('[data-quality-label]').forEach(n => n.textContent = payload.quality_score);
    }
    if (payload.last_synced) {
        const human = humanizeTime(payload.last_synced);
        document.querySelectorAll('[data-last-synced]').forEach(n => n.textContent = human);
    }
}

function humanizeTime(iso) {
    try {
        const t = new Date(iso);
        const s = Math.max(0, Math.floor((Date.now() - t.getTime()) / 1000));
        if (s < 5)  return 'just now';
        if (s < 60) return `${s}s ago`;
        const m = Math.floor(s / 60);
        if (m < 60) return `${m}m ago`;
        return t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (_e) {
        return iso;
    }
}

function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(refresh, POLL_MS);
}

function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
}

function onVisibilityChange() {
    const el = root();
    if (!el) return;
    if (document.visibilityState === 'visible' && el.dataset.tplMetaStatus === 'PENDING') {
        startPolling();
        // Immediate fetch on tab-foreground so the user doesn't wait 30s
        // after coming back to the tab.
        refresh();
    } else {
        stopPolling();
    }
}

function wireRefreshButton() {
    document.querySelectorAll('[data-refresh-now]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            refresh();
        });
    });
}

export default function init() {
    const el = root();
    if (!el) return;

    wireRefreshButton();

    // Only start the timer if we're in a PENDING state — there's
    // nothing to poll for if Meta already settled.
    if (el.dataset.tplMetaStatus === 'PENDING' && document.visibilityState === 'visible') {
        startPolling();
    }

    document.addEventListener('visibilitychange', onVisibilityChange);
}
