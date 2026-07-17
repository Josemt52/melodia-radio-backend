<?php

namespace App\Http\Controllers;

use App\Services\DeveloperSettingsService;
use App\Services\GoogleDriveService;
use App\Services\RecordingArchiveService;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'folder_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'upload_chunk_mb' => ['required', 'integer', 'min:1', 'max:64'],
            'credentials' => ['nullable', 'file', 'max:128'],
        ]);

        if ($request->hasFile('credentials')) {
            $json = $request->file('credentials')->get();
            try {
                $credentials = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return response()->json(['message' => 'El archivo de credenciales no contiene un JSON valido.'], 422);
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
        $this->settings->put('google_drive_upload_chunk_mb', $data['upload_chunk_mb']);

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
                (int) $request->user()->id
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($job, 202);
    }
}
