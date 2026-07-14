<?php

namespace Tests\Unit;

use App\Services\ExportJobService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportJobServiceTest extends TestCase
{
    private string $cutsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cutsPath = storage_path('framework/testing/export_jobs_' . uniqid());
        config([
            'radio.recordings_path' => $this->cutsPath . '/recordings',
            'radio.cuts_path' => $this->cutsPath,
            'radio.max_export_ranges' => 20,
            'radio.export_job_ttl_seconds' => 3600,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cutsPath);

        parent::tearDown();
    }

    public function test_it_creates_a_temporary_queued_export_for_its_owner(): void
    {
        $jobs = app(ExportJobService::class);
        $created = $jobs->create(42, [[
            'date' => '2026-07-13',
            'start' => '10:00:00',
            'end' => '11:00:00',
        ]], 'mp3');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $created['id']);
        $this->assertSame('queued', $created['status']);
        $this->assertSame($created, $jobs->status($created['id'], 42));
        $this->assertFileExists($this->cutsPath . '/jobs/' . $created['id'] . '.json');
    }

    public function test_it_does_not_expose_jobs_to_another_user(): void
    {
        $jobs = app(ExportJobService::class);
        $created = $jobs->create(42, [[
            'date' => '2026-07-13',
            'start' => '10:00:00',
            'end' => '10:05:00',
        ]], 'wav');

        $this->expectException(\DomainException::class);
        $jobs->status($created['id'], 7);
    }

    public function test_it_rejects_an_invalid_export_before_queueing_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ExportJobService::class)->create(42, [], 'flac');
    }
}
