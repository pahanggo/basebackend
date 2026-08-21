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
        foreach(scandir(app_path('Livewire/Widgets')) as $file) {
            if(is_dir(app_path('Livewire/Widgets') . '/' . $file)) continue;
            $className = substr($file, 0, strlen($file) - 4);
            $fqcn = "App\Livewire\Widgets\\" . $className;
            $instance = new $fqcn;
            if($instance->isEnabled()) {
                $instance->setup();
                if(!isset(static::$availableWidgets)) {
                    static::$availableWidgets = [];
                }
                $size = $instance->getDefaultSize();
                static::$availableWidgets[] = [
                    'name' => $instance->getWidgetName(),
                    'path' => $instance->getPath(),
                    'middleware' => $instance->getRequiredPermission(),
                    'w' => $size['w'],
                    'h' => $size['h'],
                ];
            }
        }
    }

    /**
     * Default GridStack size for a widget by its path slug, used by the
     * legacy-layout migrator and by Dashboard::addWidget(). Falls back to
     * the DashboardWidgetTrait default if the path is stale/unrecognized.
     *
     * @return array{w: int, h: int}
     */
    public static function defaultSizeFor(string $path): array
    {
        foreach (static::$availableWidgets as $available) {
            if ($available['path'] === $path) {
                return ['w' => $available['w'], 'h' => $available['h']];
            }
        }

        return ['w' => 3, 'h' => 2];
    }
}
