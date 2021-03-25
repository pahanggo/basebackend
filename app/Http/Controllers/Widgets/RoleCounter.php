<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\Auth\Role;
use App\Traits\DashboardWidgetTrait;

class RoleCounter extends Controller
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
        return 'Role Counter';
    }

    // return the view
    public function index()
    {
        return view('widgets.rolecounter', ['count' => Role::count()]);
    }
}
