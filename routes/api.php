<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Lead;

Route::post('/vicidial/create-or-open-case', function (Request $request) {

    $vicidialLeadId = $request->input('vicidial_lead_id');
    $phone = $request->input('phone_number');

    // 1. Try find by VICIdial lead ID
    $lead = Lead::where('vicidial_lead_id', $vicidialLeadId)->first();

    if ($lead) {
        return response()->json([
            'success' => true,
            'action' => 'opened',
            'lead_id' => $lead->id,
            'url' => url('/lead/' . $lead->id)
        ]);
    }

    // 2. Try find by phone number
    if ($phone) {
        $lead = Lead::where('phone_number', $phone)->first();

        if ($lead) {
            return response()->json([
                'success' => true,
                'action' => 'matched_by_phone',
                'lead_id' => $lead->id,
                'url' => url('/lead/' . $lead->id)
            ]);
        }
    }

    // 3. Create new lead
    $lead = Lead::create([
        'vicidial_lead_id' => $vicidialLeadId,
        'phone_number' => $phone,
        'first_name' => $request->input('first_name'),
        'last_name' => $request->input('last_name'),
        'email' => $request->input('email'),
        'house_number' => $request->input('house_number'),
        'postcode' => $request->input('postcode'),
        'address_line_1' => $request->input('address_line_1'),
    ]);

    return response()->json([
        'success' => true,
        'action' => 'created',
        'lead_id' => $lead->id,
        'url' => url('/lead/' . $lead->id)
    ]);
});