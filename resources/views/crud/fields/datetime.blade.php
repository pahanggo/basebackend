<!-- html5 datetime input -->

@php
// if the column has been cast to Carbon or Date (using attribute casting)
// get the value as a date string
if (isset($field['value']) && ($field['value'] instanceof \Carbon\CarbonInterface)) {
    $field['value'] = $field['value']->toDateTimeString();
}

$timestamp = strtotime(old(square_brackets_to_dots($field['name'])) ? old(square_brackets_to_dots($field['name'])) : (isset($field['value']) ? $field['value'] : (isset($field['default']) ? $field['default'] : '' )));

$value = $timestamp ? date('Y-m-d\TH:i:s', $timestamp) : '';
$readonly = (bool) ($field['readonly'] ?? false);
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => $value])
    @else
    <input
        type="datetime-local"
        name="{{ $field['name'] }}"
        value="{{ $value }}"
        @include('crud::fields.inc.attributes')
        >
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')
