<article class="rm-card">
    <div class="rm-card__row1">
        <h3 class="rm-card__name">{{ $task['lead_name'] }}</h3>
        <div class="rm-card__actions">
            @if($actionType === 'call')
                <form class="rm-complete-form" method="POST" action="{{ route('remarketing.call') }}">
                    @csrf
                    <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                    <input type="hidden" name="phone" value="{{ $task['phone'] }}">
                    <input type="hidden" name="reason" value="{{ $task['reason'] }}">
                    <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                    <input type="hidden" name="current_stage" value="{{ $selectedStage }}">
                    <button type="submit" class="rm-card__action rm-card__action--call">Call</button>
                </form>
            @elseif($actionType === 'whatsapp')
                <a href="{{ $task['whatsapp_url'] }}" target="_blank" rel="noopener" class="rm-card__action rm-card__action--wa">WhatsApp</a>
            @endif

            <form class="rm-complete-form" method="POST" action="{{ route('remarketing.complete') }}">
                @csrf
                <input type="hidden" name="task_type" value="{{ $task['task_type'] }}">
                <input type="hidden" name="lead_name" value="{{ $task['lead_name'] }}">
                <input type="hidden" name="current_stage" value="{{ $selectedStage }}">
                <button type="submit" class="rm-card__action rm-card__action--secondary">Mark Complete</button>
            </form>
        </div>
    </div>
    <div class="rm-card__meta">
        <span class="rm-meta-k">Phone</span> <span class="rm-meta-v">{{ $task['phone'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        <span class="rm-meta-k">Reason</span> <span class="rm-meta-v">{{ $task['reason'] }}</span>
        <span class="rm-meta-dot" aria-hidden="true">·</span>
        <span class="rm-meta-k">Time waiting</span> <span class="rm-meta-v">{{ $task['time_waiting'] }}</span>
    </div>
</article>
