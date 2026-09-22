@php
    $caseName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
    if ($caseName === '') $caseName = 'Lead #'.$lead->id;

    $outstanding = (int) ($lead->checklist_outstanding_count ?? 0);
    $sourceLabel = \App\Support\LeadSourceDisplay::label($lead->source);
    $sourceRaw = trim((string) ($lead->source ?? ''));
    $canCall = (bool) trim((string) ($lead->phone_number ?? ''));
    $needsImmediateAttention = $lead->needsImmediateAttention();
    $isReengaged = $lead->wip_status === \App\Models\Lead::WIP_STATUS_REENGAGED;
    $reengagementUnseen = $isReengaged && isset($unseenReengagementLeadSet[(int) $lead->id]);
    $reengagementChannelLabel = $isReengaged
        ? $formatReengagementChannel($reengagementChannelByLeadId[(int) $lead->id] ?? null)
        : '';

    $cardAttentionClass = $reengagementUnseen ? 'wip-card-reengagement-unseen'
        : ($needsImmediateAttention ? 'wip-card-undialled-attention' : ($lead->isPriorityWip() ? 'wip-card-priority' : ''));
    $reengagementSeenCalm = $isReengaged && ! $reengagementUnseen;

    $queueItem = $lead->wipQueueItem;
    $waitingOnLabel = $lead->wip_waiting_on_label ?? null;
    $nextChaseAt = $queueItem?->next_chase_at;
    $lastActionedAt = $queueItem?->last_actioned_at;
    $actionNote = trim((string) ($queueItem?->action_note ?? ''));
    $chaseDue = (bool) ($lead->wip_chase_due ?? false);

    $callback = is_array($lead->active_callback ?? null) ? $lead->active_callback : null;
    $callbackAt = $callback['callback_time'] ?? null;
    $sipAt = $lead->sip_booked_at;
    $sipPrepAt = $sipAt?->copy()->subMinutes(15);
    $sipPrepDoneAt = $lead->sip_prep_completed_at;
    $sipUrgencyClass = '';
    if ($lead->wip_status === 'SIP Booked') {
        if ($sipPrepDoneAt) {
            $sipUrgencyClass = 'wip-card-sip-prepped';
        } elseif (! $sipAt) {
            $sipUrgencyClass = 'wip-card-sip-missing';
        } else {
            $minutesToPrep = (int) floor(($sipPrepAt->getTimestamp() - now()->getTimestamp()) / 60);
            $sipUrgencyClass = match (true) {
                $minutesToPrep <= 0 => 'wip-card-sip-due',
                $minutesToPrep <= 15 => 'wip-card-sip-urgent',
                $minutesToPrep <= 30 => 'wip-card-sip-soon',
                $minutesToPrep <= 60 => 'wip-card-sip-upcoming',
                default => 'wip-card-sip',
            };
        }
    }
@endphp

<article
    class="wip-card wip-lead-row {{ $cardAttentionClass }}{{ $reengagementSeenCalm ? ' wip-card-reengagement-seen' : '' }} {{ $sipUrgencyClass }}"
    data-lead-id="{{ $lead->id }}"
    data-lead-name="{{ strtolower($caseName) }}"
    data-wip-status="{{ $lead->wip_status }}"
    data-lead-source="{{ e($sourceRaw) }}"
    data-reengagement-unseen="{{ $reengagementUnseen ? '1' : '0' }}"
    data-queue-position="{{ (int) ($lead->wip_queue_position ?? 0) }}"
    data-last-actioned-at="{{ $lastActionedAt?->toIso8601String() ?? '' }}"
    data-sip-at="{{ $sipAt?->toIso8601String() ?? '' }}"
    data-sip-prep-at="{{ $sipPrepAt?->toIso8601String() ?? '' }}"
    data-sip-prep-completed-at="{{ $sipPrepDoneAt?->toIso8601String() ?? '' }}"
    data-callback-id="{{ $callback['callback_id'] ?? '' }}"
    data-callback-at="{{ $callbackAt ?? '' }}"
>
    <button type="button" class="wip-stack-handle" draggable="true" aria-label="Drag {{ $caseName }} to reorder" title="Drag to reorder">
        <span aria-hidden="true">⠿</span>
    </button>
    <div class="wip-card__row1">
        <div class="wip-card__identity">
            <div class="wip-card__title"><a href="{{ url('/lead/' . $lead->id) }}">{{ $caseName }}</a></div>
            <div class="wip-card__source">
                <span>{{ $sourceLabel }}</span>
                @if ($isReengaged)
                    <span class="wip-chip wip-card__badge--reengaged" title="Re-engagement — open checklist to acknowledge">{{ $reengagementUnseen ? 'Re-engaged · review' : 'Re-engaged' }}</span>
                    @if ($reengagementChannelLabel !== '')
                        <span class="wip-chip wip-card__badge--reengagement-channel">{{ $reengagementChannelLabel }}</span>
                    @endif
                @endif
                @if ($needsImmediateAttention)
                    <span class="wip-chip wip-card__badge--undialled" title="New, never-dialled lead">New · call needed</span>
                @endif
            </div>
        </div>
        <div class="wip-card-actions">
            <button type="button"
                id="outstanding-pill-{{ $lead->id }}"
                class="open-checklist-btn wip-chip wip-chip-outstanding {{ $outstanding === 0 ? 'wip-chip-outstanding--clear' : 'wip-chip-outstanding--pending' }}"
                data-lead-id="{{ $lead->id }}"
                data-case-name="{{ $caseName }}"
                title="Open checklist">{{ $outstanding === 0 ? '✓ Clear' : $outstanding.' to do' }}</button>
            @if ($canCall)
                @include('partials.lead-click-to-call', ['lead' => $lead, 'variant' => 'icon'])
            @endif
        </div>
    </div>
    @if ($lead->wip_status === 'SIP Booked')
        <div class="wip-sip-panel" data-sip-panel>
            @if ($sipAt)
                <div class="wip-sip-panel__main">
                    <div class="wip-sip-panel__details">
                        <span class="wip-sip-panel__label">SIP booked</span>
                        <span class="wip-sip-panel__time">{{ $sipAt->format('D j M · H:i') }}</span>
                        <span class="wip-sip-panel__prep">Prep call {{ $sipPrepAt->format('H:i') }} <span class="wip-timing-countdown" data-sip-countdown></span></span>
                    </div>
                    <div class="wip-sip-panel__actions">
                        @if ($sipPrepDoneAt)
                            <span class="wip-sip-done">✓ Prep done {{ $sipPrepDoneAt->format('H:i') }}</span>
                        @else
                            <button type="button" class="wip-sip-prep-btn" data-sip-prep-done data-lead-id="{{ $lead->id }}">Prep done</button>
                        @endif
                        <button type="button" class="wip-sip-edit-btn" data-sip-edit data-lead-id="{{ $lead->id }}" data-case-name="{{ $caseName }}">Edit</button>
                    </div>
                </div>
            @else
                <div class="wip-sip-panel__main">
                    <div class="wip-sip-panel__details">
                        <span class="wip-sip-panel__label">SIP time missing</span>
                        <span class="wip-sip-panel__missing">Add the appointment to schedule the prep call.</span>
                    </div>
                    <button type="button" class="wip-sip-edit-btn is-primary" data-sip-edit data-lead-id="{{ $lead->id }}" data-case-name="{{ $caseName }}">Add time</button>
                </div>
            @endif
        </div>
    @endif

    @if ($callback)
        <div class="wip-callback-strip" data-callback-strip>
            <div class="wip-callback-strip__main">
                <span class="wip-callback-strip__icon" aria-hidden="true">↗</span>
                <span class="wip-callback-strip__label">Callback</span>
                <strong>{{ $callback['callback_display'] }}</strong>
            </div>
            <span class="wip-timing-countdown" data-callback-countdown>{{ $callback['relative_due'] }}</span>
            @if (trim((string) ($callback['comments'] ?? '')) !== '')
                <span class="wip-callback-strip__note" title="{{ $callback['comments'] }}">{{ \Illuminate\Support\Str::limit($callback['comments'], 92) }}</span>
            @endif
        </div>
    @endif

    <div class="wip-stack-state" data-stack-state>
        @if ($waitingOnLabel)
            <span class="wip-stack-state__pill">Waiting on <strong>{{ $waitingOnLabel }}</strong></span>
        @endif
        @if ($nextChaseAt)
            <span class="wip-stack-state__pill {{ $chaseDue ? 'is-due' : '' }}" data-chase-pill>{{ $chaseDue ? 'Chase due' : 'Chase' }} · {{ $nextChaseAt->format('D j M, H:i') }}</span>
        @endif
        @if ($actionNote !== '')
            <span class="wip-stack-state__note" title="{{ $actionNote }}">{{ $actionNote }}</span>
        @endif
    </div>

    <div class="wip-card__controls">
        <select data-lead-id="{{ $lead->id }}" data-original="{{ $lead->wip_status }}" class="status-select" aria-label="Change stage for {{ $caseName }}">
            @foreach($statuses as $status)
                <option value="{{ $status }}" {{ $lead->wip_status === $status ? 'selected' : '' }}>{{ $status }}</option>
            @endforeach
        </select>
        <button type="button" class="wip-actioned-btn" data-actioned-lead-id="{{ $lead->id }}" data-actioned-case-name="{{ $caseName }}">Actioned ↓</button>
    </div>
</article>
