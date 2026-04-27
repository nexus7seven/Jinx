<?php

namespace App\Console\Commands;

use App\Models\RemarketingResponseEvent;
use Illuminate\Console\Command;

class RemarketingResponseEventsInspectCommand extends Command
{
    protected $signature = 'remarketing:response-events-inspect
        {--lead_id= : Optional VICIdial lead_id}
        {--status=needs_review : Filter by status, use "all" to show all}
        {--channel= : Optional channel filter}
        {--limit=50 : Limit}
        {--json : JSON output}';

    protected $description = 'Read-only inspector for inbound remarketing response events.';

    public function handle(): int
    {
        $leadId = $this->option('lead_id');
        $status = strtolower(trim((string) ($this->option('status') ?? 'needs_review')));
        $channel = strtolower(trim((string) ($this->option('channel') ?? '')));
        $limit = max(1, (int) $this->option('limit'));
        $asJson = (bool) $this->option('json');

        $query = RemarketingResponseEvent::query()
            ->with('jinxLead')
            ->orderByDesc('id')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        $events = $query->get();

        $rows = $events->map(function (RemarketingResponseEvent $event): array {
            return [
                'id' => $event->id,
                'lead_id' => $event->lead_id,
                'jinx_lead_id' => $event->jinx_lead_id,
                'jinx_lead_name' => $event->jinxLead?->formattedName() ?: null,
                'channel' => $event->channel,
                'direction' => $event->direction,
                'status' => $event->status,
                'matched_phone' => $event->matched_phone,
                'matched_email' => $event->matched_email,
                'message_preview' => $event->message_preview,
                'detected_at' => $event->detected_at?->format('Y-m-d H:i:s'),
                'handled_at' => $event->handled_at?->format('Y-m-d H:i:s'),
                'decision' => $event->decision,
                'source_event_id' => $event->source_event_id,
                'dedupe_key' => $event->dedupe_key,
            ];
        })->values()->all();

        if ($asJson) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $jinxLeadText = '-';
            if ($row['jinx_lead_id'] !== null) {
                $jinxLeadText = (string) $row['jinx_lead_id'];
                if (! empty($row['jinx_lead_name'])) {
                    $jinxLeadText .= ' / '.$row['jinx_lead_name'];
                }
            }

            $this->line('Event ID: '.$row['id']);
            $this->line('Lead ID: '.$row['lead_id']);
            $this->line('Jinx Lead: '.$jinxLeadText);
            $this->line('Channel: '.($row['channel'] ?? '-'));
            $this->line('Status: '.($row['status'] ?? '-'));
            $this->line('Detected at: '.($row['detected_at'] ?? '-'));
            $this->line('Matched phone: '.($row['matched_phone'] ?? '-'));
            $this->line('Matched email: '.($row['matched_email'] ?? '-'));
            $this->line('Preview: '.($row['message_preview'] ?? '-'));
            $this->line('Decision: '.($row['decision'] ?? '-'));
            $this->line('Source event ID: '.($row['source_event_id'] ?? '-'));
            $this->line('Dedupe key: '.($row['dedupe_key'] ?? '-'));
            $this->line('');
            $this->line(str_repeat('-', 50));
            $this->line('');
        }

        $this->line('Summary:');
        $this->line('Total events shown: '.count($rows));

        return self::SUCCESS;
    }
}
