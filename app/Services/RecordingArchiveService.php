<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RecordingArchiveService
{
    public function __construct(private GoogleDriveService $drive) {}

    public function overview(): array
    {
        $root = rtrim((string) config('radio.recordings_path'), DIRECTORY_SEPARATOR);
        $dates = [];
        $totalBytes = 0;
        $totalFiles = 0;

        if (File::isDirectory($root)) {
            foreach (File::directories($root) as $directory) {
                $date = basename($directory);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }

                $bytes = 0;
                $files = 0;
                foreach (File::allFiles($directory) as $file) {
                    if (strtolower($file->getExtension()) !== 'mp3') {
                        continue;
                    }
                    $bytes += $file->getSize();
                    $files++;
                }

                $totalBytes += $bytes;
                $totalFiles += $files;
                $dates[] = ['date' => $date, 'files' => $files, 'bytes' => $bytes];
            }
        }

        usort($dates, fn (array $a, array $b) => strcmp($b['date'], $a['date']));
        $diskRoot = File::isDirectory($root) ? $root : storage_path();

        return [
            'recordings_path' => $root,
            'total_bytes' => $totalBytes,
            'total_files' => $totalFiles,
            'days' => count($dates),
            'disk_free_bytes' => disk_free_space($diskRoot) ?: 0,
            'disk_total_bytes' => disk_total_space($diskRoot) ?: 0,
            'dates' => $dates,
            'drive' => $this->drive->status(),
        ];
    }

    public function create(string $date, bool $deleteAfterUpload, int $userId): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! File::isDirectory($this->sourcePath($date))) {
            throw new \InvalidArgumentException('El dia seleccionado no existe en el almacenamiento local.');
        }
        if (! $this->drive->status()['configured']) {
            throw new \DomainException('Google Drive aun no esta configurado.');
        }

        foreach ($this->all() as $existing) {
            if ($existing['date'] === $date && in_array($existing['status'], ['queued', 'processing'], true)) {
                throw new \DomainException('Ese dia ya tiene un archivado en proceso.');
            }
        }

        $job = [
            'id' => bin2hex(random_bytes(16)),
            'date' => $date,
            'user_id' => $userId,
            'delete_after_upload' => $deleteAfterUpload,
            'status' => 'queued',
            'remote_file_id' => null,
            'remote_name' => null,
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $this->write($job);

        return $job;
    }

    public function all(): array
    {
        $this->ensurePaths();
        $jobs = [];
        foreach (File::files(config('developer.archive_job_path')) as $file) {
            if (! str_ends_with($file->getFilename(), '.json')) {
                continue;
            }
            $job = json_decode(File::get($file->getPathname()), true);
            if (is_array($job)) {
                $jobs[] = $job;
            }
        }
        usort($jobs, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($jobs, 0, 50);
    }

    public function processNext(): bool
    {
        foreach (array_reverse($this->all()) as $job) {
            if ($job['status'] !== 'queued') {
                continue;
            }
            $lock = $this->jobPath($job['id']).'.lock';
            $handle = @fopen($lock, 'x');
            if ($handle === false) {
                continue;
            }
            fclose($handle);

            $job['status'] = 'processing';
            $job['updated_at'] = now()->toIso8601String();
            $this->write($job);
            $archive = null;

            try {
                $archive = $this->buildArchive($job['date']);
                $uploaded = $this->drive->upload($archive, basename($archive));
                $job['remote_file_id'] = $uploaded['id'];
                $job['remote_name'] = $uploaded['name'];
                $job['status'] = 'completed';

                if ($job['delete_after_upload']) {
                    File::deleteDirectory($this->sourcePath($job['date']));
                }
            } catch (\Throwable $exception) {
                Log::error('Recording archive failed', ['job_id' => $job['id'], 'error' => $exception->getMessage()]);
                $job['status'] = 'failed';
                $job['error'] = $exception->getMessage();
            } finally {
                if ($archive) {
                    @unlink($archive);
                }
                $job['updated_at'] = now()->toIso8601String();
                $this->write($job);
                @unlink($lock);
            }

            return true;
        }

        return false;
    }

    public function recoverInterrupted(): void
    {
        foreach ($this->all() as $job) {
            if ($job['status'] === 'processing') {
                $job['status'] = 'queued';
                $job['updated_at'] = now()->toIso8601String();
                $this->write($job);
            }
            @unlink($this->jobPath($job['id']).'.lock');
        }
    }

    private function buildArchive(string $date): string
    {
        $this->ensurePaths();
        $filename = "melodia-recordings-{$date}.tar.gz";
        $output = rtrim(config('developer.archive_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        @unlink($output);

        $process = new Process([
            (string) config('developer.tar_binary'), '-czf', $output,
            '-C', rtrim(config('radio.recordings_path'), DIRECTORY_SEPARATOR), $date,
        ]);
        $process->setTimeout(null);
        $process->mustRun();

        return $output;
    }

    private function sourcePath(string $date): string
    {
        return rtrim(config('radio.recordings_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$date;
    }

    private function ensurePaths(): void
    {
        foreach ([config('developer.archive_path'), config('developer.archive_job_path')] as $path) {
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0770, true);
            }
        }
    }

    private function write(array $job): void
    {
        $this->ensurePaths();
        File::put($this->jobPath($job['id']), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function jobPath(string $id): string
    {
        return rtrim(config('developer.archive_job_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($id).'.json';
    }
}
