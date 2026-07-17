<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GoogleDriveService
{
    public function __construct(private DeveloperSettingsService $settings) {}

    public function status(): array
    {
        try {
            $credentials = $this->credentials(false);
        } catch (\Throwable $exception) {
            return [
                'configured' => false,
                'account' => null,
                'folder_id' => null,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'configured' => $credentials !== null && (bool) $this->settings->get('google_drive_folder_id'),
            'account' => $credentials['client_email'] ?? null,
            'folder_id' => $this->settings->get('google_drive_folder_id') ? 'Configurada' : null,
            'error' => null,
        ];
    }

    public function testConnection(): array
    {
        $folderId = $this->folderId();
        $response = $this->client()->get("https://www.googleapis.com/drive/v3/files/{$folderId}", [
            'fields' => 'id,name,mimeType',
            'supportsAllDrives' => 'true',
        ])->throw()->json();

        return ['connected' => true, 'folder_name' => $response['name'] ?? 'Google Drive'];
    }

    public function upload(string $path, string $filename): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('El archivo de respaldo no existe.');
        }

        $client = $this->client();
        $size = filesize($path);
        $session = $client->withHeaders([
            'X-Upload-Content-Type' => 'application/gzip',
            'X-Upload-Content-Length' => (string) $size,
        ])->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
            'name' => $filename,
            'parents' => [$this->folderId()],
            'description' => 'Respaldo automatico de Radio Melodia',
        ])->throw();

        $location = $session->header('Location');
        if (! $location) {
            throw new \RuntimeException('Google Drive no inicio la carga reanudable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el respaldo para subirlo.');
        }

        $offset = 0;
        $result = null;
        $chunkSize = max(1, (int) $this->settings->get('google_drive_upload_chunk_mb', config('developer.default_upload_chunk_mb'))) * 1048576;
        $chunkSize -= $chunkSize % 262144;

        try {
            while ($offset < $size) {
                $length = min($chunkSize, $size - $offset);
                $chunk = fread($handle, $length);
                if ($chunk === false || strlen($chunk) !== $length) {
                    throw new \RuntimeException('No se pudo leer completamente el respaldo.');
                }

                $end = $offset + $length - 1;
                $response = $client->timeout(300)
                    ->withHeaders([
                        'Content-Length' => (string) $length,
                        'Content-Range' => "bytes {$offset}-{$end}/{$size}",
                    ])
                    ->withBody($chunk, 'application/gzip')
                    ->put($location);

                if (! in_array($response->status(), [200, 201, 308], true)) {
                    $response->throw();
                }

                if ($response->successful()) {
                    $result = $response->json();
                }
                $offset += $length;
            }
        } finally {
            fclose($handle);
        }

        if (! $result || empty($result['id'])) {
            throw new \RuntimeException('Google Drive no confirmo el archivo subido.');
        }

        return ['id' => $result['id'], 'name' => $result['name'] ?? $filename, 'size' => $size];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(60)->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;

        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No se pudo firmar la credencial de Google Drive.');
        }

        $assertion = $unsigned.'.'.$this->base64Url($signature);
        $response = Http::asForm()->timeout(30)->post($credentials['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ])->throw()->json();

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Google Drive no entrego un token de acceso.');
        }

        return $response['access_token'];
    }

    private function credentials(bool $required = true): ?array
    {
        $json = $this->settings->get('google_drive_credentials');
        if (! $json) {
            if ($required) {
                throw new \RuntimeException('Falta cargar la credencial de Google Drive.');
            }

            return null;
        }

        $credentials = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new \RuntimeException('La credencial de Google Drive no es valida.');
        }

        return $credentials;
    }

    private function folderId(): string
    {
        return $this->settings->get('google_drive_folder_id')
            ?: throw new \RuntimeException('Falta configurar el ID de carpeta de Google Drive.');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
