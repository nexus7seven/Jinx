@extends('layouts.app')

@section('title', 'Remarketing')

@push('head')
<style>
    .rm-page {
        font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    .rm-header {
        margin-bottom: 18px;
    }
    .rm-header__title {
        font-size: 1.375rem;
        font-weight: 600;
        letter-spacing: -0.03em;
        color: #f8fafc;
        margin: 0;
    }
    .rm-header__sub {
        font-size: 12px;
        color: #64748b;
        margin: 4px 0 0 0;
        max-width: 42rem;
        line-height: 1.45;
    }
    .rm-section {
        margin-bottom: 22px;
    }
    .rm-section__title {
        margin: 0 0 10px 0;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #64748b;
    }
    .rm-card-grid {
        display: grid;
        gap: 10px;
    }
    .rm-card {
        background: linear-gradient(165deg, rgba(17, 24, 39, 0.98) 0%, rgba(15, 23, 42, 0.99) 100%);
        border: 1px solid rgba(51, 65, 85, 0.55);
        border-radius: 10px;
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .rm-card__row1 {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        flex-wrap: wrap;
    }
    .rm-card__title-block {
        min-width: 0;
        flex: 1;
    }
    .rm-card__name {
        margin: 0 0 6px 0;
        font-size: 1.0625rem;
        font-weight: 600;
        line-height: 1.3;
        letter-spacing: -0.02em;
        color: #f8fafc;
    }
    .rm-card__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }
    .rm-chip {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 500;
        line-height: 1.25;
        letter-spacing: 0.02em;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .rm-chip--action {
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(59, 130, 246, 0.35);
        color: #93c5fd;
    }
    .rm-chip--stage {
        background: rgba(30, 41, 59, 0.65);
        border-color: rgba(71, 85, 105, 0.55);
        color: #94a3b8;
    }
    .rm-chip--source {
        background: rgba(30, 41, 59, 0.55);
        border-color: rgba(100, 116, 139, 0.45);
        color: #cbd5e1;
        max-width: 14rem;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .rm-card__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        justify-content: flex-end;
        flex-shrink: 0;
    }
    .rm-inline-form {
        display: inline;
        margin: 0;
    }
    .rm-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        padding: 0;
        border-radius: 8px;
        font-family: inherit;
        cursor: pointer;
        text-decoration: none;
        border: 1px solid transparent;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }
    .rm-icon-btn svg {
        width: 20px;
        height: 20px;
    }
    .rm-icon-btn--call {
        background: rgba(37, 99, 235, 0.18);
        border-color: rgba(59, 130, 246, 0.42);
        color: #93c5fd;
    }
    .rm-icon-btn--call:hover {
        background: rgba(37, 99, 235, 0.32);
        border-color: rgba(96, 165, 250, 0.55);
        color: #e0f2fe;
    }
    .rm-icon-btn--wa {
        background: rgba(5, 150, 105, 0.18);
        border-color: rgba(16, 185, 129, 0.42);
        color: #6ee7b7;
    }
    .rm-icon-btn--wa:hover {
        background: rgba(5, 150, 105, 0.32);
        border-color: rgba(52, 211, 153, 0.55);
        color: #ecfdf5;
    }
    .rm-icon-btn--complete {
        background: rgba(30, 41, 59, 0.35);
        border-color: rgba(71, 85, 105, 0.45);
        color: #64748b;
    }
    .rm-icon-btn--complete:hover {
        background: rgba(6, 78, 59, 0.28);
        border-color: rgba(34, 197, 94, 0.4);
        color: #6ee7b7;
    }
    .rm-icon-btn--complete:active {
        background: rgba(6, 78, 59, 0.38);
        border-color: rgba(52, 211, 153, 0.5);
        color: #a7f3d0;
    }
    .rm-icon-btn:focus-visible {
        outline: 2px solid rgba(96, 165, 250, 0.75);
        outline-offset: 2px;
    }
    .rm-icon-btn--complete:focus-visible {
        outline-color: rgba(52, 211, 153, 0.65);
    }
    .rm-card__meta {
        font-size: 11px;
        line-height: 1.5;
        color: #64748b;
        padding-top: 4px;
        border-top: 1px solid rgba(51, 65, 85, 0.35);
    }
    .rm-meta-k { color: #64748b; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
    .rm-meta-v { color: #94a3b8; font-weight: 400; font-size: 12px; }
    .rm-meta-dot { color: #475569; margin: 0 0.25em; user-select: none; }
    .rm-empty {
        background: rgba(17, 24, 39, 0.5);
        border: 1px solid rgba(51, 65, 85, 0.45);
        border-radius: 10px;
        padding: 18px;
        color: #94a3b8;
        font-size: 13px;
    }
    .rm-activity-list {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 1px solid rgba(51, 65, 85, 0.45);
        border-radius: 10px;
        background: rgba(15, 23, 42, 0.45);
        overflow: hidden;
    }
    .rm-activity-list li {
        padding: 8px 12px;
        font-size: 12px;
        color: #94a3b8;
        border-bottom: 1px solid rgba(51, 65, 85, 0.35);
        line-height: 1.4;
    }
    .rm-activity-list li:last-child {
        border-bottom: none;
    }
    .rm-activity-list .rm-activity-name {
        color: #cbd5e1;
        font-weight: 500;
    }
    .rm-activity-list .rm-activity-time {
        color: #64748b;
        font-size: 11px;
        margin-left: 0.35em;
    }
    .rm-flash {
        margin-bottom: 14px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 13px;
    }
    .rm-flash--ok {
        border: 1px solid rgba(16, 185, 129, 0.45);
        background: rgba(6, 78, 59, 0.35);
        color: #d1fae5;
    }
    .rm-flash--err {
        border: 1px solid rgba(239, 68, 68, 0.45);
        background: rgba(127, 29, 29, 0.35);
        color: #fecaca;
    }
</style>
@endpush

@section('content')
<div class="rm-page">
    @if (session('success'))
        <div class="rm-flash rm-flash--ok" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rm-flash rm-flash--err" role="alert">{{ session('error') }}</div>
    @endif

    <header class="rm-header">
        <h1 class="rm-header__title">Remarketing</h1>
        <p class="rm-header__sub">Follow-up actions — calls, WhatsApp, and context in one queue.</p>
    </header>

    <section class="rm-section" aria-labelledby="rm-active-heading">
        <h2 id="rm-active-heading" class="rm-section__title">Needs attention</h2>
        <div class="rm-card-grid">
            @if (!empty($activeTasks))
                @foreach($activeTasks as $task)
                    @include('remarketing.partials.task-card', ['task' => $task])
                @endforeach
            @else
                <div class="rm-empty">No active remarketing tasks right now.</div>
            @endif
        </div>
    </section>

    <section class="rm-section" aria-labelledby="rm-activity-heading">
        <h2 id="rm-activity-heading" class="rm-section__title">Recent activity</h2>
        @if (!empty($recentActivity))
            <ul class="rm-activity-list">
                @foreach($recentActivity as $activity)
                    <li>
                        <span class="rm-activity-name">{{ $activity['lead_name'] }}</span>
                        <span class="rm-meta-dot" aria-hidden="true">·</span>
                        {{ $activity['activity'] }}
                        <span class="rm-activity-time">{{ $activity['time'] }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="rm-empty">No recent activity yet.</div>
        @endif
    </section>
</div>
@endsection
