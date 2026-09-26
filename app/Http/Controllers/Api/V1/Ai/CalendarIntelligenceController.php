<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Ai\CalendarIntelligenceService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarIntelligenceController extends Controller
{
    public function __construct(private readonly CalendarIntelligenceService $calendar, private readonly PlatformAccessService $access) {}

    public function capability(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.calendar.manage');

        return response()->json($this->calendar->capability($this->companyId($request), $request->user()));
    }

    public function events(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.calendar.manage');

        return response()->json(CalendarEvent::query()->where('company_id', $this->companyId($request))->whereHas('connection', fn ($query) => $query->where('user_id', $request->user()->id))->orderBy('starts_at')->paginate(50));
    }

    public function synchronize(Request $request): JsonResponse
    {
        return response()->json($this->calendar->synchronize($this->companyId($request), $request->user()), 202);
    }

    public function meetingContext(Request $request, string $event): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.calendar.manage');

        return response()->json($this->calendar->meetingContext($this->companyId($request), $request->user(), $event));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
