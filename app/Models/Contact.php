<?php

namespace App\Models;

use App\Models\Concerns\LogsNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ported from D:\wadesk_2806\New folder\app\Models\Contact.php.
 * Stripped: belongsToMany(Broadcast) — broadcasts model not yet ported.
 */
class Contact extends Model
{
    use HasFactory, LogsNotifications;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'title',
        'first_name',
        'middle_name',
        'last_name',
        'name',
        'language',
        'address',
        'contact_group',
        'email',
        'country_code',
        'mobile',
        'msg',
        'subject',
        'image',
        'is_unsubscribed',
        'custom_attributes',
    ];

        protected $casts = [
        'name'              => 'encrypted',
        'mobile'            => 'encrypted',
        'email'             => 'encrypted',
        'contact_group'     => 'encrypted:array',
        'custom_attributes' => 'array',
        'is_unsubscribed'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault();
    }

    /**
     * Sales Pipeline deals attached to this contact — open ones first so the
     * contact record + the inbox panel show what's actively in play. Drives
     * the "this person already has 2 open deals" context competitors sell.
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class)
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id');
    }

    /**
     * Workspace-shared visibility — every member of the current
     * workspace sees every contact in it. Pre-migration rows fall
     * back to the original creator only.
     */
    public function scopeForCurrentWorkspace(Builder $q): Builder
    {
        $user = auth()->user();
        if (!$user) return $q->whereRaw('1=0');
        $uId  = (int) $user->id;
        $wsId = (int) ($user->current_workspace_id ?? 0);
        return $q->where(function ($qq) use ($wsId, $uId) {
            $qq->where('workspace_id', $wsId)
               ->orWhere(function ($qqq) use ($uId) {
                   $qqq->whereNull('workspace_id')->where('user_id', $uId);
               });
        });
    }

    /**
     * Resolve display initials for avatar placeholders.
     * Falls back to "?" when name is empty.
     */
    public function getInitialsAttribute(): string
    {
        $source = $this->name ?: trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        if ($source === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', trim($source));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last) ?: '?';
    }

    /**
     * Flow auto-enrollment trigger — fire FlowEnrollmentService::onGroupJoin
     * whenever a group id is NEWLY added to the contact's group list. Any
     * active+published flow with trigger_kind='group_join' and
     * trigger_value=<gid> picks up the contact.
     *
     * The `contact_group` column is an encrypted JSON array of group ids
     * (denormalized — there's no pivot table). We diff old vs new on
     * every save so every write site (controller create/update, import,
     * bulk move) goes through this single funnel.
     */
    protected static function booted(): void
    {
        static::created(function (Contact $contact) {
            // New-contact flow trigger (trigger_kind='contact_created').
            try { app(\App\Services\Flow\FlowEnrollmentService::class)->onContactCreated($contact); }
            catch (\Throwable $e) { \Log::warning('Flow onContactCreated: ' . $e->getMessage()); }

            $groups = is_array($contact->contact_group) ? $contact->contact_group : [];
            foreach ($groups as $gid) {
                $gid = (int) $gid;
                if ($gid > 0) {
                    try { app(\App\Services\Flow\FlowEnrollmentService::class)->onGroupJoin($contact, $gid); }
                    catch (\Throwable $e) { \Log::warning('Flow onGroupJoin (created): ' . $e->getMessage()); }
                }
            }
        });

        static::updated(function (Contact $contact) {
            // Opt-in (re-subscribe) flow trigger: is_unsubscribed true→false.
            // Safe no-op if the column is absent (wasChanged returns false).
            if ($contact->wasChanged('is_unsubscribed') && !$contact->is_unsubscribed && $contact->getOriginal('is_unsubscribed')) {
                try { app(\App\Services\Flow\FlowEnrollmentService::class)->onOptIn($contact); }
                catch (\Throwable $e) { \Log::warning('Flow onOptIn: ' . $e->getMessage()); }
            }

            if (!$contact->wasChanged('contact_group')) return;
            $oldRaw = $contact->getOriginal('contact_group');
            // The original value comes back as the raw (still-encrypted)
            // string when cast=encrypted:array. Decrypt manually so the
            // diff works on plain arrays.
            $old = [];
            if (is_array($oldRaw)) {
                $old = $oldRaw;
            } elseif (is_string($oldRaw) && $oldRaw !== '') {
                try {
                    $decrypted = decrypt($oldRaw);
                    $old = is_array($decrypted)
                        ? $decrypted
                        : (is_string($decrypted) ? (json_decode($decrypted, true) ?: []) : []);
                } catch (\Throwable $e) {
                    $old = [];
                }
            }
            $new = is_array($contact->contact_group) ? $contact->contact_group : [];
            $added = array_diff(array_map('strval', $new), array_map('strval', $old));
            foreach ($added as $gid) {
                $gid = (int) $gid;
                if ($gid > 0) {
                    try { app(\App\Services\Flow\FlowEnrollmentService::class)->onGroupJoin($contact, $gid); }
                    catch (\Throwable $e) { \Log::warning('Flow onGroupJoin (updated): ' . $e->getMessage()); }
                }
            }
        });
    }
}
