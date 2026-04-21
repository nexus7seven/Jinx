<?php

namespace App\Http\Controllers;

use App\Models\LocalBrowserJob;
use Illuminate\Http\Request;

class LocalWorkerJobsPageController extends Controller
{
    public function index()
    {
        $jobs = LocalBrowserJob::query()
            ->orderByDesc('id')
            ->paginate(50);

        return view('local-worker.jobs.index', compact('jobs'));
    }

    public function show(LocalBrowserJob $job)
    {
        $job->load('artifacts');

        return view('local-worker.jobs.show', compact('job'));
    }

    public function submitInput(Request $request, LocalBrowserJob $job)
    {
        $providedInputPayload = $this->buildProvidedInputPayload($request, $job);

        $job->fill([
            'provided_input_payload' => $providedInputPayload,
            'status' => 'running',
            'awaiting_input_type' => null,
            'awaiting_input_payload' => null,
            'heartbeat_at' => now(),
            'progress_message' => 'Input provided from internal UI',
        ]);
        $job->save();

        return redirect()
            ->route('local-worker.jobs.show', $job)
            ->with('status', 'Input submitted.');
    }

    private function buildProvidedInputPayload(Request $request, LocalBrowserJob $job): array
    {
        $type = (string) ($job->awaiting_input_type ?? '');

        if ($type === 'identity_questions') {
            $validated = $request->validate([
                'selected_option' => ['required', 'string', 'max:255'],
            ]);

            return [
                'selected_option' => $validated['selected_option'],
            ];
        }

        if ($type === 'email_code') {
            $validated = $request->validate([
                'code' => ['required', 'string', 'max:255'],
            ]);

            return [
                'code' => $validated['code'],
            ];
        }

        if ($type === 'manual_pause') {
            $validated = $request->validate([
                'note' => ['required', 'string'],
            ]);

            return [
                'note' => $validated['note'],
            ];
        }

        $validated = $request->validate([
            'provided_input_json' => ['required', 'string'],
        ]);
        $decoded = json_decode($validated['provided_input_json'], true);

        if (! is_array($decoded)) {
            abort(422, 'Fallback JSON input must decode to an object/array.');
        }

        return $decoded;
    }
}
