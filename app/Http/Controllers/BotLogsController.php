<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function reply(Request $request): JsonResponse
    {
        $full = (int) config('bot-monitor.owner_user_id', 0) > 0 && (int) auth()->id() === (int) config('bot-monitor.owner_user_id');
        if (! $full) {
            return response()->json(['accepted' => false, 'reason' => 'owner_only'], 403)->header('Cache-Control', 'no-store');
        }
        $caseId = (int) $request->input('case_id');
        $noticeId = (int) $request->input('notice_id');
        $answer = trim((string) $request->input('answer'));
        if ($caseId < 1 || $noticeId < 1 || strlen($answer) < 2 || strlen($answer) > 500) {
            return response()->json(['accepted' => false, 'reason' => 'invalid_reply'], 422)->header('Cache-Control', 'no-store');
        }
        $snapshot = $this->snapshot(true);
        $card = null;
        foreach (($snapshot['work_queue']['cards'] ?? []) as $row) {
            if ((int) ($row['case_id'] ?? 0) === $caseId && (int) ($row['notice_id'] ?? 0) === $noticeId) {
                $card = $row;
                break;
            }
        }
        if (! is_array($card) || empty($card['notice_message'])) {
            return response()->json(['accepted' => false, 'reason' => 'card_not_current'], 409)->header('Cache-Control', 'no-store');
        }
        $payload = json_encode([
            'case_id' => $caseId,
            'notice_id' => $noticeId,
            'notice_message' => $card['notice_message'],
            'answer' => $answer,
            'remember' => $request->boolean('remember'),
            'activity_id' => 'ui:'.$caseId.':'.$noticeId.':'.sha1($answer),
        ], JSON_THROW_ON_ERROR);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['/usr/bin/sudo', '-n', '/opt/pacman-v2-work/scripts/pacman_work_queue_reply'], $spec, $pipes, '/opt/pacman-v2-work', ['PYTHONDONTWRITEBYTECODE' => '1']);
        if (! is_resource($proc)) {
            return response()->json(['accepted' => false, 'reason' => 'reply_unavailable'], 503)->header('Cache-Control', 'no-store');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $result = json_decode((string) $stdout, true);
        if (! is_array($result)) {
            return response()->json(['accepted' => false, 'reason' => 'reply_rejected'], 422)->header('Cache-Control', 'no-store');
        }
        if ($code !== 0 || ($result['accepted'] ?? false) !== true) {
            return response()->json(['accepted' => false, 'reason' => $result['reason'] ?? 'reply_rejected'], 422)->header('Cache-Control', 'no-store');
        }
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
