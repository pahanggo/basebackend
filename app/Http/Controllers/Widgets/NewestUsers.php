<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\DashboardWidgetTrait;

class NewestUsers extends Controller
{
    // this is needed for all dashboard widgets
    use DashboardWidgetTrait;

    // only users with this permission can add & view this widget
    public function getRequiredPermission()
    {
        return 'Manage Users';
    }

    // what the user will see the widget name as
    public function getWidgetName() : string
    {
        return 'Newest Users';
    }

    // return the view
    public function index()
    {
        return view('widgets.newest-users', [
            'users' => User::orderBy('id', 'desc')->take(5)->get()
        ]);
    }
}
