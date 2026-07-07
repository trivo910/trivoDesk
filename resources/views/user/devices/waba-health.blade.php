@php
    $phone   = is_array($health['phone'] ?? null) ? $health['phone'] : [];
    $wabaN   = is_array($health['waba'] ?? null) ? $health['waba'] : [];
    $token   = is_array($health['token'] ?? null) ? $health['token'] : [];
    $webhook = is_array($health['webhook'] ?? null) ? $health['webhook'] : [];
    $tpls    = is_array($health['templates'] ?? null) ? $health['templates'] : [];
    $issues  = (array) ($health['issues'] ?? []);
    $errors  = (array) ($health['errors'] ?? []);
    $ids     = (array) ($health['ids'] ?? []);
    $overall = (string) ($health['overall'] ?? 'healthy');

    $title = $phone['verified_name'] ?? ($waba->display_label ?: ($waba->phone_number ?: 'WABA number'));

    // Overall status — drives the first KPI card + the eyebrow live-dot.
    $status = match ($overall) {
        'blocked'   => ['label' => __('Blocked'),   'sub' => __('Sending is blocked or restricted'), 'dot' => 'bg-accent-coral', 'text' => 'text-accent-coral'],
        'attention' => ['label' => __('Attention'), 'sub' => __('Some checks need a look'),           'dot' => 'bg-accent-amber', 'text' => 'text-accent-amber'],
        default     => ['label' => __('Healthy'),   'sub' => __('This number can send messages'),     'dot' => 'bg-wa-green',     'text' => 'text-wa-deep'],
    };

    $sevStyle = fn ($s) => match ($s) {
        'critical' => ['bg-accent-coral/10 border-accent-coral/40', 'bg-accent-coral', 'text-accent-coral'],
        'warning'  => ['bg-accent-amber/10 border-accent-amber/40', 'bg-accent-amber', 'text-accent-amber'],
        default    => ['bg-paper-100 border-paper-200', 'bg-ink-400', 'text-ink-600'],
    };

    // Generic value pill: good / warn / bad / neutral.
    $tone = function ($val, array $good = [], array $warn = []) {
        $u = strtoupper((string) $val);
        if ($u === '') return 'bg-paper-100 text-ink-600 border-paper-200';
        if (in_array($u, array_map('strtoupper', $good), true)) return 'bg-wa-mint text-wa-deep border-wa-green/40';
        if (in_array($u, array_map('strtoupper', $warn), true)) return 'bg-accent-amber/10 text-accent-amber border-accent-amber/40';
        return 'bg-accent-coral/10 text-accent-coral border-accent-coral/40';
    };

    $quality = strtoupper((string) ($phone['quality_rating'] ?? ''));
    $qMeta   = match ($quality) {
        'GREEN'  => ['label' => __('Green'),  'text' => 'text-wa-deep',      'dot' => 'bg-wa-green'],
        'YELLOW' => ['label' => __('Yellow'), 'text' => 'text-accent-amber', 'dot' => 'bg-accent-amber'],
        'RED'    => ['label' => __('Red'),    'text' => 'text-accent-coral', 'dot' => 'bg-accent-coral'],
        default  => ['label' => __('Unrated'),'text' => 'text-ink-500',      'dot' => 'bg-ink-300'],
    };

    $tierRaw = (string) ($phone['messaging_limit_tier'] ?? '');
    $tier    = $tierRaw ? str_replace(['TIER_', 'UNLIMITED'], ['', '∞'], $tierRaw) : '—';
    $thr     = $phone['throughput']['level'] ?? null;

    $tplTotal    = (int) ($tpls['total'] ?? 0);
    $tplApproved = (int) ($tpls['by_status']['APPROVED'] ?? 0);

    $fetched = \Illuminate\Support\Carbon::parse($health['fetched_at'] ?? now());

    // KPI card shell — identical tokens to the operator dashboard stat cards.
    $kpi = 'col-span-12 md:col-span-6 xl:col-span-3 bg-paper-0 hairline border border-paper-200 rounded-2xl p-5 shadow-card relative overflow-hidden';
@endphp

<x-layouts.user :title="__('Account Health')" nav-key="devices" page="user-devices-waba-health">

    {{-- ========== PAGE HEADER (operator-dashboard style) ========== --}}
    <section class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pt-5 md:pt-7 pb-4">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-3 mb-2 text-[11px] font-mono uppercase tracking-[0.18em] text-ink-500">
                    <a href="{{ route('user.devices.index') }}" class="hover:text-wa-deep">{{ __('Devices') }}</a>
                    <span class="w-1 h-1 rounded-full bg-ink-500/50"></span>
                    <span>{{ __('Meta (WABA) health') }}</span>
                    <span class="w-1 h-1 rounded-full bg-ink-500/50"></span>
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full {{ $status['dot'] }}"></span>{{ __('checked') }} {{ $fetched->diffForHumans() }}</span>
                </div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[32px] md:text-[42px] xl:text-[48px] leading-[1.05] truncate">
                    {{ $title }}
                </h1>
                <p class="text-[13px] text-ink-600 mt-2">
                    <span class="font-mono">{{ $phone['display_phone_number'] ?? ($waba->phone_number ?: '+— unknown') }}</span>
                    · {{ $status['sub'] }} · {{ __('Graph') }} {{ $health['version'] ?? 'v23.0' }}
                </p>
            </div>
            <div class="flex items-center gap-2 mt-2 md:mt-0 flex-wrap">
                <a href="{{ route('user.devices.waba.health', $waba->id) }}"
                    class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal inline-flex items-center gap-2">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2v3h-3" />
                    </svg>
                    {{ __('Re-check') }}
                </a>
                @if (!empty($ids['waba_id']))
                    <a href="https://business.facebook.com/wa/manage/phone-numbers/?waba_id={{ $ids['waba_id'] }}"
                        target="_blank" rel="noopener"
                        class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 text-[12px] font-medium text-ink-700 hover:bg-paper-50 inline-flex items-center gap-1.5">
                        <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M6 3H3v10h10v-3M9 3h4v4M13 3l-7 7" />
                        </svg>
                        {{ __('Open in Meta') }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- ========== KPI STATS ROW ========== --}}
    <section class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pb-3">
        <div class="grid grid-cols-12 gap-3">

            {{-- Overall status --}}
            <div class="{{ $kpi }}">
                <div class="absolute -right-4 -top-4 w-24 h-24 rounded-full bg-[repeating-linear-gradient(135deg,rgba(7,94,84,0.05)_0_6px,transparent_6px_12px)] opacity-50"></div>
                <div class="flex items-start justify-between relative">
                    <span class="font-mono text-[10px] uppercase tracking-widest text-ink-500">{{ __('Overall status') }}</span>
                    <span class="w-2 h-2 rounded-full {{ $status['dot'] }}"></span>
                </div>
                <div class="mt-4 font-serif font-normal tracking-[-0.01em] text-[30px] md:text-[38px] leading-none {{ $status['text'] }}">{{ $status['label'] }}</div>
                <div class="mt-3 text-[11px] text-ink-600">{{ $status['sub'] }}</div>
            </div>

            {{-- Quality rating --}}
            <div class="{{ $kpi }}">
                <div class="flex items-start justify-between relative">
                    <span class="font-mono text-[10px] uppercase tracking-widest text-ink-500">{{ __('Quality rating') }}</span>
                    <span class="w-2 h-2 rounded-full {{ $qMeta['dot'] }}"></span>
                </div>
                <div class="mt-4 font-serif font-normal tracking-[-0.01em] text-[30px] md:text-[38px] leading-none {{ $qMeta['text'] }}">{{ $qMeta['label'] }}</div>
                <div class="mt-3 text-[11px] text-ink-600">{{ __('Meta message quality (last rolling window)') }}</div>
            </div>

            {{-- Messaging limit --}}
            <div class="{{ $kpi }}">
                <div class="flex items-start justify-between relative">
                    <span class="font-mono text-[10px] uppercase tracking-widest text-ink-500">{{ __('Messaging limit') }}</span>
                </div>
                <div class="mt-4 flex items-baseline gap-1.5">
                    <span class="font-serif font-normal tracking-[-0.01em] text-[40px] md:text-[52px] leading-none tabular-nums">{{ $tier }}</span>
                    @if ($tier !== '—')<span class="text-[12px] text-ink-500 font-mono">/ 24h</span>@endif
                </div>
                <div class="mt-3 text-[11px] text-ink-600">{{ __('Unique customers you can start per day') }}{{ $thr ? ' · ' . $thr : '' }}</div>
            </div>

            {{-- Free conversations this month — Meta's free monthly allowance --}}
            @php $conv = $health['conversations'] ?? null; @endphp
            @if ($conv)
            <div class="{{ $kpi }}">
                <div class="flex items-start justify-between relative">
                    <span class="font-mono text-[10px] uppercase tracking-widest text-ink-500">{{ __('Free this month') }}</span>
                </div>
                <div class="mt-4 flex items-baseline gap-1.5">
                    <span class="font-serif font-normal tracking-[-0.01em] text-[40px] md:text-[52px] leading-none tabular-nums">{{ number_format($conv['free_left']) }}</span>
                    <span class="text-[12px] text-ink-500 font-mono">/ {{ number_format($conv['free_total']) }}</span>
                </div>
                <div class="mt-3 text-[11px] text-ink-600">{{ __('Free service conversations left') }} · {{ number_format($conv['free_used']) }} {{ __('used') }}@if (($conv['paid'] ?? 0) > 0) · {{ number_format($conv['paid']) }} {{ __('paid') }}@endif</div>
            </div>
            @endif

            {{-- Templates --}}
            <div class="{{ $kpi }}">
                <div class="flex items-start justify-between relative">
                    <span class="font-mono text-[10px] uppercase tracking-widest text-ink-500">{{ __('Message templates') }}</span>
                </div>
                <div class="mt-4 font-serif font-normal tracking-[-0.01em] text-[40px] md:text-[52px] leading-none tabular-nums">{{ $tplTotal }}</div>
                <div class="mt-3 flex items-center gap-3 text-[11px] text-ink-600">
                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ $tplApproved }} {{ __('approved') }}</span>
                    @if ($tplTotal - $tplApproved > 0)
                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ $tplTotal - $tplApproved }} {{ __('other') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pb-8 space-y-4">

        {{-- Issues / blocks --}}
        @if (!empty($issues))
            <div class="bg-paper-0 hairline border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-5 py-3 border-b border-paper-200 font-mono text-[10.5px] uppercase tracking-[0.14em] text-ink-500">
                    {{ __('Issues & blocks') }} ({{ count($issues) }})
                </div>
                <div class="divide-y divide-paper-100">
                    @foreach ($issues as $iss)
                        @php [$ibg, $idot, $itext] = $sevStyle($iss['severity']); @endphp
                        <div class="px-5 py-3.5 flex items-start gap-3">
                            <span class="mt-1.5 w-2 h-2 rounded-full {{ $idot }} shrink-0"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-[12.5px] text-ink-900">{{ $iss['title'] }}</span>
                                    <span class="px-1.5 py-0.5 rounded border text-[9.5px] font-mono uppercase {{ $ibg }} {{ $itext }}">{{ $iss['area'] }}</span>
                                    @if (!empty($iss['code']))
                                        <span class="font-mono text-[10px] text-ink-400">#{{ $iss['code'] }}</span>
                                    @endif
                                </div>
                                @if (!empty($iss['detail']))
                                    <div class="text-[11.5px] text-ink-600 mt-0.5">{{ $iss['detail'] }}</div>
                                @endif
                                @if (!empty($iss['solution']))
                                    <div class="text-[11.5px] text-wa-deep mt-1">
                                        <span class="font-semibold">{{ __('Fix') }}:</span> {{ $iss['solution'] }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Detail grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            {{-- Number status --}}
            <div class="bg-paper-0 hairline border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-serif text-[17px] mb-3">{{ __('Phone number') }}</div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[12px]">
                    <dt class="text-ink-500">{{ __('Verified name') }}</dt>
                    <dd class="text-right text-ink-800 truncate min-w-0">{{ $phone['verified_name'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Connection') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($phone['status'] ?? '', ['CONNECTED']) }}">
                            {{ $phone['status'] ?? '—' }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Quality rating') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($quality, ['GREEN'], ['YELLOW']) }}">
                            {{ $quality ?: __('Unrated') }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Messaging limit') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $tier !== '—' ? $tier . ' / 24h' : '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Throughput') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $thr ?: '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Number verification') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($phone['code_verification_status'] ?? '', ['VERIFIED']) }}">
                            {{ $phone['code_verification_status'] ?? '—' }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Display name status') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($phone['name_status'] ?? '', ['APPROVED','AVAILABLE_WITHOUT_REVIEW'], ['PENDING_REVIEW']) }}">
                            {{ $phone['name_status'] ?? '—' }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Account mode') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $phone['account_mode'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Platform') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $phone['platform_type'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Official business') }}</dt>
                    <dd class="text-right text-ink-800">{{ ($phone['is_official_business_account'] ?? false) ? __('Yes (blue tick)') : __('No') }}</dd>

                    <dt class="text-ink-500">{{ __('Two-step PIN') }}</dt>
                    <dd class="text-right text-ink-800">{{ ($phone['is_pin_enabled'] ?? false) ? __('Enabled') : __('Off') }}</dd>
                </dl>
            </div>

            {{-- WABA account --}}
            <div class="bg-paper-0 hairline border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-serif text-[17px] mb-3">{{ __('Business account') }}</div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[12px]">
                    <dt class="text-ink-500">{{ __('WABA name') }}</dt>
                    <dd class="text-right text-ink-800 truncate min-w-0">{{ $wabaN['name'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Account review') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($wabaN['account_review_status'] ?? '', ['APPROVED'], ['PENDING']) }}">
                            {{ $wabaN['account_review_status'] ?? '—' }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Business verification') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $tone($wabaN['business_verification_status'] ?? '', ['VERIFIED'], ['PENDING']) }}">
                            {{ $wabaN['business_verification_status'] ?? '—' }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Owner business') }}</dt>
                    <dd class="text-right text-ink-800 truncate min-w-0">{{ $wabaN['owner_business_info']['name'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Currency') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $wabaN['currency'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Timezone') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $wabaN['timezone_id'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Country') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $wabaN['country'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('Ownership') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $wabaN['ownership_type'] ?? '—' }}</dd>
                </dl>
            </div>

            {{-- Permissions / token --}}
            <div class="bg-paper-0 hairline border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-serif text-[17px] mb-3">{{ __('Access & permissions') }}</div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[12px]">
                    <dt class="text-ink-500">{{ __('Token valid') }}</dt>
                    <dd class="text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ ($token['is_valid'] ?? false) ? 'bg-wa-mint text-wa-deep border-wa-green/40' : 'bg-accent-coral/10 text-accent-coral border-accent-coral/40' }}">
                            {{ ($token['is_valid'] ?? false) ? __('Valid') : __('Invalid') }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Token type') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $token['type'] ?? '—' }}</dd>

                    <dt class="text-ink-500">{{ __('App') }}</dt>
                    <dd class="text-right text-ink-800 truncate min-w-0">{{ $token['application'] ?? ($token['app_id'] ?? '—') }}</dd>

                    <dt class="text-ink-500">{{ __('Expires') }}</dt>
                    <dd class="text-right font-mono text-ink-800">
                        @if ($token['expires_never'] ?? false)
                            {{ __('Never (permanent)') }}
                        @elseif (!empty($token['expires_at']))
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($token['expires_at'])->format('M j, Y') }}
                        @else
                            —
                        @endif
                    </dd>
                </dl>
                <div class="mt-3 pt-3 border-t border-paper-100">
                    <div class="text-ink-500 text-[11px] mb-1.5">{{ __('Granted permissions') }}</div>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse (($token['scopes'] ?? []) as $sc)
                            <span class="px-2 py-0.5 rounded-full bg-paper-100 border border-paper-200 text-[10.5px] font-mono text-ink-700">{{ $sc }}</span>
                        @empty
                            <span class="text-[11.5px] text-ink-400">{{ __('No permissions reported') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Webhook + templates + IDs --}}
            <div class="bg-paper-0 hairline border border-paper-200 rounded-2xl shadow-card p-5">
                <div class="font-serif text-[17px] mb-3">{{ __('Delivery & content') }}</div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[12px]">
                    <dt class="text-ink-500">{{ __('Webhook subscribed') }}</dt>
                    <dd class="text-right">
                        @php $sub = $webhook['subscribed'] ?? null; @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10.5px] font-mono uppercase {{ $sub === true ? 'bg-wa-mint text-wa-deep border-wa-green/40' : ($sub === false ? 'bg-accent-coral/10 text-accent-coral border-accent-coral/40' : 'bg-paper-100 text-ink-600 border-paper-200') }}">
                            {{ $sub === true ? __('Yes') : ($sub === false ? __('No') : '—') }}
                        </span>
                    </dd>

                    <dt class="text-ink-500">{{ __('Templates total') }}</dt>
                    <dd class="text-right font-mono text-ink-800">{{ $tpls['total'] ?? '—' }}</dd>
                </dl>

                @if (!empty($tpls['by_status']))
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($tpls['by_status'] as $st => $n)
                            <span class="px-2 py-0.5 rounded-full border text-[10.5px] font-mono {{ $tone($st, ['APPROVED'], ['PENDING','PAUSED','IN_APPEAL']) }}">
                                {{ $st }} · {{ $n }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3 pt-3 border-t border-paper-100">
                    <dl class="grid grid-cols-[120px_1fr] gap-x-3 gap-y-1.5 text-[11px]">
                        <dt class="text-ink-500 font-mono uppercase tracking-wide text-[9.5px] pt-0.5">{{ __('Phone ID') }}</dt>
                        <dd class="text-right font-mono text-ink-700 break-all">{{ $ids['phone_number_id'] ?: '—' }}</dd>
                        <dt class="text-ink-500 font-mono uppercase tracking-wide text-[9.5px] pt-0.5">{{ __('WABA ID') }}</dt>
                        <dd class="text-right font-mono text-ink-700 break-all">{{ $ids['waba_id'] ?: '—' }}</dd>
                        <dt class="text-ink-500 font-mono uppercase tracking-wide text-[9.5px] pt-0.5">{{ __('Business ID') }}</dt>
                        <dd class="text-right font-mono text-ink-700 break-all">{{ $ids['business_id'] ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Fetch errors (a Graph node we couldn't read) --}}
        @if (!empty($errors))
            <div class="bg-paper-0 hairline border border-accent-amber/40 rounded-2xl shadow-card p-5">
                <div class="font-mono text-[10.5px] uppercase tracking-[0.14em] text-accent-amber mb-2">
                    {{ __('Could not read some data from Meta') }}
                </div>
                <ul class="space-y-1.5 text-[11.5px] text-ink-600 list-disc pl-4">
                    @foreach ($errors as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

    </section>
</x-layouts.user>
