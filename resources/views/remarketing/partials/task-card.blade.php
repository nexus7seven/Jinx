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
            </div>
        </div>
        <div class="rm-card__actions">
            @if ($type === 'call')
                <form class="rm-inline-form" method="POST" action="{{ route('remarketing.call') }}">
                    @csrf
                    <input type="hidden" name="task_id" value="{{ $task['id'] }}">
                    <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                    <input type="hidden" name="phone" value="{{ $task['phone'] }}">
                    <input type="hidden" name="reason" value="{{ $task['reason'] }}">
                    <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                    <button type="submit" class="rm-btn rm-btn--primary">Call</button>
                </form>
            @elseif ($type === 'whatsapp' && !empty($task['whatsapp_url']))
                <a href="{{ $task['whatsapp_url'] }}" target="_blank" rel="noopener" class="rm-btn rm-btn--primary rm-btn--whatsapp">WhatsApp</a>
            @endif

            <form class="rm-inline-form" method="POST" action="{{ route('remarketing.complete') }}">
                @csrf
                <input type="hidden" name="task_id" value="{{ $task['id'] }}">
                <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                <button type="submit" class="rm-btn rm-btn--secondary" title="Mark complete">Complete</button>
            </form>
        </div>
    </div>
    <div class="rm-card__meta">
        <span class="rm-meta-k">Phone</span> <span class="rm-meta-v">{{ $task['phone'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        <span class="rm-meta-k">Reason</span> <span class="rm-meta-v">{{ $task['reason'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        <span class="rm-meta-k">Waiting</span> <span class="rm-meta-v">{{ $task['time_waiting'] }}</span>
    </div>
</article>
