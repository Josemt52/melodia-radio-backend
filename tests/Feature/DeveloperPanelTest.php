<?php

namespace Tests\Feature;

use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DeveloperPanelTest extends TestCase
{
    private string $recordingsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordingsPath = storage_path('framework/testing/developer_panel_'.uniqid());
        File::makeDirectory($this->recordingsPath.'/2026-07-16/10', 0770, true);
        File::put($this->recordingsPath.'/2026-07-16/10/00-00.mp3', str_repeat('a', 128));

        config([
            'radio.recordings_path' => $this->recordingsPath,
            'developer.archive_path' => $this->recordingsPath.'/archives',
            'developer.archive_job_path' => $this->recordingsPath.'/jobs',
        ]);

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('status')->andReturn([
            'configured' => false,
            'account' => null,
            'folder_id' => null,
            'error' => null,
        ]);
        $this->app->instance(GoogleDriveService::class, $drive);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->recordingsPath);
        parent::tearDown();
    }

    public function test_hidden_panel_route_is_available_without_login(): void
    {
        $this->get('/'.config('developer.panel_path'))->assertOk();
    }

    public function test_panel_can_inspect_local_recording_storage_without_login(): void
    {
        $this->getJson('/api/'.config('developer.api_path').'/overview')
            ->assertOk()
            ->assertJsonPath('days', 1)
            ->assertJsonPath('total_files', 1)
            ->assertJsonPath('total_bytes', 128)
            ->assertJsonPath('dates.0.date', '2026-07-16')
            ->assertJsonPath('drive.configured', false);
    }

    public function test_archive_is_not_queued_until_drive_is_configured(): void
    {
        $this->postJson('/api/'.config('developer.api_path').'/archives', [
            'date' => '2026-07-16',
            'delete_after_upload' => false,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Google Drive aun no esta configurado.');
    }
}
