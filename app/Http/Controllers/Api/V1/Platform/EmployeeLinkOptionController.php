<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeLinkOptionResource;
use App\Models\Employee;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeLinkOptionController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'employee.links.manage');
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->select(['id', 'employee_code', 'full_name', 'status'])
            ->withExists('companyMembership');

        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $pattern = "%{$search}%";
            $query->where(function (Builder $employeeQuery) use ($pattern): void {
                $employeeQuery->where('employee_code', 'like', $pattern)
                    ->orWhere('full_name', 'like', $pattern);
            });
        }

        return EmployeeLinkOptionResource::collection(
            $query->orderBy('employee_code')->orderBy('id')->paginate($data['per_page'] ?? 25)
        );
    }
}
