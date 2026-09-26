<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class BotLogsController extends Controller
{
    private function snapshot(bool $full = false): ?array
    {
        $file = storage_path($full ? 'app/bot-monitor/overview-private.json' : 'app/bot-monitor/overview.json');
        if (! is_readable($file)) return null;
        $raw = file_get_contents($file);
        if ($raw === false || strlen($raw) > 2500000) return null;
        $data = json_decode($raw, true);
        return is_array($data) && ($data['schema'] ?? null) === 2 && isset($data['generated_at'], $data['bots'], $data['conversations'])
            && is_array($data['bots']) && is_array($data['conversations']) ? $data : null;
    }

    public function index(): View
    {
        return view('bot-logs.index');
    }

    public function data(): JsonResponse
    {
        // A general CRM login does not grant access to client/customer and private Alex messages.
        $full = (int) config('bot-monitor.owner_user_id', 0) > 0 && (int) auth()->id() === (int) config('bot-monitor.owner_user_id');
        $data = $this->snapshot($full);
        if ($data === null) return response()->json(['message' => 'Bot team monitor is unavailable.'], 503)
            ->header('Cache-Control', 'no-store');
        $data['full_access'] = $full;
        $data['stale'] = strtotime($data['generated_at']) < time() - 30;
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
