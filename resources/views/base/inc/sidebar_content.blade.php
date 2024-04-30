<!-- This file is used to store sidebar items, starting with Backpack\Base 0.9.0 -->
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('dashboard') }}"><i class="la la-home nav-icon"></i> {{ trans('backpack::base.dashboard') }}</a></li>

@can('Access Reports')
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('report') }}"><i class="la la-table nav-icon"></i> {{__('Reports')}}</a></li>
@endcan
@canany([
    'Manage Users',
    'Manage Roles and Permissions',
    'Manage Settings',
    'View System Logs',
])
<li class="nav-item nav-dropdown">
    <a class="nav-link nav-dropdown-toggle" href="#"><i class="nav-icon la la-users"></i> {{__('Administration')}}</a>
    <ul class="nav-dropdown-items">
        @can('Manage Users')
        <li class="nav-item"><a class="nav-link" href="{{ backpack_url('user') }}"><i class="nav-icon la la-user"></i> <span>{{__('Users')}}</span></a></li>
        @endcan
        @can('Manage Roles and Permissions')
        <li class="nav-item"><a class="nav-link" href="{{ backpack_url('role') }}"><i class="nav-icon la la-id-badge"></i> <span>{{__('Roles')}}</span></a></li>
        <li class="nav-item"><a class="nav-link" href="{{ backpack_url('permission') }}"><i class="nav-icon la la-key"></i> <span>{{__('Permissions')}}</span></a></li>
        @endcan
    </ul>
</li>
@endcanany