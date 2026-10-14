<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InternalCalendarEvent;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternalCalendarEventController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->authorizeOrganizer($request);
        $data = $request->validate([
            'from' => ['nullable', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => ['nullable', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/', 'after:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = InternalCalendarEvent::query()->where('company_id', $companyId)->where('organizer_user_id', $request->user()->id);
        if (isset($data['from'])) {
            $query->where('ends_at', '>=', CarbonImmutable::parse($data['from'])->utc());
        }
        if (isset($data['to'])) {
            $query->where('starts_at', '<=', CarbonImmutable::parse($data['to'])->utc());
        }

        return response()->json($query->orderBy('starts_at')->orderBy('id')->paginate($data['per_page'] ?? 25)
            ->through(fn (InternalCalendarEvent $event): array => $this->resource($event)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->authorizeOrganizer($request);
        $data = $this->eventData($request);
        $company = Company::query()->findOrFail($companyId);
        $event = DB::transaction(function () use ($request, $company, $data): InternalCalendarEvent {
            $event = InternalCalendarEvent::query()->create([
                ...$data, 'company_id' => $company->id, 'organizer_user_id' => $request->user()->id,
                'timezone' => $company->timezone, 'status' => 'ACTIVE', 'version' => 1,
            ]);
            $this->audit->record($request, $request->user(), $company->id, 'internal_calendar_event_created', 'ai', $event,
                null, ['status' => 'ACTIVE', 'version' => 1]);

            return $event;
        });

        return response()->json($this->resource($event), 201);
    }

    public function show(Request $request, string $event): JsonResponse
    {
        return response()->json($this->resource($this->ownedEvent($request, $event)));
    }

    public function update(Request $request, string $event): JsonResponse
    {
        $companyId = $this->authorizeOrganizer($request);
        $data = $this->eventData($request, false);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $result = DB::transaction(function () use ($request, $companyId, $event, $data, $version): InternalCalendarEvent {
            $model = $this->ownedEvent($request, $event, true);
            $this->assertActiveVersion($model, $version);
            $startsAt = $data['starts_at'] ?? $model->starts_at;
            $endsAt = $data['ends_at'] ?? $model->ends_at;
            $this->assertInterval($startsAt, $endsAt);
            $previousVersion = $model->version;
            $model->update([...$data, 'version' => $previousVersion + 1]);
            $this->audit->record($request, $request->user(), $companyId, 'internal_calendar_event_updated', 'ai', $model,
                ['version' => $previousVersion], ['version' => $model->version]);

            return $model;
        });

        return response()->json($this->resource($result));
    }

    public function cancel(Request $request, string $event): JsonResponse
    {
        $companyId = $this->authorizeOrganizer($request);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $result = DB::transaction(function () use ($request, $companyId, $event, $version): InternalCalendarEvent {
            $model = $this->ownedEvent($request, $event, true);
            if ($model->status === 'CANCELLED') {
                return $model;
            }
            $this->assertActiveVersion($model, $version);
            $previousVersion = $model->version;
            $model->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'version' => $previousVersion + 1]);
            $this->audit->record($request, $request->user(), $companyId, 'internal_calendar_event_cancelled', 'ai', $model,
                ['status' => 'ACTIVE', 'version' => $previousVersion], ['status' => 'CANCELLED', 'version' => $model->version]);

            return $model;
        });

        return response()->json($this->resource($result));
    }

    private function authorizeOrganizer(Request $request): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'intelligence.calendar.manage');

        return $companyId;
    }

    private function ownedEvent(Request $request, string $event, bool $lock = false): InternalCalendarEvent
    {
        $query = InternalCalendarEvent::query()->where('company_id', $this->authorizeOrganizer($request))
            ->where('organizer_user_id', $request->user()->id)->whereKey($event);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function eventData(Request $request, bool $create = true): array
    {
        $presence = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$presence, 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'starts_at' => [$presence, 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'ends_at' => [$presence, 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ]);
        if (isset($data['starts_at'])) {
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
        }
        if (isset($data['ends_at'])) {
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();
        }
        if ($create) {
            $this->assertInterval($data['starts_at'], $data['ends_at']);
        }

        return $data;
    }

    private function assertInterval(CarbonImmutable|Carbon $startsAt, CarbonImmutable|Carbon $endsAt): void
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
    }

    private function assertActiveVersion(InternalCalendarEvent $event, int $version): void
    {
        if ($event->status !== 'ACTIVE') {
            throw new PlatformException('CALENDAR_EVENT_CANCELLED', 'A cancelled event cannot be edited.', 409);
        }
        if ($event->version !== $version) {
            throw new PlatformException('CALENDAR_EVENT_VERSION_STALE', 'The calendar event changed; reload it before saving.', 409);
        }
        if ($event->version >= PHP_INT_MAX) {
            throw new PlatformException('CALENDAR_EVENT_VERSION_EXHAUSTED', 'The calendar event version is exhausted.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function resource(InternalCalendarEvent $event): array
    {
        return [
            'id' => $event->id, 'title' => $event->title, 'description' => $event->description,
            'starts_at' => $event->starts_at?->toISOString(), 'ends_at' => $event->ends_at?->toISOString(),
            'timezone' => $event->timezone, 'status' => $event->status, 'version' => $event->version,
            'cancelled_at' => $event->cancelled_at?->toISOString(),
        ];
    }
}
