@extends('layouts.app')

@section('title', 'Data Dialling Dashboard')

@section('content')
    @php
        $totalCalls = (int) $summary->sum(fn ($row) => (int) $row->calls_in_selected_period);
        $uniqueLeadsDialled = (int) $summary->sum(fn ($row) => (int) $row->unique_leads_dialled);
        $totalContactCalls = (int) $summary->sum(fn ($row) => (int) ($row->status_A ?? 0) + (int) ($row->status_AB ?? 0) + (int) ($row->status_CALLBK ?? 0) + (int) ($row->status_CBHOLD ?? 0) + (int) ($row->status_REM ?? 0) + (int) ($row->status_SALE ?? 0) + (int) ($row->status_WIP ?? 0) + (int) ($row->status_XFER ?? 0));
        $totalSoldLeadsCombined = (int) $summary->sum(fn ($row) => (int) ($row->sold_leads_combined ?? 0));
        $totalXferCalls = (int) $summary->sum(fn ($row) => (int) ($row->status_XFER ?? 0));
        $totalAnswerMachineCalls = (int) $summary->sum(fn ($row) => (int) ($row->status_AA ?? 0));
        $totalNoAnswerCalls = (int) $summary->sum(fn ($row) => (int) ($row->status_NA ?? 0));

        $kpi = [
            'total_calls' => $totalCalls,
            'unique_leads_dialled' => $uniqueLeadsDialled,
            'overall_contact_rate' => $totalCalls > 0 ? round(($totalContactCalls / $totalCalls) * 100, 2) : 0,
            'overall_sale_rate' => $uniqueLeadsDialled > 0 ? round(($totalSoldLeadsCombined / $uniqueLeadsDialled) * 100, 2) : 0,
            'overall_transfer_rate' => $totalCalls > 0 ? round(($totalXferCalls / $totalCalls) * 100, 2) : 0,
            'overall_answer_machine_rate' => $totalCalls > 0 ? round(($totalAnswerMachineCalls / $totalCalls) * 100, 2) : 0,
            'overall_no_answer_rate' => $totalCalls > 0 ? round(($totalNoAnswerCalls / $totalCalls) * 100, 2) : 0,
        ];

        $keyOutcomeStatuses = ['A', 'AA', 'NA', 'AIS', 'NI', 'NODEBT', 'CHUP', 'SALE', 'XFER', 'CALLBK', 'CBHOLD', 'REM', 'WIP'];
        $otherStatuses = ['AB', 'ADC', 'DC', 'DROP', 'ERI', 'N', 'NP', 'PDROP', 'PU', 'WRN'];

        $tableBaseCellStyle = 'padding:10px 12px;border-bottom:1px solid #1e293b;white-space:nowrap;';
        $numericCellStyle = $tableBaseCellStyle.'text-align:right;color:#e2e8f0;';
        $textCellStyle = $tableBaseCellStyle.'text-align:left;color:#f8fafc;';
    @endphp

    <style>
        .dd-section { margin-bottom: 18px; }
        .dd-panel {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 14px;
        }
        .dd-title { margin: 0 0 12px; font-size: 22px; color: #f8fafc; }
        .dd-subtitle { margin: 0 0 10px; font-size: 16px; color: #f1f5f9; }
        .dd-muted { font-size: 12px; color: #94a3b8; margin: 8px 0 0; }
        .dd-warning { font-size: 12px; margin: 8px 0 0; }

        .dd-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            align-items: end;
        }
        .dd-filters label { display: block; font-size: 12px; color: #cbd5e1; margin-bottom: 4px; }
        .dd-filters input, .dd-filters select, .dd-filters button {
            width: 100%;
            min-height: 36px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #020617;
            color: #f8fafc;
            padding: 8px 10px;
        }
        .dd-filters button { background: #1d4ed8; border-color: #2563eb; font-weight: 600; cursor: pointer; }

        .dd-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .dd-kpi-card { background: #111827; border: 1px solid #334155; border-radius: 10px; padding: 14px; }
        .dd-kpi-label { margin: 0 0 8px; font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
        .dd-kpi-value { margin: 0; font-size: 26px; color: #f8fafc; font-weight: 700; }

        .dd-table-wrap { overflow-x: auto; border: 1px solid #334155; border-radius: 8px; }
        .dd-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .dd-table thead th {
            position: sticky;
            top: 0;
            background: #1e293b;
            color: #cbd5e1;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.03em;
            padding: 10px 12px;
            border-bottom: 1px solid #334155;
            z-index: 1;
        }
        .dd-table tbody tr:hover { background: rgba(30, 41, 59, 0.55); }
        .dd-link { color: #93c5fd; text-decoration: none; }
    </style>

    <h1 class="dd-title">Data Dialling Reporting Dashboard</h1>

    <section class="dd-section dd-panel">
        <form method="GET" action="{{ route('reports.data-dialling-dashboard') }}" class="dd-filters">
            <div>
                <label for="range">Date Range</label>
                <select id="range" name="range"><option value="today" @selected($rangeType==='today')>Today</option><option value="yesterday" @selected($rangeType==='yesterday')>Yesterday</option><option value="this_week" @selected($rangeType==='this_week')>This Week</option><option value="this_month" @selected($rangeType==='this_month')>This Month</option><option value="last_30_days" @selected($rangeType==='last_30_days')>Last 30 Days</option><option value="all_time" @selected($rangeType==='all_time')>All Time</option><option value="custom" @selected($rangeType==='custom')>Custom Date Range</option></select>
            </div>
            <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
            <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
            <div><label for="campaign_id">Campaign ID</label><input id="campaign_id" type="text" name="campaign_id" placeholder="campaign_id" value="{{ request('campaign_id') }}"></div>
            <div><label for="list_id">List ID</label><input id="list_id" type="text" name="list_id" placeholder="list_id" value="{{ request('list_id') }}"></div>
            <div><label for="dial_mode">Dial Mode</label><select id="dial_mode" name="dial_mode"><option value="all" @selected(request('dial_mode','all')==='all')>All Dial Modes</option><option value="manual" @selected(request('dial_mode')==='manual')>Manual</option><option value="autodial" @selected(request('dial_mode')==='autodial')>Autodial</option></select></div>
            <div><label>&nbsp;</label><button id="refreshStatsBtn" type="submit">Refresh Stats</button></div>
        </form>

        <p class="dd-muted">Last refreshed: {{ $lastRefreshedAt->toDateTimeString() }}</p>
        @if(!empty($rangeError))
            <p class="dd-warning" style="color:#fca5a5;">{{ $rangeError }}</p>
        @endif
        @if(!empty($dialModeMeta['message']))
            <p class="dd-warning" style="color:#fbbf24;">{{ $dialModeMeta['message'] }}</p>
        @endif
    </section>

    <section class="dd-section">
        <div class="dd-kpis">
            <article class="dd-kpi-card"><p class="dd-kpi-label">Total Calls</p><p class="dd-kpi-value">{{ number_format($kpi['total_calls']) }}</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Unique Leads Dialled</p><p class="dd-kpi-value">{{ number_format($kpi['unique_leads_dialled']) }}</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Overall Contact Rate %</p><p class="dd-kpi-value">{{ number_format($kpi['overall_contact_rate'], 2) }}%</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Overall Sale Rate %</p><p class="dd-kpi-value">{{ number_format($kpi['overall_sale_rate'], 2) }}%</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Overall Transfer Rate %</p><p class="dd-kpi-value">{{ number_format($kpi['overall_transfer_rate'], 2) }}%</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Overall Answer Machine Rate %</p><p class="dd-kpi-value">{{ number_format($kpi['overall_answer_machine_rate'], 2) }}%</p></article>
            <article class="dd-kpi-card"><p class="dd-kpi-label">Overall No Answer Rate %</p><p class="dd-kpi-value">{{ number_format($kpi['overall_no_answer_rate'], 2) }}%</p></article>
        </div>
    </section>

    <section class="dd-section dd-panel">
        <h2 class="dd-subtitle">List Overview</h2>
        <div class="dd-table-wrap">
            <table class="dd-table">
                <thead><tr><th style="text-align:right;">list_id</th><th style="text-align:left;min-width:220px;">list_name</th><th style="text-align:right;">campaign_id</th><th style="text-align:right;">total_leads_in_list</th><th style="text-align:right;">calls_in_selected_period</th><th style="text-align:right;">unique_leads_dialled</th><th style="text-align:right;">average_attempts_per_dialled_lead</th><th style="text-align:right;">contact_rate_%</th><th style="text-align:right;">sale_rate_%</th><th style="text-align:right;">transfer_rate_%</th><th style="text-align:left;">detail</th></tr></thead>
                <tbody>
                @foreach($summary as $row)
                    <tr>
                        <td style="{{ $numericCellStyle }}">{{ $row->list_id }}</td>
                        <td style="{{ $textCellStyle }}min-width:220px;">{{ $row->list_name }}</td>
                        <td style="{{ $numericCellStyle }}">{{ $row->campaign_id }}</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((int) $row->total_leads_in_list) }}</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((int) $row->calls_in_selected_period) }}</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((int) $row->unique_leads_dialled) }}</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((float) $row->average_attempts_per_dialled_lead, 2) }}</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((float) $row->contact_rate_percent, 2) }}%</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((float) $row->sale_rate_percent, 2) }}%</td>
                        <td style="{{ $numericCellStyle }}">{{ number_format((float) $row->transfer_rate_percent, 2) }}%</td>
                        <td style="{{ $textCellStyle }}"><a class="dd-link" href="{{ route('reports.data-dialling-dashboard', array_merge(request()->query(), ['detail_list_id' => $row->list_id])) }}">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="dd-section dd-panel">
        <h2 class="dd-subtitle">Key Outcome Statuses</h2>
        <div class="dd-table-wrap">
            <table class="dd-table">
                <thead><tr><th style="text-align:right;">list_id</th><th style="text-align:left;min-width:220px;">list_name</th>@foreach($keyOutcomeStatuses as $status)<th style="text-align:right;">{{ $status }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($summary as $row)
                    <tr>
                        <td style="{{ $numericCellStyle }}">{{ $row->list_id }}</td>
                        <td style="{{ $textCellStyle }}min-width:220px;">{{ $row->list_name }}</td>
                        @foreach($keyOutcomeStatuses as $status)
                            <td style="{{ $numericCellStyle }}">{{ number_format((int) ($row->{'status_'.$status} ?? 0)) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="dd-section dd-panel">
        <h2 class="dd-subtitle">Other Statuses</h2>
        <div class="dd-table-wrap">
            <table class="dd-table">
                <thead><tr><th style="text-align:right;">list_id</th><th style="text-align:left;min-width:220px;">list_name</th>@foreach($otherStatuses as $status)<th style="text-align:right;">{{ $status }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($summary as $row)
                    <tr>
                        <td style="{{ $numericCellStyle }}">{{ $row->list_id }}</td>
                        <td style="{{ $textCellStyle }}min-width:220px;">{{ $row->list_name }}</td>
                        @foreach($otherStatuses as $status)
                            <td style="{{ $numericCellStyle }}">{{ number_format((int) ($row->{'status_'.$status} ?? 0)) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if($drilldown)
        <section class="dd-section dd-panel">
            <h2 class="dd-subtitle">List {{ request('detail_list_id') }} Drilldown</h2>
            <div class="dd-table-wrap">
                <table class="dd-table">
                    <thead><tr><th style="text-align:right;">lead_id</th><th style="text-align:left;">phone_number</th><th style="text-align:left;">current status</th><th style="text-align:right;">attempt count</th><th style="text-align:left;">last dial date</th><th style="text-align:left;">agent/user</th><th style="text-align:right;">call duration</th><th style="text-align:left;">recording link</th><th style="text-align:left;">sold flag</th><th style="text-align:left;">transfer flag</th></tr></thead>
                    <tbody>@foreach($drilldown as $d)<tr><td style="{{ $numericCellStyle }}">{{ $d->lead_id }}</td><td style="{{ $textCellStyle }}">{{ $d->phone_number }}</td><td style="{{ $textCellStyle }}">{{ $d->current_status }}</td><td style="{{ $numericCellStyle }}">{{ $d->attempt_count }}</td><td style="{{ $textCellStyle }}">{{ $d->last_dial_date }}</td><td style="{{ $textCellStyle }}">{{ $d->agent_user }}</td><td style="{{ $numericCellStyle }}">{{ $d->call_duration }}</td><td style="{{ $textCellStyle }}">@if($d->recording_link)<a class="dd-link" href="{{ $d->recording_link }}" target="_blank">Recording</a>@endif</td><td style="{{ $textCellStyle }}">{{ (int)$d->sold_flag === 1 ? 'Yes' : 'No' }}</td><td style="{{ $textCellStyle }}">{{ (int)$d->transfer_flag === 1 ? 'Yes' : 'No' }}</td></tr>@endforeach</tbody>
                </table>
            </div>
            <div style="margin-top:12px;">{{ $drilldown->links() }}</div>
        </section>
    @endif

    <script>
        const btn = document.getElementById('refreshStatsBtn');
        btn?.form?.addEventListener('submit', () => {
            btn.disabled = true;
            btn.textContent = 'Refreshing...';
        });
    </script>
@endsection
