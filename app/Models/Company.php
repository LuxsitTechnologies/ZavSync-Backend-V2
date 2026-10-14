<?php

namespace App\Models;

use App\Exceptions\PlatformException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new PlatformException('COMPANY_DELETION_RETAINED', 'Company records are retained; deactivate the company instead.', 409);
        });
    }

    protected $fillable = ['name', 'slug', 'currency', 'timezone', 'is_active', 'approval_status', 'company_size', 'province_code', 'profile_version', 'created_by', 'creation_idempotency_key', 'creation_idempotency_hash', 'billing_policy_applies'];

    protected $hidden = ['creation_idempotency_key', 'creation_idempotency_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'billing_policy_applies' => 'boolean', 'profile_version' => 'integer', 'fbr_credit_note_sequence' => 'integer'];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function supplierBills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    public function accountingCloseRecords(): HasMany
    {
        return $this->hasMany(AccountingCloseRecord::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    public function fbrConfiguration(): HasOne
    {
        return $this->hasOne(FbrCompanyConfiguration::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(CompanyEntitlement::class);
    }
}
