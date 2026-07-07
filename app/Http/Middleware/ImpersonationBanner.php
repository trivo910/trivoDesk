<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * If the authenticated user has an open impersonation session, share the
 * banner data with all views (so layouts can render the yellow stop-strip)
 * and ensure their `current_workspace_id` is pointed at the impersonation
 * target — that way every workspace-scoped query naturally returns the
 * target workspace's data without any per-controller branching.
 */
class ImpersonationBanner
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before the app is installed there is no database, so resolving the
        // user (a `select * from users` via the session guard) would throw and
        // crash the installer itself. Skip entirely until install completes.
        if (! is_file(storage_path('installed'))) return $next($request);

        $user = $request->user();
        if (!$user) return $next($request);

        $session = ImpersonationSession::active()
            ->forAdmin($user->id)
            ->latest('started_at')
            ->first();

        if (!$session) {
            view()->share('impersonation', null);
            return $next($request);
        }

        // Force the in-memory user state to point at the target workspace.
        // We DON'T persist this — the column is restored on stop. This way
        // a forgotten ended_at doesn't leak into the admin's normal sessions.
        if ($user->current_workspace_id !== (int) $session->target_workspace_id) {
            $user->setAttribute('current_workspace_id', (int) $session->target_workspace_id);
        }

        view()->share('impersonation', [
            'active'                => true,
            'admin_user_id'         => $session->admin_user_id,
            'target_workspace_id'   => $session->target_workspace_id,
            'target_workspace_name' => optional($session->targetWorkspace)->name,
            'reason'                => $session->reason,
            'started_at'            => $session->started_at,
            'session_id'            => $session->id,
        ]);

        return $next($request);
    }
}
