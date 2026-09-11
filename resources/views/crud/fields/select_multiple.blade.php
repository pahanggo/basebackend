<!-- select multiple -->
@php
    if (!isset($field['options'])) {
        $options = $field['model']::all();
    } else {
        $options = call_user_func($field['options'], $field['model']::query());
    }
    $field['allows_null'] = $field['allows_null'] ?? true;
    $readonly = (bool) ($field['readonly'] ?? false);

    if ($readonly) {
        $selectedKeys = old(square_brackets_to_dots($field["name"])) ?? (isset($field['value']) ? $field['value']->pluck($field['model']::make()->getKeyName())->toArray() : []);
        $readonlyDisplay = $options->filter(fn ($option) => in_array($option->getKey(), $selectedKeys ?? []))->pluck($field['attribute'])->implode(', ');
    }
@endphp

@include('crud::fields.inc.wrapper_start')

    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => $readonlyDisplay])
    @else
    <select
    	class="form-control"
        name="{{ $field['name'] }}[]"
        @include('crud::fields.inc.attributes')
    	multiple>

		@if ($field['allows_null'])
			<option value="">-</option>
		@endif

    	@if (count($options))
    		@foreach ($options as $option)
				@if( (old(square_brackets_to_dots($field["name"])) && in_array($option->getKey(), old(square_brackets_to_dots($field["name"])))) || (is_null(old(square_brackets_to_dots($field["name"]))) && isset($field['value']) && in_array($option->getKey(), $field['value']->pluck($option->getKeyName(), $option->getKeyName())->toArray())))
					<option value="{{ $option->getKey() }}" selected>{{ $option->{$field['attribute']} }}</option>
				@else
					<option value="{{ $option->getKey() }}">{{ $option->{$field['attribute']} }}</option>
				@endif
    		@endforeach
    	@endif

	</select>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif

@include('crud::fields.inc.wrapper_end')
