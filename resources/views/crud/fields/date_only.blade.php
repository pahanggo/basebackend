{{-- date_only: a single-date picker (bootstrap-datepicker) that submits Y-m-d.

     The visible input is readonly and shows the date in `format`; a hidden input named
     $field['name'] carries the Y-m-d value that is actually submitted.

     Options:
       - format       => 'dd/mm/yyyy'   display format (bootstrap-datepicker syntax)
       - min_date     => null           'Y-m-d' string or 'today'
       - max_date     => null           'Y-m-d' string or 'today'
       - clear_button => true           show the "Clear" button in the picker
       - today_button => true           show the "Today" button in the picker
       - week_start   => 1              0 = Sunday ... 6 = Saturday
       - autoclose    => true           close the picker after choosing a date
       - language     => app locale     bootstrap-datepicker locale key (resolved with frontend_locale())
       - attributes   => []             extra attributes for the visible input
       - hint / default / wrapper as usual

     Accepts Carbon/DateTime instances, 'Y-m-d' and 'Y-m-d H:i:s' strings as the existing value. --}}
@php
    $normaliseDateOnlyValue = static function ($value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) ? $matches[1] : '';
    };

    $resolveDateOnlyLimit = static function ($limit) use ($normaliseDateOnlyValue): ?string {
        if ($limit === null || $limit === '') {
            return null;
        }

        return $limit === 'today' ? date('Y-m-d') : ($normaliseDateOnlyValue($limit) ?: null);
    };

    $value = $normaliseDateOnlyValue(old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '');

    $language = frontend_locale('packages/bootstrap-datepicker/dist/locales/bootstrap-datepicker.%s.min.js', $field['language'] ?? null) ?? 'en';

    $config = [
        'format' => $field['format'] ?? 'dd/mm/yyyy',
        'startDate' => $resolveDateOnlyLimit($field['min_date'] ?? null),
        'endDate' => $resolveDateOnlyLimit($field['max_date'] ?? null),
        'clearBtn' => (bool) ($field['clear_button'] ?? true),
        'todayBtn' => ($field['today_button'] ?? true) ? 'linked' : false,
        'weekStart' => (int) ($field['week_start'] ?? 1),
        'autoclose' => (bool) ($field['autoclose'] ?? true),
        'language' => $language,
    ];

    $field['attributes']['style'] = $field['attributes']['style'] ?? 'background-color: white!important;';
    $field['attributes']['readonly'] = $field['attributes']['readonly'] ?? 'readonly';
    $field['attributes']['autocomplete'] = $field['attributes']['autocomplete'] ?? 'off';

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'date_only';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <input type="hidden" class="date-only-value" name="{{ $field['name'] }}" value="{{ $value }}">
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    <div class="input-group date">
        <input
            type="text"
            data-init-function="bpFieldInitDateOnlyElement"
            data-date-only-config="{{ json_encode($config) }}"
            @include('crud::fields.inc.attributes')
            >
        <div class="input-group-append">
            <span class="input-group-text">
                <span class="la la-calendar"></span>
            </span>
        </div>
    </div>

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
        // the date_picker field ships the same plugin; do not load it twice when it rendered before us
        $datepickerAssetsNeeded = $crud->fieldTypeNotLoaded('date_picker');
    @endphp

    @if ($datepickerAssetsNeeded)
        {{-- FIELD CSS - will be loaded in the after_styles section --}}
        @push('crud_fields_styles')
        <link rel="stylesheet" href="{{ asset('packages/bootstrap-datepicker/dist/css/bootstrap-datepicker3.css') }}">
        @endpush
    @endif

    {{-- FIELD JS - will be loaded in the after_scripts section --}}
    @push('crud_fields_scripts')
    @if ($datepickerAssetsNeeded)
        <script src="{{ asset('packages/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js') }}"></script>
    @endif
    @if ($language !== 'en')
        <script charset="UTF-8" src="{{ asset('packages/bootstrap-datepicker/dist/locales/bootstrap-datepicker.'.$language.'.min.js') }}"></script>
    @endif
    <script>
        if (!$.fn.bootstrapDP) {
            $.fn.bootstrapDP = $.fn.datepicker;
        }

        /**
         * Turn a Y-m-d string into a local Date (null when empty/invalid).
         * Parts are passed individually because the Date constructor treats ISO strings as UTC.
         */
        function bpDateOnlyParseIso(value) {
            var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '');

            return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
        }

        function bpDateOnlyToIso(date) {
            var pad = function (number) { return (number < 10 ? '0' : '') + number; };

            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
        }

        function bpFieldInitDateOnlyElement(element) {
            var $visible = element,
                $hidden = $visible.closest('[data-field-type=date_only]').find('input.date-only-value'),
                config = $.extend({}, $visible.data('date-only-config')),
                syncing = false;

            config.startDate = bpDateOnlyParseIso(config.startDate) || -Infinity;
            config.endDate = bpDateOnlyParseIso(config.endDate) || Infinity;

            var $picker = $visible.bootstrapDP(config);

            // show the stored Y-m-d value (or the value the repeatable field set on the hidden input)
            // the picker silently drops dates outside startDate/endDate, so re-read what it accepted
            // and keep the hidden (submitted) value in sync with what the user actually sees
            var existing = bpDateOnlyParseIso($hidden.val());
            if (existing) {
                syncing = true;
                $picker.bootstrapDP('setDate', existing);
                syncing = false;
            }

            var accepted = $picker.bootstrapDP('getDate');
            $hidden.val(accepted && !isNaN(accepted.getTime()) ? bpDateOnlyToIso(accepted) : '');

            $picker.on('changeDate clearDate', function () {
                if (syncing) {
                    return;
                }

                var selected = $picker.bootstrapDP('getDate');

                $hidden.val(selected && !isNaN(selected.getTime()) ? bpDateOnlyToIso(selected) : '').trigger('change');
            });

            $visible.on('backpack_field.deleted', function () {
                $picker.bootstrapDP('destroy');
            });
        }
    </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
