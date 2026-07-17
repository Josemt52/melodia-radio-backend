<?php

return [
    'panel_path' => env('DEVELOPER_PANEL_PATH', '_melodia-system'),
    'archive_path' => env('RADIO_ARCHIVE_PATH') ?: storage_path('app/archives'),
    'archive_job_path' => env('RADIO_ARCHIVE_JOB_PATH') ?: storage_path('app/archive-jobs'),
    'tar_binary' => env('RADIO_TAR_BINARY', 'tar'),
    'google_credentials_path' => env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
    'google_folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
    'upload_chunk_bytes' => (int) env('GOOGLE_DRIVE_UPLOAD_CHUNK_BYTES', 8388608),
];
