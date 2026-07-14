<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ExportJobService
{
    private string $jobsPath;

    public function __construct(private RadioRecordingService $recordings)
    {
        $this->jobsPath = rtrim(config('radio.cuts_path'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jobs';

        if (!File::isDirectory($this->jobsPath)) {
            File::makeDirectory($this->jobsPath, 0770, true);
        }
    }

    public function create(int $userId, array $ranges, string $format): array
    {
        $format = strtolower($format);
        $this->validateRequest($ranges, $format);

        $job = [
            'id' => bin2hex(random_bytes(16)),
            'user_id' => $userId,
            'status' => 'queued',
            'format' => $format,
            'ranges' => array_values($ranges),
            'filename' => 'melodia_' . now()->format('Y-m-d_H-i-s') . '.' . $format,
            'output_file' => null,
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        $this->write($job);

        return $this->publicStatus($job);
    }

    public function status(string $id, int $userId): array
    {
        $job = $this->ownedJob($id, $userId);

        return $this->publicStatus($job);
    }

    public function completedJob(string $id, int $userId): array
    {
        $job = $this->ownedJob($id, $userId);

        if ($job['status'] !== 'completed') {
            throw new \DomainException('La exportacion aun no esta lista.');
        }

        $path = $this->resultPath($job);
        if (!File::isFile($path)) {
            throw new \DomainException('El archivo temporal ya no esta disponible.');
        }

        $job['path'] = $path;

        return $job;
    }

    public function forget(string $id): void
    {
        $job = $this->read($id);
        if ($job && $job['output_file']) {
            @unlink($this->jobsPath . DIRECTORY_SEPARATOR . basename($job['output_file']));
        }

        @unlink($this->jobPath($id));
        @unlink($this->lockPath($id));
    }

    public function processNext(): bool
    {
        foreach (File::files($this->jobsPath) as $file) {
            if (!preg_match('/^[a-f0-9]{32}\.json$/', $file->getFilename())) {
                continue;
            }

            $job = $this->read(pathinfo($file->getFilename(), PATHINFO_FILENAME));
            if (!$job || $job['status'] !== 'queued' || !$this->acquireLock($job['id'])) {
                continue;
            }

            $job['status'] = 'processing';
            $job['updated_at'] = now()->toIso8601String();
            $this->write($job);

            try {
                $generatedPath = $this->recordings->export($job['ranges'], $job['format']);
                $job['output_file'] = $job['id'] . '.' . $job['format'];
                File::move($generatedPath, $this->resultPath($job));
                $job['status'] = 'completed';
            } catch (\Throwable $exception) {
                Log::error('Background audio export failed', [
                    'job_id' => $job['id'],
                    'error' => $exception->getMessage(),
                ]);
                $job['status'] = 'failed';
                $job['error'] = $exception instanceof \InvalidArgumentException || $exception instanceof \DomainException
                    ? $exception->getMessage()
                    : 'No se pudo procesar el audio.';
            } finally {
                $job['updated_at'] = now()->toIso8601String();
                $this->write($job);
                @unlink($this->lockPath($job['id']));
            }

            return true;
        }

        return false;
    }

    public function recoverInterrupted(): void
    {
        foreach ($this->allJobs() as $job) {
            if ($job['status'] === 'processing') {
                $job['status'] = 'queued';
                $job['updated_at'] = now()->toIso8601String();
                $this->write($job);
            }

            @unlink($this->lockPath($job['id']));
        }
    }

    public function cleanupExpired(): void
    {
        $ttl = max(300, (int) config('radio.export_job_ttl_seconds', 21600));

        foreach ($this->allJobs() as $job) {
            $updatedAt = strtotime($job['updated_at'] ?? $job['created_at'] ?? '') ?: 0;
            if (time() - $updatedAt > $ttl) {
                $this->forget($job['id']);
            }
        }
    }

    private function validateRequest(array $ranges, string $format): void
    {
        if (!in_array($format, ['mp3', 'wav'], true)) {
            throw new \InvalidArgumentException('El formato debe ser mp3 o wav.');
        }

        $limit = (int) config('radio.max_export_ranges', 20);
        if ($ranges === [] || count($ranges) > $limit) {
            throw new \InvalidArgumentException("Selecciona entre 1 y $limit fragmentos.");
        }

        foreach ($ranges as $range) {
            if (
                !is_array($range)
                || !isset($range['date'], $range['start'], $range['end'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $range['date'])
                || !preg_match('/^\d{2}:\d{2}:\d{2}$/', (string) $range['start'])
                || !preg_match('/^\d{2}:\d{2}:\d{2}$/', (string) $range['end'])
            ) {
                throw new \InvalidArgumentException('Uno de los fragmentos no es valido.');
            }
        }
    }

    private function ownedJob(string $id, int $userId): array
    {
        $job = $this->read($id);
        if (!$job || (int) $job['user_id'] !== $userId) {
            throw new \DomainException('La exportacion no existe.');
        }

        return $job;
    }

    private function publicStatus(array $job): array
    {
        return [
            'id' => $job['id'],
            'status' => $job['status'],
            'format' => $job['format'],
            'filename' => $job['filename'],
            'error' => $job['error'],
        ];
    }

    private function allJobs(): array
    {
        return collect(File::files($this->jobsPath))
            ->filter(fn ($file) => preg_match('/^[a-f0-9]{32}\.json$/', $file->getFilename()))
            ->map(fn ($file) => $this->read(pathinfo($file->getFilename(), PATHINFO_FILENAME)))
            ->filter()
            ->values()
            ->all();
    }

    private function read(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = $this->jobPath($id);
        if (!File::isFile($path)) {
            return null;
        }

        $job = json_decode(File::get($path), true);

        return is_array($job) ? $job : null;
    }

    private function write(array $job): void
    {
        $temporaryPath = $this->jobsPath . DIRECTORY_SEPARATOR . '.' . $job['id'] . '.' . bin2hex(random_bytes(4));
        File::put($temporaryPath, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($temporaryPath, $this->jobPath($job['id']));
    }

    private function acquireLock(string $id): bool
    {
        $handle = @fopen($this->lockPath($id), 'x');
        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    private function resultPath(array $job): string
    {
        return $this->jobsPath . DIRECTORY_SEPARATOR . basename((string) $job['output_file']);
    }

    private function jobPath(string $id): string
    {
        return $this->jobsPath . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function lockPath(string $id): string
    {
        return $this->jobsPath . DIRECTORY_SEPARATOR . $id . '.lock';
    }
}
