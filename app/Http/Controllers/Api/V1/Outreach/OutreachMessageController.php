<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Resources\OutreachMessageResource;
use App\Jobs\SendOutreachMessage;
use App\Models\OutreachMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutreachMessageController extends Controller
{
    use AuthorizesOutreachRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');
        $query = OutreachMessage::query()->where('company_id', $this->companyId($request))->with('sequence')->withCount('attempts');
        foreach (['sequence_id', 'state', 'recipient_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        } if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($builder) => $builder->where('subject', 'like', $term)->orWhere('to_email', 'like', $term)->orWhere('to_name', 'like', $term));
        }

        return OutreachMessageResource::collection($query->orderByDesc('scheduled_at')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function show(Request $request, string $message): OutreachMessageResource
    {
        $this->authorizePermission($request, 'outreach.view');

        return new OutreachMessageResource(OutreachMessage::query()->where('company_id', $this->companyId($request))->with(['sequence', 'events' => fn ($query) => $query->orderBy('occurred_at')])->withCount('attempts')->findOrFail($message));
    }

    public function retry(Request $request, string $message): OutreachMessageResource
    {
        $this->authorizePermission($request, 'outreach.send');
        $model = OutreachMessage::query()->where('company_id', $this->companyId($request))->findOrFail($message);
        abort_unless($model->state === 'FAILED', 409, 'Only failed messages can be retried.');
        $model->update(['state' => 'QUEUED', 'queued_at' => now(), 'scheduled_at' => now(), 'failure_message' => null]);
        SendOutreachMessage::dispatch($model->id)->afterCommit()->onQueue('outreach');

        return new OutreachMessageResource($model->fresh()->load('sequence')->loadCount('attempts'));
    }
}
