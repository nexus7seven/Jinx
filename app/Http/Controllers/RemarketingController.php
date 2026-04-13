<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RemarketingController extends Controller
{
    public function index()
    {
        $stages = ['all', 'fresh', 'cooling', 'cold', 'dormant'];

        return view('remarketing.index', [
            'stages' => $stages,
        ]);
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'task_type' => ['required', 'in:call,whatsapp'],
            'lead_name' => ['required', 'string', 'max:255'],
        ]);

        Log::info('Remarketing task completed', [
            'task_type' => $validated['task_type'],
            'lead_name' => $validated['lead_name'],
        ]);

        return redirect()
            ->route('remarketing.index')
            ->with('success', 'Task marked complete.');
    }
}
