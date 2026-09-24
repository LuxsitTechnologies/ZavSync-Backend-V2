<?php

namespace App\Services\Crm;

use App\Exceptions\CrmException;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmImport;
use App\Models\CrmImportRow;
use App\Models\CrmLead;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CrmImportService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function preview(Request $request, array $data): CrmImport
    {
        $rows = $this->parse($data['csv']);
        if (count($rows) < 2) {
            throw new CrmException('CRM_IMPORT_EMPTY', 'The CSV must contain a header and at least one data row.');
        }
        if (count($rows) > 5001) {
            throw new CrmException('CRM_IMPORT_TOO_LARGE', 'A CRM import may contain at most 5,000 data rows.');
        }
        $headers = array_map(fn (string $value): string => trim($value), array_shift($rows));
        if (count($headers) !== count(array_unique($headers))) {
            throw new CrmException('CRM_IMPORT_DUPLICATE_COLUMNS', 'CSV column names must be unique.');
        }
        $companyId = (string) $request->attributes->get('company_id');

        return DB::transaction(function () use ($request, $data, $rows, $headers, $companyId): CrmImport {
            $import = CrmImport::query()->create(['company_id' => $companyId, 'entity_type' => $data['entity_type'], 'original_filename' => basename($data['filename']), 'status' => 'PREVIEWED', 'mapping' => $data['mapping'], 'summary' => [], 'file_hash' => hash('sha256', $data['csv']), 'created_by' => $request->user()->id]);
            $summary = ['total' => count($rows), 'valid' => 0, 'invalid' => 0, 'duplicates' => 0];
            foreach ($rows as $offset => $values) {
                $source = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), ''));
                $source = array_map(fn (string $value): string => $this->safeCell($value), $source ?: []);
                $mapped = [];
                foreach ($data['mapping'] as $sourceColumn => $targetField) {
                    if (! in_array($targetField, $this->allowedFields($data['entity_type']), true)) {
                        throw new CrmException('CRM_IMPORT_MAPPING_INVALID', "The field $targetField cannot be imported.");
                    }
                    $mapped[$targetField] = trim((string) ($source[$sourceColumn] ?? '')) ?: null;
                }
                $mapped = $this->normalize($data['entity_type'], $mapped);
                $validation = Validator::make($mapped, $this->rules($companyId, $data['entity_type']));
                $duplicate = ! $validation->fails() && $this->isDuplicate($companyId, $data['entity_type'], $mapped);
                $state = $validation->fails() ? 'INVALID' : ($duplicate ? 'POSSIBLE_DUPLICATE' : 'CREATE');
                $summary[$validation->fails() ? 'invalid' : 'valid']++;
                if ($duplicate) {
                    $summary['duplicates']++;
                }
                CrmImportRow::query()->create(['company_id' => $companyId, 'crm_import_id' => $import->id, 'row_number' => $offset + 2, 'source_data' => $source, 'mapped_data' => $mapped, 'state' => $state, 'errors' => $validation->errors()->toArray() ?: null, 'warnings' => $duplicate ? ['A possible existing record matches this row.'] : null]);
            }
            $import->update(['summary' => $summary]);
            $this->audit->record($request, $request->user(), $companyId, 'preview', 'crm_import', $import, null, $import->toArray());

            return $import->load('rows');
        });
    }

    /** @param array<string, mixed> $data */
    public function confirm(Request $request, CrmImport $import, array $data): CrmImport
    {
        return DB::transaction(function () use ($request, $import, $data): CrmImport {
            $companyId = (string) $request->attributes->get('company_id');
            $locked = CrmImport::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($import->id);
            $existing = CrmImport::query()->where('company_id', $companyId)->where('idempotency_key', $data['idempotency_key'])->whereKeyNot($locked->id)->exists();
            if ($existing) {
                throw new CrmException('CRM_IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used for another import.', 409);
            }
            if ($locked->status === 'CONFIRMED') {
                return $locked->load('rows');
            }

            foreach ($locked->rows()->orderBy('row_number')->lockForUpdate()->get() as $row) {
                $decision = $data['decisions'][(string) $row->row_number] ?? ($row->state === 'CREATE' ? 'CREATE' : 'SKIP');
                if ($decision !== 'CREATE' || $row->state === 'INVALID') {
                    $row->update(['state' => 'SKIP']);

                    continue;
                }
                $record = $this->createRecord($companyId, (int) $request->user()->id, $locked->entity_type, $row->mapped_data);
                $row->update(['state' => 'IMPORTED', 'created_record_type' => $record->getMorphClass(), 'created_record_id' => $record->getKey()]);
            }
            $old = $locked->toArray();
            $locked->update(['status' => 'CONFIRMED', 'idempotency_key' => $data['idempotency_key'], 'confirmed_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'confirm', 'crm_import', $locked, $old, $locked->fresh()->toArray());

            return $locked->fresh()->load('rows');
        });
    }

    /** @return array<int, array<int, string>> */
    private function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new CrmException('CRM_IMPORT_READ_FAILED', 'The CSV could not be read.');
        }
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(fn ($value): string => trim((string) $value), $row);
        }
        fclose($handle);

        return $rows;
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'".$value : $value;
    }

    /** @return array<int, string> */
    private function allowedFields(string $type): array
    {
        return match ($type) {
            'ACCOUNT' => ['name', 'legal_name', 'email', 'phone', 'website', 'ntn', 'registration_number', 'industry', 'source', 'city', 'country'],
            'CONTACT' => ['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title', 'department', 'account_id'],
            'LEAD' => ['first_name', 'last_name', 'company_name', 'email', 'phone', 'website', 'source', 'estimated_value', 'currency', 'interest'],
            default => [],
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(string $type, array $data): array
    {
        if ($type === 'ACCOUNT') {
            $data['country'] = strtoupper((string) ($data['country'] ?? 'PK'));
            $data['ntn'] = isset($data['ntn']) ? preg_replace('/\D+/', '', (string) $data['ntn']) : null;
        }
        if ($type === 'LEAD') {
            $data['estimated_value'] = (int) ($data['estimated_value'] ?? 0);
            $data['currency'] = strtoupper((string) ($data['currency'] ?? 'PKR'));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function rules(string $companyId, string $type): array
    {
        return match ($type) {
            'ACCOUNT' => ['name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'website' => ['nullable', 'url:http,https'], 'ntn' => ['nullable', 'digits:7'], 'country' => ['nullable', 'size:2']],
            'CONTACT' => ['first_name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'account_id' => ['nullable', 'uuid', Rule::exists('crm_accounts', 'id')->where('company_id', $companyId)]],
            'LEAD' => ['first_name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'website' => ['nullable', 'url:http,https'], 'estimated_value' => ['integer', 'min:0'], 'currency' => ['size:3']],
            default => [],
        };
    }

    /** @param array<string, mixed> $data */
    private function isDuplicate(string $companyId, string $type, array $data): bool
    {
        return match ($type) {
            'ACCOUNT' => CrmAccount::query()->where('company_id', $companyId)->where(fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $data['name'])])->when($data['ntn'] ?? null, fn ($query, $ntn) => $query->orWhere('ntn', $ntn))->when($data['website'] ?? null, fn ($query, $website) => $query->orWhere('website', $website)))->exists(),
            'CONTACT' => ! empty($data['email']) || ! empty($data['phone']) ? CrmContact::query()->where('company_id', $companyId)->where(fn ($query) => $query->when($data['email'] ?? null, fn ($query, $email) => $query->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]))->when($data['phone'] ?? null, fn ($query, $phone) => $query->orWhere('phone', $phone)))->exists() : false,
            'LEAD' => ! empty($data['email']) || ! empty($data['phone']) ? CrmLead::query()->where('company_id', $companyId)->where(fn ($query) => $query->when($data['email'] ?? null, fn ($query, $email) => $query->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]))->when($data['phone'] ?? null, fn ($query, $phone) => $query->orWhere('phone', $phone)))->exists() : false,
            default => false,
        };
    }

    /** @param array<string, mixed> $data */
    private function createRecord(string $companyId, int $userId, string $type, array $data): CrmAccount|CrmContact|CrmLead
    {
        return match ($type) {
            'ACCOUNT' => CrmAccount::query()->create([...$data, 'company_id' => $companyId, 'account_type' => 'BUSINESS', 'status' => 'PROSPECT', 'country' => $data['country'] ?? 'PK', 'created_by' => $userId]),
            'CONTACT' => CrmContact::query()->create([...$data, 'company_id' => $companyId, 'status' => 'ACTIVE', 'created_by' => $userId]),
            'LEAD' => CrmLead::query()->create([...$data, 'company_id' => $companyId, 'status' => 'NEW', 'estimated_value' => $data['estimated_value'] ?? 0, 'currency' => $data['currency'] ?? 'PKR', 'created_by' => $userId]),
            default => throw new CrmException('CRM_IMPORT_TYPE_INVALID', 'Unsupported CRM import type.'),
        };
    }
}
