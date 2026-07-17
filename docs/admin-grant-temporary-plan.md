# Granting a workspace temporary plan access (no payment)

Removes the "Trial account" tag from a workspace and gives it full paid-plan
access for a limited, admin-chosen number of days — without the customer
paying. Used for manual onboarding (pilots, sales demos, comped extensions).

## Why this exists

The trial tag is driven by `Workspace::onTrial()`, which is only true while
a workspace is on a free plan with a trial window still running. There was
previously no way to grant a workspace paid-tier access for a *limited*
time without a real purchase — an admin could switch a workspace's plan via
the edit form, but that grant never expired. This flow adds that missing
piece by reusing the exact same fields a real purchase sets
(`plan`, `trial_ends_at`, `plan_ends_at`), so every part of the app that
checks plan/trial status treats a grant identically to a real purchase.

## Step-by-step: granting access

1. Go to **Admin → Workspaces** and open the workspace's detail page
   (`/admin/workspaces/{id}`).
2. Scroll to the **"Grant temporary access"** card (below the volume chart /
   plan usage section).
3. Pick a **Plan** from the dropdown — any real plan in the catalog
   (Starter, Growth, Pro, Enterprise, etc.). Picking a paid plan is what
   removes the trial tag.
4. Enter **Days** — how long the grant should last (defaults to 14). This is
   a free-form number, independent of the plan's normal billing cycle —
   it's an onboarding window, not a subscription term.
5. Click **Grant access**.
6. The page reloads with a confirmation ("Granted access until …") and the
   "Current window" label on the card updates to show the new expiry date.

That's it — the trial badge disappears for that workspace's users on their
next page load, and every feature/limit gate now uses the granted plan.

## Step-by-step: what happens when the granted window ends

1. `plan_ends_at` passes.
2. `Workspace::planExpired()` becomes `true`, so `Workspace::package()`
   automatically falls back to the free package — the workspace's feature
   limits (message caps, device caps, etc.) drop to free-tier levels
   immediately, on the next read. No cron job is involved; this is
   recomputed live on every request.
3. The `EnsureTrialActive` middleware (runs globally on every web request)
   now sees `planIsActive() === false` and hard-blocks the workspace:
   the user is redirected to `/account/plans` (or gets a `402 Payment
   Required` JSON response for AJAX calls) until someone picks a real plan
   or an admin grants another window.
4. Platform admins are never blocked, regardless of the workspace's state.
5. To extend or change the grant, an admin just repeats the steps above —
   each grant overwrites the previous window (it does not stack).

## What actually changes on the workspace row

| Field | Before grant | After grant |
|---|---|---|
| `plan` | e.g. `starter` (free) | the selected package's id |
| `trial_ends_at` | a date, or null | always `null` |
| `plan_ends_at` | usually null | `now() + N days` |

This is the same write `CheckoutController::finalizeOrder()` performs on a
real purchase — see `app/Http/Controllers/CheckoutController.php` (the
"PLAN purchase" branch). Reusing it means no separate code path needed to
be taught to `onTrial()`, `onFreePlan()`, `trialExpired()`, `planExpired()`,
`planIsActive()`, or `Workspace::effectiveLimit()` — they all already
understand it.

## Where the code lives

| Piece | File |
|---|---|
| Grant action (validates + writes + audits) | `app/Http/Controllers/Admin/WorkspacesController.php` → `grantPlan()` |
| Route | `routes/admin.php` → `POST admin/workspaces/{id}/grant-plan` (name: `admin.workspaces.grant-plan`) |
| Admin UI form | `resources/views/admin/workspaces/detail.blade.php` → "Grant temporary access" section |
| Hard-cutoff enforcement | `app/Http/Middleware/EnsureTrialActive.php` (checks `Workspace::planIsActive()`) |
| Trial badge | `resources/views/components/trial-bar.blade.php` (checks `Workspace::onTrial()`) |
| Expiry/limit logic | `app/Models/Workspace.php` → `onTrial()`, `trialExpired()`, `planExpired()`, `planIsActive()`, `package()`, `effectiveLimit()` |

Every grant is recorded in the audit log as `admin.workspace.plan_granted`
with the chosen plan, day count, and computed expiry — visible wherever
platform audit events are reviewed.


===============================

Files touched for the "grant temporary plan access" feature:

Modified

app/Http/Controllers/Admin/WorkspacesController.php — new grantPlan() action
app/Http/Middleware/EnsureTrialActive.php — widened gate to planIsActive()
routes/admin.php — new POST admin/workspaces/{id}/grant-plan route
resources/views/admin/workspaces/detail.blade.php — new "Grant temporary access" form
New

docs/admin-grant-temporary-plan.md — the doc from the previous step
Note: git status also shows other modified/untracked files (.gitignore, extension/content.js, node/config, resources/views/admin/users/edit.blade.php, the *_bkp.php files, public/uploads/) — those are pre-existing changes unrelated to this feature, not touched by this work.