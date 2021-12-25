<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\CrudController;
use App\Http\Requests\Auth\UserStoreCrudRequest as StoreRequest;
use App\Http\Requests\Auth\UserUpdateCrudRequest as UpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Prologue\Alerts\Facades\Alert;
use Ramsey\Uuid\Uuid;

class UserCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation { store as traitStore; }
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation { update as traitUpdate; }
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;

    public function setup()
    {
        $this->crud->setModel(config('backpack.permissionmanager.models.user'));
        $this->crud->setEntityNameStrings(trans('backpack::permissionmanager.user'), trans('backpack::permissionmanager.users'));
        $this->crud->setRoute(backpack_url('user'));
    }

    public function setupListOperation()
    {
        $this->crud->addColumns([
            [
                'name'  => 'name',
                'label' => trans('backpack::permissionmanager.name'),
                'type'  => 'text',
            ],
            [
                'name'  => 'email',
                'label' => trans('backpack::permissionmanager.email'),
                'type'  => 'email',
            ],
            [ // n-n relationship (with pivot table)
                'label'     => trans('backpack::permissionmanager.roles'), // Table column heading
                'type'      => 'select_multiple',
                'name'      => 'roles', // the method that defines the relationship in your Model
                'entity'    => 'roles', // the method that defines the relationship in your Model
                'attribute' => 'name', // foreign key attribute that is shown to user
                'model'     => config('permission.models.role'), // foreign key model
            ],
        ]);

        // Role Filter
        $this->crud->addFilter(
            [
                'name'  => 'role',
                'type'  => 'dropdown',
                'label' => trans('backpack::permissionmanager.role'),
            ],
            config('permission.models.role')::all()->pluck('name', 'id')->toArray(),
            function ($value) { // if the filter is active
                $this->crud->addClause('whereHas', 'roles', function ($query) use ($value) {
                    $query->where('role_id', '=', $value);
                });
            }
        );

        // Extra Permission Filter
        $this->crud->addFilter(
            [
                'name'  => 'permissions',
                'type'  => 'select2',
                'label' => trans('backpack::permissionmanager.extra_permissions'),
            ],
            config('permission.models.permission')::all()->pluck('name', 'id')->toArray(),
            function ($value) { // if the filter is active
                $this->crud->addClause('whereHas', 'permissions', function ($query) use ($value) {
                    $query->where('permission_id', '=', $value);
                });
            }
        );

        $this->crud->addButtonFromView('line', 'assume-user', 'assume-user', 'beginning');
    }

    public function setupCreateOperation()
    {
        $this->addUserFields();
        $this->crud->setValidation(StoreRequest::class);
    }

    public function setupUpdateOperation()
    {
        $this->addUserFields();
        $this->crud->setValidation(UpdateRequest::class);
    }

    /**
     * Store a newly created resource in the database.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store()
    {
        $this->crud->setRequest($this->crud->validateRequest());
        $this->crud->setRequest($this->handlePasswordInput($this->crud->getRequest()));
        $this->crud->unsetValidation(); // validation has already been run

        return $this->traitStore();
    }

    /**
     * Update the specified resource in the database.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update()
    {
        $this->crud->setRequest($this->crud->validateRequest());
        $this->crud->setRequest($this->handlePasswordInput($this->crud->getRequest()));
        $this->crud->unsetValidation(); // validation has already been run

        return $this->traitUpdate();
    }

    /**
     * Handle password input fields.
     */
    protected function handlePasswordInput($request)
    {
        // Remove fields not present on the user.
        $request->request->remove('password_confirmation');
        $request->request->remove('roles_show');
        $request->request->remove('permissions_show');

        // Encrypt password if specified.
        if ($request->input('password')) {
            $request->request->set('password', Hash::make($request->input('password')));
        } else {
            $request->request->remove('password');
        }

        return $request;
    }

    protected function addUserFields()
    {
        $this->crud->addFields([
            [
                'name'  => 'name',
                'label' => trans('backpack::permissionmanager.name'),
                'type'  => 'text',
                'tab'   => __('Account'),
            ],
            [
                'name'    => 'username',
                'label'   => 'Username',
                'type'    => 'text',
                'wrapper' => ['class' => 'form-group col-md-6'],
                'tab'     => __('Account'),
            ],
            [
                'name'    => 'email',
                'label'   => trans('backpack::permissionmanager.email'),
                'type'    => 'email',
                'wrapper' => ['class' => 'form-group col-md-6'],
                'tab'     => __('Account'),
            ],
            [
                'name'    => 'password',
                'label'   => trans('backpack::permissionmanager.password'),
                'type'    => 'password',
                'wrapper' => ['class' => 'form-group col-md-6'],
                'tab'     => __('Account'),
            ],
            [
                'name'    => 'password_confirmation',
                'label'   => trans('backpack::permissionmanager.password_confirmation'),
                'type'    => 'password',
                'wrapper' => ['class' => 'form-group col-md-6'],
                'tab'     => __('Account'),
            ],
            [
                'label'     => trans('backpack::permissionmanager.roles'),
                'name'      => 'roles',
                'entity'    => 'roles',
                'attribute' => 'name',
                'model'     => config('permission.models.role'),
                'pivot'     => true,
                'type'      => 'select2_multiple',
                'tab'       => __('Account'),
            ],
        ]);
    }

    public function assume($userId)
    {
        Session::put('_assuming_user_id', user()->id);
        Auth::loginUsingId($userId);
        Alert::success('Logged in as user')->flash();
        return redirect()->to(backpack_url('dashboard'));
    }

    public function resume()
    {
        if(!Session::has('_assuming_user_id')) {
            return redirect()->back();
        }
        $userId = Session::pull('_assuming_user_id');
        Auth::loginUsingId($userId);
        Alert::success('Resumed session')->flash();
        return redirect()->to(backpack_url('user'));
    }

    public function updateAvatar(Request $request)
    {
        $user = backpack_user();
        $meta = explode(',', $request->payload);
        $content = base64_decode($meta[1]);
        $filename = Uuid::uuid4()->__toString() . '.png';

        $existingFile = str_replace('/storage', '', $user->getAvatarUrl());
        if(Storage::disk('public')->exists($existingFile)) {
            Storage::disk('public')->delete($existingFile);
        }
        Storage::disk('public')->put('/avatars/' . $filename, $content);
        $user->update(['avatar_url' => '/storage/avatars/' . $filename]);
        return $user->avatar_url;
    }
}
