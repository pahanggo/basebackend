<?php

namespace App\Http\Controllers\Widgets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\DashboardWidgetTrait;

class Welcome extends Controller
{
    // this is needed for all dashboard widgets
    use DashboardWidgetTrait;

    // what the user will see the widget name as
    public function getWidgetName() : string
    {
        return 'Welcome';
    }

    // return the view
    public function index()
    {
        return view('widgets.welcome', [
            'user' => user()->load('roles')
        ]);
    }
}
