<?php

namespace App\Console\Commands;

use App\Services\WhatsAppDetectorEventIngestor;
use Illuminate\Console\Command;

class IngestWhatsAppDetectorEvents extends Command
{
    protected $signature = 'whatsapp:ingest-detector-events
                            {--path= : Override JSONL path (default: config whatsapp_detector.jsonl_path)}';

    protected $description = 'Passively ingest whatsapp-detector events.jsonl into Jinx for inspection (no remarketing changes).';

    public function handle(WhatsAppDetectorEventIngestor $ingestor): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;
        $eventsUrl = (string) config('whatsapp_detector.events_url', '');
        $sourceType = $eventsUrl !== '' ? 'url' : 'file';
        $source = $eventsUrl !== '' ? $eventsUrl : ($path ?? (string) config('whatsapp_detector.jsonl_path'));

        try {
            $stats = $ingestor->ingest($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line('whatsapp:ingest-detector-events');
        $this->line('  source_type: ' . $sourceType);
        $this->line('  source: ' . ($source !== '' ? $source : '(empty source)'));
        foreach ($stats as $key => $value) {
            $this->line(sprintf('  %s: %d', $key, $value));
        }

        return self::SUCCESS;
    }
}
