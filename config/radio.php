<?php

return [
    'recordings_path' => env('RADIO_RECORDINGS_PATH') ?: storage_path('recordings/melodia'),
    'recordings_timezone' => env('RADIO_RECORDINGS_TIMEZONE', 'UTC'),
    'cuts_path' => env('RADIO_CUTS_PATH') ?: storage_path('app/temp'),
    'segment_seconds' => (int) env('RADIO_SEGMENT_SECONDS', 10),
    'ready_file_age_seconds' => (int) env('RADIO_READY_FILE_AGE_SECONDS', 12),
    'max_export_ranges' => (int) env('RADIO_MAX_EXPORT_RANGES', 20),
    'max_range_seconds' => (int) env('RADIO_MAX_RANGE_SECONDS', 14400),
    'ffmpeg_timeout' => (int) env('RADIO_FFMPEG_TIMEOUT', 600),
    'ffmpeg_binary' => env('RADIO_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('RADIO_FFPROBE_BINARY', 'ffprobe'),
    'export_job_ttl_seconds' => (int) env('RADIO_EXPORT_JOB_TTL_SECONDS', 21600),
    'admin_email' => env('ADMIN_EMAIL'),
];
