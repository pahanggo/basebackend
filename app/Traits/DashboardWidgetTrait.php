<?php

namespace App\Traits;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

trait DashboardWidgetTrait {

    public function isEnabled() : bool
    {
        return true;
    }

    public function getRequiredPermission()
    {
        return null;
    }

    public function getPath() : string
    {
        return strtolower(class_basename($this));
    }

    public function getWidgetName() : string
    {
        if(property_exists($this, 'name')) {
            return $this->name;
        }
        return class_basename($this);
    }

    /**
     * Default GridStack size (12-column grid units) for this widget when it
     * is placed on the dashboard, either via legacy-layout migration or when
     * newly added through the "Tambah Widget" control.
     *
     * @return array{w: int, h: int}
     */
    public function getDefaultSize(): array
    {
        return ['w' => 3, 'h' => 2];
    }

    public function setup()
    {
        $middleware = [];
        if($this->getRequiredPermission()) {
            $middleware[] = 'can:' . $this->getRequiredPermission();
        }
        Route::group([
            'as'         => 'widgets.' . $this->getPath() . '.',
            'middleware' => array_merge([config('backpack.base.web_middleware', 'web')], $middleware),
            'prefix'     => config('backpack.base.route_prefix') . '/widgets/' . $this->getPath()
        ], function(){
            Route::get('/', [
                'as'   => 'index',
                'uses' => static::class . '@index',
            ]);
        });
    }

    public function index()
    {
        return '<div class="col-2"><div class="card">Not implemented</div></div>';
    }

    // protected

    protected function getClassName() : string
    {
        return class_basename($this);
    }

    protected function getLowerName() : string
    {
        return Str::slug($this->getWidgetName());
    }
}