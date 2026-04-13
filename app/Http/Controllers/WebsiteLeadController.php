<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\VicidialLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebsiteLeadController extends Controller
{
    public function __construct(
        private VicidialLeadService $vicidialLeadService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'details' => ['nullable', 'string'],
            // Honeypot field: real users never fill this.
            'website' => ['nullable', 'max:0'],
        ]);

        $fullName = trim((string) preg_replace('/\s+/', ' ', $validated['full_name']));
        [$firstName, $lastName] = $this->splitName($fullName);

        $phone = trim($validated['phone']);
        $email = $validated['email'] ?? '';
        $details = $validated['details'] ?? '';

        DB::beginTransaction();

        try {
            $lead = Lead::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $phone,
                'email' => $validated['email'] ?? null,
                'case_notes' => $validated['details'] ?? null,
                'source' => Lead::websiteIntakeSourceId(),
                'wip_status' => Lead::defaultWipStatusForWebsiteIntake(),
                'vicidial_lead_id' => null,
            ]);

            $vicidialLeadId = $this->vicidialLeadService->createWebsiteLead([
                'first_name' => $firstName ?? '',
                'last_name' => $lastName ?? '',
                'phone_number' => $phone,
                'email' => $email,
                'comments' => $details,
            ]);

            $lead->update([
                'vicidial_lead_id' => $vicidialLeadId,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Lead could not be submitted. Please try again later.',
            ], 500);
        }

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
