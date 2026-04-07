<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use Illuminate\Http\Request;

class DebtController extends Controller
{
    public function show(Debt $debt)
    {
        $debt->load(['creditor']);

        return response()->json([
            'id' => $debt->id,
            'creditor_id' => $debt->creditor_id,
            'creditor_name' => $debt->creditor?->name,
            'balance' => (float) $debt->balance,
            'source_expected' => $debt->source_expected,
            'reference' => $debt->reference,
        ]);
    }

    public function update(Request $request, Debt $debt)
    {
        $validated = $request->validate([
            'creditor_id' => ['required', 'integer', 'exists:creditors,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'source_expected' => ['required', 'in:credit_check,3wc,screenshot_pdf,live_chat,other'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $debt->update($validated);

        // Reload relationships for response
        $debt->load(['creditor', 'document']);

        return response()->json([
            'success' => true,
            'debt' => [
                'id' => $debt->id,
                'creditor_id' => $debt->creditor_id,
                'creditor_name' => $debt->creditor->name,
                'balance' => (float) $debt->balance,
                'reference' => $debt->reference,
                'source_expected' => $debt->source_expected,
                'voting_house' => $debt->creditor->voting_house,
                'voting_practice1' => $debt->creditor->voting_practice1,
                'voting_practice2' => $debt->creditor->voting_practice2,
                'voting_practice3' => $debt->creditor->voting_practice3,
                'document_complete' => (bool) ($debt->document?->is_complete),
            ],
        ]);
    }
}