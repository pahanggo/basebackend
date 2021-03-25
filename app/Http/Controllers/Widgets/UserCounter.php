<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\DashboardWidgetTrait;

class UserCounter extends Controller
{
    // this is needed for all dashboard widgets
    use DashboardWidgetTrait;

    public function getRequiredPermission()
    {
        return 'Manage Users';
    }

    // what the user will see the widget name as
    public function getWidgetName() : string
    {
        return 'User Counter';
    }

    // return the view
    public function index()
    {
        return view('widgets.usercounter', ['count' => User::count()]);
    }
}
