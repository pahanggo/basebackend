<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\Auth\Permission;
use App\Traits\DashboardWidgetTrait;

class PermissionCounter extends Controller
{
    // this is needed for all dashboard widgets
    use DashboardWidgetTrait;

    public function getRequiredPermission()
    {
        return 'Manage Roles and Permissions';
    }

    // what the user will see the widget name as
    public function getWidgetName() : string
    {
        return 'Permission Counter';
    }

    // return the view
    public function index()
    {
        return view('widgets.permissioncounter', ['count' => Permission::count()]);
    }
}
