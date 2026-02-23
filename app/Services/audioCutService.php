<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AudioCutService
{
    public function cut(string $date, string $start, string $end): string
    {
        $recordPath = storage_path("recordings/melodia");

        $startTime = strtotime("$date $start");
        $endTime   = strtotime("$date $end");

        if (!$startTime || !$endTime || $endTime <= $startTime) {
            throw new \Exception("Rango de tiempo inválido");
        }

        $segments = [];

        foreach (File::files($recordPath) as $file) {

            $filename = $file->getFilename();

            if (!str_starts_with($filename, $date)) {
                continue;
            }

            $segmentTime = strtotime(
                substr($filename, 0, 19)
            );

            if ($segmentTime >= $startTime && $segmentTime <= $endTime) {
                $segments[] = $file->getPathname();
            }
        }

        if (empty($segments)) {
            throw new \Exception("No hay audio en ese rango");
        }

        return $this->concatSegments($segments);
    }

    private function concatSegments(array $files): string
    {
        $tmpList = storage_path("app/tmp_concat.txt");

        $outputFile = storage_path(
            "app/cut_" . time() . ".mp3"
        );

        $content = "";

        foreach ($files as $file) {
            $content .= "file '$file'\n";
        }

        file_put_contents($tmpList, $content);

        $process = new Process([
            "ffmpeg",
            "-f", "concat",
            "-safe", "0",
            "-i", $tmpList,
            "-c", "copy",
            $outputFile
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception("Error al cortar audio");
        }

        return $outputFile;
    }
}
