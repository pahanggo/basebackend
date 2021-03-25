<?php

namespace App\Http\Controllers\Reports;

use App\Models\Auth\Role;
use App\Traits\ReportTrait;

class R02RoleReport
{
    // report
    use ReportTrait;

    // only users with this permission can add & view this widget
    public function getRequiredPermission()
    {
        return 'Manage Roles and Permissions';
    }

    // grouping of report
    public function getGroupName() : string
    {
        return 'Access Control';
    }

    // what the user will see the widget name as
    public function getReportName() : string
    {
        return 'R02: Role Report';
    }

    // return query to get data
    protected function getQuery()
    {
        return Role::with('permissions', 'users')->orderBy('id');
    }

    protected function getViewPath() : string
    {
        return 'reports.role-report';
    }
}