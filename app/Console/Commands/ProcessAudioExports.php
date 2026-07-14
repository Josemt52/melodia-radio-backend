<?php

namespace App\Console\Commands;

use App\Services\ExportJobService;
use Illuminate\Console\Command;

class ProcessAudioExports extends Command
{
    protected $signature = 'radio:work-exports {--sleep=2} {--once}';

    protected $description = 'Process temporary audio export jobs';

    public function handle(ExportJobService $jobs): int
    {
        $sleep = max(1, (int) $this->option('sleep'));
        $jobs->recoverInterrupted();
        $lastCleanup = 0;

        do {
            if (time() - $lastCleanup >= 60) {
                $jobs->cleanupExpired();
                $lastCleanup = time();
            }

            $processed = $jobs->processNext();
            if (!$processed && !$this->option('once')) {
                sleep($sleep);
            }
        } while (!$this->option('once'));

        return self::SUCCESS;
    }
}
