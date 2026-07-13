<?php

namespace App\Http\Controllers;

use App\Services\RadioRecordingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RadioController extends Controller
{
    public function __construct(private RadioRecordingService $recordings) {}

    public function list(Request $request)
    {
        $this->recordings->cleanupCuts();
        $this->ensureAdmin($request);

        $date = $request->query('date');

        return response()->json($this->recordings->list($date));
    }

    public function current(Request $request)
    {
        $this->recordings->cleanupCuts();
        $this->ensureAdmin($request);

        return response()->json([
            'recording' => $this->recordings->latest($request->query('date')),
        ]);
    }

    public function hours(Request $request)
    {
        $this->ensureAdmin($request);
        $date = (string) $request->query('date', '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'La fecha no es valida.'], 422);
        }

        try {
            return response()->json([
                'date' => $date,
                'hours' => $this->recordings->hours($date),
            ]);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'La fecha no es valida.'], 422);
        }
    }

    public function play(Request $request)
    {
        $this->ensureAdmin($request);
        $file = $request->query('file');

        if (!$file) {
            abort(400);
        }

        $path = $this->recordings->pathForRelativeFile($file);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => File::size($path),
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function cut(Request $request)
    {
        return $this->downloadExport($request, [[
            'date' => $request->input('date'),
            'start' => $request->input('start'),
            'end' => $request->input('end'),
        ]]);
    }

    public function export(Request $request)
    {
        return $this->downloadExport($request, $request->input('ranges', []));
    }

    private function ensureAdmin(Request $request): void
    {
        $user = $request->user();
        $adminEmail = config('radio.admin_email');

        if (!$user) {
            abort(403);
        }

        if (Schema::hasColumn('users', 'is_active') && !$user->is_active) {
            abort(403);
        }

        if (Schema::hasColumn('users', 'role')) {
            if ($user->isAdmin()) {
                return;
            }

            if (!$adminEmail || strtolower($user->email) !== strtolower($adminEmail)) {
                abort(403);
            }

            return;
        }

        if ($adminEmail && strtolower($user->email) !== strtolower($adminEmail)) {
            abort(403);
        }
    }

    private function downloadExport(Request $request, mixed $ranges): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $this->recordings->cleanupCuts();
        $this->ensureAdmin($request);

        if (!is_array($ranges)) {
            return response()->json(['error' => 'Los fragmentos no son validos.'], 422);
        }

        $format = strtolower((string) $request->input('format', 'mp3'));

        try {
            $outputFile = $this->recordings->export($ranges, $format);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (\RuntimeException $exception) {
            Log::error('FFmpeg failed while exporting audio', ['error' => $exception->getMessage()]);

            return response()->json([
                'error' => 'No se pudo procesar el audio.',
                'details' => $exception->getMessage(),
            ], 500);
        }

        $filename = 'melodia_' . now()->format('Y-m-d_H-i-s') . '.' . $format;

        return response()->download($outputFile, $filename, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }
}
