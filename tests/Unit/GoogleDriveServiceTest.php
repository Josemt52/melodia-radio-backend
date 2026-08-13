<?php

namespace Tests\Unit;

use App\Services\DeveloperSettingsService;
use App\Services\GoogleDriveService;
use Mockery;
use Tests\TestCase;

class GoogleDriveServiceTest extends TestCase
{
    public function test_it_automatically_plans_upload_parts_for_archives_of_any_size(): void
    {
        $drive = new GoogleDriveService(Mockery::mock(DeveloperSettingsService::class));
        $gigabyte = 1073741824;

        $this->assertSame([
            'chunk_bytes' => 256 * 1048576,
            'parts' => 8,
        ], $drive->uploadPlan(2 * $gigabyte));

        $this->assertSame([
            'chunk_bytes' => 512 * 1048576,
            'parts' => 5,
        ], $drive->uploadPlan((2 * $gigabyte) + 1));

        $this->assertSame([
            'chunk_bytes' => 1024 * 1048576,
            'parts' => 9,
        ], $drive->uploadPlan((8 * $gigabyte) + 1));
    }
}
