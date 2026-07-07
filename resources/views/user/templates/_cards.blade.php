@php
    /** @var \Illuminate\Support\Collection $templates */
    $catColors = [
        // WhatsApp/Meta categories — what the library badge + tabs show.
        'marketing' => 'bg-accent-coral/15 text-[#A1431F]',
        'authentication' => 'bg-[#13478A]/10 text-[#13478A]',
        'utility' => 'bg-[#DFF1ED] text-wa-deep',
        // Legacy local verticals (fallback when no meta_category is set).
        'travel' => 'bg-wa-bubble text-wa-deep',
        'healthcare' => 'bg-[#DFF1ED] text-wa-deep',
        'education' => 'bg-[#F4E9C9] text-[#7B5A14]',
        'ecommerce' => 'bg-accent-coral/15 text-[#A1431F]',
        'festival' => 'bg-[#EFE5F5] text-[#5B3D8A]',
        'finance' => 'bg-[#13478A]/10 text-[#13478A]',
    ];
    $statusDot = [
        'approved' => 'bg-wa-green',
        'public' => 'bg-wa-green',
        'pending' => 'bg-accent-amber',
        'rejected' => 'bg-accent-coral',
    ];
@endphp

@forelse ($templates as $t)
    @php
        // Badge/filter use the WhatsApp category (meta_category); fall back to
        // the local vertical only when a template has no Meta category yet.
        $catKey = $t->meta_category ?: $t->category;
        $cls = $catColors[$catKey] ?? 'bg-paper-50 text-ink-700';
        $dot = $statusDot[$t->status] ?? 'bg-paper-300';
        // First 5 paragraphs of the body. Body is encrypted text;
        // we just split on \n\n so the card preview matches what
        // the operator typed.
        $bodyHtml = collect(explode("\n\n", (string) $t->template_body))
            ->take(5)
            ->map(fn($p) => '<p>' . nl2br(e($p)) . '</p>')
            ->implode('');
        // Which engine this template belongs to — shown as a chip on the card.
        $engKey = $t->engineKey();
        $engMeta =
            [
                'baileys' => [__('Unofficial'), 'bg-wa-mint text-wa-deep'],
                'waba' => [__('Meta WABA'), 'bg-wa-bubble text-wa-deep'],
                'twilio' => [__('Twilio'), 'bg-[#F22F46]/10 text-[#A12534]'],
            ][$engKey] ?? [__('Unofficial'), 'bg-paper-50 text-ink-700'];
    @endphp
    <div class="tpl-card bg-white border border-paper-200 rounded-[14px] p-4 transition flex flex-col min-h-[360px] hover:border-wa-deep hover:shadow-soft hover:-translate-y-px"
        data-template-row data-template-id="{{ $t->id }}" data-category="{{ $catKey }}"
        data-status="{{ $t->status }}">
        <div class="flex items-start justify-between gap-2 mb-2">
            <span class="tpl-name text-[13px] font-semibold text-ink-900 break-words">{{ $t->template_name }}</span>
            <span
                class="tpl-cat shrink-0 text-[10px] font-medium px-2 py-0.5 rounded {{ $cls }}">{{ ucfirst($catKey) }}</span>
        </div>
        <div class="flex items-center gap-1.5 mb-2 text-[10.5px] font-mono text-ink-500 flex-wrap">
            <span
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full font-semibold {{ $engMeta[1] }}">
                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M2.6 11.2 2 14l2.9-.6A6 6 0 1 0 2.6 11.2Z" />
                </svg>{{ $engMeta[0] }}
            </span>
            <span class="inline-flex items-center gap-1.5"><span
                    class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>{{ $t->status_label }}</span>
            <span>·</span>
            <span>{{ ucfirst($t->template_type) }}</span>
            @if ($t->meta_category)
                <span>·</span>
                <span>{{ ucfirst($t->meta_category) }}</span>
            @endif
            @if ($engKey === 'waba' && $t->provider && $t->provider->id)
                {{-- Which WABA account this template belongs to — so 3-account
                     workspaces can tell their synced templates apart. --}}
                <span>·</span>
                <span class="inline-flex items-center gap-1 text-wa-deep font-semibold truncate max-w-[150px]"
                    title="{{ $t->provider->display_label ?: $t->provider->phone_number }}">
                    <svg viewBox="0 0 16 16" class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M5.5 2.5h5a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1Zm1.5 10h2" />
                    </svg>{{ $t->provider->display_label ?: $t->provider->phone_number ?: 'WABA' }}
                </span>
            @endif
        </div>
        @if (!empty($t->header))
            <div class="tpl-header break-words text-[12.5px] font-semibold text-ink-900 leading-snug mb-1.5">
                {{ \Illuminate\Support\Str::limit($t->header, 100) }}</div>
        @endif
        <div class="tpl-body break-words text-[12px] leading-[1.55] text-ink-700 [&_p]:mb-2 flex-1">
            {!! $bodyHtml !!}
        </div>
        @if (!empty($t->footer))
            <div class="tpl-footer text-[10.5px] text-ink-500 mt-2 pt-2 border-t border-paper-100">
                {{ \Illuminate\Support\Str::limit($t->footer, 120) }}</div>
        @endif
        @php $btns = is_array($t->buttons) ? $t->buttons : []; @endphp
        @if (count($btns))
            <div class="mt-2 flex flex-wrap gap-1">
                @foreach (array_slice($btns, 0, 3) as $b)
                    <span
                        class="text-[10.5px] font-medium px-2 py-0.5 rounded-full bg-wa-bubble text-wa-deep border border-wa-green/30">{{ $b['text'] ?? 'Button' }}</span>
                @endforeach
                @if (count($btns) > 3)
                    <span class="text-[10.5px] font-mono text-ink-500">+{{ count($btns) - 3 }}
                        {{ __('more') }}</span>
                @endif
            </div>
        @endif
        @if ($t->status === 'rejected' && $t->rejection_reason)
            <div
                class="mt-2 rounded-lg border border-accent-coral/30 bg-accent-coral/10 px-2.5 py-1.5 text-[11px] text-[#A1431F]">
                <b>Rejected:</b> {{ $t->rejection_reason }}
            </div>
        @endif
        <div class="mt-3 flex items-center gap-2">
            <a href="{{ route('user.templates.edit', $t->id) }}"
                class="flex-1 use-sample border border-dashed border-wa-deep text-wa-deep bg-transparent text-[11px] font-medium py-[7px] rounded-full transition hover:bg-wa-deep hover:text-paper-0 hover:border-solid text-center">Edit
                template</a>
            <button data-template-delete="{{ $t->id }}" data-name="{{ $t->template_name }}" type="button"
                class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-accent-coral/10 hover:border-accent-coral hover:text-accent-coral grid place-items-center"
                title="{{ __('Delete') }}">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path d="M3 4h10M5 4V2.5h6V4M4 4l1 10h6l1-10" />
                </svg>
            </button>
        </div>
    </div>
@empty
    @include('user.partials.empty-state', [
        'class' => 'col-span-full',
        'message' =>
            'No templates match the current filters. Try clearing filters or submit a new template for review.',
        'resetHref' => url('/templates'),
        'actionHref' => route('user.templates.create'),
        'actionLabel' => 'Create template',
    ])
@endforelse
