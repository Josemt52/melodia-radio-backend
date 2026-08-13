<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeveloperDriveOAuthController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    public function redirect(Request $request)
    {
        try {
            $state = bin2hex(random_bytes(32));
            $request->session()->put('google_drive_oauth_state', $state);

            return redirect()->away($this->drive->oauthAuthorizationUrl($state));
        } catch (\Throwable $exception) {
            return redirect()->route('developer.panel', [
                'drive_error' => $exception->getMessage(),
            ]);
        }
    }

    public function callback(Request $request)
    {
        $expectedState = (string) $request->session()->pull('google_drive_oauth_state', '');
        $receivedState = (string) $request->query('state', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return redirect()->route('developer.panel', [
                'drive_error' => 'La autorizacion de Google vencio o no es valida.',
            ]);
        }

        if ($request->filled('error')) {
            return redirect()->route('developer.panel', [
                'drive_error' => 'Google no autorizo el acceso a Drive.',
            ]);
        }

        try {
            $connection = $this->drive->connectOAuth((string) $request->query('code'));

            return redirect()->route('developer.panel', [
                'drive_connected' => $connection['account'] ?? 'Google Drive',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Google Drive OAuth connection failed', ['error' => $exception->getMessage()]);

            return redirect()->route('developer.panel', [
                'drive_error' => $exception->getMessage(),
            ]);
        }
    }
}
