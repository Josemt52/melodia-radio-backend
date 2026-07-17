<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
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
            'developer.google_credentials_path' => null,
            'developer.google_folder_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->recordingsPath);
        parent::tearDown();
    }

    public function test_regular_admin_cannot_open_developer_api(): void
    {
        Sanctum::actingAs(new User(['role' => 'admin', 'is_active' => true]));

        $this->getJson('/api/developer/overview')->assertForbidden();
    }

    public function test_developer_can_inspect_local_recording_storage(): void
    {
        Sanctum::actingAs(new User(['role' => 'developer', 'is_active' => true]));

        $this->getJson('/api/developer/overview')
            ->assertOk()
            ->assertJsonPath('days', 1)
            ->assertJsonPath('total_files', 1)
            ->assertJsonPath('total_bytes', 128)
            ->assertJsonPath('dates.0.date', '2026-07-16')
            ->assertJsonPath('drive.configured', false);
    }

    public function test_archive_is_not_queued_until_drive_is_configured(): void
    {
        Sanctum::actingAs(new User(['role' => 'developer', 'is_active' => true]));

        $this->postJson('/api/developer/archives', [
            'date' => '2026-07-16',
            'delete_after_upload' => false,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Google Drive aun no esta configurado.');
    }
}
