<?php

namespace App\Services\Migration;

class HistoricalResponseSanitizer
{
    /** @var array<int, string> */
    private const ALLOWED_KEYS = ['status', 'code', 'message', 'errors', 'timestamp', 'invoiceNumber', 'referenceNumber', 'validationResponse', 'invoiceStatuses', 'errorCode', 'error'];

    /** @return array<string, mixed>|null */
    public function sanitize(mixed $response): ?array
    {
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? $decoded : ['message' => $response];
        }
        if (! is_array($response)) {
            return null;
        }

        return $this->filter($response, 0);
    }

    /** @param array<string|int, mixed> $values @return array<string, mixed> */
    private function filter(array $values, int $depth): array
    {
        if ($depth > 4) {
            return [];
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->filterNested($value, $depth + 1);
            } elseif (is_string($value)) {
                $safe[$key] = $this->sanitizeString($value);
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    private function filterNested(array $values, int $depth): array
    {
        if (array_is_list($values)) {
            return array_map(function (mixed $value) use ($depth): mixed {
                if (is_array($value)) {
                    return $this->filter($value, $depth);
                }

                return is_string($value) ? $this->sanitizeString($value) : (is_scalar($value) || $value === null ? $value : null);
            }, array_slice($values, 0, 100));
        }

        return $this->filter($values, $depth);
    }

    private function sanitizeString(string $value): string
    {
        $redacted = preg_replace('/\bbearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? '';
        $redacted = preg_replace('/\b(authorization|credential|password|(?:access_|refresh_)?token|api[ _-]?key|(?:client_)?secret)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $redacted) ?? '';
        $redacted = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $redacted) ?? '';

        return mb_substr($redacted, 0, 2000);
    }
}
