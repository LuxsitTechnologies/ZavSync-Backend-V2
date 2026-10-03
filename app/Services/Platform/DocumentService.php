<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyAnnouncement;
use App\Models\CrmAccount;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeExpenseClaim;
use App\Models\EmployeeTask;
use App\Models\EmployeeTicket;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\PayrollBatch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @var array<string, class-string<Model>> */
    private const TYPES = [
        'company' => Company::class,
        'customer' => Customer::class, 'invoice' => Invoice::class, 'supplier' => Supplier::class,
        'purchase_order' => PurchaseOrder::class, 'supplier_bill' => SupplierBill::class,
        'inventory_item' => InventoryItem::class, 'employee' => Employee::class, 'payroll_batch' => PayrollBatch::class,
        'crm_account' => CrmAccount::class, 'crm_lead' => CrmLead::class, 'crm_deal' => CrmDeal::class,
        'leave_request' => LeaveRequest::class,
        'employee_task' => EmployeeTask::class, 'employee_ticket' => EmployeeTicket::class,
        'announcement' => CompanyAnnouncement::class,
        'expense_claim' => EmployeeExpenseClaim::class,
    ];

    public function resolveOwnedEntity(string $companyId, string $type, string $id): Model
    {
        $class = self::TYPES[$type] ?? null;
        if ($class === null) {
            throw new PlatformException('FILE_NOT_ALLOWED', 'This document owner type is not supported.', 422);
        }

        $model = $class === Company::class
            ? $class::query()->whereKey($companyId)->whereKey($id)->first()
            : $class::query()->where('company_id', $companyId)->whereKey($id)->first();
        if ($model === null) {
            throw new PlatformException('TENANT_ACCESS_DENIED', 'The related record is unavailable in this company.', 404);
        }

        return $model;
    }

    public function store(string $companyId, User $user, string $type, string $id, string $category, UploadedFile $file): Document
    {
        $entity = $this->resolveOwnedEntity($companyId, $type, $id);
        $usedBytes = (int) Document::query()->where('company_id', $companyId)->sum('size_bytes');
        $this->entitlements->assertWithinLimit($companyId, 'document_storage_mb', $usedBytes, (int) $file->getSize(), 1_048_576);
        $disk = 'local';
        $path = $file->store("companies/{$companyId}/documents", $disk);
        if (! is_string($path)) {
            throw new PlatformException('FILE_STORAGE_FAILED', 'The document could not be stored.', 500);
        }

        try {
            return Document::query()->create([
                'company_id' => $companyId, 'uploaded_by' => $user->id,
                'documentable_type' => $entity->getMorphClass(), 'documentable_id' => (string) $entity->getKey(),
                'category' => $category, 'original_filename' => $file->getClientOriginalName(),
                'storage_disk' => $disk, 'storage_key' => $path,
                'mime_type' => (string) $file->getMimeType(), 'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function delete(Document $document): void
    {
        Storage::disk($document->storage_disk)->delete($document->storage_key);
        $document->delete();
    }
}
