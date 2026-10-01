<?php

namespace App\Services\Migration;

use Illuminate\Validation\ValidationException;
use JsonException;

class LegacyImportFileReader
{
    /** @return array{data: array<string, mixed>, fingerprint: string, filename: string} */
    public function read(string $suppliedPath): array
    {
        $root = realpath((string) config('legacy_migration.input_root'));
        $path = realpath($suppliedPath);
        if ($root === false || $path === false || ! is_file($path) || is_link($suppliedPath)) {
            throw ValidationException::withMessages(['path' => 'The migration input must be an existing regular file in the configured private import directory.']);
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $rootPrefix) || mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            throw ValidationException::withMessages(['path' => 'The migration input path or file type is not allowed.']);
        }
        $size = filesize($path);
        $maximum = (int) config('legacy_migration.maximum_input_bytes', 20 * 1024 * 1024);
        if ($size === false || $size > $maximum) {
            throw ValidationException::withMessages(['path' => 'The migration input exceeds the configured size limit.']);
        }
        $contents = file_get_contents($path, false, null, 0, $maximum + 1);
        if (! is_string($contents) || strlen($contents) > $maximum) {
            throw ValidationException::withMessages(['path' => 'The migration input could not be read.']);
        }
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['path' => 'The migration input is not valid JSON.']);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw ValidationException::withMessages(['path' => 'The migration input must contain a JSON object.']);
        }
        foreach (['source_system', 'manifest', 'invoices', 'invoice_items', 'fbr_invoice_submissions'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw ValidationException::withMessages(['path' => "The migration input is missing the {$key} section."]);
            }
        }
        if (! is_string($data['source_system']) || ! is_array($data['manifest']) || ! is_array($data['invoices']) || ! is_array($data['invoice_items']) || ! is_array($data['fbr_invoice_submissions'])) {
            throw ValidationException::withMessages(['path' => 'The migration input contains an invalid section type.']);
        }

        return ['data' => $data, 'fingerprint' => hash('sha256', $contents), 'filename' => basename($path)];
    }
}
