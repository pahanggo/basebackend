{{-- dependent_select: a plain <select> whose options are fetched from a URL whenever another field
     on the same form (the parent) changes value. Cascading selects such as state -> district.

     Options:
       - depends_on      => 'state_id'   name of the parent field (required); inside a repeatable
                                          group the parent is looked up in the same group first
       - data_source     => backpack_url('thing/children')   URL fetched with GET as
                                          ?parent=<parent value>&q= (required). The response may be a
                                          JSON array of {id, text} or {value, label} objects, an array
                                          of objects using the `attribute` key for the text, or a
                                          plain object map {id: text}. A {data: [...]} envelope is
                                          also accepted.
       - options         => [] | fn ($parentValue) => [id => text]   initial options for the current
                                          parent value so the edit page renders without a fetch;
                                          when absent the field fetches on init if the parent has a value
       - attribute       => 'name'       key used for the option text when the response objects
                                          carry neither `text` nor `label`
       - placeholder     => __('Select...')
       - allows_null     => true         false makes the placeholder unselectable once options are loaded
       - reset_on_change => true         clear the value when the parent changes; false keeps the
                                          current value when it still exists in the new options
       - attributes      => []           extra HTML attributes for the <select>
       - readonly        => false        when true, show the value as plain text with no form control and no name attribute (not submitted)
       - hint

     Behaviour: the select is disabled and shows only the placeholder while the parent is empty; on
     parent change the options are rebuilt from data_source and a `change` event is triggered so a
     further dependent_select can chain off this one. Fetch errors show a small inline hint. --}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $value = is_object($value) ? $value->getKey() : $value;

    if (empty($field['depends_on']) || empty($field['data_source'])) {
        throw new \InvalidArgumentException('The dependent_select field "'.$field['name'].'" needs both a `depends_on` and a `data_source` option.');
    }

    $dependsOn = $field['depends_on'];
    $allowsNull = $field['allows_null'] ?? true;
    $resetOnChange = $field['reset_on_change'] ?? true;
    $placeholder = $field['placeholder'] ?? __('Select...');
    $attribute = $field['attribute'] ?? 'name';

    // the parent value the server knows about: old input first, then the entry being edited
    try {
        $entry = $crud->getCurrentEntry();
    } catch (\Throwable $e) {
        $entry = false;
    }
    $parentValue = old(square_brackets_to_dots($dependsOn)) ?? ($entry ? data_get($entry, $dependsOn) : null);
    $parentValue = is_object($parentValue) ? $parentValue->getKey() : $parentValue;

    // initial options are only useful when they were produced for a known parent value
    $initialOptions = [];
    if (isset($field['options']) && $parentValue !== null && $parentValue !== '') {
        $options = ! is_array($field['options']) && is_callable($field['options']) ? $field['options']($parentValue) : $field['options'];
        foreach ($options as $key => $text) {
            $initialOptions[] = ['id' => (string) $key, 'text' => (string) $text];
        }
    }

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'dependent_select';
    $field['wrapper']['data-field-name'] = $field['name'];
    $readonly = (bool) ($field['readonly'] ?? false);

    // best effort: options come from an AJAX endpoint, so only a value present in the already-resolved
    // $initialOptions (built above from the `options` callback) gets its label; otherwise show the raw value
    if ($readonly) {
        $readonlyMatch = collect($initialOptions)->first(fn ($option) => (string) $option['id'] === (string) $value);
        $readonlyDisplay = $readonlyMatch['text'] ?? $value;
    }
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => $readonlyDisplay])
    @else
    {{-- The container starts as x-ignore so Alpine does not initialise it on its own;
         bpFieldInitDependentSelectElement (called by Backpack's field init pipeline, including
         for repeatable clones) lifts the ignore and initialises the tree. The <select> is rendered
         without options on purpose: the repeatable field only hands a clone its value through
         data-selected-options when the select has no options, and the component builds them.
         Disabled inputs are not submitted, so a hidden input with the same name is enabled only
         while the select is disabled: clearing the parent then also clears the stored child. --}}
    <div class="dependent-select"
         x-ignore
         x-data="bpDependentSelect({
             value: @js((string) $value),
             dependsOn: @js($dependsOn),
             dataSource: @js($field['data_source']),
             attribute: @js($attribute),
             options: @js($initialOptions),
             optionsFor: @js($parentValue === null ? '' : (string) $parentValue),
             placeholder: @js($placeholder),
             allowsNull: @js((bool) $allowsNull),
             resetOnChange: @js((bool) $resetOnChange),
             messages: @js([
                 'loading' => __('Loading...'),
                 'failed' => __('Could not load options'),
             ]),
         })">

        <input type="hidden" name="{{ $field['name'] }}" value="" x-ref="fallback" data-dependent-select-fallback :disabled="! disabled">

        <select
            name="{{ $field['name'] }}"
            x-ref="select"
            data-init-function="bpFieldInitDependentSelectElement"
            :disabled="disabled"
            @change="value = $event.target.value"
            @include('crud::fields.inc.attributes')
        ></select>

        <p class="help-block text-danger mb-0" style="display: none;" x-show="error" x-text="error"></p>
    </div>
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

    {{-- FIELD JS - will be loaded in the after_scripts section --}}
    @push('crud_fields_scripts')
        <script>
            (function () {
                var instanceCounter = 0;

                Alpine.data('bpDependentSelect', function (config) {
                    return {
                        value: String(config.value == null ? '' : config.value),
                        options: [],
                        parentValue: '',
                        loading: false,
                        error: '',
                        requestId: 0,
                        eventNamespace: '',
                        parentInput: null,

                        init: function () {
                            var self = this;
                            var $select = $(this.$refs.select);
                            var $parent = this.findParent();

                            // a repeatable clone receives its value through data-selected-options
                            var restored = $select.attr('data-selected-options');
                            if (typeof restored === 'string' && restored.length) {
                                try {
                                    var parsed = JSON.parse(restored);
                                    this.value = String(parsed == null ? '' : parsed);
                                } catch (e) {}
                                $select.removeAttr('data-selected-options');
                            } else if ($select.val() != null && $select.val() !== '') {
                                this.value = String($select.val());
                            }

                            // the repeatable field copies the restored value into every input of the row
                            this.$refs.fallback.value = '';

                            this.parentValue = $parent.length ? String($parent.val() == null ? '' : $parent.val()) : '';

                            if (this.parentValue === '') {
                                this.options = [];
                                this.value = '';
                                this.render();
                            } else if (config.options.length && String(config.optionsFor) === this.parentValue) {
                                this.options = config.options;
                                this.render();
                            } else {
                                this.load(this.parentValue, this.value);
                            }

                            if ($parent.length) {
                                this.eventNamespace = '.bpDependentSelect' + (++instanceCounter);
                                this.parentInput = $parent;

                                $parent.on('change' + this.eventNamespace, function () {
                                    var next = String($parent.val() == null ? '' : $parent.val());
                                    if (next === self.parentValue) {
                                        return;
                                    }
                                    self.parentValue = next;
                                    self.onParentChange();
                                });

                                // a parent outside the repeatable row outlives the row: unbind when the row is removed
                                $select.on('backpack_field.deleted' + this.eventNamespace, function () {
                                    self.unbind();
                                });
                            }
                        },

                        destroy: function () {
                            this.unbind();
                        },

                        unbind: function () {
                            if (this.parentInput) {
                                this.parentInput.off(this.eventNamespace);
                                $(this.$refs.select).off(this.eventNamespace);
                                this.parentInput = null;
                            }
                        },

                        get disabled() {
                            return this.parentValue === '' || this.loading;
                        },

                        /**
                         * The parent input: inside a repeatable group look in the same group first
                         * (where inputs carry data-repeatable-input-name), then in the whole form.
                         */
                        findParent: function () {
                            var $select = $(this.$refs.select);
                            // skip the hidden fallback of another dependent_select so chains resolve to its <select>
                            var selector = '[name="' + config.dependsOn + '"]:not([data-dependent-select-fallback]), [data-repeatable-input-name="' + config.dependsOn + '"]:not([data-dependent-select-fallback])';
                            var $group = $select.closest('.repeatable-element');
                            var $found = $group.length ? $group.find(selector).first() : $();

                            if (! $found.length) {
                                $found = $select.closest('form').find(selector).first();
                            }

                            return $found;
                        },

                        onParentChange: function () {
                            var keep = config.resetOnChange ? '' : this.value;

                            this.error = '';

                            if (this.parentValue === '') {
                                ++this.requestId; // drop any response still in flight
                                this.loading = false;
                                this.options = [];
                                this.value = '';
                                this.render();
                                this.notify();
                                return;
                            }

                            this.load(this.parentValue, keep);
                        },

                        /** Fetch the options for a parent value and keep `wanted` selected when it is still available. */
                        load: function (parentValue, wanted) {
                            var self = this;
                            var requestId = ++this.requestId;
                            var url = new URL(config.dataSource, window.location.href);

                            url.searchParams.set('parent', parentValue);
                            if (! url.searchParams.has('q')) {
                                url.searchParams.set('q', '');
                            }

                            this.loading = true;
                            this.error = '';
                            this.options = [];
                            this.render();

                            fetch(url.toString(), {
                                method: 'GET',
                                credentials: 'same-origin',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'application/json'
                                }
                            }).then(function (response) {
                                if (! response.ok) {
                                    throw new Error(response.status);
                                }
                                return response.json();
                            }).then(function (body) {
                                if (requestId !== self.requestId) {
                                    return;
                                }
                                self.options = self.normalize(body);
                                self.value = self.hasOption(wanted) ? String(wanted) : '';
                                self.loading = false;
                                self.render();
                                self.notify();
                            }).catch(function () {
                                if (requestId !== self.requestId) {
                                    return;
                                }
                                self.options = [];
                                self.value = '';
                                self.loading = false;
                                self.error = config.messages.failed;
                                self.render();
                                self.notify();
                            });
                        },

                        /** Accept [{id,text}], [{value,label}], [{id, <attribute>}], {id: text} or a {data: [...]} envelope. */
                        normalize: function (body) {
                            if (body && ! Array.isArray(body) && Array.isArray(body.data)) {
                                body = body.data;
                            }

                            if (Array.isArray(body)) {
                                return body.map(function (item) {
                                    if (item === null || typeof item !== 'object') {
                                        return { id: String(item), text: String(item) };
                                    }
                                    var id = item.id !== undefined ? item.id : (item.value !== undefined ? item.value : item.key);
                                    var text = item.text !== undefined ? item.text : (item.label !== undefined ? item.label : item[config.attribute]);
                                    return { id: String(id == null ? '' : id), text: String(text == null ? id : text) };
                                });
                            }

                            if (body && typeof body === 'object') {
                                return Object.keys(body).map(function (key) {
                                    return { id: String(key), text: String(body[key]) };
                                });
                            }

                            return [];
                        },

                        hasOption: function (id) {
                            if (id == null || id === '') {
                                return false;
                            }
                            return this.options.some(function (option) {
                                return option.id === String(id);
                            });
                        },

                        /** Rebuild the <option> elements from state; the select itself is never re-created so its name and listeners survive. */
                        render: function () {
                            var select = this.$refs.select;
                            var value = this.value;

                            while (select.firstChild) {
                                select.removeChild(select.firstChild);
                            }

                            var placeholder = document.createElement('option');
                            placeholder.value = '';
                            placeholder.textContent = this.loading ? config.messages.loading : config.placeholder;
                            placeholder.disabled = ! config.allowsNull && this.options.length > 0;
                            placeholder.selected = value === '';
                            select.appendChild(placeholder);

                            this.options.forEach(function (option) {
                                var el = document.createElement('option');
                                el.value = option.id;
                                el.textContent = option.text;
                                el.selected = option.id === value;
                                select.appendChild(el);
                            });

                            select.value = value;
                        },

                        /** Let listeners (validation, a further dependent_select) know the value was rebuilt. */
                        notify: function () {
                            $(this.$refs.select).trigger('change');
                        }
                    };
                });

                window.bpFieldInitDependentSelectElement = function (element) {
                    var container = element.closest('.dependent-select')[0];

                    if (! container || container._x_dataStack) {
                        return;
                    }

                    delete container._x_ignore;
                    container.removeAttribute('x-ignore');
                    Alpine.initTree(container);
                };
            })();
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
