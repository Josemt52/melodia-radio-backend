<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class RadioController extends Controller
{
    private string $recordPath;

    public function __construct()
    {
        $this->recordPath = storage_path('recordings/melodia');

        if (!File::exists($this->recordPath)) {
            File::makeDirectory($this->recordPath, 0755, true);
        }
    }

    /*
    |-----------------------------------------------------
    | LISTAR GRABACIONES
    |-----------------------------------------------------
    */
    public function list(Request $request)
    {
        $date = $request->query('date');

        if (!File::exists($this->recordPath)) {
            return response()->json([]);
        }

        $files = collect(File::files($this->recordPath))
            ->map(fn($file) => $file->getFilename())
            ->sort();

        if ($date) {
            $files = $files->filter(
                fn($file) => str_starts_with($file, $date)
            );
        }

        return response()->json(array_values($files->toArray()));
    }

    /*
    |-----------------------------------------------------
    | RECORTE DE AUDIO POR RANGO
    |-----------------------------------------------------
    */
    public function cut(Request $request)
    {
        $date = $request->input('date');
        $start = $request->input('start');
        $end = $request->input('end');

        if (!$date || !$start || !$end) {
            return response()->json([
                'error' => 'Missing parameters'
            ], 400);
        }

        $startTime = strtotime("$date $start");
        $endTime = strtotime("$date $end");

        if ($endTime <= $startTime) {
            return response()->json([
                'error' => 'Invalid time range'
            ], 400);
        }

        $files = collect(File::files($this->recordPath))
            ->filter(function ($file) use ($date, $startTime, $endTime) {

                $name = $file->getFilename();

                if (!str_starts_with($name, $date)) {
                    return false;
                }

                $timestamp = strtotime(
                    str_replace('_', ' ', substr($name, 0, 19))
                );

                return $timestamp >= $startTime &&
                       $timestamp <= $endTime;
            })
            ->sort();

        if ($files->isEmpty()) {
            return response()->json([
                'error' => 'No audio found in range'
            ], 404);
        }

        $concatFile = storage_path('app/temp_concat.txt');

        $content = $files->map(function ($file) {
            return "file '" . $file->getPathname() . "'";
        })->implode("\n");

        File::ensureDirectoryExists(storage_path('app'));

        file_put_contents($concatFile, $content);

        $outputFile = storage_path(
            'app/temp/' . uniqid('cut_') . '.mp3'
        );

        File::ensureDirectoryExists(dirname($outputFile));

        exec("ffmpeg -f concat -safe 0 -i $concatFile -c copy $outputFile");

        return response()->json([
            'url' => url(str_replace(
                public_path(),
                '',
                $outputFile
            ))
        ]);
    }

    /*
    |-----------------------------------------------------
    | REPRODUCIR AUDIO
    |-----------------------------------------------------
    */
    public function play(Request $request)
    {
        $file = $request->query('file');

        $path = $this->recordPath . '/' . $file;

        if (!File::exists($path)) {
            abort(404);
        }

        return response()->file($path);
    }
}
