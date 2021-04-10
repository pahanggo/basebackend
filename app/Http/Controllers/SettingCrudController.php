<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CrudController;
use App\Http\Requests\CreateSettingRequest;
use App\Models\Setting;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

class SettingCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;

    public function setup()
    {
        CRUD::setModel("App\Models\Setting");
        CRUD::setEntityNameStrings(trans('backpack::settings.setting_singular'), trans('backpack::settings.setting_plural'));
        CRUD::setRoute(backpack_url('setting'));
    }

    public function setupListOperation()
    {
        // only show settings which are marked as active
        CRUD::addClause('where', 'active', 1);

        // columns to show in the table view
        CRUD::setColumns([
            [
                'name'  => 'name',
                'label' => trans('backpack::settings.name'),
            ],
            [
                'name'  => 'value',
                'label' => trans('backpack::settings.value'),
            ],
            [
                'name'  => 'description',
                'label' => trans('backpack::settings.description'),
            ],
        ]);
    }

    public function setupCreateOperation()
    {
        $this->crud->setValidation(CreateSettingRequest::class);
        CRUD::addField([
            'name'       => 'name',
            'label'      => trans('backpack::settings.name'),
            'type'       => 'text',
            'attributes' => [
                'required' => 'required',
            ],
        ]);
        CRUD::addField([
            'name'       => 'description',
            'label'      => 'Description',
            'type'       => 'textarea',
            'attributes' => [
                'required' => 'required',
            ],
        ]);
        CRUD::addField([
            'name'       => 'field',
            'label'      => 'Field Type',
            'type'       => 'setting_field',
            'attributes' => [
                'required' => 'required',
            ],
        ]);
    }

    public function setupUpdateOperation()
    {
        CRUD::addField([
            'name'       => 'name',
            'label'      => trans('backpack::settings.name'),
            'type'       => 'text',
            'attributes' => [
                'disabled' => 'disabled',
            ],
        ]);

        $valueField = json_decode(CRUD::getCurrentEntry()->field, true);
        $valueField['value'] = Setting::get($valueField['name']);
        CRUD::addField($valueField);
    }
}
