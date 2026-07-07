<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\Workspace;

/**
 * Anti-spam / anti-loop guard for the routing engine's auto_reply paths
 * (both routing-rule auto_reply actions and outside-business-hours
 * template replies).
 *
 * Two protections:
 *
 *   1. PER-CONTACT COOLDOWN — after we auto-reply on a conversation we
 *      refuse to auto-reply again to that conversation until the
 *      cooldown elapses. Stored on `conversation.routing_meta.last_auto_reply_at`
 *      so it survives across requests without a new column.
 *
 *   2. FLOOD GUARD — if a sender sends more than N inbound messages
 *      inside a short window we flip `conversations.is_spam = true`
 *      and skip every auto-reply path for that conversation
 *      permanently. Mirrors the existing manual `mark_spam` flow.
 *
 * Limits read from the workspace's `business_hours` JSON so they're
 * configurable per-tenant without code changes; sensible defaults are
 * baked in so the guard still works for workspaces that never opened
 * the business-hours modal.
 */
class AutoReplyGuard
{
    public const DEFAULT_COOLDOWN_MINUTES   = 720;   // 12 hours
    public const DEFAULT_FLOOD_THRESHOLD    = 20;    // messages — must match UI validation min
    public const DEFAULT_FLOOD_WINDOW_SECS  = 60;    // per minute

    /** Per-workspace_id config cache so a single canAutoReply() call doesn't hit the DB 3+ times. */
    private array $cfgCache = [];

    /**
     * Returns true when ANY auto-reply path is currently safe to fire
     * on this conversation. Marks the conversation as spam if the
     * sender is currently flooding so the next inbound short-circuits
     * even before we get to the engine.
     */
    public function canAutoReply(Conversation $conv): bool
    {
        if ($conv->is_spam) return false;

        $cfg = $this->workspaceConfig($conv);

        // Flood check before cooldown — a flooding contact gets the
        // spam flag set FIRST, which then prevents every future reply
        // until an operator un-flags them manually.
        if ($this->isFlooding($conv, $cfg)) {
            $conv->forceFill([
                'is_spam'      => true,
                'inbox_status' => 'spam',
            ])->save();
            return false;
        }

        if ($this->isInCooldown($conv, $cfg)) return false;

        return true;
    }

    /**
     * Mark a successful auto-reply send so the cooldown timer starts.
     * Caller MUST invoke this BEFORE the actual outbound dispatch —
     * even if Node-side dispatch fails, we want the cooldown to honor
     * the attempt (otherwise a failing channel would loop on retry).
     */
    public function markReplied(Conversation $conv): void
    {
        $meta = is_array($conv->routing_meta) ? $conv->routing_meta : [];
        $meta['last_auto_reply_at'] = now()->toIso8601String();
        $conv->forceFill(['routing_meta' => $meta])->save();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function isInCooldown(Conversation $conv, array $cfg): bool
    {
        $last = $conv->routing_meta['last_auto_reply_at'] ?? null;
        if (!$last) return false;
        try {
            $last = \Carbon\Carbon::parse($last);
        } catch (\Throwable $e) {
            return false;
        }
        $cooldownMin = max(1, (int) ($cfg['auto_reply_cooldown_min'] ?? self::DEFAULT_COOLDOWN_MINUTES));
        return $last->gt(now()->subMinutes($cooldownMin));
    }

    private function isFlooding(Conversation $conv, array $cfg): bool
    {
        // Defensive floors match the UI validation min: cooldown ≥ 1,
        // spam_threshold ≥ 2 (otherwise 1 message would trip spam),
        // window ≥ 5s.
        $threshold = max(2, (int) ($cfg['spam_threshold_msgs']  ?? self::DEFAULT_FLOOD_THRESHOLD));
        $window    = max(5, (int) ($cfg['spam_window_seconds']  ?? self::DEFAULT_FLOOD_WINDOW_SECS));

        $count = InboxMessage::query()
            ->where('conversation_id', $conv->id)
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subSeconds($window))
            ->count();
        return $count > $threshold;
    }

    private function workspaceConfig(Conversation $conv): array
    {
        $wsId = (int) $conv->workspace_id;
        if (!array_key_exists($wsId, $this->cfgCache)) {
            $ws = Workspace::find($wsId);
            $this->cfgCache[$wsId] = is_array($ws?->business_hours) ? $ws->business_hours : [];
        }
        return $this->cfgCache[$wsId];
    }
}
