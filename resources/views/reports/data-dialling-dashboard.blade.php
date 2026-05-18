@extends('layouts.app')

@section('title', 'Data Dialling Dashboard')

@section('content')
    <h1 style="margin:0 0 12px;">Data Dialling Reporting Dashboard</h1>
    <form method="GET" action="{{ route('reports.data-dialling-dashboard') }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <select name="range"><option value="today" @selected($rangeType==='today')>Today</option><option value="yesterday" @selected($rangeType==='yesterday')>Yesterday</option><option value="this_week" @selected($rangeType==='this_week')>This Week</option><option value="this_month" @selected($rangeType==='this_month')>This Month</option><option value="last_30_days" @selected($rangeType==='last_30_days')>Last 30 Days</option><option value="all_time" @selected($rangeType==='all_time')>All Time</option><option value="custom" @selected($rangeType==='custom')>Custom Date Range</option></select>
        <input type="date" name="from" value="{{ request('from') }}">
        <input type="date" name="to" value="{{ request('to') }}">
        <input type="text" name="campaign_id" placeholder="campaign_id" value="{{ request('campaign_id') }}">
        <input type="text" name="list_id" placeholder="list_id" value="{{ request('list_id') }}">
        <select name="dial_mode"><option value="all" @selected(request('dial_mode','all')==='all')>All Dial Modes</option><option value="manual" @selected(request('dial_mode')==='manual')>Manual</option><option value="autodial" @selected(request('dial_mode')==='autodial')>Autodial</option></select>
        <button id="refreshStatsBtn" type="submit">Refresh Stats</button>
    </form>
    <p style="font-size:12px;color:#94a3b8;">Last refreshed: {{ $lastRefreshedAt->toDateTimeString() }}</p>

    @if(!empty($rangeError))
        <p style="color:#fca5a5;font-size:12px;margin:8px 0;">{{ $rangeError }}</p>
    @endif
    @if(!empty($dialModeMeta['message']))
        <p style="color:#fbbf24;font-size:12px;margin:8px 0;">{{ $dialModeMeta['message'] }}</p>
    @endif

    <div style="overflow:auto; border:1px solid #334155; border-radius:8px;">
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead><tr><th>list_id</th><th>list_name</th><th>campaign_id</th><th>total_leads_in_list</th><th>calls_in_selected_period</th><th>unique_leads_dialled</th><th>average_attempts_per_dialled_lead</th><th>contact_rate_%</th><th>sale_rate_%</th><th>transfer_rate_%</th><th>answer_machine_rate_%</th><th>no_answer_rate_%</th>@foreach($statuses as $status)<th>{{ $status }}</th>@endforeach<th>detail</th></tr></thead>
            <tbody>
            @foreach($summary as $row)
                <tr>
                    <td>{{ $row->list_id }}</td><td>{{ $row->list_name }}</td><td>{{ $row->campaign_id }}</td><td>{{ $row->total_leads_in_list }}</td><td>{{ $row->calls_in_selected_period }}</td><td>{{ $row->unique_leads_dialled }}</td><td>{{ $row->average_attempts_per_dialled_lead }}</td><td>{{ $row->contact_rate_percent }}</td><td>{{ $row->sale_rate_percent }}</td><td>{{ $row->transfer_rate_percent }}</td><td>{{ $row->answer_machine_rate_percent }}</td><td>{{ $row->no_answer_rate_percent }}</td>@foreach($statuses as $status)<td>{{ $row->{'status_'.$status} }}</td>@endforeach
                    <td><a href="{{ route('reports.data-dialling-dashboard', array_merge(request()->query(), ['detail_list_id' => $row->list_id])) }}">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($drilldown)
        <h2>List {{ request('detail_list_id') }} Drilldown</h2>
        <table style="width:100%;font-size:12px;">
            <thead><tr><th>lead_id</th><th>phone_number</th><th>current status</th><th>attempt count</th><th>last dial date</th><th>agent/user</th><th>call duration</th><th>recording link</th><th>sold flag</th><th>transfer flag</th></tr></thead>
            <tbody>@foreach($drilldown as $d)<tr><td>{{ $d->lead_id }}</td><td>{{ $d->phone_number }}</td><td>{{ $d->current_status }}</td><td>{{ $d->attempt_count }}</td><td>{{ $d->last_dial_date }}</td><td>{{ $d->agent_user }}</td><td>{{ $d->call_duration }}</td><td>@if($d->recording_link)<a href="{{ $d->recording_link }}" target="_blank">Recording</a>@endif</td><td>{{ (int)$d->sold_flag === 1 ? 'Yes' : 'No' }}</td><td>{{ (int)$d->transfer_flag === 1 ? 'Yes' : 'No' }}</td></tr>@endforeach</tbody>
        </table>
        {{ $drilldown->links() }}
    @endif

    <script>
        const btn = document.getElementById('refreshStatsBtn');
        btn?.form?.addEventListener('submit', () => {
            btn.disabled = true;
            btn.textContent = 'Refreshing...';
        });
    </script>
@endsection
