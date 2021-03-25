<?php

namespace App\Http\Controllers;

use App\Http\Middleware\LoadWidgetOnDashboardMiddleware;
use App\Services\WidgetService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminController extends Controller
{
    protected $data = []; // the information we send to the view

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(backpack_middleware());
    }

    /**
     * Show the admin dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard()
    {
        $this->data['title'] = trans('backpack::base.dashboard'); // set the page title
        $this->data['breadcrumbs'] = [
            trans('backpack::crud.admin')     => backpack_url('dashboard'),
            trans('backpack::base.dashboard') => false,
        ];
        $this->data['availableWidgets'] = WidgetService::all();
        return view(backpack_view('dashboard'), $this->data);
    }

    public function widgets()
    {
        return user()->widgets;
    }

    public function addWidgetRow()
    {
        $user = user();
        $widgets = $user->widgets;
        $widgets[] = [];
        $user->widgets = $widgets;
        $user->save();
        return $user->widgets;
    }

    public function removeWidgetRow(Request $request)
    {
        $user = user();
        $widgets = $user->widgets;
        array_splice($widgets, $request->index, 1);
        $user->widgets = $widgets;
        $user->save();
        return $user->widgets;
    }

    public function addWidget(Request $request)
    {
        $user = user();
        $widgets = $user->widgets;
        $widgets[$request->index][] = $request->widget;
        $user->widgets = $widgets;
        $user->save();
        return $user->widgets;
    }

    public function removeWidget(Request $request)
    {
        $user = user();
        $widgets = $user->widgets;
        array_splice($widgets[$request->index], $request->widget, 1);
        $user->widgets = $widgets;
        $user->save();
        return $user->widgets;
    }

    /**
     * Redirect to the dashboard.
     *
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function redirect()
    {
        // The '/admin' route is not to be used as a page, because it breaks the menu's active state.
        return redirect(backpack_url('dashboard'));
    }
}
