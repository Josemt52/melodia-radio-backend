<?php

namespace Tests\Unit;

use App\Services\DeveloperSettingsService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Http;
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

    public function test_it_replaces_content_range_for_each_resumable_upload_part(): void
    {
        $ranges = [];
        $uploadedParts = 0;

        Http::fake(function ($request) use (&$ranges, &$uploadedParts) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'access-token']);
            }

            if (str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files')) {
                return Http::response(null, 200, ['Location' => 'https://upload.example.test/session']);
            }

            if ($request->url() === 'https://upload.example.test/session') {
                $ranges[] = $request->header('Content-Range');
                $uploadedParts++;

                return $uploadedParts < 3
                    ? Http::response(null, 308, ['Range' => 'bytes=0-'.(($uploadedParts * 4) - 1)])
                    : Http::response(['id' => 'drive-file-id', 'name' => 'backup.tar.gz']);
            }

            return Http::response(null, 404);
        });

        $settings = Mockery::mock(DeveloperSettingsService::class);
        $settings->shouldReceive('driveAuthMode')->twice()->andReturn(DeveloperSettingsService::AUTH_OAUTH);
        $settings->shouldReceive('get')->with('google_drive_oauth_credentials')->once()->andReturn(json_encode([
            'web' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ]));
        $settings->shouldReceive('get')->with('google_drive_oauth_refresh_token')->once()->andReturn('refresh-token');
        $settings->shouldReceive('get')->with('google_drive_oauth_folder_id')->once()->andReturn('folder-id');

        $drive = new class($settings) extends GoogleDriveService
        {
            public function uploadPlan(int $size): array
            {
                return ['chunk_bytes' => 4, 'parts' => (int) ceil($size / 4)];
            }
        };

        $path = tempnam(sys_get_temp_dir(), 'drive-upload-');
        file_put_contents($path, '0123456789');

        try {
            $result = $drive->upload($path, 'backup.tar.gz');
        } finally {
            @unlink($path);
        }

        $this->assertSame('drive-file-id', $result['id']);
        $this->assertSame([
            ['bytes 0-3/10'],
            ['bytes 4-7/10'],
            ['bytes 8-9/10'],
        ], $ranges);
    }

    public function test_it_builds_an_offline_oauth_authorization_url_for_my_drive(): void
    {
        $settings = Mockery::mock(DeveloperSettingsService::class);
        $settings->shouldReceive('get')
            ->once()
            ->with('google_drive_oauth_credentials')
            ->andReturn(json_encode([
                'web' => [
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret',
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                ],
            ]));

        $query = [];
        parse_str(parse_url((new GoogleDriveService($settings))->oauthAuthorizationUrl('secure-state'), PHP_URL_QUERY), $query);

        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('secure-state', $query['state']);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', $query['scope']);
        $this->assertSame(route('developer.drive.oauth.callback'), $query['redirect_uri']);
    }

    public function test_it_connects_a_user_and_creates_the_backup_folder_in_my_drive(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]),
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'id' => 'folder-id',
                'name' => 'melodia-backups',
            ]),
        ]);

        $settings = Mockery::mock(DeveloperSettingsService::class);
        $settings->shouldReceive('get')->with('google_drive_oauth_credentials')->once()->andReturn(json_encode([
            'web' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ]));
        $settings->shouldReceive('get')->with('google_drive_oauth_folder_id')->once()->andReturn(null);
        $settings->shouldReceive('put')->with('google_drive_oauth_refresh_token', 'refresh-token', true)->once();
        $settings->shouldReceive('put')->with('google_drive_oauth_folder_id', 'folder-id')->once();
        $settings->shouldReceive('put')->with('google_drive_oauth_email', null)->once();
        $settings->shouldReceive('put')->with('google_drive_auth_mode', DeveloperSettingsService::AUTH_OAUTH)->once();

        $result = (new GoogleDriveService($settings))->connectOAuth('authorization-code');

        $this->assertSame('Mi unidad', $result['account']);
        $this->assertSame('melodia-backups', $result['folder_name']);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/drive/v3/files?fields=id,name'
            && $request['parents'] === ['root']);
    }

    public function test_it_rejects_an_oauth_token_without_drive_file_scope(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'scope' => 'openid email',
            ]),
        ]);

        $settings = Mockery::mock(DeveloperSettingsService::class);
        $settings->shouldReceive('get')->with('google_drive_oauth_credentials')->once()->andReturn(json_encode([
            'web' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google no concedio el permiso drive.file.');

        (new GoogleDriveService($settings))->connectOAuth('authorization-code');
    }

    public function test_it_refreshes_oauth_access_when_testing_the_drive_connection(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-token']),
            'https://www.googleapis.com/drive/v3/files/folder-id*' => Http::response([
                'id' => 'folder-id',
                'name' => 'melodia-backups',
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
        ]);

        $settings = Mockery::mock(DeveloperSettingsService::class);
        $settings->shouldReceive('driveAuthMode')->twice()->andReturn(DeveloperSettingsService::AUTH_OAUTH);
        $settings->shouldReceive('get')->with('google_drive_oauth_folder_id')->once()->andReturn('folder-id');
        $settings->shouldReceive('get')->with('google_drive_oauth_credentials')->once()->andReturn(json_encode([
            'web' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ]));
        $settings->shouldReceive('get')->with('google_drive_oauth_refresh_token')->once()->andReturn('refresh-token');

        $result = (new GoogleDriveService($settings))->testConnection();

        $this->assertTrue($result['connected']);
        $this->assertSame('melodia-backups', $result['folder_name']);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token');
    }
}
