<?php

namespace Tests\Unit;

use App\Services\DeveloperSettingsService;
use Mockery;
use Tests\TestCase;

class DeveloperSettingsServiceTest extends TestCase
{
    public function test_it_uses_a_supported_drive_upload_chunk_size(): void
    {
        config([
            'developer.default_upload_chunk_mb' => 256,
            'developer.upload_chunk_options_mb' => [256, 512, 1024],
        ]);

        $settings = Mockery::mock(DeveloperSettingsService::class)->makePartial();
        $settings->shouldReceive('get')
            ->once()
            ->with('google_drive_upload_chunk_mb', 256)
            ->andReturn('1024');

        $this->assertSame(1024, $settings->uploadChunkMb());
    }

    public function test_it_replaces_an_old_drive_upload_chunk_size_with_the_default(): void
    {
        config([
            'developer.default_upload_chunk_mb' => 256,
            'developer.upload_chunk_options_mb' => [256, 512, 1024],
        ]);

        $settings = Mockery::mock(DeveloperSettingsService::class)->makePartial();
        $settings->shouldReceive('get')
            ->once()
            ->with('google_drive_upload_chunk_mb', 256)
            ->andReturn('64');

        $this->assertSame(256, $settings->uploadChunkMb());
    }
}
