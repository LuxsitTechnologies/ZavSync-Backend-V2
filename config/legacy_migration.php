<?php

return [
    'input_root' => env('LEGACY_MIGRATION_INPUT_ROOT', storage_path('app/private/legacy-imports')),
    'maximum_input_bytes' => env('LEGACY_MIGRATION_MAXIMUM_INPUT_BYTES', 20 * 1024 * 1024),
    'chunk_size' => env('LEGACY_MIGRATION_CHUNK_SIZE', 100),
];
