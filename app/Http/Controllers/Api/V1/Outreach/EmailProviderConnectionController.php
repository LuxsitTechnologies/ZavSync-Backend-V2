<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\EmailProviderConnectionRequest;
use App\Http\Resources\EmailProviderConnectionResource;
use App\Models\EmailProviderConnection;
use App\Services\AuditService;
use App\Services\Outreach\OutreachEventService;
use App\Services\Outreach\ProviderConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailProviderConnectionController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly ProviderConnectionService $connections, private readonly OutreachEventService $events, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');

        return EmailProviderConnectionResource::collection(EmailProviderConnection::query()->where('company_id', $this->companyId($request))->withCount('identities')->orderBy('name')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(EmailProviderConnectionRequest $request): EmailProviderConnectionResource
    {
        $connection = EmailProviderConnection::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'status' => 'DISCONNECTED', 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'provider_connection_created', 'outreach', $connection, null, ['id' => $connection->id, 'name' => $connection->name, 'provider_type' => $connection->provider_type]);

        return new EmailProviderConnectionResource($connection);
    }

    public function show(Request $request, string $connection): EmailProviderConnectionResource
    {
        $this->authorizePermission($request, 'outreach.view');

        return new EmailProviderConnectionResource($this->connection($request, $connection)->loadCount('identities'));
    }

    public function update(EmailProviderConnectionRequest $request, string $connection): EmailProviderConnectionResource
    {
        $model = $this->connection($request, $connection);
        $old = ['name' => $model->name, 'provider_type' => $model->provider_type, 'status' => $model->status];
        $model->update([...$request->validated(), 'status' => 'DISCONNECTED', 'last_error' => null, 'updated_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'provider_connection_updated', 'outreach', $model, $old, ['name' => $model->name, 'provider_type' => $model->provider_type, 'status' => $model->status]);

        return new EmailProviderConnectionResource($model->fresh());
    }

    public function verify(Request $request, string $connection): EmailProviderConnectionResource
    {
        $this->authorizePermission($request, 'outreach.providers.manage');
        $model = $this->connections->verify($this->connection($request, $connection));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'provider_connection_verified', 'outreach', $model, null, ['status' => $model->status]);

        return new EmailProviderConnectionResource($model);
    }

    public function disconnect(Request $request, string $connection): EmailProviderConnectionResource
    {
        $this->authorizePermission($request, 'outreach.providers.manage');
        $model = $this->connections->disconnect($this->connection($request, $connection));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'provider_connection_disconnected', 'outreach', $model, null, ['status' => $model->status]);

        return new EmailProviderConnectionResource($model);
    }

    public function syncReplies(Request $request, string $connection): JsonResponse
    {
        $this->authorizePermission($request, 'outreach.send');

        return response()->json(['synchronized' => $this->events->synchronizeReplies($this->connection($request, $connection))]);
    }

    public function webhook(Request $request, string $connection): JsonResponse
    {
        $model = EmailProviderConnection::query()->findOrFail($connection);

        return response()->json(['accepted' => $this->events->receiveWebhook($model, $request)], 202);
    }

    private function connection(Request $request, string $id): EmailProviderConnection
    {
        return EmailProviderConnection::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }
}
