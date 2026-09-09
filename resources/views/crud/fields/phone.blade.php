{{-- phone: Malaysian-first phone input.

     The visible input formats what the user types for display; the value submitted under
     $field['name'] lives in a hidden input and is written in the chosen `store` form.
     Existing values in any stored form (+60123456789, 0123456789 or "+60 12-345 6789") load fine.

     Options:
       - country_code => '+60'      country code used when the user types a national number (leading 0)
       - format       => 'my'       display formatting: 'my' (+60 12-345 6789 mobiles, +60 3-1234 5678 landlines)
                                    or 'raw' (no display formatting, e.g. +60123456789)
       - store        => 'e164'     what gets submitted: 'e164' (+60123456789) | 'national' (0123456789) | 'display' (formatted)
       - allowed      => 'any'      'any' | 'mobile' | 'landline' — numbers of another kind show the invalid hint
       - placeholder  => '+60 12-345 6789'
       - attributes   => []         HTML attributes for the visible input
       - hint / prefix / suffix as for the text field

     The invalid hint (__('Enter a valid phone number')) shows when the digit count is outside the Malaysian
     ranges (mobile 9-10 digits after +60, landline 8-9) but never blocks submitting the form. While typing, a
     number that is merely still too short is not flagged; it is checked on blur and when an existing value loads.
     Numbers typed with a foreign country code are stored as +digits without formatting. --}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $countryCode = '+'.(preg_replace('/\D/', '', (string) ($field['country_code'] ?? '+60')) ?: '60');
    $format = in_array($field['format'] ?? 'my', ['my', 'raw'], true) ? $field['format'] ?? 'my' : 'my';
    $store = in_array($field['store'] ?? 'e164', ['e164', 'national', 'display'], true) ? $field['store'] ?? 'e164' : 'e164';
    $allowed = in_array($field['allowed'] ?? 'any', ['any', 'mobile', 'landline'], true) ? $field['allowed'] ?? 'any' : 'any';
    $placeholder = $field['placeholder'] ?? ($format === 'raw' ? $countryCode.'123456789' : $countryCode.' 12-345 6789');

    $field['attributes'] = $field['attributes'] ?? [];
    $field['attributes']['placeholder'] = $field['attributes']['placeholder'] ?? $placeholder;
    $field['attributes']['inputmode'] = $field['attributes']['inputmode'] ?? 'tel';
    $field['attributes']['autocomplete'] = $field['attributes']['autocomplete'] ?? 'tel';

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'phone';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    {{-- the submitted value; repeatable writes restored values here before calling the init function --}}
    <input
        type="hidden"
        name="{{ $field['name'] }}"
        value="{{ $value }}"
        data-init-function="bpFieldInitPhoneElement"
        data-country-code="{{ $countryCode }}"
        data-format="{{ $format }}"
        data-store="{{ $store }}"
        data-allowed="{{ $allowed }}"
    >

    @if(isset($field['prefix']) || isset($field['suffix'])) <div class="input-group"> @endif
        @if(isset($field['prefix'])) <div class="input-group-prepend"><span class="input-group-text">{!! $field['prefix'] !!}</span></div> @endif
        <input
            type="text"
            value="{{ $value }}"
            data-phone-display
            @include('crud::fields.inc.attributes')
        >
        @if(isset($field['suffix'])) <div class="input-group-append"><span class="input-group-text">{!! $field['suffix'] !!}</span></div> @endif
    @if(isset($field['prefix']) || isset($field['suffix'])) </div> @endif

    <small class="form-text text-danger phone-invalid-hint" hidden>{{ __('Enter a valid phone number') }}</small>

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
                /**
                 * Normalise anything (typed text or a stored value) into
                 * { national: digits after the country code without the leading 0, foreign: full digits of a non-local number or null }.
                 */
                function bpPhoneParse(raw, countryCode) {
                    raw = String(raw || '').trim();
                    var ccDigits = countryCode.replace(/\D/g, '');
                    var hasPlus = raw.charAt(0) === '+';
                    var digits = raw.replace(/\D/g, '');

                    if (! digits.length) {
                        return { national: '', foreign: null };
                    }

                    // "60123456789" without a plus is almost certainly an E.164 value that lost its sign
                    if (! hasPlus && digits.charAt(0) !== '0' && digits.indexOf(ccDigits) === 0 && digits.length >= ccDigits.length + 8) {
                        hasPlus = true;
                    }

                    if (hasPlus) {
                        // "+6" is an unfinished country code, not a foreign number
                        if (ccDigits.indexOf(digits) === 0) {
                            return { national: '', foreign: null };
                        }
                        if (digits.indexOf(ccDigits) === 0) {
                            return { national: digits.slice(ccDigits.length).replace(/^0/, ''), foreign: null };
                        }
                        return { national: '', foreign: digits };
                    }

                    if (digits.charAt(0) === '0') {
                        return { national: digits.slice(1), foreign: null };
                    }

                    return { national: digits, foreign: null };
                }

                /** 'mobile' (01x), 'landline' (03-09) or 'unknown'. */
                function bpPhoneKind(national) {
                    if (national.charAt(0) === '1') return 'mobile';
                    if (/[3-9]/.test(national.charAt(0))) return 'landline';
                    return 'unknown';
                }

                /** Malaysian grouping: +60 12-345 6789, +60 11-2345 6789, +60 3-1234 5678, +60 4-123 4567, +60 82-123 456. */
                function bpPhoneFormatMy(national, countryCode) {
                    var first = national.charAt(0);
                    var areaLength = (first === '1' || first === '8') ? 2 : 1;
                    var area = national.slice(0, areaLength);
                    var rest = national.slice(areaLength);
                    var lead = first === '8' ? 3 : (first === '3' || (first === '1' && rest.length >= 8)) ? 4 : 3;
                    var text = countryCode + ' ' + area;

                    if (! rest.length) return text;
                    text += '-' + rest.slice(0, lead);
                    if (rest.length > lead) text += ' ' + rest.slice(lead);
                    return text;
                }

                function bpPhoneDisplay(parsed, config) {
                    if (parsed.foreign) return '+' + parsed.foreign;
                    if (! parsed.national) return '';
                    return config.format === 'raw' ? config.countryCode + parsed.national : bpPhoneFormatMy(parsed.national, config.countryCode);
                }

                function bpPhoneStored(parsed, config) {
                    if (parsed.foreign) return '+' + parsed.foreign;
                    if (! parsed.national) return '';
                    if (config.store === 'national') return '0' + parsed.national;
                    if (config.store === 'display') return bpPhoneDisplay(parsed, config);
                    return config.countryCode + parsed.national;
                }

                /** True while the number is still shorter than the smallest valid length, so typing is not flagged prematurely. */
                function bpPhoneIsIncomplete(parsed) {
                    if (parsed.foreign) return parsed.foreign.length < 8;
                    if (! parsed.national) return true;

                    var kind = bpPhoneKind(parsed.national);

                    if (kind === 'mobile') return parsed.national.length < 9;
                    if (kind === 'landline') return parsed.national.length < 8;
                    return false;
                }

                function bpPhoneIsValid(parsed, config) {
                    if (parsed.foreign) return config.allowed === 'any' && parsed.foreign.length >= 8 && parsed.foreign.length <= 15;
                    if (! parsed.national) return true;

                    var kind = bpPhoneKind(parsed.national);
                    var length = parsed.national.length;

                    if (kind === 'mobile') return (config.allowed !== 'landline') && length >= 9 && length <= 10;
                    if (kind === 'landline') return (config.allowed !== 'mobile') && length >= 8 && length <= 9;
                    return false;
                }

                // element is the hidden input (it carries the name, so repeatable restores values into it)
                window.bpFieldInitPhoneElement = function (element) {
                    var wrapper = element.closest('[data-field-type=phone]');
                    var display = wrapper.find('[data-phone-display]');
                    var hint = wrapper.find('.phone-invalid-hint');
                    var config = {
                        countryCode: String(element.data('country-code') || '+60'),
                        format: element.data('format') || 'my',
                        store: element.data('store') || 'e164',
                        allowed: element.data('allowed') || 'any'
                    };

                    function apply(raw, fromTyping) {
                        var parsed = bpPhoneParse(raw, config.countryCode);
                        var text = bpPhoneDisplay(parsed, config);

                        // while typing keep a bare "+" / country code prefix, and turn a leading 0 into the country code
                        if (fromTyping && ! parsed.national && ! parsed.foreign) {
                            var digits = raw.replace(/\D/g, '');
                            text = raw.trim().charAt(0) === '+' ? '+' + digits : (digits === '0' ? config.countryCode + ' ' : '');
                        }

                        if (display.val() !== text) display.val(text);

                        var stored = bpPhoneStored(parsed, config);
                        if (element.val() !== stored) element.val(stored).trigger('change');

                        // too-short numbers are only flagged once typing stops (blur / initial value), the rest immediately
                        hint.prop('hidden', bpPhoneIsValid(parsed, config) || (fromTyping && bpPhoneIsIncomplete(parsed)));
                    }

                    display.on('input', function () {
                        apply(display.val(), true);
                    });

                    display.on('blur change', function () {
                        apply(display.val(), false);
                    });

                    // the hidden input wins over the rendered display (repeatable clones set it)
                    apply(element.val(), false);
                };
            })();
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
