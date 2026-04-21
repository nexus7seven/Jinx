<?php

namespace App\Http\Controllers;

use App\Models\LocalBrowserJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalWorkerJobController extends Controller
{
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => ['required', 'string', 'max:255'],
        ]);

        $job = DB::transaction(function () use ($validated) {
            $nextJob = LocalBrowserJob::query()
                ->where('status', 'queued')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $nextJob) {
                return null;
            }

            $now = now();
            $nextJob->fill([
                'status' => 'claimed',
                'claimed_by' => $validated['worker_id'],
                'claimed_at' => $now,
                'started_at' => $now,
                'heartbeat_at' => $now,
            ]);
            $nextJob->save();

            return $nextJob->fresh();
        });

        return response()->json([
            'job' => $job,
        ]);
    }

    public function heartbeat(LocalBrowserJob $job): JsonResponse
    {
        $job->heartbeat_at = now();
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function log(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $result = $job->result_json;
        if (! is_array($result)) {
            $result = [];
        }

        $logs = $result['logs'] ?? [];
        if (! is_array($logs)) {
            $logs = [];
        }

        $logs[] = [
            'message' => $validated['message'],
            'created_at' => now()->toIso8601String(),
        ];

        $result['logs'] = $logs;
        $job->result_json = $result;
        $job->heartbeat_at = now();
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function complete(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['nullable', 'array'],
        ]);

        $job->fill([
            'status' => 'completed',
            'result_json' => $validated['result'] ?? null,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'error_text' => null,
        ]);
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function fail(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'error' => ['required', 'string'],
            'result' => ['nullable', 'array'],
        ]);

        $job->fill([
            'status' => 'failed',
            'error_text' => $validated['error'],
            'result_json' => $validated['result'] ?? null,
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }
}
