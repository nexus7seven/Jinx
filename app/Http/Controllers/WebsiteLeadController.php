<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebsiteLeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'details' => ['nullable', 'string'],
            // Honeypot field: real users never fill this.
            'website' => ['nullable', 'max:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $fullName = trim((string) preg_replace('/\s+/', ' ', $validated['full_name']));
        [$firstName, $lastName] = $this->splitName($fullName);

        $lead = Lead::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone_number' => trim($validated['phone']),
            'email' => $validated['email'] ?? null,
            'case_notes' => $validated['details'] ?? null,
            'source' => 'WEBSITE-CLEARMYCREDIT',
            'wip_status' => 'WIP',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead submitted successfully.',
            'lead_id' => $lead->id,
        ], 201);
    }

    private function splitName(string $fullName): array
    {
        if ($fullName === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $fullName, 2);

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
    }
}
