<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateCompanyExport;
use App\Models\CompanyExport;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyExportController extends Controller
{
    private const SECTIONS = ['settings', 'users', 'customers', 'suppliers', 'invoices', 'accounting_reports', 'employees', 'crm_accounts', 'crm_contacts', 'crm_leads', 'crm_deals'];

    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(CompanyExport::query()->where('company_id', $this->companyId($request))->latest()->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request, 'platform.exports.manage');
        $data = $request->validate(['sections' => ['required', 'array', 'min:1'], 'sections.*' => ['string', Rule::in(self::SECTIONS)]]);
        $export = CompanyExport::query()->create(['company_id' => $this->companyId($request), 'requested_by' => $request->user()->id, 'sections' => array_values(array_unique($data['sections']))]);
        GenerateCompanyExport::dispatch($export->id)->afterCommit();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'export_requested', 'platform', $export, null, $export->toArray());

        return response()->json($export, 202);
    }

    public function download(Request $request, CompanyExport $export): StreamedResponse
    {
        $this->owned($request, $export);
        $this->authorize($request);
        abort_unless($export->status === 'COMPLETED' && $export->storage_key !== null && ! $export->expires_at?->isPast(), 409, 'This export is not available.');

        return Storage::disk($export->storage_disk)->download($export->storage_key, "zavsync-company-{$export->company_id}.json", ['Content-Type' => 'application/json', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function owned(Request $request, CompanyExport $export): void
    {
        abort_unless($export->company_id === $this->companyId($request), 404);
    }

    private function authorize(Request $request, string $permission = 'platform.exports.view'): void
    {
        $this->access->authorize($request->user(), $this->companyId($request), $permission);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
