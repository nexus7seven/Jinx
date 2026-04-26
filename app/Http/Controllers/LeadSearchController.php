<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';

        $results = Lead::query()
            ->where(function ($builder) use ($like) {
                $builder
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('vicidial_lead_id', 'like', $like);
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'phone_number', 'vicidial_lead_id'])
            ->map(function (Lead $lead) {
                $name = trim((string) ($lead->first_name ?? '') . ' ' . (string) ($lead->last_name ?? ''));

                return [
                    'id' => $lead->id,
                    'name' => $name !== '' ? $name : ('Lead #' . $lead->id),
                    'phone_number' => (string) ($lead->phone_number ?? ''),
                    'vicidial_lead_id' => (string) ($lead->vicidial_lead_id ?? ''),
                    'url' => url('/lead/' . $lead->id),
                ];
            })
            ->values()
            ->all();

        return response()->json(['results' => $results]);
    }
}
