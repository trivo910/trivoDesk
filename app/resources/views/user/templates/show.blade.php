@php
    /** @var \App\Models\WaTemplate $template */
    $template = $template ?? null;
    $provider = $provider ?? null;
    $wabaSubmittable = $wabaSubmittable ?? false;

    $metaStatus = strtoupper((string) $template->meta_status);
    $localStatus = (string) $template->status;

    // Every Meta-side state we have to render. APPROVED + REJECTED are
    // terminal-ish; PENDING/IN_APPEAL/PENDING_DELETION are transient;
    // PAUSED/DISABLED/LIMIT_EXCEEDED/FLAGGED block sends. DELETED keeps
    // the row visible for audit but locks edit/send.
    $isPending = in_array($metaStatus, ['PENDING', 'IN_APPEAL', 'PENDING_DELETION'], true);
    $isApproved = $metaStatus === 'APPROVED';
    $isRejected = $metaStatus === 'REJECTED';
    $isPaused = $metaStatus === 'PAUSED' || ($template->paused_until && now()->lt($template->paused_until));
    $isDisabled = in_array($metaStatus, ['DISABLED', 'LIMIT_EXCEEDED', 'FLAGGED', 'DELETED'], true);
    $quality = strtoupper((string) ($template->quality_score ?: 'UNKNOWN'));

    $statusPillClass = match (true) {
        $isApproved => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        $isRejected => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        $isPaused => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        $isDisabled => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-300',
        $isPending => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
        default => 'bg-paper-50 text-ink-700 ring-1 ring-paper-200',
    };
    $statusLabel = match ($metaStatus) {
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'PENDING' => 'In review',
        'IN_APPEAL' => 'In appeal',
        'PENDING_DELETION' => 'Deleting',
        'DELETED' => 'Deleted',
        'DISABLED' => 'Disabled',
        'LIMIT_EXCEEDED' => 'Limit exceeded',
        'FLAGGED' => 'Flagged',
        'PAUSED' => 'Paused',
        default => $isPaused ? 'Paused' : ucfirst(strtolower($metaStatus ?: $localStatus ?: 'Draft')),
    };

    $qualityPillClass = match ($quality) {
        'GREEN' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        'YELLOW' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        'RED' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        default => 'bg-paper-50 text-ink-500 ring-1 ring-paper-200',
    };

    $rejectionFriendly = match ($template->rejection_reason_code) {
        'ABUSIVE_CONTENT' => 'Content was flagged as abusive or threatening.',
        'INVALID_FORMAT' => 'Format violates Meta\'s template rules (placeholders, line breaks, length).',
        'PROMOTIONAL' => 'Marketing language not allowed in this category.',
        'TAG_CONTENT_MISMATCH' => 'Category does not match the content (e.g. promotional copy in a UTILITY template).',
        'SCAM' => 'Meta flagged the template as a potential scam — review claims and language.',
        'NONE' => null,
        default => $template->rejection_reason ?: null,
    };

    $lintWarnings = (array) session('lint_warnings', []);
@endphp

<x-layouts.user :title="__('Template — :name', ['name' => $template->template_name])" nav-key="templates" page="user-templates-show">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-7 py-7" data-tpl-show data-tpl-id="{{ $template->id }}"
        data-tpl-meta-status="{{ $metaStatus }}"
        data-tpl-refresh-url="{{ route('user.templates.refresh', $template->id) }}">

        {{-- ============================================================ --}}
        {{-- HEADER ------------------------------------------------------ --}}
        {{-- ============================================================ --}}
        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1 text-[11px] text-ink-500">
                    <a href="{{ route('user.templates.index') }}" class="hover:text-ink-900">{{ __('Templates') }}</a>
                    <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M6 4l4 4-4 4" />
                    </svg>
                    <span class="text-ink-700">{{ $template->template_name }}</span>
                </div>
                <h1 class="text-[20px] font-semibold text-ink-900 leading-tight truncate">{{ $template->template_name }}
                </h1>
                <p class="text-[13px] text-ink-500 mt-1">
                    {{ strtoupper($template->meta_category ?: $template->category) }} ·
                    {{ $template->language ?: 'en_US' }} · {{ ucfirst($template->template_type ?: 'standard') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $statusPillClass }}"
                    data-status-pill>
                    @if ($isPending)
                        <svg viewBox="0 0 16 16" class="w-3 h-3 animate-spin" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <circle cx="8" cy="8" r="6" stroke-dasharray="20 8" />
                        </svg>
                    @elseif ($isApproved)
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3.5 8.5l3 3 6-6" />
                        </svg>
                    @elseif ($isRejected)
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4l8 8M12 4l-8 8" />
                        </svg>
                    @endif
                    <span data-status-label>{{ $statusLabel }}</span>
                </span>

                @if ($isApproved)
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $qualityPillClass }}"
                        data-quality-pill>
                        Quality · <span data-quality-label>{{ $quality }}</span>
                    </span>
                @endif

                <a href="{{ route('user.templates.edit', $template->id) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-800 {{ $isPending ? 'opacity-50 pointer-events-none' : '' }}">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                        stroke-width="1.6">
                        <path d="M11 3l2 2-7 7H4v-2z" />
                    </svg>
                    Edit
                </a>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- BANNERS ----------------------------------------------------- --}}
        {{-- ============================================================ --}}

        @if ($isPending)
            <div class="mb-5 rounded-xl border border-sky-200 bg-sky-50 p-4 flex items-start gap-3">
                <svg viewBox="0 0 24 24" class="w-5 h-5 text-sky-600 flex-shrink-0" fill="none" stroke="currentColor"
                    stroke-width="1.6">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v5l3 2" />
                </svg>
                <div class="min-w-0 flex-1">
                    <div class="text-[13px] font-semibold text-sky-900">{{ __('Submitted for Meta review') }}</div>
                    <div class="text-[12px] text-sky-800 mt-0.5">
                        {{ __('Most templates are approved within minutes — Meta allows up to 48 hours. This page refreshes automatically every 30 seconds while the status is pending.') }}
                    </div>
                    @if ($template->submitted_at)
                        <div class="text-[11px] text-sky-700 mt-1">
                            Submitted {{ $template->submitted_at->diffForHumans() }}
                            @if ($template->last_synced_at)
                                · last checked {{ $template->last_synced_at->diffForHumans() }}
                            @endif
                        </div>
                    @endif
                </div>
                <button type="button" data-refresh-now
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium border border-sky-300 bg-paper-0 hover:bg-sky-100 text-sky-800">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                        stroke-width="1.6">
                        <path d="M3 8a5 5 0 019-3M13 8a5 5 0 01-9 3M12 4v3h-3M4 12V9h3" />
                    </svg>
                    Refresh now
                </button>
            </div>
        @endif

        @if ($isRejected)
            <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-4">
                <div class="flex items-start gap-3">
                    <svg viewBox="0 0 24 24" class="w-5 h-5 text-rose-600 flex-shrink-0" fill="none"
                        stroke="currentColor" stroke-width="1.6">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M9 9l6 6M15 9l-6 6" />
                    </svg>
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-semibold text-rose-900">{{ __('Rejected by Meta') }}</div>
                        @if ($rejectionFriendly)
                            <div class="text-[12px] text-rose-800 mt-0.5">{{ $rejectionFriendly }}</div>
                        @endif
                        @if ($template->rejection_reason_code)
                            <div class="text-[11px] font-mono text-rose-700 mt-1">Code:
                                {{ $template->rejection_reason_code }}</div>
                        @endif
                    </div>
                    <a href="{{ route('user.templates.edit', $template->id) }}"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium bg-rose-600 text-paper-0 hover:bg-rose-700">
                        Edit & resubmit
                    </a>
                </div>
            </div>
        @endif

        @if ($isPaused)
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
                <svg viewBox="0 0 24 24" class="w-5 h-5 text-amber-600 flex-shrink-0" fill="none"
                    stroke="currentColor" stroke-width="1.6">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M10 8v8M14 8v8" />
                </svg>
                <div class="min-w-0 flex-1">
                    <div class="text-[13px] font-semibold text-amber-900">{{ __('Paused by Meta') }}</div>
                    <div class="text-[12px] text-amber-800 mt-0.5">
                        Repeated negative feedback (blocks/spam reports) paused this template. Quality score must
                        recover before it can be sent again.
                        @if ($template->paused_until)
                            Auto-unpause after {{ $template->paused_until->format('M j, H:i') }}.
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @foreach ($lintWarnings as $warning)
            <div
                class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 flex items-start gap-2.5 text-[12px] text-amber-900">
                <svg viewBox="0 0 16 16" class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none"
                    stroke="currentColor" stroke-width="1.6">
                    <path d="M8 2L1 14h14z" />
                    <path d="M8 6v4M8 12v.5" />
                </svg>
                <span>{{ $warning }}</span>
            </div>
        @endforeach

        {{-- ============================================================ --}}
        {{-- TWO-COLUMN BODY -------------------------------------------- --}}
        {{-- ============================================================ --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5">

            {{-- ===== LEFT — Preview + components ===== --}}
            <div class="space-y-5">

                {{-- Preview card --}}
                <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <h2 class="text-[13px] font-semibold text-ink-900">{{ __('Preview') }}</h2>
                        <span class="text-[11px] text-ink-500">{{ __('How it appears on WhatsApp') }}</span>
                    </div>
                    <div class="p-5 bg-[#ECE5DD]">
                        <div class="max-w-sm mx-auto bg-paper-0 rounded-lg shadow-card overflow-hidden">
                            @if ($template->attachment_type && $template->attachment_file)
                                @if ($template->attachment_type === 'image')
                                    <img src="{{ media_url($template->attachment_file) }}"
                                        class="w-full max-h-48 object-cover" alt="{{ __('header media') }}">
                                @elseif ($template->attachment_type === 'video')
                                    <div
                                        class="aspect-video bg-zinc-900 flex items-center justify-center text-paper-0 text-[12px]">
                                        {{ __('video header') }}</div>
                                @else
                                    <div
                                        class="px-4 py-3 bg-paper-50 text-[12px] text-ink-700 border-b border-paper-200">
                                        {{ basename($template->attachment_file) }}</div>
                                @endif
                            @endif
                            @if ($template->header && (!$template->attachment_type || $template->attachment_type === 'none'))
                                <div class="px-4 pt-3 text-[13px] font-semibold text-ink-900">{{ $template->header }}
                                </div>
                            @endif
                            <div class="px-4 py-3 text-[13px] text-ink-800 whitespace-pre-line">
                                {{ $template->template_body }}</div>
                            @if ($template->footer)
                                <div class="px-4 pb-3 text-[11px] text-ink-500">{{ $template->footer }}</div>
                            @endif
                            @if (is_array($template->buttons) && count($template->buttons))
                                <div class="border-t border-paper-200">
                                    @foreach ($template->buttons as $btn)
                                        <div
                                            class="px-4 py-2.5 text-[13px] text-center text-sky-600 font-medium border-b last:border-b-0 border-paper-100">
                                            {{ $btn['text'] ?? '' }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($template->template_type === 'carousel' && is_array($template->carousel_data) && count($template->carousel_data))
                            <div class="mt-3 flex gap-3 overflow-x-auto pb-2 -mx-2 px-2">
                                @foreach ($template->carousel_data as $card)
                                    <div
                                        class="min-w-[200px] max-w-[200px] bg-paper-0 rounded-lg shadow-card overflow-hidden flex-shrink-0">
                                        @if (!empty($card['image']))
                                            <img src="{{ media_url($card['image']) }}"
                                                class="w-full h-28 object-cover" alt="">
                                        @endif
                                        <div class="px-3 py-2 text-[12px] text-ink-800">{{ $card['body'] ?? '' }}
                                        </div>
                                        @if (!empty($card['buttons']))
                                            <div class="border-t border-paper-200">
                                                @foreach ($card['buttons'] as $b)
                                                    <div
                                                        class="px-3 py-2 text-[12px] text-center text-sky-600 border-b last:border-b-0 border-paper-100">
                                                        {{ $b['text'] ?? '' }}</div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Components breakdown --}}
                <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 shadow-card">
                    <div class="px-5 py-3 border-b border-paper-200">
                        <h2 class="text-[13px] font-semibold text-ink-900">{{ __('Components') }}</h2>
                    </div>
                    <dl class="divide-y divide-paper-100 text-[12px]">
                        @if ($template->header)
                            <div class="px-5 py-3 grid grid-cols-[100px_1fr] gap-3">
                                <dt class="text-ink-500 font-medium">{{ __('Header') }}</dt>
                                <dd class="text-ink-800">{{ $template->header }}</dd>
                            </div>
                        @endif
                        <div class="px-5 py-3 grid grid-cols-[100px_1fr] gap-3">
                            <dt class="text-ink-500 font-medium">{{ __('Body') }}</dt>
                            <dd class="text-ink-800 whitespace-pre-line">{{ $template->template_body }}</dd>
                        </div>
                        @if ($template->footer)
                            <div class="px-5 py-3 grid grid-cols-[100px_1fr] gap-3">
                                <dt class="text-ink-500 font-medium">{{ __('Footer') }}</dt>
                                <dd class="text-ink-800">{{ $template->footer }}</dd>
                            </div>
                        @endif
                        @if (is_array($template->buttons) && count($template->buttons))
                            <div class="px-5 py-3 grid grid-cols-[100px_1fr] gap-3">
                                <dt class="text-ink-500 font-medium">{{ __('Buttons') }}</dt>
                                <dd class="text-ink-800 space-y-1">
                                    @foreach ($template->buttons as $btn)
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="text-[10px] font-mono uppercase tracking-wider px-1.5 py-0.5 rounded bg-paper-100 text-ink-600">{{ $btn['type'] ?? 'quick_reply' }}</span>
                                            <span>{{ $btn['text'] ?? '' }}</span>
                                            @if (!empty($btn['value']))
                                                <span class="text-ink-500 text-[11px]">→ {{ $btn['value'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- ===== RIGHT — Meta sync sidebar ===== --}}
            <aside class="space-y-3">

                <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 shadow-card">
                    <div class="px-4 py-3 border-b border-paper-200">
                        <h3 class="text-[12px] font-semibold text-ink-900 uppercase tracking-wide">
                            {{ __('Meta sync') }}</h3>
                    </div>
                    <dl class="divide-y divide-paper-100 text-[12px]">
                        <div class="px-4 py-2.5 flex items-center justify-between">
                            <dt class="text-ink-500">{{ __('Status') }}</dt>
                            <dd class="text-ink-900 font-medium" data-meta-status>{{ $metaStatus ?: '—' }}</dd>
                        </div>
                        <div class="px-4 py-2.5 flex items-center justify-between">
                            <dt class="text-ink-500">{{ __('Quality') }}</dt>
                            <dd class="text-ink-900" data-quality-text>{{ $quality }}</dd>
                        </div>
                        <div class="px-4 py-2.5 flex items-center justify-between">
                            <dt class="text-ink-500">{{ __('Meta category') }}</dt>
                            <dd class="text-ink-900">{{ strtoupper($template->meta_category ?: '—') }}</dd>
                        </div>
                        <div class="px-4 py-2.5 flex items-center justify-between gap-2">
                            <dt class="text-ink-500 shrink-0">{{ __('Template ID') }}</dt>
                            <dd class="text-ink-900 font-mono text-[11px] min-w-0 truncate text-right">{{ $template->meta_template_id ?: '—' }}
                            </dd>
                        </div>
                        @if ($template->submitted_at)
                            <div class="px-4 py-2.5 flex items-center justify-between">
                                <dt class="text-ink-500">{{ __('Submitted') }}</dt>
                                <dd class="text-ink-900">{{ $template->submitted_at->format('M j, H:i') }}</dd>
                            </div>
                        @endif
                        <div class="px-4 py-2.5 flex items-center justify-between">
                            <dt class="text-ink-500">{{ __('Last synced') }}</dt>
                            <dd class="text-ink-900" data-last-synced>
                                {{ optional($template->last_synced_at)->diffForHumans() ?: '—' }}</dd>
                        </div>
                    </dl>
                </div>

                @if ($provider)
                    <div class="hairline border border-paper-200 rounded-2xl bg-paper-0 shadow-card">
                        <div class="px-4 py-3 border-b border-paper-200">
                            <h3 class="text-[12px] font-semibold text-ink-900 uppercase tracking-wide">
                                {{ __('WABA account') }}</h3>
                        </div>
                        <div class="px-4 py-3 space-y-1.5 text-[12px]">
                            <div class="text-ink-900 font-medium">
                                {{ $provider->meta_json['verified_name'] ?? ($provider->display_label ?? 'Connected WABA') }}
                            </div>
                            <div class="text-ink-500">
                                {{ $provider->phone_number ?? ($provider->meta_json['display_phone_number'] ?? '') }}
                            </div>
                        </div>
                    </div>
                @elseif ($wabaSubmittable)
                    {{-- Submittable but no provider — shouldn't happen; safety net. --}}
                @else
                    <div
                        class="hairline border border-amber-200 bg-amber-50 rounded-2xl px-4 py-3 text-[12px] text-amber-900">
                        No WABA account connected. <button type="button" data-connect-device
                            class="font-semibold underline cursor-pointer">{{ __('Connect one') }}</button> to submit templates to Meta.
                    </div>
                @endif

                @if ($isApproved)
                    <a href="{{ route('user.broadcasts.create') ?? '#' }}"
                        class="block text-center px-4 py-2.5 rounded-xl bg-emerald-600 text-paper-0 text-[13px] font-semibold hover:bg-emerald-700">
                        {{ __('Use in a broadcast') }}
                    </a>
                @endif
            </aside>
        </div>
    </div>

</x-layouts.user>
