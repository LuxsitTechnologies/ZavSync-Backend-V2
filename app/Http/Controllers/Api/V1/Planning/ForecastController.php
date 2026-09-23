<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\StoreForecastRequest;
use App\Http\Requests\Api\V1\Planning\UpdateForecastRequest;
use App\Http\Resources\ForecastResource;
use App\Models\Forecast;
use App\Services\AuditService;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ForecastController extends Controller
{
    public function __construct(private readonly PlanningService $planning, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return ForecastResource::collection(Forecast::query()->where('company_id', $this->companyId($request))->with('fiscalYear')->orderByDesc('created_at')->get());
    }

    public function store(StoreForecastRequest $request): ForecastResource
    {
        $forecast = $this->planning->createForecast($this->companyId($request), $request->user(), $request->validated());
        $this->record($request, 'create', $forecast);

        return new ForecastResource($forecast);
    }

    public function show(Request $request, string $forecast): ForecastResource
    {
        $this->authorizeView($request);

        return new ForecastResource($this->forecast($request, $forecast)->load(['fiscalYear.periods', 'lines.account', 'lines.period']));
    }

    public function update(UpdateForecastRequest $request, string $forecast): ForecastResource
    {
        $model = $this->forecast($request, $forecast);
        $old = $model->toArray();
        $model = $this->planning->updateForecast($this->companyId($request), $model, $request->validated());
        $this->record($request, 'update', $model, $old);

        return new ForecastResource($model);
    }

    public function activate(Request $request, string $forecast): ForecastResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'forecast.manage'), 403);
        $model = $this->planning->activateForecast($this->companyId($request), $request->user(), $this->forecast($request, $forecast));
        $this->record($request, 'activate', $model);

        return new ForecastResource($model);
    }

    public function projection(Request $request, string $forecast): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json($this->planning->forecastProjection($this->companyId($request), $this->forecast($request, $forecast), $request->query('as_of')));
    }

    private function forecast(Request $request, string $id): Forecast
    {
        return Forecast::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'forecast.view'), 403);
    }

    /** @param array<string, mixed>|null $old */
    private function record(Request $request, string $action, Forecast $forecast, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'forecast', $forecast, $old, $forecast->toArray());
    }
}
