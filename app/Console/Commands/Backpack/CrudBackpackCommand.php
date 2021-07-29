<?php

namespace App\Console\Commands\Backpack;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrudBackpackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backpack:crud {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a CRUD interface: Controller, Model, Request';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = Str::of($this->argument('name'));
        $nameTitle = $name->camel()->ucfirst();
        $nameKebab = $nameTitle->kebab();
        $namePlural = $nameKebab->plural()->replace('-', ' ')->title();

        // Create the CRUD Model and show output
        $this->call('backpack:crud-model', ['name' => $nameTitle]);

        // Create the CRUD Controller and show output
        $this->call('backpack:crud-controller', ['name' => $nameTitle]);

        // Create the CRUD Request and show output
        $this->call('backpack:crud-request', ['name' => $nameTitle . 'Create']);
        $this->call('backpack:crud-request', ['name' => $nameTitle . 'Update']);

        // Create permissions
        $this->call('backpack:permission', ['name' => 'Manage ' . $namePlural]);

        // Create the CRUD route
        $this->call('backpack:add-custom-route', [
            'code' => "Route::group(['middleware' => 'can:Manage $namePlural'], function(){
        Route::crud('$nameKebab', '{$nameTitle}CrudController');
    });",
        ]);

        // Create the sidebar item
        $this->call('backpack:add-sidebar-content', [
            'code' => "@canany(['Manage $namePlural'])
<li class='nav-item'>
    <a class='nav-link' href='{{ backpack_url('$nameKebab') }}'>
        <i class='nav-icon la la-table'></i>
        {{__('$namePlural')}}
    </a>
</li>
@endcanany",
        ]);

        // if the application uses cached routes, we should rebuild the cache so the previous added route will
        // be acessible without manually clearing the route cache.
        if (app()->routesAreCached()) {
            $this->call('route:cache');
        }
    }
}
