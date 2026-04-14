@extends('layouts.app')

@section('title', 'Remarketing')

@push('head')
<style>
    .rm-stage-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-bottom: 22px;
    }
    .rm-stage-pill {
        appearance: none;
        cursor: default;
        display: inline-flex;
        align-items: center;
        padding: 8px 14px;
        border-radius: 999px;
        border: 1px solid #374151;
        background: #111827;
        color: #e5e7eb;
        font-size: 13px;
        font-weight: 600;
        font-family: inherit;
        text-decoration: none;
    }
    .rm-stage-pill--active {
        border-color: #2563eb;
        background: #1d4ed8;
        color: #fff;
    }
    .rm-section {
        margin-bottom: 28px;
    }
    .rm-section__title {
        margin: 0 0 12px 0;
        font-size: 18px;
        font-weight: 700;
        color: #f1f5f9;
    }
    .rm-card-grid {
        display: grid;
        gap: 12px;
    }
    .rm-card {
        background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
        border: 1px solid #1e293b;
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .rm-card__row1 {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
    }
    .rm-card__name {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #f8fafc;
    }
    .rm-card__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: flex-end;
    }
    .rm-card__meta {
        font-size: 13px;
        color: #94a3b8;
        line-height: 1.5;
    }
    .rm-meta-k { color: #64748b; font-weight: 500; }
    .rm-meta-v { color: #94a3b8; font-weight: 400; }
    .rm-meta-dot { color: #3f4f63; margin: 0 0.28em; user-select: none; }
    .rm-card__action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 8px 14px;
        border-radius: 10px;
        border: 0;
        font-size: 13px;
        font-weight: 700;
        font-family: inherit;
        cursor: default;
    }
    .rm-card__action--call {
        background: #2563eb;
        color: #fff;
    }
    .rm-card__action--wa {
        background: #059669;
        color: #fff;
    }
    .rm-card__action--secondary {
        background: #1e293b;
        color: #e2e8f0;
        border: 1px solid #475569;
        cursor: pointer;
    }
    .rm-card__action--secondary:hover {
        background: #334155;
    }
    .rm-complete-form {
        display: inline;
        margin: 0;
    }
</style>
@endpush

@section('content')
    @if (session('success'))
        <div role="status"
             style="margin-bottom:16px; padding:12px 14px; border-radius:10px; border:1px solid #166534; background:#14532d; color:#dcfce7; font-size:14px;">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div role="alert"
             style="margin-bottom:16px; padding:12px 14px; border-radius:10px; border:1px solid #b91c1c; background:#7f1d1d; color:#fee2e2; font-size:14px;">
            {{ session('error') }}
        </div>
    @endif

    <div style="margin-bottom:20px;">
        <h1 style="margin:0; font-size:26px; font-weight:700;">Remarketing</h1>
        <p style="margin:8px 0 0 0; font-size:13px; color:#9ca3af; max-width:52ch;">
            Manual follow-up tasks for leads who need another touch—calls, WhatsApp, and quick context in one place.
        </p>
    </div>

    <div class="rm-stage-bar" role="group" aria-label="Stage filter">
        @foreach($stages as $stage)
            @php
                $label = $stage === 'all' ? 'All' : ucfirst($stage);
                $isActive = $selectedStage === $stage;
            @endphp
            <a href="{{ route('remarketing.index', ['stage' => $stage]) }}"
               class="rm-stage-pill {{ $isActive ? 'rm-stage-pill--active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <section class="rm-section" aria-labelledby="rm-add-task-heading">
        <h2 id="rm-add-task-heading" class="rm-section__title">Add Test Task</h2>
        <form method="POST" action="{{ route('remarketing.tasks.store') }}" class="rm-card">
            @csrf
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                <input
                    type="number"
                    name="lead_id"
                    value="{{ old('lead_id') }}"
                    placeholder="Lead ID"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                <input
                    type="text"
                    name="lead_name"
                    value="{{ old('lead_name') }}"
                    placeholder="Lead name"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                <input
                    type="text"
                    name="phone"
                    value="{{ old('phone') }}"
                    placeholder="Phone"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                <select
                    name="task_type"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                    <option value="call" {{ old('task_type') === 'call' ? 'selected' : '' }}>call</option>
                    <option value="whatsapp" {{ old('task_type') === 'whatsapp' ? 'selected' : '' }}>whatsapp</option>
                </select>
                <input
                    type="text"
                    name="reason"
                    value="{{ old('reason') }}"
                    placeholder="Reason"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                <input
                    type="text"
                    name="campaign_id"
                    value="{{ old('campaign_id') }}"
                    placeholder="Campaign ID"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                <select
                    name="stage"
                    required
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
                    <option value="fresh" {{ old('stage') === 'fresh' ? 'selected' : '' }}>fresh</option>
                    <option value="cooling" {{ old('stage') === 'cooling' ? 'selected' : '' }}>cooling</option>
                    <option value="cold" {{ old('stage') === 'cold' ? 'selected' : '' }}>cold</option>
                    <option value="dormant" {{ old('stage') === 'dormant' ? 'selected' : '' }}>dormant</option>
                </select>
                <input
                    type="text"
                    name="time_waiting_text"
                    value="{{ old('time_waiting_text') }}"
                    placeholder="Time waiting text (optional)"
                    style="box-sizing:border-box; width:100%; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
                >
            </div>
            <div>
                <button type="submit" class="rm-card__action rm-card__action--call">Add Test Task</button>
            </div>
        </form>
    </section>

    <section class="rm-section" aria-labelledby="rm-call-heading">
        <h2 id="rm-call-heading" class="rm-section__title">Call Tasks</h2>
        <div class="rm-card-grid">
            @if (!empty($callTasks))
                @foreach($callTasks as $task)
                    @include('remarketing.partials.task-card', [
                        'task' => $task,
                        'selectedStage' => $selectedStage,
                        'actionType' => 'call',
                    ])
                @endforeach
            @else
                <article class="rm-card">
                    <h3 class="rm-card__name">No call tasks</h3>
                    <div class="rm-card__meta">There are no call tasks for this stage right now.</div>
                </article>
            @endif
        </div>
    </section>

    <section class="rm-section" aria-labelledby="rm-wa-heading">
        <h2 id="rm-wa-heading" class="rm-section__title">WhatsApp Tasks</h2>
        <div class="rm-card-grid">
            @if (!empty($whatsappTasks))
                @foreach($whatsappTasks as $task)
                    @include('remarketing.partials.task-card', [
                        'task' => $task,
                        'selectedStage' => $selectedStage,
                        'actionType' => 'whatsapp',
                    ])
                @endforeach
            @else
                <article class="rm-card">
                    <h3 class="rm-card__name">No WhatsApp tasks</h3>
                    <div class="rm-card__meta">There are no WhatsApp tasks for this stage right now.</div>
                </article>
            @endif
        </div>
    </section>

    <section class="rm-section" aria-labelledby="rm-activity-heading">
        <h2 id="rm-activity-heading" class="rm-section__title">Recent Activity</h2>
        <div class="rm-card-grid">
            @foreach($recentActivity as $activity)
                <article class="rm-card">
                    <div class="rm-card__row1">
                        <h3 class="rm-card__name">{{ $activity['lead_name'] }}</h3>
                    </div>
                    <div class="rm-card__meta">
                        <span class="rm-meta-k">Activity</span> <span class="rm-meta-v">{{ $activity['activity'] }}</span>
                        <span class="rm-meta-dot" aria-hidden="true">·</span>
                        <span class="rm-meta-k">Time</span> <span class="rm-meta-v">{{ $activity['time'] }}</span>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endsection
