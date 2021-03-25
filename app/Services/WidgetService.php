<?php

namespace App\Services;

class WidgetService
{
    static $availableWidgets = [];

    public static function all()
    {
        $hasPermissions = [];
        foreach(static::$availableWidgets as $available) {
            if(!$available['middleware'] || user()->checkPermissionTo($available['middleware'])) {
               $hasPermissions[] = $available;
            }
        }

        return $hasPermissions;
    }

    public static function setup()
    {
        foreach(scandir(app_path('Http/Controllers/Widgets')) as $file) {
            if(is_dir(app_path('Http/Controllers/Widgets') . '/' . $file)) continue;
            $className = substr($file, 0, strlen($file) - 4);
            $fqcn = "App\Http\Controllers\Widgets\\" . $className;
            $instance = new $fqcn;
            if($instance->isEnabled()) {
                $instance->setup();
                if(!isset(static::$availableWidgets)) {
                    static::$availableWidgets = [];
                }
                static::$availableWidgets[] = [
                    'name' => $instance->getWidgetName(),
                    'path' => $instance->getPath(),
                    'middleware' => $instance->getRequiredPermission()
                ];
            }
        }
    }
}