<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Services\Platform\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $query = PlatformNotification::query()->where('company_id', $companyId)->where('recipient_id', $request->user()->id);
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return response()->json(['unread_count' => (clone $query)->whereNull('read_at')->count(), 'notifications' => $query->latest()->paginate(30)]);
    }

    public function read(Request $request, PlatformNotification $notification): JsonResponse
    {
        $this->owned($request, $notification);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json($notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        PlatformNotification::query()->where('company_id', $this->companyId($request))->where('recipient_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(NotificationPreference::query()->where('company_id', $this->companyId($request))->where('user_id', $request->user()->id)->orderBy('type')->get());
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => ['required', 'array'], 'preferences.*.type' => ['required', 'string', 'max:80'], 'preferences.*.in_app_enabled' => ['required', 'boolean'], 'preferences.*.email_enabled' => ['required', 'boolean']]);
        $companyId = $this->companyId($request);
        foreach ($data['preferences'] as $preference) {
            if (in_array($preference['type'], NotificationService::CRITICAL_TYPES, true) && (! $preference['in_app_enabled'] || ! $preference['email_enabled'])) {
                throw new PlatformException('CRITICAL_NOTIFICATION_REQUIRED', 'Critical security notifications cannot be disabled.', 422);
            }
            NotificationPreference::query()->updateOrCreate(
                ['company_id' => $companyId, 'user_id' => $request->user()->id, 'type' => $preference['type']],
                ['in_app_enabled' => $preference['in_app_enabled'], 'email_enabled' => $preference['email_enabled']],
            );
        }

        return $this->preferences($request);
    }

    private function owned(Request $request, PlatformNotification $notification): void
    {
        abort_unless($notification->company_id === $this->companyId($request) && $notification->recipient_id === $request->user()->id, 404);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
