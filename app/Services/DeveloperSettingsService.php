<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

class DeveloperSettingsService
{
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
        $credentials = $this->get('google_drive_credentials');
        $decoded = $credentials ? json_decode($credentials, true) : null;

        return [
            'folder_id' => (string) $this->get('google_drive_folder_id', ''),
            'upload_chunk_mb' => $this->uploadChunkMb(),
            'credentials_configured' => is_array($decoded),
            'service_account_email' => $decoded['client_email'] ?? null,
        ];
    }

    public function uploadChunkMb(): int
    {
        $default = (int) config('developer.default_upload_chunk_mb');
        $configured = (int) $this->get('google_drive_upload_chunk_mb', $default);
        $options = array_map('intval', config('developer.upload_chunk_options_mb', [$default]));

        return in_array($configured, $options, true) ? $configured : $default;
    }
}
