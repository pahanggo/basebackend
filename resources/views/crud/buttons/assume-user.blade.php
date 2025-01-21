@can('Assume Users')
@if(user()->id != $entry->id)
	<a href="{{ route('users.assume', $entry->id) }}" class="btn btn-sm btn-link" data-style="zoom-in"><span class="ladda-label"><i class="la la-user"></i> {{__('Assume')}}</span></a>
@endif
@endcan