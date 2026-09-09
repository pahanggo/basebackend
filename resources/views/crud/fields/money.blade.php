{{-- money: a text input that formats while typing (thousands separator, fixed decimals)
     and submits a plain decimal string ("1234.50") through a hidden input named $field['name'].

     Options:
       - prefix              => 'RM'   rendered as an input-group prepend; set to '' or null for a plain formatted number
       - suffix              => null   rendered as an input-group append
       - decimals            => 2      digits after the decimal separator (0 disables decimals)
       - thousands_separator => ','
       - decimal_separator   => '.'
       - min                 => null   clamped on blur when set
       - max                 => null   clamped on blur when set
       - allow_negative      => false  accept a leading minus sign
       - attributes          => []     extra attributes for the visible input (class defaults to form-control);
                                       `disabled` is mirrored onto the hidden input so nothing is submitted
       - hint

     Typing accepts digits and one decimal separator and is reformatted on every keystroke (caret preserved);
     leaving the field pads/truncates to the fixed decimals. Pasting "1,234.5" or "RM 1234" parses.
     The hidden input is the source of truth, so it works inside repeatable and with old() after validation. --}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $prefix = array_key_exists('prefix', $field) ? $field['prefix'] : 'RM';
    $suffix = $field['suffix'] ?? null;
    $decimals = max(0, (int) ($field['decimals'] ?? 2));
    $thousandsSeparator = (string) ($field['thousands_separator'] ?? ',');
    $decimalSeparator = (string) ($field['decimal_separator'] ?? '.');
    $allowNegative = (bool) ($field['allow_negative'] ?? false);
    $hasAddon = ($prefix !== null && $prefix !== '') || ($suffix !== null && $suffix !== '');

    // normalise whatever came from the model/old() into a canonical decimal string for the hidden input
    $value = is_numeric($value) ? number_format((float) $value, $decimals, '.', '') : '';
    $display = $value === '' ? '' : number_format((float) $value, $decimals, $decimalSeparator, $thousandsSeparator);

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'money';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    <input
        type="hidden"
        name="{{ $field['name'] }}"
        value="{{ $value }}"
        data-init-function="bpFieldInitMoneyElement"
        data-decimals="{{ $decimals }}"
        data-thousands-separator="{{ $thousandsSeparator }}"
        data-decimal-separator="{{ $decimalSeparator }}"
        data-min="{{ isset($field['min']) && is_numeric($field['min']) ? $field['min'] : '' }}"
        data-max="{{ isset($field['max']) && is_numeric($field['max']) ? $field['max'] : '' }}"
        data-allow-negative="{{ $allowNegative ? 1 : 0 }}"
        @if(isset($field['attributes']['disabled'])) disabled @endif
    >

    @if($hasAddon) <div class="input-group"> @endif
        @if($prefix !== null && $prefix !== '') <div class="input-group-prepend"><span class="input-group-text">{!! $prefix !!}</span></div> @endif
        <input
            type="text"
            data-money-display="1"
            inputmode="{{ $allowNegative ? 'text' : 'decimal' }}"
            autocomplete="off"
            value="{{ $display }}"
            @include('crud::fields.inc.attributes')
        >
        @if($suffix !== null && $suffix !== '') <div class="input-group-append"><span class="input-group-text">{!! $suffix !!}</span></div> @endif
    @if($hasAddon) </div> @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    @push('crud_fields_scripts')
        <script>
            (function () {
                function escapeRegExp(text) {
                    return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                }

                /**
                 * Split free text into its sign, integer digits and decimal digits.
                 * `canonical` parses the hidden input ("." decimal, no thousands separator);
                 * otherwise the field's own separators are used, so "RM 1,234.5" parses too.
                 */
                function parse(raw, cfg, canonical) {
                    var text = String(raw == null ? '' : raw);
                    var thousands = canonical ? '' : cfg.thousands;
                    var decimal = canonical ? '.' : cfg.decimal;

                    if (thousands !== '') {
                        text = text.split(thousands).join('');
                    }
                    if (decimal !== '.') {
                        text = text.split(decimal).join('.');
                    }
                    text = text.replace(/[^0-9.\-]/g, '');

                    var negative = cfg.negative && text.charAt(0) === '-';
                    text = text.replace(/-/g, '');

                    var parts = text.split('.');
                    var integer = parts[0].replace(/^0+(?=\d)/, '');
                    var hasDecimal = cfg.decimals > 0 && parts.length > 1;
                    var fraction = hasDecimal ? parts.slice(1).join('').slice(0, cfg.decimals) : '';

                    return { negative: negative, integer: integer, fraction: fraction, hasDecimal: hasDecimal };
                }

                function isEmpty(parsed) {
                    return parsed.integer === '' && parsed.fraction === '';
                }

                /** Display text for a parsed value; keeps a trailing decimal separator while the user is typing. */
                function format(parsed, cfg) {
                    if (isEmpty(parsed) && ! parsed.hasDecimal) {
                        return parsed.negative ? '-' : '';
                    }

                    var integer = (parsed.integer === '' ? '0' : parsed.integer).replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousands);
                    var text = (parsed.negative ? '-' : '') + integer;

                    if (parsed.hasDecimal) {
                        text += cfg.decimal + parsed.fraction;
                    }

                    return text;
                }

                /** Canonical "1234.50" string with fixed decimals, clamped to min/max unless `skipClamp` is set. */
                function canonical(parsed, cfg, skipClamp) {
                    if (isEmpty(parsed)) {
                        return '';
                    }

                    var fraction = parsed.fraction;
                    while (fraction.length < cfg.decimals) {
                        fraction += '0';
                    }

                    var text = (parsed.integer === '' ? '0' : parsed.integer) + (cfg.decimals > 0 ? '.' + fraction : '');
                    var number = Number(text) * (parsed.negative ? -1 : 1);

                    if (! skipClamp && cfg.min !== null && number < cfg.min) number = cfg.min;
                    if (! skipClamp && cfg.max !== null && number > cfg.max) number = cfg.max;
                    if (number === 0) number = 0; // drop "-0"

                    return number.toFixed(cfg.decimals);
                }

                /** Caret helpers: count the significant characters (digits, decimal sign, minus) left of the caret. */
                function significantBefore(text, position, cfg) {
                    var pattern = new RegExp('[0-9\\-]|' + escapeRegExp(cfg.decimal), 'g');
                    return (text.slice(0, position).match(pattern) || []).length;
                }

                function caretAfter(text, count, cfg) {
                    if (count <= 0) return 0;
                    var pattern = new RegExp('[0-9\\-]|' + escapeRegExp(cfg.decimal));
                    var seen = 0;
                    for (var i = 0; i < text.length; i++) {
                        if (pattern.test(text.charAt(i)) && ++seen === count) {
                            return i + 1;
                        }
                    }
                    return text.length;
                }

                window.bpFieldInitMoneyElement = function (element) {
                    var hidden = element;
                    var display = hidden.closest('[data-field-type=money]').find("input[data-money-display]");
                    var cfg = {
                        decimals: Number(hidden.data('decimals')) || 0,
                        thousands: String(hidden.attr('data-thousands-separator') || ''),
                        decimal: String(hidden.attr('data-decimal-separator') || '.'),
                        min: hidden.attr('data-min') === '' ? null : Number(hidden.attr('data-min')),
                        max: hidden.attr('data-max') === '' ? null : Number(hidden.attr('data-max')),
                        negative: Number(hidden.data('allow-negative')) === 1
                    };

                    function setHidden(value) {
                        if (hidden.val() !== value) {
                            hidden.val(value).trigger('change');
                        }
                    }

                    /** Display text for a canonical value, padded to the fixed decimals ("1234.5" -> "1,234.50"). */
                    function render(value) {
                        var padded = canonical(parse(value, cfg, true), cfg, true);
                        return padded === '' ? '' : format(parse(padded, cfg, true), cfg);
                    }

                    function normalise() {
                        var value = canonical(parse(display.val(), cfg, false), cfg);
                        setHidden(value);
                        display.val(render(value));
                    }

                    // the hidden input wins over the rendered text (repeatable clones restore values into it,
                    // possibly as an unpadded number such as 1234.5)
                    display.val(render(hidden.val()));

                    display.on('input', function () {
                        var input = display[0];
                        var before = significantBefore(input.value, input.selectionStart, cfg);
                        var parsed = parse(input.value, cfg, false);
                        var formatted = format(parsed, cfg);

                        // format() synthesises a leading "0" for ".5" / "-.5"; keep the caret to the right of it
                        if (parsed.integer === '' && parsed.hasDecimal && before > (parsed.negative ? 1 : 0)) {
                            before += 1;
                        }

                        input.value = formatted;
                        var caret = caretAfter(formatted, before, cfg);
                        input.setSelectionRange(caret, caret);

                        setHidden(canonical(parsed, cfg));
                    });

                    display.on('blur', normalise);

                    display.on('paste', function () {
                        setTimeout(normalise, 0);
                    });

                    // let other scripts push a value through the hidden input (e.g. dependent fields)
                    hidden.on('bp.money.refresh', function () {
                        display.val(render(hidden.val()));
                    });
                };
            })();
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
