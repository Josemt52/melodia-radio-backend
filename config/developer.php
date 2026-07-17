<?php

return [
    'archive_path' => env('RADIO_ARCHIVE_PATH') ?: storage_path('app/archives'),
    'archive_job_path' => env('RADIO_ARCHIVE_JOB_PATH') ?: storage_path('app/archive-jobs'),
    'tar_binary' => env('RADIO_TAR_BINARY', 'tar'),
    'default_upload_chunk_mb' => 8,
];
