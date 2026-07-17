<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveService;
use App\Services\RecordingArchiveService;
use Illuminate\Http\Request;

class DeveloperController extends Controller
{
    public function __construct(
        private RecordingArchiveService $archives,
        private GoogleDriveService $drive
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
