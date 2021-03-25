<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;

class ReportService
{
    static $availableReports = [];

    public static function all()
    {
        $hasPermissions = [];
        foreach(static::$availableReports as $groupName => $reportGroup) {
            foreach($reportGroup as $available) {
                if(!isset($hasPermissions[$groupName])) {
                    $hasPermissions[$groupName] = [];
                }
                if(!$available['middleware'] || user()->checkPermissionTo($available['middleware'])) {
                    $hasPermissions[$groupName][] = $available;
                }
            }
        }

        return $hasPermissions;
    }

    public static function setup()
    {
        Route::group([
            'as'         => 'reports.',
            'middleware' => array_merge([config('backpack.base.web_middleware', 'web')], ['can:Access Reports']),
            'prefix'     => config('backpack.base.route_prefix') . '/report',
            'namespace'  => 'App\Http\Controllers'
        ], function(){
            Route::get('/', [
                'as'   => 'index',
                'uses' => 'ReportController@index',
            ]);
        });

        foreach(scandir(app_path('Http/Controllers/Reports')) as $file) {
            if(is_dir(app_path('Http/Controllers/Reports') . '/' . $file)) continue;
            $className = substr($file, 0, strlen($file) - 4);
            $fqcn = "App\Http\Controllers\Reports\\" . $className;
            $instance = new $fqcn;
            if($instance->isEnabled()) {
                $instance->setup();
                if(!isset(static::$availableReports)) {
                    static::$availableReports = [];
                }
                if(!isset(static::$availableReports[$instance->getGroupName()])) {
                    static::$availableReports[$instance->getGroupName()] = [];
                }
                static::$availableReports[$instance->getGroupName()][] = [
                    'name' => $instance->getReportName(),
                    'title' => $instance->getReportTitle(),
                    'path' => $instance->getPath(),
                    'middleware' => $instance->getRequiredPermission()
                ];
            }
        }
    }
}