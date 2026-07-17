<?php

namespace App\Console\Commands;

use App\Services\RecordingArchiveService;
use Illuminate\Console\Command;

class ProcessRecordingArchives extends Command
{
    protected $signature = 'radio:work-archives {--sleep=5} {--once}';

    protected $description = 'Package recording days and archive them in Google Drive';

    public function handle(RecordingArchiveService $archives): int
    {
        $archives->recoverInterrupted();
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            if (! $archives->processNext() && ! $this->option('once')) {
                sleep($sleep);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
