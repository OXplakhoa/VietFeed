<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\AuthorizationAudit;
use Illuminate\View\View;

final class AuthorizationAuditController
{
    public function __invoke(AuthorizationAudit $audit): View
    {
        return view('admin.authorization.audit', ['events' => $audit->recent()]);
    }
}
