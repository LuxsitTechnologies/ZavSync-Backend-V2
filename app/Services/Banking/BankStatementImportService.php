<?php

namespace App\Services\Banking;

use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BankStatementImportService
{
    public function __construct(private readonly IntegerMoneyParser $moneyParser) {}

    /** @param array<string,mixed> $data */
    public function preview(string $companyId, User $user, UploadedFile $file, array $data, string $idempotencyKey): BankStatementImport
    {
        $fileHash = hash_file('sha256', $file->getRealPath());
        if ($fileHash === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded statement could not be read.']);
        }
        $mapping = array_replace($this->defaultMapping(), $data['mapping'] ?? []);
        $payload = ['financial_account_id' => $data['financial_account_id'], 'file_hash' => $fileHash, 'mapping' => $mapping, 'statement_reference' => $data['statement_reference'] ?? null, 'statement_start_date' => $data['statement_start_date'] ?? null, 'statement_end_date' => $data['statement_end_date'] ?? null, 'opening_balance' => $data['opening_balance'] ?? null, 'closing_balance' => $data['closing_balance'] ?? null];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($companyId, $user, $file, $data, $idempotencyKey, $fileHash, $mapping, $hash): BankStatementImport {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $existingKey = BankStatementImport::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existingKey !== null) {
                if (! hash_equals($existingKey->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different statement import.');
                }

                return $existingKey->load('financialAccount');
            }
            $duplicate = BankStatementImport::query()->where('company_id', $companyId)->where('financial_account_id', $data['financial_account_id'])->where('file_hash', $fileHash)->first();
            if ($duplicate !== null) {
                return $duplicate->load('financialAccount');
            }
            $account = FinancialAccount::query()->where('company_id', $companyId)->where('type', 'bank')->where('is_active', true)->findOrFail($data['financial_account_id']);
            $rows = $this->parseCsv($file, $account, $mapping);

            return BankStatementImport::query()->create([
                'company_id' => $companyId,
                'financial_account_id' => $account->id,
                'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                'file_hash' => $fileHash,
                'statement_reference' => $data['statement_reference'] ?? null,
                'statement_start_date' => $data['statement_start_date'] ?? null,
                'statement_end_date' => $data['statement_end_date'] ?? null,
                'opening_balance' => $data['opening_balance'] ?? null,
                'closing_balance' => $data['closing_balance'] ?? null,
                'status' => 'preview',
                'column_mapping' => $mapping,
                'preview_rows' => $rows,
                'row_count' => count($rows),
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
                'imported_by' => $user->id,
            ])->load('financialAccount');
        });
    }

    public function confirm(string $companyId, BankStatementImport $import): BankStatementImport
    {
        return DB::transaction(function () use ($companyId, $import): BankStatementImport {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $import = BankStatementImport::query()->where('company_id', $companyId)->with('financialAccount')->lockForUpdate()->findOrFail($import->id);
            if ($import->status === 'confirmed') {
                return $import->load(['financialAccount', 'transactions']);
            }
            $invalid = collect($import->preview_rows)->first(fn (array $row): bool => $row['errors'] !== []);
            if ($invalid !== null) {
                throw ValidationException::withMessages(['rows' => 'Resolve every invalid CSV row before confirming the import.']);
            }
            $imported = 0;
            $duplicates = 0;
            foreach ($import->preview_rows as $row) {
                $duplicateQuery = BankTransaction::query()->where('company_id', $companyId)->where('financial_account_id', $import->financial_account_id);
                if ($row['external_transaction_id'] !== null) {
                    $duplicateQuery->where('external_transaction_id', $row['external_transaction_id']);
                } else {
                    $duplicateQuery->where('fingerprint', $row['fingerprint']);
                }
                if ($duplicateQuery->exists()) {
                    $duplicates++;

                    continue;
                }
                BankTransaction::query()->create([
                    'company_id' => $companyId,
                    'financial_account_id' => $import->financial_account_id,
                    'bank_statement_import_id' => $import->id,
                    'statement_row' => $row['row_number'],
                    'evidence_type' => 'statement',
                    'transaction_date' => $row['transaction_date'],
                    'value_date' => $row['value_date'],
                    'description' => $row['description'],
                    'bank_reference' => $row['bank_reference'],
                    'external_transaction_id' => $row['external_transaction_id'],
                    'direction' => $row['direction'],
                    'amount' => $row['amount'],
                    'running_balance' => $row['running_balance'],
                    'currency' => $import->financialAccount->currency,
                    'counterparty_name' => $row['counterparty_name'],
                    'fingerprint' => $row['fingerprint'],
                    'status' => 'unmatched',
                    'created_by' => $import->imported_by,
                ]);
                $imported++;
            }
            $import->update(['status' => 'confirmed', 'imported_count' => $imported, 'duplicate_count' => $duplicates, 'confirmed_at' => now()]);

            return $import->load(['financialAccount', 'transactions']);
        }, 3);
    }

    /** @param array<string,string> $mapping @return array<int,array<string,mixed>> */
    private function parseCsv(UploadedFile $file, FinancialAccount $account, array $mapping): array
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded statement could not be opened.']);
        }
        $headers = fgetcsv($stream);
        if (! is_array($headers) || $headers === []) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'The CSV must contain a header row.']);
        }
        $normalizedHeaders = array_map(fn (string $header): string => mb_strtolower(trim($header)), $headers);
        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($stream)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $record = [];
            foreach ($normalizedHeaders as $index => $header) {
                $record[$header] = trim((string) ($values[$index] ?? ''));
            }
            $rows[] = $this->normalizeRow($record, $mapping, $account, $rowNumber);
        }
        fclose($stream);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The CSV does not contain any transaction rows.']);
        }

        return $rows;
    }

    /** @param array<string,string> $record @param array<string,string> $mapping @return array<string,mixed> */
    private function normalizeRow(array $record, array $mapping, FinancialAccount $account, int $rowNumber): array
    {
        $value = fn (string $key): string => trim($record[mb_strtolower($mapping[$key] ?? '')] ?? '');
        $errors = [];
        try {
            $date = $this->date($value('date'));
        } catch (ValidationException $exception) {
            $date = null;
            $errors[] = $exception->getMessage();
        }
        $valueDate = $value('value_date') === '' ? null : $this->safeDate($value('value_date'), $errors);
        $description = $value('description');
        if ($description === '') {
            $errors[] = 'Description is required.';
        }
        try {
            [$direction, $amount] = $this->directionAndAmount($value, $mapping);
        } catch (ValidationException $exception) {
            $direction = null;
            $amount = 0;
            $errors[] = $exception->getMessage();
        }
        $balance = null;
        if ($value('balance') !== '') {
            try {
                $balance = $this->moneyParser->parse($value('balance'), 'balance');
            } catch (ValidationException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        $reference = $value('reference') ?: null;
        $externalId = $value('external_id') ?: null;
        $fingerprint = hash('sha256', implode('|', [$account->id, $date ?? '', $valueDate ?? '', $direction ?? '', $amount, mb_strtolower($reference ?? ''), mb_strtolower($description)]));

        return ['row_number' => $rowNumber, 'transaction_date' => $date, 'value_date' => $valueDate, 'description' => $description, 'bank_reference' => $reference, 'external_transaction_id' => $externalId, 'direction' => $direction, 'amount' => abs($amount), 'running_balance' => $balance, 'counterparty_name' => $value('counterparty') ?: null, 'fingerprint' => $fingerprint, 'errors' => $errors];
    }

    /** @param callable(string):string $value @param array<string,string> $mapping @return array{string,int} */
    private function directionAndAmount(callable $value, array $mapping): array
    {
        if (($mapping['debit'] ?? '') !== '' || ($mapping['credit'] ?? '') !== '') {
            $debit = $value('debit');
            $credit = $value('credit');
            if (($debit === '') === ($credit === '')) {
                throw ValidationException::withMessages(['amount' => 'Exactly one debit or credit amount is required.']);
            }

            return $debit !== '' ? ['debit', abs($this->moneyParser->parse($debit))] : ['credit', abs($this->moneyParser->parse($credit))];
        }
        $amount = $this->moneyParser->parse($value('amount'));
        $rawDirection = mb_strtolower($value('direction'));
        if ($rawDirection !== '') {
            $direction = match ($rawDirection) {
                'credit', 'cr', 'in', 'deposit', 'receipt' => 'credit',
                'debit', 'dr', 'out', 'withdrawal', 'payment' => 'debit',
                default => throw ValidationException::withMessages(['direction' => 'Direction must identify a debit or credit.']),
            };

            return [$direction, abs($amount)];
        }
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must not be zero.']);
        }

        return [$amount > 0 ? 'credit' : 'debit', abs($amount)];
    }

    private function date(string $value): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }
        throw ValidationException::withMessages(['date' => 'Transaction date is invalid.']);
    }

    /** @param array<int,string> $errors */
    private function safeDate(string $value, array &$errors): ?string
    {
        try {
            return $this->date($value);
        } catch (ValidationException $exception) {
            $errors[] = $exception->getMessage();

            return null;
        }
    }

    /** @return array<string,string> */
    private function defaultMapping(): array
    {
        return ['date' => 'date', 'value_date' => 'value_date', 'description' => 'description', 'reference' => 'reference', 'external_id' => 'external_id', 'direction' => 'direction', 'amount' => 'amount', 'debit' => '', 'credit' => '', 'balance' => 'balance', 'counterparty' => 'counterparty'];
    }
}
