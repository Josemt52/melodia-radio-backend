<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

class DeveloperSettingsService
{
    public const AUTH_OAUTH = 'oauth';

    public const AUTH_SERVICE_ACCOUNT = 'service_account';

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = SystemSetting::query()->where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return $setting->encrypted && $setting->value !== null
            ? Crypt::decryptString($setting->value)
            : $setting->value;
    }

    public function put(string $key, mixed $value, bool $encrypted = false): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value === null ? null : ($encrypted ? Crypt::encryptString((string) $value) : (string) $value),
                'encrypted' => $encrypted,
            ]
        );
    }

    public function driveSettings(): array
    {
        $serviceCredentials = $this->get('google_drive_credentials');
        $serviceDecoded = $serviceCredentials ? json_decode($serviceCredentials, true) : null;
        $oauthCredentials = $this->get('google_drive_oauth_credentials');
        $oauthDecoded = $oauthCredentials ? json_decode($oauthCredentials, true) : null;
        $oauthConnected = (bool) $this->get('google_drive_oauth_refresh_token')
            && (bool) $this->get('google_drive_oauth_folder_id');

        return [
            'auth_mode' => $this->driveAuthMode(),
            'folder_id' => (string) $this->get('google_drive_folder_id', ''),
            'credentials_configured' => is_array($serviceDecoded),
            'service_account_email' => $serviceDecoded['client_email'] ?? null,
            'oauth_credentials_configured' => isset($oauthDecoded['web']['client_id']),
            'oauth_connected' => $oauthConnected,
            'oauth_account' => $oauthConnected ? ($this->get('google_drive_oauth_email') ?: 'Mi unidad') : null,
            'oauth_redirect_uri' => route('developer.drive.oauth.callback'),
            'oauth_connect_url' => isset($oauthDecoded['web']['client_id'])
                ? route('developer.drive.oauth.redirect')
                : null,
        ];
    }

    public function driveAuthMode(): string
    {
        $mode = $this->get('google_drive_auth_mode');
        if (in_array($mode, [self::AUTH_OAUTH, self::AUTH_SERVICE_ACCOUNT], true)) {
            return $mode;
        }

        if ($this->get('google_drive_oauth_refresh_token')) {
            return self::AUTH_OAUTH;
        }

        return $this->get('google_drive_credentials')
            ? self::AUTH_SERVICE_ACCOUNT
            : self::AUTH_OAUTH;
    }
}
