<?php

namespace App\Http\Controllers;

use App\Models\LocalBrowserJob;
use App\Services\LocalWorkerTempMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LocalWorkerJobController extends Controller
{
    public function __construct(
        private LocalWorkerTempMailService $localWorkerTempMailService,
    ) {
    }

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
                'awaiting_input_type' => null,
                'awaiting_input_payload' => null,
                'provided_input_payload' => null,
                'claimed_by' => $validated['worker_id'],
                'claimed_at' => $now,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'current_step' => 'claimed',
                'progress_message' => 'Job claimed by worker',
            ]);
            $nextJob->save();

            return $nextJob->fresh();
        });

        return response()->json([
            'job' => $job,
        ]);
    }

    public function heartbeat(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'current_step' => ['nullable', 'string', 'max:255'],
            'progress_message' => ['nullable', 'string', 'max:255'],
        ]);

        $job->heartbeat_at = now();
        if ($job->status === 'claimed' || $job->status === 'running') {
            $job->status = 'running';
        }
        if (array_key_exists('current_step', $validated)) {
            $job->current_step = $validated['current_step'];
        }
        if (array_key_exists('progress_message', $validated)) {
            $job->progress_message = $validated['progress_message'];
        }
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function log(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
            'current_step' => ['nullable', 'string', 'max:255'],
            'progress_message' => ['nullable', 'string', 'max:255'],
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
        if (array_key_exists('current_step', $validated)) {
            $job->current_step = $validated['current_step'];
        }
        if (array_key_exists('progress_message', $validated)) {
            $job->progress_message = $validated['progress_message'];
        }
        if ($job->status === 'claimed') {
            $job->status = 'running';
        }
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function complete(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['nullable', 'array'],
            'current_step' => ['nullable', 'string', 'max:255'],
            'progress_message' => ['nullable', 'string', 'max:255'],
        ]);

        $job->fill([
            'status' => 'completed',
            'result_json' => $validated['result'] ?? null,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'error_text' => null,
            'current_step' => $validated['current_step'] ?? $job->current_step,
            'progress_message' => $validated['progress_message'] ?? $job->progress_message,
            'awaiting_input_type' => null,
            'awaiting_input_payload' => null,
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
            'current_step' => ['nullable', 'string', 'max:255'],
            'progress_message' => ['nullable', 'string', 'max:255'],
        ]);

        $job->fill([
            'status' => 'failed',
            'error_text' => $validated['error'],
            'result_json' => $validated['result'] ?? null,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'current_step' => $validated['current_step'] ?? $job->current_step,
            'progress_message' => $validated['progress_message'] ?? $job->progress_message,
            'awaiting_input_type' => null,
            'awaiting_input_payload' => null,
        ]);
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function artifact(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'type' => ['nullable', 'string', 'max:100'],
            'meta_json' => ['nullable'],
        ]);

        $file = $request->file('file');
        $type = (string) ($validated['type'] ?? 'file');
        $relativePath = 'local-browser-jobs/'.$job->id;
        $storedPath = $file->store($relativePath, 'public');

        $meta = null;
        if (array_key_exists('meta_json', $validated)) {
            $meta = $validated['meta_json'];

            if (is_string($meta) && $meta !== '') {
                $decoded = json_decode($meta, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $meta = $decoded;
                }
            }
        }

        $artifact = $job->artifacts()->create([
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'meta_json' => $meta,
        ]);

        return response()->json([
            'ok' => true,
            'artifact' => [
                'id' => $artifact->id,
                'path' => $artifact->path,
                'url' => Storage::disk('public')->url($artifact->path),
            ],
        ]);
    }

    public function awaitInput(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'awaiting_input_type' => ['required', 'string', 'max:100'],
            'awaiting_input_payload' => ['nullable', 'array'],
            'current_step' => ['nullable', 'string', 'max:255'],
            'progress_message' => ['nullable', 'string', 'max:255'],
        ]);

        $job->fill([
            'status' => 'awaiting_input',
            'awaiting_input_type' => $validated['awaiting_input_type'],
            'awaiting_input_payload' => $validated['awaiting_input_payload'] ?? null,
            'heartbeat_at' => now(),
            'current_step' => $validated['current_step'] ?? $job->current_step,
            'progress_message' => $validated['progress_message'] ?? $job->progress_message,
        ]);
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function provideInput(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $validated = $request->validate([
            'provided_input_payload' => ['required', 'array'],
        ]);

        $job->fill([
            'provided_input_payload' => $validated['provided_input_payload'],
            'status' => 'running',
            'awaiting_input_type' => null,
            'awaiting_input_payload' => null,
            'heartbeat_at' => now(),
            'progress_message' => 'Input provided by operator',
        ]);
        $job->save();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function show(LocalBrowserJob $job): JsonResponse
    {
        return response()->json([
            'job' => $job,
        ]);
    }

    public function generateTempEmail(LocalBrowserJob $job): JsonResponse
    {
        $result = $this->localWorkerTempMailService->generate($job);

        return response()->json([
            'ok' => true,
            'email' => $result['email'],
            'meta' => $result['meta'],
        ]);
    }

    public function tempEmail(LocalBrowserJob $job): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'email' => $job->temp_email_address,
            'meta' => $job->temp_email_meta_json,
            'latest_email_code' => $job->latest_email_code,
        ]);
    }

    public function latestCode(Request $request, LocalBrowserJob $job): JsonResponse
    {
        $result = $this->localWorkerTempMailService->latestCode(
            $job,
            $request->query('pattern')
        );

        return response()->json(array_merge([
            'ok' => true,
        ], $result));
    }
}
