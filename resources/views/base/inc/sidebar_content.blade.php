<!-- This file is used to store sidebar items, starting with Backpack\Base 0.9.0 -->
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('dashboard') }}"><i class="la la-home nav-icon"></i> {{ trans('backpack::base.dashboard') }}</a></li>

@can('Access Reports')
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('report') }}"><i class="la la-table nav-icon"></i> {{__('Reports')}}</a></li>
@endcan