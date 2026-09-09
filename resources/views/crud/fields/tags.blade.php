{{-- tags: free-text tag pills stored as a JSON array of strings in a hidden input (cast the attribute to array).
     An existing comma-separated string value is accepted and converted.

     Options:
       - suggestions      => null        PHP array of strings (filtered client-side) or a URL fetched with GET ?q=<term>
                                         that returns a JSON array of strings or of {text: '...'} objects
       - max              => null        maximum number of tags (null = unlimited)
       - min_length       => 1           shortest tag accepted
       - max_length       => 40          longest tag accepted (also sets maxlength on the input)
       - allow_duplicates => false
       - case_sensitive   => false       duplicates and suggestion matching ignore case when false
       - separators       => [',', 'Enter']  keys / characters that commit the typed tag (blur and paste also commit)
       - placeholder      => __('Add a tag...')
       - attributes       => []          extra attributes for the text input; `disabled` or `readonly` here also
                                         locks the pills (no add / remove)
       - hint --}}
@php
    $tags = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? [];

    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        $tags = is_array($decoded) ? $decoded : explode(',', $tags);
    }

    $tags = is_array($tags) ? array_values(array_filter(array_map(
        fn ($tag) => is_scalar($tag) ? trim((string) $tag) : '',
        $tags
    ), fn ($tag) => $tag !== '')) : [];

    $max = isset($field['max']) && (int) $field['max'] > 0 ? (int) $field['max'] : null;
    $minLength = max(1, (int) ($field['min_length'] ?? 1));
    $maxLength = max($minLength, (int) ($field['max_length'] ?? 40));
    $suggestions = $field['suggestions'] ?? null;
    $separators = array_values((array) ($field['separators'] ?? [',', 'Enter']));
    $disabled = isset($field['attributes']['disabled']) || isset($field['attributes']['readonly']);

    $field['attributes'] = $field['attributes'] ?? [];
    $field['attributes']['class'] = $field['attributes']['class'] ?? 'tags-field-input';
    $field['attributes']['placeholder'] = $field['attributes']['placeholder'] ?? $field['placeholder'] ?? __('Add a tag...');
    $field['attributes']['maxlength'] = $field['attributes']['maxlength'] ?? $maxLength;

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'tags';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    {{-- x-ignore keeps Alpine from initialising this on its own; bpFieldInitTagsElement lifts it
         (this also covers repeatable clones, whose hidden input is filled before the init call). --}}
    <div class="tags-field"
         x-ignore
         x-data="bpTagsField({
             tags: @js($tags),
             suggestions: @js($suggestions),
             max: @js($max),
             minLength: {{ $minLength }},
             maxLength: {{ $maxLength }},
             allowDuplicates: @js((bool) ($field['allow_duplicates'] ?? false)),
             caseSensitive: @js((bool) ($field['case_sensitive'] ?? false)),
             separators: @js($separators),
             disabled: @js($disabled),
             messages: @js([
                 'tooShort' => __('Tags must be at least :min characters', ['min' => $minLength]),
                 'tooLong' => __('Tags may not be longer than :max characters', ['max' => $maxLength]),
             ]),
         })">

        <input type="hidden"
               name="{{ $field['name'] }}"
               value="{{ count($tags) ? json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}"
               data-init-function="bpFieldInitTagsElement"
               :value="serialized">

        <div class="tags-field-box form-control" :class="{ 'is-invalid': error, 'is-focused': focused, 'is-full': isFull, 'disabled': disabled }" @click="focusInput()">
            <template x-for="(tag, index) in tags" :key="index">
                <span class="tags-field-pill badge badge-primary">
                    <span x-text="tag"></span>
                    <button type="button" class="tags-field-remove" x-show="! disabled" :aria-label="@js(__('Remove') . ' ') + tag" @click.stop="remove(index)"><i class="la la-times"></i></button>
                </span>
            </template>

            <input type="text"
                   x-ref="input"
                   x-model="query"
                   x-show="! isFull"
                   autocomplete="off"
                   @focus="focused = true; onInput()"
                   @blur="focused = false; commit(); close()"
                   @input="onInput()"
                   @keydown="onKeydown($event)"
                   @paste="onPaste($event)"
                   @include('crud::fields.inc.attributes')>

            <span class="tags-field-max text-muted small" x-show="isFull" x-cloak>{{ __('Maximum :max tags', ['max' => $max ?? '']) }}</span>
        </div>

        <div class="dropdown-menu tags-field-suggestions" :class="{ show: open && matches.length }" x-show="open && matches.length" x-cloak>
            <template x-for="(match, index) in matches" :key="index + ':' + match">
                <a href="#" class="dropdown-item" :class="{ active: index === highlighted }" x-text="match"
                   @mousedown.prevent="pick(match)" @mousemove="highlighted = index"></a>
            </template>
        </div>

        <div class="invalid-feedback d-block" x-show="error" x-text="error" x-cloak></div>
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
    @endphp

    @push('crud_fields_styles')
        <style>
            .tags-field { position: relative; }
            .tags-field [x-cloak] { display: none !important; }
            .tags-field-box { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem; height: auto; min-height: calc(1.5em + .75rem + 2px); cursor: text; }
            .tags-field-box.disabled { background-color: #e4e7ea; cursor: default; }
            .tags-field-box.is-focused { border-color: #8ad4ee; box-shadow: 0 0 0 .2rem rgba(0, 123, 255, .25); }
            .tags-field-pill { display: inline-flex; align-items: center; gap: .25rem; font-size: .8rem; font-weight: 500; padding: .3em .5em; max-width: 100%; }
            .tags-field-pill > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tags-field-remove { border: 0; background: transparent; color: inherit; opacity: .7; padding: 0; margin: 0; line-height: 1; cursor: pointer; }
            .tags-field-remove:hover { opacity: 1; }
            .tags-field-input { flex: 1 1 6rem; min-width: 6rem; border: 0; outline: 0; background: transparent; padding: 0 .25rem; color: inherit; }
            .tags-field-suggestions { top: 100%; left: 0; max-height: 14rem; overflow-y: auto; margin-top: 2px; }
        </style>
    @endpush

    {{-- FIELD JS - will be loaded in the after_scripts section --}}
    @push('crud_fields_scripts')
        <script>
            (function () {
                Alpine.data('bpTagsField', function (config) {
                    var debounceTimer = null;
                    var requestCounter = 0;

                    return {
                        tags: [],
                        query: '',
                        matches: [],
                        highlighted: -1,
                        open: false,
                        focused: false,
                        error: '',
                        remote: [],
                        max: config.max ? Number(config.max) : null,
                        disabled: !! config.disabled,

                        init: function () {
                            this.tags = this.currentValue();
                        },

                        get serialized() {
                            return this.tags.length ? JSON.stringify(this.tags) : '';
                        },

                        get isFull() {
                            return this.max !== null && this.tags.length >= this.max;
                        },

                        get suggestionsAreRemote() {
                            return typeof config.suggestions === 'string' && config.suggestions.length > 0;
                        },

                        /** Single-character separators that split pasted text (named keys like Enter cannot appear in text). */
                        get splitCharacters() {
                            return config.separators.filter(function (separator) { return String(separator).length === 1; });
                        },

                        /**
                         * Tags to start from. The hidden input wins over the rendered config
                         * because the repeatable field writes restored values into it before
                         * calling the init function on a cloned group. Accepts JSON or a comma list.
                         */
                        currentValue: function () {
                            var hidden = this.$root.querySelector('input[type=hidden]');
                            var raw = hidden ? hidden.value : '';

                            if (typeof raw === 'string' && raw.trim().length) {
                                var parsed = null;
                                try { parsed = JSON.parse(raw); } catch (e) {}

                                if (Array.isArray(parsed)) {
                                    return this.clean(parsed);
                                }

                                return this.clean(raw.split(','));
                            }

                            return this.clean(Array.isArray(config.tags) ? config.tags : []);
                        },

                        clean: function (list) {
                            return list.map(function (tag) { return String(tag == null ? '' : tag).trim(); })
                                .filter(function (tag) { return tag.length > 0; });
                        },

                        normalise: function (tag) {
                            return config.caseSensitive ? tag : tag.toLowerCase();
                        },

                        has: function (tag) {
                            var needle = this.normalise(tag);
                            var self = this;
                            return this.tags.some(function (existing) { return self.normalise(existing) === needle; });
                        },

                        focusInput: function () {
                            if (! this.disabled && ! this.isFull && this.$refs.input) {
                                this.$refs.input.focus();
                            }
                        },

                        /** Adds one tag; returns false (and sets the error) when the tag is not acceptable. */
                        add: function (tag) {
                            tag = String(tag == null ? '' : tag).trim();

                            if (this.disabled || ! tag.length || this.isFull) {
                                return false;
                            }

                            if (tag.length < config.minLength) {
                                this.error = config.messages.tooShort;
                                return false;
                            }

                            if (tag.length > config.maxLength) {
                                this.error = config.messages.tooLong;
                                return false;
                            }

                            if (! config.allowDuplicates && this.has(tag)) {
                                // silently drop duplicates, treat it as handled
                                return true;
                            }

                            this.tags.push(tag);
                            this.error = '';
                            return true;
                        },

                        /** Turns whatever is typed into a tag. */
                        commit: function () {
                            if (! this.query.trim().length) {
                                this.query = '';
                                return;
                            }

                            if (this.add(this.query)) {
                                this.query = '';
                                this.matches = [];
                                this.highlighted = -1;
                            }
                        },

                        pick: function (tag) {
                            if (this.add(tag)) {
                                this.query = '';
                                this.close();
                                this.focusInput();
                            }
                        },

                        remove: function (index) {
                            if (this.disabled) {
                                return;
                            }

                            this.tags.splice(index, 1);
                            this.error = '';
                            this.$nextTick(this.focusInput.bind(this));
                        },

                        close: function () {
                            this.open = false;
                            this.highlighted = -1;
                        },

                        onKeydown: function (event) {
                            if (this.disabled || event.isComposing) {
                                return;
                            }

                            if (config.separators.indexOf(event.key) !== -1) {
                                event.preventDefault();
                                if (this.open && this.highlighted > -1 && this.matches[this.highlighted] !== undefined) {
                                    this.pick(this.matches[this.highlighted]);
                                } else {
                                    this.commit();
                                }
                                return;
                            }

                            if (event.key === 'Backspace' && this.query === '' && this.tags.length) {
                                event.preventDefault();
                                this.remove(this.tags.length - 1);
                                return;
                            }

                            if (event.key === 'ArrowDown' && this.matches.length) {
                                event.preventDefault();
                                this.open = true;
                                this.highlighted = (this.highlighted + 1) % this.matches.length;
                                return;
                            }

                            if (event.key === 'ArrowUp' && this.matches.length) {
                                event.preventDefault();
                                this.open = true;
                                this.highlighted = this.highlighted <= 0 ? this.matches.length - 1 : this.highlighted - 1;
                                return;
                            }

                            if (event.key === 'Escape' && this.open) {
                                event.preventDefault();
                                this.close();
                            }
                        },

                        onPaste: function (event) {
                            if (this.disabled) {
                                event.preventDefault();
                                return;
                            }

                            var clipboard = event.clipboardData || window.clipboardData;
                            var text = clipboard && clipboard.getData ? clipboard.getData('text') : '';
                            var splitters = this.splitCharacters;

                            if (! text || ! splitters.some(function (character) { return text.indexOf(character) !== -1; }) && text.indexOf('\n') === -1) {
                                return;
                            }

                            event.preventDefault();

                            var pattern = new RegExp('[\\n\\r' + splitters.map(function (character) {
                                return character.replace(/[\\\]^-]/g, '\\$&');
                            }).join('') + ']');
                            var self = this;

                            (this.query + text).split(pattern).forEach(function (piece) { self.add(piece); });
                            this.query = '';
                            this.close();
                        },

                        onInput: function () {
                            this.error = '';
                            this.highlighted = -1;

                            if (config.suggestions === null || config.suggestions === undefined) {
                                return;
                            }

                            if (this.suggestionsAreRemote) {
                                this.fetchRemote();
                                return;
                            }

                            this.matches = this.filter(Array.isArray(config.suggestions) ? config.suggestions : Object.values(config.suggestions));
                            this.open = this.matches.length > 0;
                        },

                        /** Keeps suggestions that contain the typed text and are not already picked. */
                        filter: function (list) {
                            var self = this;
                            var needle = this.normalise(this.query.trim());

                            return this.clean(list).filter(function (suggestion) {
                                return (needle === '' || self.normalise(suggestion).indexOf(needle) !== -1)
                                    && (config.allowDuplicates || ! self.has(suggestion));
                            }).slice(0, 20);
                        },

                        fetchRemote: function () {
                            var self = this;
                            var term = this.query.trim();

                            window.clearTimeout(debounceTimer);

                            if (! term.length) {
                                this.matches = [];
                                this.open = false;
                                return;
                            }

                            debounceTimer = window.setTimeout(function () {
                                var request = ++requestCounter;
                                var url = config.suggestions + (config.suggestions.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(term);

                                fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                                    .then(function (response) { return response.ok ? response.json() : []; })
                                    .then(function (body) {
                                        if (request !== requestCounter) {
                                            return; // a newer request is in flight
                                        }

                                        var list = Array.isArray(body) ? body : (body && Array.isArray(body.data) ? body.data : []);
                                        self.remote = list.map(function (item) {
                                            return item && typeof item === 'object' ? (item.text || item.name || item.value || '') : item;
                                        });
                                        self.matches = self.filter(self.remote);
                                        self.open = self.focused && self.matches.length > 0;
                                    })
                                    .catch(function () {});
                            }, 250);
                        }
                    };
                });

                window.bpFieldInitTagsElement = function (element) {
                    var container = element.closest('.tags-field')[0];

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
