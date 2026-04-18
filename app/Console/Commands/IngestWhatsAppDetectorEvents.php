<?php

namespace App\Console\Commands;

use App\Services\WhatsAppDetectorEventIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class IngestWhatsAppDetectorEvents extends Command
{
    protected $signature = 'whatsapp:ingest-detector-events
                            {--path= : Override JSONL path (default: config whatsapp_detector.jsonl_path)}';

    protected $description = 'Passively ingest whatsapp-detector events.jsonl into Jinx for inspection (no remarketing changes).';

    public function handle(WhatsAppDetectorEventIngestor $ingestor): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;
        $resolved = $path ?? (string) config('whatsapp_detector.jsonl_path');
        if ($resolved === '' || ! File::isReadable($resolved)) {
            $this->error('JSONL not readable: ' . ($resolved !== '' ? $resolved : '(empty path)'));
            return self::FAILURE;
        }

        $stats = $ingestor->ingest($path);

        $this->line('whatsapp:ingest-detector-events');
        foreach ($stats as $key => $value) {
            $this->line(sprintf('  %s: %d', $key, $value));
        }

        return self::SUCCESS;
    }
}
