<?php

namespace App\Services;

use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GoogleDriveService
{
    private const MEGABYTE = 1048576;

    private const GIGABYTE = 1073741824;

    public function __construct(private DeveloperSettingsService $settings) {}

    public function status(): array
    {
        try {
            $mode = $this->settings->driveAuthMode();
            if ($mode === DeveloperSettingsService::AUTH_OAUTH) {
                $credentials = $this->oauthCredentials(false);

                return [
                    'configured' => $credentials !== null
                        && (bool) $this->settings->get('google_drive_oauth_refresh_token')
                        && (bool) $this->settings->get('google_drive_oauth_folder_id'),
                    'mode' => $mode,
                    'account' => $this->settings->get('google_drive_oauth_email'),
                    'folder_id' => $this->settings->get('google_drive_oauth_folder_id') ? 'Configurada' : null,
                    'error' => null,
                ];
            }

            $credentials = $this->credentials(false);
        } catch (\Throwable $exception) {
            return [
                'configured' => false,
                'mode' => $this->settings->driveAuthMode(),
                'account' => null,
                'folder_id' => null,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'configured' => $credentials !== null && (bool) $this->settings->get('google_drive_folder_id'),
            'mode' => DeveloperSettingsService::AUTH_SERVICE_ACCOUNT,
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

    public function oauthAuthorizationUrl(string $state): string
    {
        $credentials = $this->oauthCredentials();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->oauthRedirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connectOAuth(string $code): array
    {
        if ($code === '') {
            throw new \RuntimeException('Google no devolvio el codigo de autorizacion.');
        }

        $credentials = $this->oauthCredentials();
        $tokens = Http::asForm()->timeout(30)->post($credentials['token_uri'], [
            'code' => $code,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $this->oauthRedirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new \RuntimeException('Google no entrego acceso permanente. Vuelve a autorizar la cuenta.');
        }

        $accessToken = $tokens['access_token'];
        $profile = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(30)
            ->get('https://openidconnect.googleapis.com/v1/userinfo')
            ->throw()
            ->json();
        $folder = $this->ensureOAuthFolder($accessToken);

        $this->settings->put('google_drive_oauth_refresh_token', $tokens['refresh_token'], true);
        $this->settings->put('google_drive_oauth_folder_id', $folder['id']);
        $this->settings->put('google_drive_oauth_email', $profile['email'] ?? null);
        $this->settings->put('google_drive_auth_mode', DeveloperSettingsService::AUTH_OAUTH);

        return [
            'account' => $profile['email'] ?? null,
            'folder_name' => $folder['name'] ?? 'melodia-backups',
        ];
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
        $chunkSize = $this->uploadPlan($size)['chunk_bytes'];
        $stream = Utils::streamFor($handle);

        try {
            while ($offset < $size) {
                $length = min($chunkSize, $size - $offset);
                $end = $offset + $length - 1;
                $chunk = new LimitStream($stream, $length, $offset);
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
            $stream->close();
        }

        if (! $result || empty($result['id'])) {
            throw new \RuntimeException('Google Drive no confirmo el archivo subido.');
        }

        return ['id' => $result['id'], 'name' => $result['name'] ?? $filename, 'size' => $size];
    }

    public function uploadPlan(int $size): array
    {
        $size = max(0, $size);
        $chunkMb = match (true) {
            $size <= 2 * self::GIGABYTE => 256,
            $size <= 8 * self::GIGABYTE => 512,
            default => 1024,
        };
        $chunkBytes = $chunkMb * self::MEGABYTE;

        return [
            'chunk_bytes' => $chunkBytes,
            'parts' => $size > 0 ? (int) ceil($size / $chunkBytes) : 0,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(60)->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        if ($this->settings->driveAuthMode() === DeveloperSettingsService::AUTH_OAUTH) {
            return $this->oauthAccessToken();
        }

        return $this->serviceAccountAccessToken();
    }

    private function serviceAccountAccessToken(): string
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

    private function oauthAccessToken(): string
    {
        $credentials = $this->oauthCredentials();
        $refreshToken = $this->settings->get('google_drive_oauth_refresh_token');
        if (! $refreshToken) {
            throw new \RuntimeException('Falta conectar la cuenta de Google Drive.');
        }

        $response = Http::asForm()->timeout(30)->post($credentials['token_uri'], [
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ])->throw()->json();

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Google Drive no pudo renovar el acceso.');
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

    private function oauthCredentials(bool $required = true): ?array
    {
        $json = $this->settings->get('google_drive_oauth_credentials');
        if (! $json) {
            if ($required) {
                throw new \RuntimeException('Falta cargar la credencial OAuth de Google Drive.');
            }

            return null;
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $credentials = $decoded['web'] ?? null;
        if (
            ! is_array($credentials)
            || empty($credentials['client_id'])
            || empty($credentials['client_secret'])
            || empty($credentials['token_uri'])
        ) {
            throw new \RuntimeException('La credencial OAuth debe ser de tipo aplicacion web.');
        }

        return $credentials;
    }

    private function folderId(): string
    {
        if ($this->settings->driveAuthMode() === DeveloperSettingsService::AUTH_OAUTH) {
            return $this->settings->get('google_drive_oauth_folder_id')
                ?: throw new \RuntimeException('Falta conectar la carpeta de Google Drive.');
        }

        return $this->settings->get('google_drive_folder_id')
            ?: throw new \RuntimeException('Falta configurar el ID de carpeta de Google Drive.');
    }

    private function ensureOAuthFolder(string $accessToken): array
    {
        $folderId = $this->settings->get('google_drive_oauth_folder_id');
        if ($folderId) {
            $existing = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(30)
                ->get("https://www.googleapis.com/drive/v3/files/{$folderId}", [
                    'fields' => 'id,name,mimeType',
                ]);

            if ($existing->successful()) {
                return $existing->json();
            }
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($accessToken)
            ->timeout(30)
            ->post('https://www.googleapis.com/drive/v3/files?fields=id,name', [
                'name' => 'melodia-backups',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => ['root'],
            ])
            ->throw()
            ->json();
    }

    private function oauthRedirectUri(): string
    {
        return route('developer.drive.oauth.callback');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
