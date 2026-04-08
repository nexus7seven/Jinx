<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadActionPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadCaseController extends Controller
{
    public function updateCaseNotes(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'case_notes' => ['nullable', 'string'],
        ]);

        $lead->update([
            'case_notes' => $validated['case_notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'case_notes' => $lead->case_notes,
        ]);
    }

    public function storeActionPoint(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $item = LeadActionPoint::create([
            'lead_id' => $lead->id,
            'note' => trim($validated['note']),
        ]);

        return response()->json([
            'success' => true,
            'item' => $item,
        ]);
    }

    public function destroyActionPoint(Lead $lead, LeadActionPoint $item): JsonResponse
    {
        abort_unless($item->lead_id === $lead->id, 404);

        $item->delete();

        return response()->json([
            'success' => true,
        ]);
    }
}
