<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banking\ClassifyBankTransactionRequest;
use App\Http\Requests\Api\V1\Banking\MatchBankTransactionRequest;
use App\Http\Requests\Api\V1\Banking\StoreCashTransactionRequest;
use App\Http\Requests\Api\V1\Banking\StoreInternalTransferRequest;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankReconciliationMatch;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\SupplierBill;
use App\Services\AuditService;
use App\Services\Banking\BankingService;
use App\Services\Banking\BankMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BankTransactionController extends Controller
{
    public function __construct(private readonly BankMatchingService $matchingService, private readonly BankingService $bankingService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);
        $query = BankTransaction::query()->where('company_id', $this->companyId($request))->with('matches');
        if ($request->filled('financial_account_id')) {
            $query->where('financial_account_id', $request->string('financial_account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return BankTransactionResource::collection($query->orderByDesc('transaction_date')->paginate(min($request->integer('per_page', 50), 100)));
    }

    public function suggestions(Request $request, string $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);
        $transaction = $this->transaction($request, $bankTransaction);
        $suggestions = $this->matchingService->suggestions($transaction);
        if ($suggestions->isNotEmpty() && $transaction->status->value === 'unmatched') {
            $transaction->update(['status' => 'suggested']);
        }

        return response()->json(['data' => $suggestions]);
    }

    public function match(MatchBankTransactionRequest $request, string $bankTransaction): JsonResponse
    {
        $transaction = $this->transaction($request, $bankTransaction);
        $match = $this->matchingService->match($this->companyId($request), $request->user(), $transaction, $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'match', 'banking', $match, null, $match->withoutRelations()->toArray());

        return response()->json(['data' => $match]);
    }

    public function unmatch(Request $request, string $match): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.reconcile'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $model = BankReconciliationMatch::query()->where('company_id', $this->companyId($request))->findOrFail($match);
        $old = $model->toArray();
        $model = $this->matchingService->unmatch($this->companyId($request), $request->user(), $model, $data['reason']);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'unmatch', 'banking', $model, $old, $model->withoutRelations()->toArray());

        return response()->json(['data' => $model]);
    }

    public function classify(ClassifyBankTransactionRequest $request, string $bankTransaction): BankTransactionResource
    {
        $transaction = $this->bankingService->classify($this->companyId($request), $request->user(), $this->transaction($request, $bankTransaction), $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'classify_and_post', 'banking', $transaction, null, $transaction->withoutRelations()->toArray());

        return new BankTransactionResource($transaction);
    }

    public function cash(StoreCashTransactionRequest $request): BankTransactionResource
    {
        $transaction = $this->bankingService->cashTransaction($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'cash_transaction', 'banking', $transaction, null, $transaction->withoutRelations()->toArray());

        return new BankTransactionResource($transaction);
    }

    public function transfer(StoreInternalTransferRequest $request): JsonResponse
    {
        $transfer = $this->bankingService->internalTransfer($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'internal_transfer', 'banking', $transfer, null, $transfer->withoutRelations()->toArray());

        return response()->json(['data' => $transfer]);
    }

    public function customerReceipt(Request $request, string $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.post'), 403);
        $data = $request->validate(['invoice_id' => ['required', 'uuid', Rule::exists('invoices', 'id')->where('company_id', $this->companyId($request))], 'amount' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:5000']]);
        $transaction = $this->transaction($request, $bankTransaction)->load('financialAccount');
        $invoice = Invoice::query()->where('company_id', $this->companyId($request))->findOrFail($data['invoice_id']);
        $payment = $this->bankingService->customerReceipt($this->companyId($request), $request->user(), $transaction, $invoice, $data, $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'customer_receipt', 'banking', $payment, null, $payment->withoutRelations()->toArray());

        return response()->json(['data' => $payment]);
    }

    public function supplierPayment(Request $request, string $bankTransaction): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.post'), 403);
        $data = $request->validate(['supplier_bill_id' => ['required', 'uuid', Rule::exists('supplier_bills', 'id')->where('company_id', $this->companyId($request))], 'amount' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:5000']]);
        $transaction = $this->transaction($request, $bankTransaction)->load('financialAccount');
        $bill = SupplierBill::query()->where('company_id', $this->companyId($request))->findOrFail($data['supplier_bill_id']);
        $payment = $this->bankingService->supplierPayment($this->companyId($request), $request->user(), $transaction, $bill, $data, $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'supplier_payment', 'banking', $payment, null, $payment->withoutRelations()->toArray());

        return response()->json(['data' => $payment]);
    }

    private function transaction(Request $request, string $id): BankTransaction
    {
        return BankTransaction::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_if(! is_string($key) || trim($key) === '', 422, 'Idempotency-Key header is required.');

        return trim($key);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
