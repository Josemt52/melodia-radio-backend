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
        $this->assertStringContainsString('drive.file', $query['scope']);
        $this->assertSame(route('developer.drive.oauth.callback'), $query['redirect_uri']);
    }

    public function test_it_connects_a_user_and_creates_the_backup_folder_in_my_drive(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@example.com',
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
        $settings->shouldReceive('put')->with('google_drive_oauth_email', 'owner@example.com')->once();
        $settings->shouldReceive('put')->with('google_drive_auth_mode', DeveloperSettingsService::AUTH_OAUTH)->once();

        $result = (new GoogleDriveService($settings))->connectOAuth('authorization-code');

        $this->assertSame('owner@example.com', $result['account']);
        $this->assertSame('melodia-backups', $result['folder_name']);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/drive/v3/files?fields=id,name'
            && $request['parents'] === ['root']);
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
