<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreJournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use App\Services\Accounting\JournalPostingService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalController extends Controller
{
    public function __construct(private readonly JournalPostingService $postingService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = (string) $request->attributes->get('company_id');
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.view'), 403);
        $query = Journal::query()->where('company_id', $companyId)->with('lines.account');
        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('reference_type') && $request->string('reference_type')->toString() !== 'all') {
            $query->where('reference_type', $request->string('reference_type')->toString());
        }
        if ($request->filled('from')) {
            $query->whereDate('posting_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('posting_date', '<=', $request->date('to'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($builder) => $builder->where('number', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }
        $journals = $query->orderByDesc('posting_date')->orderByDesc('id')->limit(500)->get();

        return JournalResource::collection($journals);
    }

    public function show(Request $request, string $journal): JournalResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.view'), 403);

        return new JournalResource(Journal::query()->where('company_id', $companyId)->with('lines.account')->findOrFail($journal));
    }

    public function store(StoreJournalRequest $request): JournalResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $isDraft = $request->string('status')->toString() === 'draft';
        $journal = DB::transaction(function () use ($request, $companyId, $isDraft): Journal {
            $journal = $isDraft ? $this->postingService->saveDraft($companyId, $request->user(), $request->validated()) : $this->postingService->post($companyId, $request->user(), $request->validated(), $this->idempotencyKey($request, true));
            if ($journal->wasRecentlyCreated) {
                $this->auditService->record($request, $request->user(), $companyId, $isDraft ? 'create_draft' : 'post', 'accounting', $journal, null, ['number' => $journal->number, 'status' => $journal->status, 'total' => $journal->lines->sum('debit')]);
            }

            return $journal;
        });

        return new JournalResource($journal);
    }

    public function update(StoreJournalRequest $request, string $journal): JournalResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $model = Journal::query()->where('company_id', $companyId)->findOrFail($journal);
        $oldValues = $model->load('lines')->toArray();
        $updated = DB::transaction(function () use ($request, $companyId, $model, $oldValues): Journal {
            $updated = $this->postingService->saveDraft($companyId, $request->user(), $request->validated(), $model);
            $this->auditService->record($request, $request->user(), $companyId, 'update_draft', 'accounting', $updated, $oldValues, $updated->toArray());

            return $updated;
        });

        return new JournalResource($updated);
    }

    public function post(StoreJournalRequest $request, ?string $journal = null): JournalResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $posted = DB::transaction(function () use ($request, $companyId, $journal): Journal {
            $posted = $journal ? $this->postingService->postDraft($companyId, $request->user(), Journal::query()->where('company_id', $companyId)->findOrFail($journal), $request->validated()) : $this->postingService->post($companyId, $request->user(), $request->validated(), $this->idempotencyKey($request, true));
            if ($journal !== null || $posted->wasRecentlyCreated) {
                $this->auditService->record($request, $request->user(), $companyId, 'post', 'accounting', $posted, null, ['number' => $posted->number, 'total' => $posted->lines->sum('debit')]);
            }

            return $posted;
        });

        return new JournalResource($posted);
    }

    public function reverse(Request $request, string $journal): JournalResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.post'), 403);
        $data = $request->validate(['posting_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']]);
        $model = Journal::query()->where('company_id', $companyId)->with('lines')->findOrFail($journal);
        $oldValues = $model->toArray();
        $reversal = DB::transaction(function () use ($request, $companyId, $model, $oldValues, $data): Journal {
            $reversal = $this->postingService->reverse($companyId, $request->user(), $model, $data['posting_date'], $data['reason'], $this->idempotencyKey($request));
            if ($reversal->wasRecentlyCreated) {
                $this->auditService->record($request, $request->user(), $companyId, 'reverse', 'accounting', $model, $oldValues, $model->fresh()->toArray());
            }

            return $reversal;
        });

        return new JournalResource($reversal);
    }

    private function idempotencyKey(Request $request, bool $required = false): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            if ($required) {
                throw ValidationException::withMessages(['idempotency_key' => 'An Idempotency-Key header is required for direct journal posting.']);
            }

            return null;
        }
        abort_if(mb_strlen($key) > 100, 422, 'The idempotency key must not exceed 100 characters.');

        return $key;
    }
}
