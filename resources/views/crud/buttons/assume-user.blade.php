@can('Assume Users')
@if(user()->id != $entry->id)
	<a href="{{ route('users.assume', $entry->id) }}" class="btn btn-sm btn-link" data-style="zoom-in" data-toggle="tooltip" title="{{ __('Assume') }}"><span class="ladda-label"><i class="la la-user"></i><span class="sr-only">{{ __('Assume') }}</span></span></a>
@endif
@endcan