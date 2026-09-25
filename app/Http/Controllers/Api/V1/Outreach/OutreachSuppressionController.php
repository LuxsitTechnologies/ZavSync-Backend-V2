<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\StoreOutreachSuppressionRequest;
use App\Http\Resources\OutreachSuppressionResource;
use App\Models\OutreachSuppression;
use App\Services\AuditService;
use App\Services\Outreach\SuppressionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutreachSuppressionController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly SuppressionService $suppressions, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');
        $query = OutreachSuppression::query()->where('company_id', $this->companyId($request));
        if ($request->filled('reason')) {
            $query->where('reason', $request->string('reason')->upper()->toString());
        } if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return OutreachSuppressionResource::collection($query->orderByDesc('suppressed_at')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(StoreOutreachSuppressionRequest $request): OutreachSuppressionResource
    {
        $model = $this->suppressions->suppress($this->companyId($request), $request->validated('email'), $request->validated('reason'), 'MANUAL', $request->user()->id, $request->validated('details'));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'suppression_added', 'outreach', $model, null, $model->toArray());

        return new OutreachSuppressionResource($model);
    }

    public function destroy(Request $request, string $suppression): OutreachSuppressionResource
    {
        $this->authorizePermission($request, 'outreach.suppressions.manage');
        $model = OutreachSuppression::query()->where('company_id', $this->companyId($request))->findOrFail($suppression);
        $model = $this->suppressions->remove($model, $request->user()->id);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'suppression_removed', 'outreach', $model, null, $model->toArray());

        return new OutreachSuppressionResource($model);
    }
}
