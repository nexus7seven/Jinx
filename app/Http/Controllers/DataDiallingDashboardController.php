<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class DataDiallingDashboardController extends Controller
{
    private const STATUSES = ['A','AA','AB','ADC','AIS','CALLBK','CBHOLD','CHUP','DC','DROP','ERI','N','NA','NEW','NI','NODEBT','NP','PDROP','PU','REM','SALE','WIP','WRN','XFER'];

    private const CONTACT_STATUSES = ['A', 'AB', 'CALLBK', 'CBHOLD', 'REM', 'SALE', 'WIP', 'XFER'];

    public function index(Request $request): View
    {
        [$from, $to, $rangeType, $rangeError] = $this->resolveRange($request);
        $campaignId = trim((string) $request->query('campaign_id', ''));
        $listId = trim((string) $request->query('list_id', ''));
        $dialMode = trim((string) $request->query('dial_mode', 'all'));
        $dialModeMeta = $this->resolveDialModeMeta($from, $to);
        $effectiveDialMode = $dialModeMeta['is_available'] ? $dialMode : 'all';

        $baseLogs = DB::connection(config('services.vicidial.db_connection'))
            ->table('vicidial_log as vl')
            ->join('vicidial_list as l', 'l.lead_id', '=', 'vl.lead_id')
            ->join('vicidial_lists as ls', 'ls.list_id', '=', 'l.list_id')
            ->when($from !== null, fn ($q) => $q->whereBetween('vl.call_date', [$from, $to]))
            ->when($campaignId !== '', fn ($q) => $q->where('ls.campaign_id', $campaignId))
            ->when($listId !== '', fn ($q) => $q->where('ls.list_id', $listId))
            ->leftJoin('vicidial_carrier_log as vcl', 'vcl.uniqueid', '=', 'vl.uniqueid');

        $this->applyDialModeFilter($baseLogs, $effectiveDialMode, $dialModeMeta['manual_prefix'], $dialModeMeta['autodial_prefix']);

        $statusSelect = [];
        foreach (self::STATUSES as $status) {
            $statusSelect[] = "SUM(CASE WHEN vl.status = '{$status}' THEN 1 ELSE 0 END) as status_{$status}";
        }

        $summary = (clone $baseLogs)
            ->selectRaw('ls.list_id, ls.list_name, ls.campaign_id, COUNT(vl.uniqueid) as calls_in_selected_period, COUNT(DISTINCT vl.lead_id) as unique_leads_dialled')
            ->selectRaw(implode(', ', $statusSelect))
            ->selectRaw("COUNT(DISTINCT CASE WHEN vl.status = 'SALE' OR l.status = 'SALE' OR l.is_sold IN ('1', 1, 'Y', 'y') THEN vl.lead_id ELSE NULL END) as sold_leads_by_vicidial")
            ->groupBy('ls.list_id', 'ls.list_name', 'ls.campaign_id')
            ->orderBy('ls.list_id')
            ->get();

        $combinedSoldLeadsByList = $this->combinedSoldLeadsByList($from, $to, $campaignId, $listId, $effectiveDialMode, $dialModeMeta);

        $leadTotals = DB::connection(config('services.vicidial.db_connection'))
            ->table('vicidial_list as l')
            ->join('vicidial_lists as ls', 'ls.list_id', '=', 'l.list_id')
            ->when($campaignId !== '', fn ($q) => $q->where('ls.campaign_id', $campaignId))
            ->when($listId !== '', fn ($q) => $q->where('ls.list_id', $listId))
            ->selectRaw('ls.list_id, COUNT(l.lead_id) as total_leads_in_list')
            ->groupBy('ls.list_id')
            ->pluck('total_leads_in_list', 'list_id');

        foreach ($summary as $row) {
            $row->total_leads_in_list = (int) ($leadTotals[$row->list_id] ?? 0);
            $row->average_attempts_per_dialled_lead = (int) $row->unique_leads_dialled > 0 ? round(((int) $row->calls_in_selected_period / (int) $row->unique_leads_dialled), 2) : 0.0;
            $contactCalls = 0;
            foreach (self::CONTACT_STATUSES as $status) {
                $contactCalls += (int) ($row->{'status_'.$status} ?? 0);
            }

            // contact_rate_percent = (A + AB + CALLBK + CBHOLD + REM + SALE + WIP + XFER) / total calls * 100
            $row->contact_rate_percent = (int) $row->calls_in_selected_period > 0 ? round(($contactCalls / (int) $row->calls_in_selected_period) * 100, 2) : 0.0;
            $soldByCombinedLogic = (int) ($combinedSoldLeadsByList[$row->list_id] ?? 0);
            $row->sold_leads_combined = $soldByCombinedLogic;
            // sale_rate_percent = distinct sold leads (combined sale logic) / unique dialled leads * 100
            $row->sale_rate_percent = (int) $row->unique_leads_dialled > 0 ? round(($soldByCombinedLogic / (int) $row->unique_leads_dialled) * 100, 2) : 0.0;
            // transfer_rate_percent = XFER outcomes / total calls * 100
            $row->transfer_rate_percent = (int) $row->calls_in_selected_period > 0 ? round(((int) $row->status_XFER / (int) $row->calls_in_selected_period) * 100, 2) : 0.0;
            // answer_machine_rate_percent = status AA / total calls * 100
            $row->answer_machine_rate_percent = (int) $row->calls_in_selected_period > 0 ? round(((int) $row->status_AA / (int) $row->calls_in_selected_period) * 100, 2) : 0.0;
            // no_answer_rate_percent = status NA / total calls * 100
            $row->no_answer_rate_percent = (int) $row->calls_in_selected_period > 0 ? round(((int) $row->status_NA / (int) $row->calls_in_selected_period) * 100, 2) : 0.0;
        }

        $drilldown = $this->drilldown($request, $from, $to, $campaignId, $effectiveDialMode, $dialModeMeta);

        return view('reports.data-dialling-dashboard', [
            'summary' => $summary,
            'statuses' => self::STATUSES,
            'rangeType' => $rangeType,
            'from' => $from,
            'to' => $to,
            'rangeError' => $rangeError,
            'dialModeMeta' => $dialModeMeta,
            'drilldown' => $drilldown,
            'lastRefreshedAt' => now(),
        ]);
    }

    private function drilldown(Request $request, ?Carbon $from, ?Carbon $to, string $campaignId, string $dialMode, array $dialModeMeta): ?LengthAwarePaginator
    {
        $selectedList = trim((string) $request->query('detail_list_id', ''));
        if ($selectedList === '') {
            return null;
        }

        $recordingSub = DB::connection(config('services.vicidial.db_connection'))
            ->table('recording_log as rr')
            ->selectRaw('rr.lead_id, MAX(rr.recording_id) as max_recording_id')
            ->groupBy('rr.lead_id');

        $query = DB::connection(config('services.vicidial.db_connection'))
            ->table('vicidial_list as l')
            ->leftJoin('vicidial_log as vl', 'vl.lead_id', '=', 'l.lead_id')
            ->leftJoin('vicidial_carrier_log as vcl', 'vcl.uniqueid', '=', 'vl.uniqueid')
            ->leftJoin('vicidial_lists as ls', 'ls.list_id', '=', 'l.list_id')
            ->leftJoinSub($recordingSub, 'rmax', fn ($join) => $join->on('rmax.lead_id', '=', 'l.lead_id'))
            ->leftJoin('recording_log as rl', function ($join) {
                $join->on('rl.lead_id', '=', 'rmax.lead_id')
                    ->on('rl.recording_id', '=', 'rmax.max_recording_id');
            })
            ->when($from !== null, fn ($q) => $q->whereBetween('vl.call_date', [$from, $to]))
            ->where('l.list_id', $selectedList)
            ->when($campaignId !== '', fn ($q) => $q->where('ls.campaign_id', $campaignId));

        $this->applyDialModeFilter($query, $dialMode, $dialModeMeta['manual_prefix'], $dialModeMeta['autodial_prefix']);

        $rows = $query->selectRaw("l.lead_id, l.phone_number, l.status as current_status, COUNT(DISTINCT vl.uniqueid) as attempt_count, MAX(vl.call_date) as last_dial_date, MAX(vl.user) as agent_user, MAX(vl.length_in_sec) as call_duration, MAX(rl.location) as recording_link, MAX(CASE WHEN l.status = 'XFER' THEN 1 ELSE 0 END) as transfer_flag, MAX(CASE WHEN vl.status = 'SALE' OR l.status = 'SALE' OR l.is_sold IN ('1', 1, 'Y', 'y') THEN 1 ELSE 0 END) as sold_flag_vicidial")
            ->groupBy('l.lead_id', 'l.phone_number', 'l.status')
            ->orderByDesc(DB::raw('MAX(vl.call_date)'))
            ->paginate(50)
            ->withQueryString();

        $jinxSoldByVicidialId = DB::table('leads')
            ->whereIn('vicidial_lead_id', $rows->pluck('lead_id')->map(fn ($id) => (string) $id)->all())
            ->whereRaw('LOWER(COALESCE(status, "")) = ?', ['sale'])
            ->pluck('vicidial_lead_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $rows->getCollection()->transform(function ($row) use ($jinxSoldByVicidialId) {
            $isJinxSale = isset($jinxSoldByVicidialId[(int) $row->lead_id]);
            $row->sold_flag = ((int) $row->sold_flag_vicidial === 1 || $isJinxSale) ? 1 : 0;

            return $row;
        });

        return $rows;
    }

    private function resolveRange(Request $request): array
    {
        $rangeType = (string) $request->query('range', 'today');
        $now = now();

        return match ($rangeType) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), $rangeType],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay(), $rangeType],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), $rangeType],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), $rangeType],
            'all_time' => [null, null, $rangeType],
            'custom' => $this->safeCustomRange($request),
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today', null],
        };
    }

    private function safeCustomRange(Request $request): array
    {
        $now = now();
        $fromInput = trim((string) $request->query('from', ''));
        $toInput = trim((string) $request->query('to', ''));

        if ($fromInput === '' || $toInput === '') {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today', 'Custom range missing from/to. Fell back to Today.'];
        }

        try {
            $from = Carbon::parse($fromInput)->startOfDay();
            $to = Carbon::parse($toInput)->endOfDay();
            if ($from->gt($to)) {
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today', 'Custom range has from after to. Fell back to Today.'];
            }

            return [$from, $to, 'custom', null];
        } catch (Throwable) {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today', 'Custom range is invalid. Fell back to Today.'];
        }
    }

    private function resolveDialModeMeta(?Carbon $from, ?Carbon $to): array
    {
        $meta = ['is_available' => false, 'manual_prefix' => null, 'autodial_prefix' => null, 'message' => 'Dial mode filter unavailable: M/V caller_code pattern could not be confirmed.'];
        try {
            $rows = DB::connection(config('services.vicidial.db_connection'))
                ->table('vicidial_carrier_log as vcl')
                ->join('vicidial_log as vl', 'vl.uniqueid', '=', 'vcl.uniqueid')
                ->when($from !== null, fn ($q) => $q->whereBetween('vl.call_date', [$from, $to]))
                ->selectRaw('UPPER(SUBSTRING(vcl.caller_code,1,1)) as pfx, COUNT(*) as c')
                ->whereNotNull('vcl.caller_code')
                ->groupBy('pfx')
                ->get();

            $prefixes = $rows->pluck('pfx')->filter()->values()->all();
            if (in_array('M', $prefixes, true) && in_array('V', $prefixes, true)) {
                $meta['is_available'] = true;
                $meta['manual_prefix'] = 'M';
                $meta['autodial_prefix'] = 'V';
                $meta['message'] = null;
            }
        } catch (Throwable) {
            // Leave dial mode unavailable when table/columns are not readable.
        }

        return $meta;
    }

    private function applyDialModeFilter($query, string $dialMode, ?string $manualPrefix, ?string $autodialPrefix): void
    {
        if ($dialMode === 'manual' && $manualPrefix !== null) {
            $query->whereRaw('UPPER(SUBSTRING(COALESCE(vcl.caller_code, ""),1,1)) = ?', [$manualPrefix]);
        }

        if ($dialMode === 'autodial' && $autodialPrefix !== null) {
            $query->whereRaw('UPPER(SUBSTRING(COALESCE(vcl.caller_code, ""),1,1)) = ?', [$autodialPrefix]);
        }
    }

    private function combinedSoldLeadsByList(?Carbon $from, ?Carbon $to, string $campaignId, string $listId, string $dialMode, array $dialModeMeta): array
    {
        $connection = config('services.vicidial.db_connection');

        $jinxSoldLeadIds = DB::table('leads')
            ->whereRaw('LOWER(COALESCE(status, "")) = ?', ['sale'])
            ->whereNotNull('vicidial_lead_id')
            ->pluck('vicidial_lead_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $byVicidialSignals = DB::connection($connection)
            ->table('vicidial_list as l')
            ->leftJoin('vicidial_lists as ls', 'ls.list_id', '=', 'l.list_id')
            ->leftJoin('vicidial_log as vl', 'vl.lead_id', '=', 'l.lead_id')
            ->leftJoin('vicidial_carrier_log as vcl', 'vcl.uniqueid', '=', 'vl.uniqueid')
            ->when($campaignId !== '', fn ($x) => $x->where('ls.campaign_id', $campaignId))
            ->when($listId !== '', fn ($x) => $x->where('ls.list_id', $listId))
            ->when($from !== null, fn ($x) => $x->whereBetween('vl.call_date', [$from, $to]))
            ->where(function ($q) {
                $q->where('vl.status', 'SALE')
                    ->orWhere('l.status', 'SALE')
                    ->orWhereIn('l.is_sold', ['1', 1, 'Y', 'y']);
            });

        $this->applyDialModeFilter($byVicidialSignals, $dialMode, $dialModeMeta['manual_prefix'], $dialModeMeta['autodial_prefix']);

        $soldLeadListPairs = $byVicidialSignals
            ->selectRaw('DISTINCT l.list_id, l.lead_id')
            ->get();

        $soldPairs = [];
        foreach ($soldLeadListPairs as $pair) {
            $listKey = (string) $pair->list_id;
            $leadKey = (int) $pair->lead_id;
            if ($listKey === '' || $leadKey <= 0) {
                continue;
            }
            $soldPairs[$listKey.'|'.$leadKey] = true;
        }

        if ($jinxSoldLeadIds !== []) {
            $jinxSignal = DB::connection($connection)
                ->table('vicidial_list as l')
                ->leftJoin('vicidial_lists as ls', 'ls.list_id', '=', 'l.list_id')
                ->leftJoin('vicidial_log as vl', 'vl.lead_id', '=', 'l.lead_id')
                ->leftJoin('vicidial_carrier_log as vcl', 'vcl.uniqueid', '=', 'vl.uniqueid')
                ->whereIn('l.lead_id', $jinxSoldLeadIds)
                ->when($campaignId !== '', fn ($x) => $x->where('ls.campaign_id', $campaignId))
                ->when($listId !== '', fn ($x) => $x->where('ls.list_id', $listId))
                ->when($from !== null, fn ($x) => $x->whereBetween('vl.call_date', [$from, $to]));

            $this->applyDialModeFilter($jinxSignal, $dialMode, $dialModeMeta['manual_prefix'], $dialModeMeta['autodial_prefix']);

            $jinxPairs = $jinxSignal->selectRaw('DISTINCT l.list_id, l.lead_id')->get();
            foreach ($jinxPairs as $pair) {
                $listKey = (string) $pair->list_id;
                $leadKey = (int) $pair->lead_id;
                if ($listKey === '' || $leadKey <= 0) {
                    continue;
                }
                $soldPairs[$listKey.'|'.$leadKey] = true;
            }
        }

        $counts = [];
        foreach (array_keys($soldPairs) as $k) {
            [$listKey, $leadKey] = explode('|', $k, 2);
            $counts[$listKey] = ($counts[$listKey] ?? 0) + 1;
        }

        return $counts;
    }
}
