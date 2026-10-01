<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Http\Controllers\Controller;
use App\Http\Resources\LegacyFbrEvidenceResource;
use App\Http\Resources\LegacyImportRunResource;
use App\Http\Resources\MigrationExceptionResource;
use App\Models\LegacyImportRun;
use App\Models\MigrationException;
use App\Models\PakistanFbrInvoice;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LegacyMigrationController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->authorize($request);

        return LegacyImportRunResource::collection(LegacyImportRun::query()->where('company_id', $companyId)->latest()->get());
    }

    public function show(Request $request, string $run): LegacyImportRunResource
    {
        $companyId = $this->authorize($request);

        return new LegacyImportRunResource(LegacyImportRun::query()->where('company_id', $companyId)->findOrFail($run));
    }

    public function exceptions(Request $request, string $run): AnonymousResourceCollection
    {
        $companyId = $this->authorize($request);
        $model = LegacyImportRun::query()->where('company_id', $companyId)->findOrFail($run);

        return MigrationExceptionResource::collection($model->exceptions()->orderByDesc('severity')->orderBy('created_at')->get());
    }

    public function evidence(Request $request, string $invoice): AnonymousResourceCollection
    {
        $companyId = $this->authorize($request);
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('is_historical', true)->findOrFail($invoice);

        return LegacyFbrEvidenceResource::collection($model->legacyFbrEvidence()->orderBy('source_created_at')->get());
    }

    public function resolve(Request $request, string $exception): MigrationExceptionResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'migration.manage');
        $data = $request->validate(['resolution_state' => ['required', 'in:RESOLVED,IGNORED'], 'resolution_note' => ['required', 'string', 'max:4000']]);
        $model = MigrationException::query()->where('company_id', $companyId)->findOrFail($exception);
        $old = $model->only(['resolution_state', 'resolution_note']);
        $model->update([...$data, 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        $this->audit->record($request, $request->user(), $companyId, 'migration_exception_resolved', 'migration', $model, $old, $model->only(['resolution_state', 'resolution_note']));

        return new MigrationExceptionResource($model);
    }

    private function authorize(Request $request): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'migration.view');

        return $companyId;
    }
}
