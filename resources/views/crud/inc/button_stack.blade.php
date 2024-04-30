@if($stack == $crud->get('stack-action-buttons'))
<div class="dropdown">
	<button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
	  ...
	</button>
	<div class="dropdown-menu dropdown-menu-right">
	@if ($crud->buttons()->where('stack', $stack)->count())
		@foreach ($crud->buttons()->where('stack', $stack) as $button)
		{!! $button->getHtml($entry ?? null) !!}
		@endforeach
	@endif
	</div>
</div>
@else
@if ($crud->buttons()->where('stack', $stack)->count())
	@foreach ($crud->buttons()->where('stack', $stack) as $button)
	{!! $button->getHtml($entry ?? null) !!}
	@endforeach
@endif
@endif
