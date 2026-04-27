@php
    $type = (string) ($task['task_type'] ?? '');
    $actionLabel = match ($type) {
        'call' => 'Call',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'email' => 'Email',
        default => \Illuminate\Support\Str::title(str_replace('_', ' ', $type ?: 'Task')),
    };
    $stageLabel = isset($task['stage']) && $task['stage'] !== '' ? ucfirst((string) $task['stage']) : '';
    $sourceLabel = isset($task['source_label']) ? trim((string) $task['source_label']) : '';
    $isDueNow = (bool) ($task['is_due_now'] ?? false);
    $waitingText = trim((string) ($task['waiting_text'] ?? $task['time_waiting'] ?? ''));
@endphp
<article class="rm-card">
    <div class="rm-card__row1">
        <div class="rm-card__title-block">
            <h3 class="rm-card__name">{{ $task['lead_name'] }}</h3>
            <div class="rm-card__chips">
                <span class="rm-chip rm-chip--action">{{ $actionLabel }}</span>
                @if ($stageLabel !== '')
                    <span class="rm-chip rm-chip--stage">{{ $stageLabel }}</span>
                @endif
                @if ($sourceLabel !== '')
                    <span class="rm-chip rm-chip--source" title="{{ $sourceLabel }}">{{ $sourceLabel }}</span>
                @endif
            </div>
        </div>
        <div class="rm-card__actions" role="group" aria-label="Task actions">
            @if ($type === 'call')
                <form class="rm-inline-form" method="POST" action="{{ route('remarketing.call') }}">
                    @csrf
                    <input type="hidden" name="task_id" value="{{ $task['id'] }}">
                    <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                    <input type="hidden" name="phone" value="{{ $task['phone'] }}">
                    <input type="hidden" name="reason" value="{{ $task['reason'] }}">
                    <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                    <button type="submit" class="rm-icon-btn rm-icon-btn--call" title="Call" aria-label="Call">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                        </svg>
                    </button>
                </form>
            @elseif ($type === 'whatsapp' && !empty($task['whatsapp_url']))
                <a
                    href="{{ $task['whatsapp_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rm-icon-btn rm-icon-btn--wa"
                    title="Open WhatsApp"
                    aria-label="Open WhatsApp"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                    </svg>
                </a>
            @endif

            @if (!empty($task['is_linear']))
                <button type="button" class="rm-icon-btn rm-icon-btn--complete" title="Complete (coming soon)" aria-label="Mark task complete (coming soon)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </button>
            @else
                <form class="rm-inline-form" method="POST" action="{{ route('remarketing.complete') }}">
                    @csrf
                    <input type="hidden" name="task_id" value="{{ $task['id'] }}">
                    <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                    <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                    <button type="submit" class="rm-icon-btn rm-icon-btn--complete" title="Complete" aria-label="Mark task complete">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </button>
                </form>
            @endif
        </div>
    </div>
    <div class="rm-card__meta">
        <span class="rm-meta-k">Phone</span> <span class="rm-meta-v">{{ $task['phone'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        <span class="rm-meta-k">Reason</span> <span class="rm-meta-v">{{ $task['reason'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        @if ($isDueNow)
            <span class="rm-meta-k">DUE NOW</span>
        @else
            <span class="rm-meta-k">WAITING</span> <span class="rm-meta-v">{{ $waitingText !== '' ? $waitingText : 'Waiting' }}</span>
        @endif
    </div>
</article>
