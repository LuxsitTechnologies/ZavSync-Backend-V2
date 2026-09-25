<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use Illuminate\Http\Request;

trait AuthorizesOutreachRequests
{
    protected function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasCompanyPermission($this->companyId($request), $permission), 403);
    }
}
