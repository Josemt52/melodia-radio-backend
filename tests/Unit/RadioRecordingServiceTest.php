<?php

namespace Tests\Unit;

use App\Services\RadioRecordingService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RadioRecordingServiceTest extends TestCase
{
    private string $recordingsPath;
    private string $cutsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordingsPath = storage_path('framework/testing/recordings_' . uniqid());
        $this->cutsPath = storage_path('framework/testing/cuts_' . uniqid());

        config([
            'app.timezone' => 'UTC',
            'radio.recordings_path' => $this->recordingsPath,
            'radio.cuts_path' => $this->cutsPath,
            'radio.segment_seconds' => 10,
            'radio.ready_file_age_seconds' => 0,
            'radio.recordings_timezone' => 'UTC',
        ]);

        File::makeDirectory($this->recordingsPath . '/2026-07-08/19', 0755, true);
        File::put($this->recordingsPath . '/2026-07-08/19/53-42.mp3', 'fake audio');
        File::put($this->recordingsPath . '/2026-07-08/19/53-52.mp3', 'fake audio');
        File::put($this->recordingsPath . '/2026-02-23_10-38-34.mp3', 'legacy fake audio');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->recordingsPath);
        File::deleteDirectory($this->cutsPath);

        parent::tearDown();
    }

    public function test_it_lists_vps_nested_recordings_for_a_date(): void
    {
        $files = app(RadioRecordingService::class)->list('2026-07-08');

        $this->assertCount(2, $files);
        $this->assertSame('2026-07-08/19/53-42.mp3', $files[0]['file']);
        $this->assertSame('2026-07-08/19/53-52.mp3', $files[1]['file']);
        $this->assertSame('2026-07-08T19:53:42+00:00', $files[0]['date']);
    }

    public function test_it_keeps_legacy_flat_recordings_compatible(): void
    {
        $files = app(RadioRecordingService::class)->list('2026-02-23');

        $this->assertCount(1, $files);
        $this->assertSame('2026-02-23_10-38-34.mp3', $files[0]['file']);
    }

    public function test_it_returns_the_latest_recording_for_the_user_console(): void
    {
        $recording = app(RadioRecordingService::class)->latest('2026-07-08');

        $this->assertNotNull($recording);
        $this->assertSame('2026-07-08/19/53-52.mp3', $recording['file']);
        $this->assertSame('2026-07-08T19:53:52+00:00', $recording['date']);
    }

    public function test_it_builds_a_complete_24_hour_summary(): void
    {
        $hours = app(RadioRecordingService::class)->hours('2026-07-08');

        $this->assertCount(24, $hours);
        $this->assertFalse($hours[18]['available']);
        $this->assertTrue($hours[19]['available']);
        $this->assertSame('19:00 - 20:00', $hours[19]['label']);
        $this->assertSame(2, $hours[19]['segment_count']);
        $this->assertSame(20, $hours[19]['coverage_seconds']);
        $this->assertSame('2026-07-08T19:53:42+00:00', $hours[19]['starts_at']);
        $this->assertSame('2026-07-08T19:54:02+00:00', $hours[19]['ends_at']);
    }

    public function test_it_converts_utc_recording_names_to_the_local_radio_day(): void
    {
        config(['app.timezone' => 'America/Lima']);
        File::makeDirectory($this->recordingsPath . '/2026-07-09/02', 0755, true);
        File::put($this->recordingsPath . '/2026-07-09/02/00-00.mp3', 'next UTC day audio');

        $files = app(RadioRecordingService::class)->list('2026-07-08');
        $hours = app(RadioRecordingService::class)->hours('2026-07-08');

        $this->assertCount(3, $files);
        $this->assertSame('2026-07-08T14:53:42-05:00', $files[0]['date']);
        $this->assertSame('2026-07-08T21:00:00-05:00', $files[2]['date']);
        $this->assertTrue($hours[14]['available']);
        $this->assertTrue($hours[21]['available']);
    }

    public function test_it_ignores_empty_recordings_that_are_still_being_written(): void
    {
        File::put($this->recordingsPath . '/2026-07-08/19/54-02.mp3', '');

        $files = app(RadioRecordingService::class)->list('2026-07-08');

        $this->assertCount(2, $files);
        $this->assertSame([
            '2026-07-08/19/53-42.mp3',
            '2026-07-08/19/53-52.mp3',
        ], array_column($files, 'file'));
    }
}
