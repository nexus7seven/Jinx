<?php

namespace App\Http\Controllers;

use App\Services\SetmoreSipAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SipAvailabilityController extends Controller
{
    public function index(): View
    {
        return view('sip-availability.index');
    }

    public function data(Request $request, SetmoreSipAvailabilityService $service): JsonResponse
    {
        $days = (int) $request->integer('days', 21);

        try {
            return response()->json($service->availability($days));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Setmore availability could not be loaded right now.'], 502);
        }
    }
}
