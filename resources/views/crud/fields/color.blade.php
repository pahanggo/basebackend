<!-- html5 color input -->
@php
    $readonly = (bool) ($field['readonly'] ?? false);
    $colorValue = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
@endphp
@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['raw' => true, 'value' => ($colorValue !== '' ? '<span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;margin-right:.4em;border:1px solid #ccc;background:'.e($colorValue).'"></span>' : '').e($colorValue)])
    @else
    <input
    	type="color"
    	name="{{ $field['name'] }}"
        value="{{ $colorValue }}"
        @include('crud::fields.inc.attributes')
    	>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')