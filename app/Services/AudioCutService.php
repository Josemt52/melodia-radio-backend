<?php

namespace App\Services;

class AudioCutService
{
    public function __construct(private RadioRecordingService $recordings) {}

    public function cut(string $date, string $start, string $end): string
    {
        return $this->recordings->cut($date, $start, $end);
    }
}
