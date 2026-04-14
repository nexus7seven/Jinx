<?php

namespace App\Http\Controllers;

use App\Models\RemarketingTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RemarketingController extends Controller
{
    public function index(Request $request)
    {
        $stages = ['all', 'fresh', 'cooling', 'cold', 'dormant'];
        $selectedStage = $request->query('stage', 'all');
        if (!in_array($selectedStage, $stages, true)) {
            $selectedStage = 'all';
        }

        $tasksQuery = RemarketingTask::query()
            ->where('status', 'pending');

        if ($selectedStage !== 'all') {
            $tasksQuery->where('stage', $selectedStage);
        }

        $pendingTasks = $tasksQuery
            ->orderBy('id')
            ->get();

        $callTasks = $pendingTasks
            ->where('task_type', 'call')
            ->values()
            ->map(function (RemarketingTask $task) {
                return [
                    'id' => $task->id,
                    'lead_name' => $task->lead_name,
                    'phone' => $task->phone,
                    'reason' => $task->reason,
                    'time_waiting' => $task->time_waiting_text ?? 'Waiting',
                    'task_type' => $task->task_type,
                    'stage' => $task->stage,
                ];
            })
            ->all();

        $whatsappTasks = $pendingTasks
            ->where('task_type', 'whatsapp')
            ->values()
            ->map(function (RemarketingTask $task) {
                $phone = str_replace(' ', '', $task->phone);
                if (str_starts_with($phone, '0')) {
                    $phone = '44' . substr($phone, 1);
                }
                $message = 'Hi ' . $task->lead_name . ', just following up in case WhatsApp is easier for you.';
                $whatsappUrl = 'https://wa.me/' . $phone . '?text=' . urlencode($message);

                return [
                    'id' => $task->id,
                    'lead_name' => $task->lead_name,
                    'phone' => $task->phone,
                    'reason' => $task->reason,
                    'time_waiting' => $task->time_waiting_text ?? 'Waiting',
                    'task_type' => $task->task_type,
                    'stage' => $task->stage,
                    'whatsapp_url' => $whatsappUrl,
                ];
            })
            ->all();

        $recentActivity = [
            [
                'lead_name' => 'Jamie Patel',
                'activity' => 'Voicemail left after no answer',
                'time' => 'Just now',
            ],
            [
                'lead_name' => 'Taylor Quinn',
                'activity' => 'Read message, no reply yet',
                'time' => 'Yesterday',
            ],
        ];

        return view('remarketing.index', [
            'stages' => $stages,
            'selectedStage' => $selectedStage,
            'callTasks' => $callTasks,
            'whatsappTasks' => $whatsappTasks,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'task_type' => ['required', 'in:call,whatsapp'],
            'reason' => ['required', 'string', 'max:255'],
            'stage' => ['required', 'in:fresh,cooling,cold,dormant'],
            'time_waiting_text' => ['nullable', 'string', 'max:255'],
        ]);

        RemarketingTask::create([
            'lead_name' => $validated['lead_name'],
            'phone' => $validated['phone'],
            'task_type' => $validated['task_type'],
            'reason' => $validated['reason'],
            'stage' => $validated['stage'],
            'status' => 'pending',
            'time_waiting_text' => $validated['time_waiting_text'] ?? null,
        ]);

        return redirect()
            ->route('remarketing.index')
            ->with('success', 'Test task added.');
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'task_type' => ['required', 'in:call,whatsapp'],
            'lead_name' => ['required', 'string', 'max:255'],
            'current_stage' => ['nullable', 'in:all,fresh,cooling,cold,dormant'],
        ]);

        Log::info('Remarketing task completed', [
            'task_id' => $validated['task_id'],
            'task_type' => $validated['task_type'],
            'lead_name' => $validated['lead_name'],
        ]);

        $task = RemarketingTask::query()
            ->where('id', $validated['task_id'])
            ->where('status', 'pending')
            ->first();

        if ($task) {
            $task->status = 'completed';
            $task->save();
        }

        return redirect()
            ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
            ->with('success', 'Task marked complete.');
    }

    public function call(Request $request)
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'task_type' => ['required', 'in:call'],
            'current_stage' => ['nullable', 'in:all,fresh,cooling,cold,dormant'],
        ]);

        Log::info('Remarketing call task opened', [
            'task_id' => $validated['task_id'],
            'lead_name' => $validated['lead_name'],
            'phone' => $validated['phone'],
            'reason' => $validated['reason'],
            'task_type' => $validated['task_type'],
            'current_stage' => $validated['current_stage'] ?? null,
        ]);

        return redirect()
            ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
            ->with('success', 'Call task opened.');
    }

}
