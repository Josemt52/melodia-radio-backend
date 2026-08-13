<?php

namespace App\Http\Controllers;

use App\Services\DeveloperSettingsService;
use App\Services\GoogleDriveService;
use App\Services\RecordingArchiveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeveloperController extends Controller
{
    public function __construct(
        private RecordingArchiveService $archives,
        private GoogleDriveService $drive,
        private DeveloperSettingsService $settings
    ) {}

    public function overview()
    {
        return response()->json($this->archives->overview(), headers: ['Cache-Control' => 'no-store']);
    }

    public function testDrive()
    {
        try {
            return response()->json($this->drive->testConnection());
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function settings()
    {
        return response()->json($this->settings->driveSettings(), headers: ['Cache-Control' => 'no-store']);
    }

    public function updateSettings(Request $request)
    {
        $request->merge([
            'auth_mode' => $request->input('auth_mode', $this->settings->driveAuthMode()),
        ]);
        $data = $request->validate([
            'auth_mode' => ['required', Rule::in([
                DeveloperSettingsService::AUTH_OAUTH,
                DeveloperSettingsService::AUTH_SERVICE_ACCOUNT,
            ])],
            'folder_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credentials' => ['nullable', 'file', 'max:128'],
            'service_account_credentials' => ['nullable', 'file', 'max:128'],
            'oauth_credentials' => ['nullable', 'file', 'max:128'],
        ]);

        if ($data['auth_mode'] === DeveloperSettingsService::AUTH_OAUTH) {
            return $this->updateOAuthSettings($request);
        }

        return $this->updateServiceAccountSettings($request, $data);
    }

    private function updateOAuthSettings(Request $request)
    {
        if ($request->hasFile('oauth_credentials')) {
            $json = $request->file('oauth_credentials')->get();
            try {
                $credentials = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return response()->json(['message' => 'La credencial OAuth no contiene un JSON valido.'], 422);
            }

            $web = $credentials['web'] ?? null;
            if (
                ! is_array($web)
                || empty($web['client_id'])
                || empty($web['client_secret'])
                || empty($web['token_uri'])
            ) {
                return response()->json(['message' => 'El JSON debe pertenecer a un cliente OAuth de tipo aplicacion web.'], 422);
            }

            if ($json !== $this->settings->get('google_drive_oauth_credentials')) {
                $this->settings->put('google_drive_oauth_credentials', $json, true);
                $this->settings->put('google_drive_oauth_refresh_token', null, true);
                $this->settings->put('google_drive_oauth_folder_id', null);
                $this->settings->put('google_drive_oauth_email', null);
            }
        } elseif (! $this->settings->get('google_drive_oauth_credentials')) {
            return response()->json(['message' => 'Debes cargar el JSON del cliente OAuth de Google.'], 422);
        }

        $this->settings->put('google_drive_auth_mode', DeveloperSettingsService::AUTH_OAUTH);

        return response()->json($this->settings->driveSettings());
    }

    private function updateServiceAccountSettings(Request $request, array $data)
    {
        if (empty($data['folder_id'])) {
            return response()->json(['message' => 'Debes indicar el ID de la carpeta en la unidad compartida.'], 422);
        }

        $file = $request->file('service_account_credentials') ?? $request->file('credentials');
        if ($file) {
            $json = $file->get();
            try {
                $credentials = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return response()->json(['message' => 'La cuenta de servicio no contiene un JSON valido.'], 422);
            }

            if (
                ($credentials['type'] ?? null) !== 'service_account'
                || empty($credentials['client_email'])
                || empty($credentials['private_key'])
                || empty($credentials['token_uri'])
            ) {
                return response()->json(['message' => 'El JSON no corresponde a una cuenta de servicio de Google.'], 422);
            }

            $this->settings->put('google_drive_credentials', $json, true);
        } elseif (! $this->settings->get('google_drive_credentials')) {
            return response()->json(['message' => 'Debes cargar el JSON de la cuenta de servicio.'], 422);
        }

        $this->settings->put('google_drive_folder_id', $data['folder_id']);
        $this->settings->put('google_drive_auth_mode', DeveloperSettingsService::AUTH_SERVICE_ACCOUNT);

        return response()->json($this->settings->driveSettings());
    }

    public function archives()
    {
        return response()->json(['jobs' => $this->archives->all()], headers: ['Cache-Control' => 'no-store']);
    }

    public function createArchive(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'delete_after_upload' => ['sometimes', 'boolean'],
        ]);

        try {
            $job = $this->archives->create(
                $data['date'],
                (bool) ($data['delete_after_upload'] ?? false),
                0
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($job, 202);
    }
}
