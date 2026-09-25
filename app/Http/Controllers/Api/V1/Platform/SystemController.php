<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = ['application' => true, 'database' => false, 'cache' => false, 'queue' => false, 'storage' => false];
        try {
            DB::select('select 1');
            $checks['database'] = true;
        } catch (Throwable) {
        }
        try {
            Cache::put('health:readiness', true, 10);
            $checks['cache'] = Cache::pull('health:readiness') === true;
        } catch (Throwable) {
        }
        try {
            $checks['queue'] = config('queue.default') !== null;
            $probe = 'health/readiness-'.bin2hex(random_bytes(8));
            Storage::disk('local')->put($probe, 'ok');
            $checks['storage'] = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);
        } catch (Throwable) {
        }
        $ready = ! in_array(false, $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'degraded', 'checks' => $checks], $ready ? 200 : 503);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $this->authorize($request);
        $jobs = DB::table('failed_jobs')->latest('failed_at')->paginate(25);
        $jobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);

            return ['id' => $job->id, 'uuid' => $job->uuid, 'connection' => $job->connection, 'queue' => $job->queue, 'type' => $payload['displayName'] ?? 'Unknown job', 'failed_at' => $job->failed_at, 'exception_summary' => strtok($job->exception, "\n")];
        });

        return response()->json($jobs);
    }

    public function retryJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorize($request, 'platform.jobs.manage');
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Failed job queued for retry.']);
    }

    public function schedule(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['tasks' => [
            ['command' => 'platform:expire-invitations', 'frequency' => 'hourly'],
            ['command' => 'platform:evaluate-subscriptions', 'frequency' => 'daily'],
            ['command' => 'queue:prune-failed --hours=720', 'frequency' => 'daily'],
        ]]);
    }

    private function authorize(Request $request, string $permission = 'platform.jobs.view'): void
    {
        abort_unless($request->user()->is_platform_admin, 403, 'Platform administrator access is required.');
        $this->access->authorize($request->user(), (string) $request->attributes->get('company_id'), $permission);
    }
}
