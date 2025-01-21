<?php

namespace App\Services;

class SettingsRenderer
{
    protected $config = [];

    public function __construct($config) {
        $this->config = $config;
    }

    public function render()
    {
        $user = user();
        if(!$user) return '';

        $settings = [];
        foreach($this->config as $group => $routes)
        {
            $canAccess = false;
            foreach($routes as $title => $config)
            {
                if(!isset($config['permissions'])) {
                    $canAccess = true;
                } else if($user->hasAnyPermission($config['permissions'])) {
                    $canAccess = true;
                } else if($config['permissions'] === false) {
                    $canAccess = false;
                    break;
                }
            }

            if($canAccess) {
                $setting = [];
                foreach($routes as $title => $config)
                {
                    $canAccess = false;
                    if(!isset($config['permissions'])) {
                        $canAccess = true;
                    } else if($user->hasAnyPermission($config['permissions'])) {
                        $canAccess = true;
                    } else if($config['permissions'] === false) {
                        $canAccess = false;
                    }

                    if($canAccess) {
                        $setting[$title] = backpack_url($config['path']);
                    }
                }

                if($setting) {
                    $settings[$group] = $setting;
                }
            }
        }

        $str = '';

        foreach($settings as $group => $children) {
            $str .= '<div class="col-md-3"><div class="card"><div class="card-header">' . $group . '</div>';
            foreach($children as $name => $path) {
                $str .= '<a href="' . $path . '" class="list-group-item">' . $name . '</a>';
            }
            $str .= '</div>
            </div>';
        }

        return $str;
    }
}