<?php

namespace App\Http\Controllers\Auth;

use Backpack\CRUD\app\Http\Controllers\CrudController;
use App\Http\Requests\Auth\PermissionStoreCrudRequest as StoreRequest;
use App\Http\Requests\Auth\PermissionUpdateCrudRequest as UpdateRequest;
use Illuminate\Support\Facades\Cache;

// VALIDATION

class PermissionCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;

    public function setup()
    {
        $this->role_model = $role_model = config('backpack.permissionmanager.models.role');
        $this->permission_model = $permission_model = config('backpack.permissionmanager.models.permission');

        $this->crud->setModel($permission_model);
        $this->crud->setEntityNameStrings(trans('backpack::permissionmanager.permission_singular'), trans('backpack::permissionmanager.permission_plural'));
        $this->crud->setRoute(backpack_url('permission'));

        // deny access according to configuration file
        if (config('backpack.permissionmanager.allow_permission_create') == false) {
            $this->crud->denyAccess('create');
        }
        if (config('backpack.permissionmanager.allow_permission_update') == false) {
            $this->crud->denyAccess('update');
        }
        if (config('backpack.permissionmanager.allow_permission_delete') == false) {
            $this->crud->denyAccess('delete');
        }
    }

    public function setupListOperation()
    {
        $this->crud->addColumn([
            'name'  => 'name',
            'label' => trans('backpack::permissionmanager.name'),
            'type'  => 'text',
        ]);

        if (config('backpack.permissionmanager.multiple_guards')) {
            $this->crud->addColumn([
                'name'  => 'guard_name',
                'label' => trans('backpack::permissionmanager.guard_type'),
                'type'  => 'text',
            ]);
        }

        $this->data['breadcrumbs'] = [
            trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
            'Settings' => backpack_url('settings'),
            $this->crud->entity_name_plural => url($this->crud->route),
            trans('backpack::crud.list') => false,
        ];
    }

    public function setupCreateOperation()
    {
        $this->addFields();
        $this->crud->setValidation(StoreRequest::class);

        //otherwise, changes won't have effect
        Cache::forget('spatie.permission.cache');

        $this->data['breadcrumbs'] = [
            trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
            'Settings' => backpack_url('settings'),
            $this->crud->entity_name_plural => url($this->crud->route),
            trans('backpack::crud.add') => false,
        ];
    }

    public function setupUpdateOperation()
    {
        $this->addFields();
        $this->crud->setValidation(UpdateRequest::class);

        //otherwise, changes won't have effect
        Cache::forget('spatie.permission.cache');

        $this->data['breadcrumbs'] = [
            trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
            'Settings' => backpack_url('settings'),
            $this->crud->entity_name_plural => url($this->crud->route),
            trans('backpack::crud.edit') => false,
        ];
    }

    private function addFields()
    {
        $this->crud->addField([
            'name'  => 'name',
            'label' => trans('backpack::permissionmanager.name'),
            'type'  => 'text',
        ]);

        if (config('backpack.permissionmanager.multiple_guards')) {
            $this->crud->addField([
                'name'    => 'guard_name',
                'label'   => trans('backpack::permissionmanager.guard_type'),
                'type'    => 'select_from_array',
                'options' => $this->getGuardTypes(),
            ]);
        }
    }

    /*
     * Get an array list of all available guard types
     * that have been defined in app/config/auth.php
     *
     * @return array
     **/
    private function getGuardTypes()
    {
        $guards = config('auth.guards');

        $returnable = [];
        foreach ($guards as $key => $details) {
            $returnable[$key] = $key;
        }

        return $returnable;
    }
}
