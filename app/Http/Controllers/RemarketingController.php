<?php

namespace App\Http\Controllers;

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

        $callTasks = [
            [
                'lead_name' => 'Alex Morgan',
                'phone' => '07123 456789',
                'reason' => 'SMS reply',
                'time_waiting' => '2h',
                'task_type' => 'call',
                'stage' => 'fresh',
            ],
            [
                'lead_name' => 'Jordan Lee',
                'phone' => '07999 112233',
                'reason' => 'No contact',
                'time_waiting' => '1d',
                'task_type' => 'call',
                'stage' => 'cold',
            ],
        ];
        $whatsappTasks = [
            [
                'lead_name' => 'Sam Taylor',
                'phone' => '07888 445566',
                'reason' => 'Requested callback',
                'time_waiting' => '45m',
                'task_type' => 'whatsapp',
                'stage' => 'fresh',
            ],
            [
                'lead_name' => 'Riley Chen',
                'phone' => '07555 998877',
                'reason' => 'Dropped call',
                'time_waiting' => '3h',
                'task_type' => 'whatsapp',
                'stage' => 'cooling',
            ],
            [
                'lead_name' => 'Casey Brooks',
                'phone' => '07333 221100',
                'reason' => 'Follow-up doc',
                'time_waiting' => '30m',
                'task_type' => 'whatsapp',
                'stage' => 'dormant',
            ],
        ];

        if ($selectedStage !== 'all') {
            $callTasks = array_values(array_filter($callTasks, function (array $task) use ($selectedStage) {
                return $task['stage'] === $selectedStage;
            }));
            $whatsappTasks = array_values(array_filter($whatsappTasks, function (array $task) use ($selectedStage) {
                return $task['stage'] === $selectedStage;
            }));
        }

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
