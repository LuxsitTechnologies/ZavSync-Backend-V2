<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class JournalPostingService
{
    /** @param array{posting_date:string,reference?:string|null,reference_type?:string,source_id?:string|null,source?:string,description:string,lines:array<int,array{account_id:string,description?:string|null,debit:int,credit:int,related_type?:string|null,related_id?:string|null}>} $data */
    public function saveDraft(string $companyId, User $user, array $data, ?Journal $journal = null): Journal
    {
        return DB::transaction(function () use ($companyId, $user, $data, $journal): Journal {
            $this->assertAccountsBelongToCompany($companyId, $data['lines'], false);

            if ($journal !== null) {
                $journal = Journal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($journal->id);
                if ($journal->status !== 'draft') {
                    throw ValidationException::withMessages(['journal' => 'Only a draft journal can be edited.']);
                }
            } else {
                [$sequence, $number] = $this->nextNumber($companyId, $data['posting_date']);
                $journal = new Journal(['company_id' => $companyId, 'sequence' => $sequence, 'number' => $number, 'created_by' => $user->getKey()]);
            }

            $journal->fill($this->headerData($data, 'draft'))->save();
            $journal->lines()->delete();
            $journal->lines()->createMany($data['lines']);

            return $journal->load('lines.account');
        });
    }

    /** @param array{posting_date:string,reference?:string|null,reference_type?:string,source_id?:string|null,source?:string,description:string,lines:array<int,array{account_id:string,description?:string|null,debit:int,credit:int,related_type?:string|null,related_id?:string|null}>} $data */
    public function post(string $companyId, User $user, array $data, ?string $idempotencyKey = null): Journal
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): Journal {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $idempotencyHash = $this->idempotencyHash($data);
            if ($idempotencyKey !== null) {
                $existing = Journal::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->idempotency_hash, $idempotencyHash)) {
                        throw new ConflictHttpException('The idempotency key has already been used for a different journal request.');
                    }

                    return $existing->load('lines.account');
                }
            }

            $this->assertOpenPeriod($companyId, $data['posting_date']);
            $this->assertPostableLines($companyId, $data['lines']);
            [$sequence, $number] = $this->nextNumberAfterCompanyLock($companyId, $data['posting_date']);
            $journal = Journal::query()->create([
                ...$this->headerData($data, 'posted'),
                'company_id' => $companyId,
                'sequence' => $sequence,
                'number' => $number,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $idempotencyHash,
                'created_by' => $user->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
            ]);
            $journal->lines()->createMany($data['lines']);

            return $journal->load('lines.account');
        });
    }

    /** @param array{posting_date:string,reference?:string|null,reference_type?:string,source_id?:string|null,source?:string,description:string,lines:array<int,array{account_id:string,description?:string|null,debit:int,credit:int,related_type?:string|null,related_id?:string|null}>} $data */
    public function postDraft(string $companyId, User $user, Journal $journal, array $data): Journal
    {
        return DB::transaction(function () use ($companyId, $user, $journal, $data): Journal {
            $lockedJournal = Journal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($journal->id);
            if ($lockedJournal->status !== 'draft') {
                throw ValidationException::withMessages(['journal' => 'Only a draft journal can be posted.']);
            }
            $this->assertOpenPeriod($companyId, $data['posting_date']);
            $this->assertPostableLines($companyId, $data['lines']);
            $lockedJournal->fill([
                ...$this->headerData($data, 'posted'),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
            ])->save();
            $lockedJournal->lines()->delete();
            $lockedJournal->lines()->createMany($data['lines']);

            return $lockedJournal->load('lines.account');
        });
    }

    public function reverse(string $companyId, User $user, Journal $original, string $postingDate, string $reason, ?string $idempotencyKey = null): Journal
    {
        return DB::transaction(function () use ($companyId, $user, $original, $postingDate, $reason, $idempotencyKey): Journal {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $idempotencyHash = $this->idempotencyHash(['journal_id' => $original->id, 'posting_date' => $postingDate, 'reason' => $reason]);
            if ($idempotencyKey !== null) {
                $existing = Journal::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->idempotency_hash, $idempotencyHash)) {
                        throw new ConflictHttpException('The idempotency key has already been used for a different reversal request.');
                    }

                    return $existing->load('lines.account');
                }
            }
            $original = Journal::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($original->id);
            if ($original->status !== 'posted' || $original->reversed_by_journal_id !== null) {
                throw ValidationException::withMessages(['journal' => 'This journal cannot be reversed again.']);
            }
            $this->assertOpenPeriod($companyId, $postingDate);
            [$sequence, $number] = $this->nextNumberAfterCompanyLock($companyId, $postingDate);
            $reversal = Journal::query()->create([
                'company_id' => $companyId,
                'sequence' => $sequence,
                'number' => $number,
                'posting_date' => $postingDate,
                'reference' => $original->number,
                'reference_type' => $original->reference_type,
                'source_id' => $original->source_id,
                'source' => 'reversal',
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $idempotencyHash,
                'description' => 'Reversal of '.$original->number.' — '.$reason,
                'status' => 'posted',
                'created_by' => $user->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
                'reverses_journal_id' => $original->id,
            ]);
            $reversal->lines()->createMany($original->lines->map(fn ($line): array => ['account_id' => $line->account_id, 'description' => $line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'related_type' => $line->related_type, 'related_id' => $line->related_id])->all());
            $original->update(['status' => 'reversed', 'reversed_by_journal_id' => $reversal->id]);

            return $reversal->load('lines.account');
        });
    }

    private function assertOpenPeriod(string $companyId, string $postingDate): void
    {
        $period = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('start_date', '<=', $postingDate)->whereDate('end_date', '>=', $postingDate)->lockForUpdate()->first();
        if ($period === null) {
            throw ValidationException::withMessages(['posting_date' => 'The posting date must belong to an accounting period.']);
        }
        if ($period->status !== 'open') {
            throw ValidationException::withMessages(['posting_date' => 'The selected accounting period is locked.']);
        }
    }

    /** @param array<int, array{account_id:string,debit:int,credit:int}> $lines */
    private function assertPostableLines(string $companyId, array $lines): void
    {
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));
        if ($debit > 9007199254740991 || $credit > 9007199254740991) {
            throw ValidationException::withMessages(['lines' => 'Journal totals exceed the supported minor-unit range.']);
        }
        if ($debit <= 0 || $debit !== $credit) {
            throw ValidationException::withMessages(['lines' => 'Total debit must equal total credit and must be greater than zero.']);
        }
        foreach ($lines as $index => $line) {
            if (($line['debit'] > 0) === ($line['credit'] > 0)) {
                throw ValidationException::withMessages(["lines.$index" => 'Each line must contain either a debit or a credit.']);
            }
        }
        $this->assertAccountsBelongToCompany($companyId, $lines, true);
    }

    /** @param array<int, array{account_id:string}> $lines */
    private function assertAccountsBelongToCompany(string $companyId, array $lines, bool $requireActive): void
    {
        $accountIds = array_values(array_unique(array_column($lines, 'account_id')));
        $query = Account::query()->where('company_id', $companyId)->whereIn('id', $accountIds);
        if ($requireActive) {
            $query->where('is_active', true);
        }
        if ($query->count() !== count($accountIds)) {
            throw ValidationException::withMessages(['lines' => $requireActive ? 'Every journal account must be active and belong to the selected company.' : 'Every journal account must belong to the selected company.']);
        }
    }

    /** @return array{int, string} */
    private function nextNumber(string $companyId, string $postingDate): array
    {
        Company::query()->lockForUpdate()->findOrFail($companyId);

        return $this->nextNumberAfterCompanyLock($companyId, $postingDate);
    }

    /** @return array{int, string} */
    private function nextNumberAfterCompanyLock(string $companyId, string $postingDate): array
    {
        $sequence = (int) Journal::query()->where('company_id', $companyId)->max('sequence') + 1;

        return [$sequence, sprintf('JV-%s-%04d', date('Y', strtotime($postingDate)), $sequence)];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function headerData(array $data, string $status): array
    {
        return ['posting_date' => $data['posting_date'], 'reference' => $data['reference'] ?? null, 'reference_type' => $data['reference_type'] ?? 'manual_journal', 'source_id' => $data['source_id'] ?? null, 'source' => $data['source'] ?? 'manual', 'description' => $data['description'], 'status' => $status];
    }

    /** @param array<string, mixed> $data */
    private function idempotencyHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
