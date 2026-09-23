<?php

use App\Http\Controllers\Api\V1\Accounting\AccountController;
use App\Http\Controllers\Api\V1\Accounting\AccountingPeriodController;
use App\Http\Controllers\Api\V1\Accounting\AccountMappingController;
use App\Http\Controllers\Api\V1\Accounting\CustomerController;
use App\Http\Controllers\Api\V1\Accounting\FbrInvoiceController;
use App\Http\Controllers\Api\V1\Accounting\FinancialReportController;
use App\Http\Controllers\Api\V1\Accounting\InvoiceActionController;
use App\Http\Controllers\Api\V1\Accounting\InvoiceController;
use App\Http\Controllers\Api\V1\Accounting\JournalController;
use App\Http\Controllers\Api\V1\Accounting\LedgerController;
use App\Http\Controllers\Api\V1\Accounting\PayableController;
use App\Http\Controllers\Api\V1\Accounting\PurchaseOrderActionController;
use App\Http\Controllers\Api\V1\Accounting\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Accounting\PurchaseReceiptController;
use App\Http\Controllers\Api\V1\Accounting\ReceivableController;
use App\Http\Controllers\Api\V1\Accounting\SupplierBillActionController;
use App\Http\Controllers\Api\V1\Accounting\SupplierBillController;
use App\Http\Controllers\Api\V1\Accounting\SupplierController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'current']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('company')->group(function (): void {
            Route::get('accounting/accounts/selectable', [AccountController::class, 'selectable']);
            Route::patch('accounting/accounts/{account}/status', [AccountController::class, 'status']);
            Route::apiResource('accounting/accounts', AccountController::class)->except('destroy');
            Route::apiResource('accounting/periods', AccountingPeriodController::class)->only(['index', 'store', 'update']);
            Route::post('accounting/journals/post', [JournalController::class, 'post']);
            Route::post('accounting/journals/{journal}/post', [JournalController::class, 'post']);
            Route::post('accounting/journals/{journal}/reverse', [JournalController::class, 'reverse']);
            Route::apiResource('accounting/journals', JournalController::class)->only(['index', 'show', 'store', 'update']);
            Route::get('accounting/ledger', [LedgerController::class, 'index']);
            Route::get('accounting/ledger/trial-balance', [LedgerController::class, 'trialBalance']);
            Route::get('accounting/reports/trial-balance', [FinancialReportController::class, 'trialBalance']);
            Route::get('accounting/reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss']);
            Route::get('accounting/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('accounting/settings/account-mappings', [AccountMappingController::class, 'index']);
            Route::patch('accounting/settings/account-mappings/{key}', [AccountMappingController::class, 'update']);
            Route::apiResource('accounting/customers', CustomerController::class);
            Route::post('accounting/invoices/{invoice}/post', [InvoiceActionController::class, 'post']);
            Route::post('accounting/invoices/{invoice}/void', [InvoiceActionController::class, 'void']);
            Route::apiResource('accounting/invoices', InvoiceController::class);
            Route::get('accounting/fbr/invoices', [FbrInvoiceController::class, 'index']);
            Route::post('accounting/fbr/invoices/{invoice}/submit', [FbrInvoiceController::class, 'submit']);
            Route::get('accounting/receivables/customers', [ReceivableController::class, 'customers']);
            Route::get('accounting/receivables/invoices', [ReceivableController::class, 'invoices']);
            Route::get('accounting/receivables/payments', [ReceivableController::class, 'payments']);
            Route::post('accounting/receivables/invoices/{invoice}/payments', [ReceivableController::class, 'recordPayment']);
            Route::get('accounting/receivables/aging', [ReceivableController::class, 'aging']);
            Route::get('accounting/receivables/customers/{customer}/ledger', [ReceivableController::class, 'ledger']);
            Route::get('accounting/receivables/customers/{customer}/statement', [ReceivableController::class, 'statement']);
            Route::apiResource('accounting/payables/suppliers', SupplierController::class);
            Route::post('purchases/orders/{order}/submit', [PurchaseOrderActionController::class, 'submit']);
            Route::post('purchases/orders/{order}/approve', [PurchaseOrderActionController::class, 'approve']);
            Route::post('purchases/orders/{order}/reject', [PurchaseOrderActionController::class, 'reject']);
            Route::post('purchases/orders/{order}/cancel', [PurchaseOrderActionController::class, 'cancel']);
            Route::post('purchases/orders/{order}/convert-to-bill', [PurchaseOrderActionController::class, 'convertToBill']);
            Route::get('purchases/orders/{order}/receipts', [PurchaseReceiptController::class, 'index']);
            Route::post('purchases/orders/{order}/receipts', [PurchaseReceiptController::class, 'store']);
            Route::get('purchases/receipts/{receipt}', [PurchaseReceiptController::class, 'show']);
            Route::apiResource('purchases/orders', PurchaseOrderController::class)->parameters(['orders' => 'order']);
            Route::post('accounting/payables/bills/{bill}/post', [SupplierBillActionController::class, 'post']);
            Route::post('accounting/payables/bills/{bill}/void', [SupplierBillActionController::class, 'void']);
            Route::apiResource('accounting/payables/bills', SupplierBillController::class)->parameters(['bills' => 'bill']);
            Route::get('accounting/payables/summary/suppliers', [PayableController::class, 'suppliers']);
            Route::get('accounting/payables/open-bills', [PayableController::class, 'bills']);
            Route::get('accounting/payables/payments', [PayableController::class, 'payments']);
            Route::post('accounting/payables/bills/{bill}/payments', [PayableController::class, 'recordPayment']);
            Route::get('accounting/payables/aging', [PayableController::class, 'aging']);
            Route::get('accounting/payables/suppliers/{supplier}/ledger', [PayableController::class, 'ledger']);
            Route::get('accounting/payables/suppliers/{supplier}/statement', [PayableController::class, 'statement']);
        });
    });
});
