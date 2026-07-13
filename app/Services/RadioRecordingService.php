<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RadioRecordingService
{
    private string $recordingsPath;
    private string $cutsPath;
    private int $segmentSeconds;
    private int $readyFileAgeSeconds;

    public function __construct()
    {
        $this->recordingsPath = rtrim(config('radio.recordings_path'), DIRECTORY_SEPARATOR);
        $this->cutsPath = rtrim(config('radio.cuts_path'), DIRECTORY_SEPARATOR);
        $this->segmentSeconds = max(1, (int) config('radio.segment_seconds', 10));
        $this->readyFileAgeSeconds = max(0, (int) config('radio.ready_file_age_seconds', 12));

        if (!File::exists($this->cutsPath)) {
            File::makeDirectory($this->cutsPath, 0755, true);
        }
    }

    public function list(?string $date = null): array
    {
        return $this->segments($date)
            ->map(fn (array $segment) => [
                'file' => $segment['relative_path'],
                'url' => route('api.play', ['file' => $segment['relative_path']]),
                'date' => $segment['starts_at']->toIso8601String(),
                'duration' => $this->duration($segment['path']),
                'size' => File::size($segment['path']),
            ])
            ->values()
            ->all();
    }

    public function latest(?string $date = null): ?array
    {
        $segment = $this->segments($date)->last();

        if (!$segment) {
            return null;
        }

        return [
            'file' => $segment['relative_path'],
            'url' => route('api.play', ['file' => $segment['relative_path']]),
            'date' => $segment['starts_at']->toIso8601String(),
            'duration' => $this->duration($segment['path']),
            'size' => File::size($segment['path']),
        ];
    }

    public function hours(string $date): array
    {
        $this->parseDateTime($date, '00:00:00');
        $segments = $this->segments($date)->groupBy(
            fn (array $segment) => (int) $segment['starts_at']->format('H')
        );

        return collect(range(0, 23))->map(function (int $hour) use ($segments) {
            /** @var Collection<int, array>|null $hourSegments */
            $hourSegments = $segments->get($hour);
            $first = $hourSegments?->first();
            $last = $hourSegments?->last();
            $available = $hourSegments !== null && $hourSegments->isNotEmpty();

            return [
                'hour' => $hour,
                'label' => sprintf('%02d:00 - %02d:00', $hour, ($hour + 1) % 24),
                'available' => $available,
                'segment_count' => $hourSegments?->count() ?? 0,
                'coverage_seconds' => min(3600, ($hourSegments?->count() ?? 0) * $this->segmentSeconds),
                'starts_at' => $first ? $first['starts_at']->toIso8601String() : null,
                'ends_at' => $last ? $last['starts_at']->addSeconds($this->segmentSeconds)->toIso8601String() : null,
            ];
        })->all();
    }

    public function pathForRelativeFile(string $file): string
    {
        $relativePath = $this->normalizeRelativePath($file);
        $path = $this->recordingsPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!File::exists($path) || !preg_match('/\.mp3$/i', $path) || !$this->isReadyFile($path)) {
            abort(404);
        }

        return $path;
    }

    public function cut(string $date, string $start, string $end, string $format = 'mp3'): string
    {
        return $this->export([
            ['date' => $date, 'start' => $start, 'end' => $end],
        ], $format);
    }

    public function export(array $ranges, string $format = 'mp3'): string
    {
        $format = strtolower($format);

        if (!in_array($format, ['mp3', 'wav'], true)) {
            throw new \InvalidArgumentException('El formato debe ser mp3 o wav.');
        }

        if ($ranges === [] || count($ranges) > (int) config('radio.max_export_ranges', 20)) {
            throw new \InvalidArgumentException('Selecciona entre 1 y 20 fragmentos.');
        }

        $workspace = $this->cutsPath . DIRECTORY_SEPARATOR . 'job_' . bin2hex(random_bytes(8));
        File::makeDirectory($workspace, 0755, true);
        $clips = [];

        try {
            foreach (array_values($ranges) as $index => $range) {
                if (!is_array($range) || !isset($range['date'], $range['start'], $range['end'])) {
                    throw new \InvalidArgumentException('Uno de los fragmentos no es valido.');
                }

                $clips[] = $this->renderRange(
                    (string) $range['date'],
                    (string) $range['start'],
                    (string) $range['end'],
                    $workspace,
                    $index
                );
            }

            $outputFile = $this->cutsPath . DIRECTORY_SEPARATOR
                . 'melodia_' . now()->format('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
            $this->joinClips($clips, $outputFile, $format, $workspace);

            return $outputFile;
        } catch (\Throwable $exception) {
            if (isset($outputFile)) {
                @unlink($outputFile);
            }

            throw $exception;
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function cleanupCuts(int $olderThanSeconds = 3600): void
    {
        if (!File::exists($this->cutsPath)) {
            return;
        }

        $now = time();
        foreach (File::files($this->cutsPath) as $file) {
            if ($now - $file->getMTime() > $olderThanSeconds) {
                @unlink($file->getPathname());
            }
        }

        foreach (File::directories($this->cutsPath) as $directory) {
            if ($now - File::lastModified($directory) > $olderThanSeconds) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function segments(?string $date = null): Collection
    {
        if (!File::exists($this->recordingsPath)) {
            return collect();
        }

        $files = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? $this->filesForDate($date)
            : collect(File::allFiles($this->recordingsPath));

        return $files
            ->filter(fn (SplFileInfo $file) => preg_match('/\.mp3$/i', $file->getFilename()))
            ->filter(fn (SplFileInfo $file) => $this->isReadyFile($file->getPathname()))
            ->map(fn (SplFileInfo $file) => $this->segmentFromFile($file))
            ->filter()
            ->when($date, fn (Collection $items) => $items->filter(
                fn (array $segment) => $segment['starts_at']->format('Y-m-d') === $date
            ))
            ->sortBy(fn (array $segment) => $segment['starts_at']->getTimestamp())
            ->values();
    }

    private function filesForDate(string $date): Collection
    {
        $datePath = $this->recordingsPath . DIRECTORY_SEPARATOR . $date;

        if (File::isDirectory($datePath)) {
            return collect(File::allFiles($datePath));
        }

        return collect(File::allFiles($this->recordingsPath));
    }

    private function isReadyFile(string $path): bool
    {
        if (!File::exists($path) || File::size($path) <= 0) {
            return false;
        }

        return time() - File::lastModified($path) >= $this->readyFileAgeSeconds;
    }

    private function segmentFromFile(SplFileInfo $file): ?array
    {
        $root = rtrim(str_replace('\\', '/', $this->recordingsPath), '/') . '/';
        $absolutePath = str_replace('\\', '/', $file->getPathname());
        $relativePath = str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : str_replace('\\', '/', $file->getRelativePathname());
        $startsAt = $this->timestampFromRelativePath($relativePath);

        if (!$startsAt) {
            return null;
        }

        return [
            'path' => $file->getPathname(),
            'relative_path' => $relativePath,
            'starts_at' => $startsAt,
        ];
    }

    private function timestampFromRelativePath(string $relativePath): ?CarbonImmutable
    {
        if (preg_match('#^(\d{4}-\d{2}-\d{2})/(\d{2})/(\d{2})-(\d{2})\.mp3$#', $relativePath, $matches)) {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$matches[1]} {$matches[2]}:{$matches[3]}:{$matches[4]}");
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.mp3$/', basename($relativePath), $matches)) {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$matches[1]} {$matches[2]}:{$matches[3]}:{$matches[4]}");
        }

        return null;
    }

    private function parseDateTime(string $date, string $time): CarbonImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \InvalidArgumentException('Invalid date or time format');
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "$date $time");
    }

    private function normalizeRelativePath(string $file): string
    {
        $relativePath = str_replace('\\', '/', trim(rawurldecode($file), '/'));

        if (
            $relativePath === ''
            || str_contains($relativePath, '..')
            || str_starts_with($relativePath, '/')
            || !preg_match('/\.mp3$/i', $relativePath)
        ) {
            abort(400);
        }

        return $relativePath;
    }

    private function renderRange(string $date, string $start, string $end, string $workspace, int $index): string
    {
        $startTime = $this->parseDateTime($date, $start);
        $endTime = $this->parseDateTime($date, $end);

        if ($endTime->lessThanOrEqualTo($startTime)) {
            throw new \InvalidArgumentException('La hora final debe ser posterior a la inicial.');
        }

        $durationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
        if ($durationSeconds > (int) config('radio.max_range_seconds', 14400)) {
            throw new \InvalidArgumentException('Cada fragmento puede durar hasta 4 horas.');
        }

        $segments = $this->segments($date)
            ->filter(function (array $segment) use ($startTime, $endTime) {
                $segmentEnd = $segment['starts_at']->addSeconds($this->segmentSeconds);

                return $segment['starts_at']->lessThan($endTime) && $segmentEnd->greaterThan($startTime);
            })
            ->values();

        if ($segments->isEmpty()) {
            throw new \DomainException("No se encontro audio entre $start y $end.");
        }

        $concatPath = $workspace . DIRECTORY_SEPARATOR . "range_$index.txt";
        $outputFile = $workspace . DIRECTORY_SEPARATOR . sprintf('clip_%03d.mp3', $index);
        $firstSegmentTime = $segments->first()['starts_at'];
        $offsetSeconds = max(0, $startTime->getTimestamp() - $firstSegmentTime->getTimestamp());

        $content = $segments
            ->map(fn (array $segment) => "file '" . $this->escapeConcatPath($segment['path']) . "'")
            ->implode(PHP_EOL);

        File::put($concatPath, $content . PHP_EOL);

        $process = new Process([
            (string) config('radio.ffmpeg_binary', 'ffmpeg'),
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $concatPath,
            '-ss', (string) $offsetSeconds,
            '-t', (string) $durationSeconds,
            '-c:a', 'libmp3lame',
            '-b:a', '192k',
            $outputFile,
        ]);
        $process->setTimeout((int) config('radio.ffmpeg_timeout', 600));

        $this->runFfmpeg($process);

        return $outputFile;
    }

    private function joinClips(array $clips, string $outputFile, string $format, string $workspace): void
    {
        $concatPath = $workspace . DIRECTORY_SEPARATOR . 'clips.txt';
        $content = collect($clips)
            ->map(fn (string $clip) => "file '" . $this->escapeConcatPath($clip) . "'")
            ->implode(PHP_EOL);
        File::put($concatPath, $content . PHP_EOL);

        $codecArguments = $format === 'wav'
            ? ['-c:a', 'pcm_s16le']
            : ['-c:a', 'libmp3lame', '-b:a', '192k'];

        $process = new Process(array_merge([
            (string) config('radio.ffmpeg_binary', 'ffmpeg'), '-y', '-f', 'concat', '-safe', '0', '-i', $concatPath, '-vn',
        ], $codecArguments, [$outputFile]));
        $process->setTimeout((int) config('radio.ffmpeg_timeout', 600));
        $this->runFfmpeg($process);
    }

    private function runFfmpeg(Process $process): void
    {
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new \RuntimeException(substr($process->getErrorOutput() ?: $exception->getMessage(), 0, 1000));
        }
    }

    private function escapeConcatPath(string $path): string
    {
        return str_replace("'", "'\\''", str_replace('\\', '/', $path));
    }

    private function duration(string $path): ?float
    {
        $process = new Process([
            (string) config('radio.ffprobe_binary', 'ffprobe'),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $process->setTimeout(5);

        try {
            $process->mustRun();
            $output = trim($process->getOutput());

            return is_numeric($output) ? round((float) $output, 2) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
