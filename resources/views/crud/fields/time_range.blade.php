{{-- time_range: a start and an end time on one row, using two native <input type="time"> inputs.

     'name' must be an array of two attribute names: ['opens_from', 'opens_to'].
     Each input is named after its attribute and submits 'H:i' (empty string when cleared).

     Options:
       - step            => 300            seconds between selectable values (applied to both inputs)
       - min             => null           'H:i' lower bound for both inputs
       - max             => null           'H:i' upper bound for both inputs
       - labels          => ['From', 'To'] input-group prepend texts (passed through __())
       - allow_overnight => false          when false, an inline hint is shown while end <= start (submit is not blocked)
       - attributes      => []             extra attributes for both inputs
       - default         => [start, end]   used when the entry has no value
       - readonly        => false          when true, show both values as plain text with no form controls and no name attributes (not submitted)
       - hint / wrapper as usual

     Accepts 'H:i' / 'H:i:s' strings, Carbon/DateTime instances and null as existing values. --}}
@php
    $normaliseTimeRangeValue = static function ($value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $value = trim((string) $value);

        return preg_match('/^(\d{2}:\d{2})/', $value, $matches) ? $matches[1] : '';
    };

    $names = is_array($field['name']) ? array_values($field['name']) : [$field['name'], $field['end_name'] ?? $field['name'].'_end'];
    $labels = $field['labels'] ?? ['From', 'To'];
    $allowOvernight = (bool) ($field['allow_overnight'] ?? false);
    $defaults = is_array($field['default'] ?? null) ? array_values($field['default']) : [];

    $values = [];
    foreach ($names as $index => $name) {
        $stored = null;
        if (isset($field['value']) && is_array($field['value'])) {
            $stored = $field['value'][$index] ?? $field['value'][$name] ?? null;
        } elseif (isset($entry)) {
            $stored = $entry->{$name} ?? null;
        }

        $values[$index] = $normaliseTimeRangeValue(old(square_brackets_to_dots($name)) ?? $stored ?? $defaults[$index] ?? '');
    }

    $field['attributes'] = $field['attributes'] ?? [];
    $inputClass = $field['attributes']['class'] ?? 'form-control';
    $field['attributes']['step'] = $field['attributes']['step'] ?? (int) ($field['step'] ?? 300);
    if (! empty($field['min'])) {
        $field['attributes']['min'] = $normaliseTimeRangeValue($field['min']);
    }
    if (! empty($field['max'])) {
        $field['attributes']['max'] = $normaliseTimeRangeValue($field['max']);
    }

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'time_range';
    $field['wrapper']['data-field-name'] = implode(',', $names);
    $readonly = (bool) ($field['readonly'] ?? false);
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => trim(($values[0] ?? '').' - '.($values[1] ?? ''), ' -')])
    @else
        <div class="form-row time-range-row">
            @foreach ($names as $index => $name)
                @php
                    $field['attributes']['class'] = $inputClass.' time-range-'.($index === 0 ? 'start' : 'end');
                @endphp
                <div class="col">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text small">{{ __($labels[$index] ?? ($index === 0 ? 'From' : 'To')) }}</span>
                        </div>
                        <input
                            type="time"
                            name="{{ $name }}"
                            value="{{ $values[$index] }}"
                            @if ($index === 0)
                                data-init-function="bpFieldInitTimeRangeElement"
                                data-allow-overnight="{{ $allowOvernight ? 1 : 0 }}"
                            @endif
                            @include('crud::fields.inc.attributes')
                            >
                    </div>
                </div>
            @endforeach
        </div>

        <p class="help-block text-warning time-range-overnight-hint mb-0" hidden>{{ __('End time must be after start time') }}</p>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
{{-- If a field type is shown multiple times on a form, the CSS and JS will only be loaded once --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    @push('crud_fields_scripts')
    <script>
        function bpFieldInitTimeRangeElement(element) {
            var $wrapper = element.closest('[data-field-type=time_range]'),
                $start = element,
                $end = $wrapper.find('input.time-range-end'),
                $hint = $wrapper.find('.time-range-overnight-hint'),
                allowOvernight = String(element.data('allow-overnight')) === '1';

            // native inputs may carry seconds when the browser fills them; submit H:i only
            var trimToMinutes = function ($input) {
                var value = $input.val() || '';
                if (value.length > 5) {
                    $input.val(value.substring(0, 5));
                }
            };

            var refreshHint = function () {
                trimToMinutes($start);
                trimToMinutes($end);

                var start = $start.val(),
                    end = $end.val(),
                    invalid = !allowOvernight && start && end && end <= start;

                $hint.prop('hidden', !invalid);
            };

            $start.add($end).on('change input', refreshHint);
            refreshHint();
        }
    </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
