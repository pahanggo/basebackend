{{-- Text Backpack CRUD filter --}}

<li filter-name="{{ $filter->name }}"
    filter-type="{{ $filter->type }}"
    filter-key="{{ $filter->key }}"
    class="nav-item dropdown {{ Request::get($filter->name) ? 'active' : '' }}">
    <div class="d-flex align-items-center">
        <label for="text-filter-{{ $filter->key }}" id="text-filter-label-{{ $filter->key }}" class="caret px-2 mb-0" style="color:#869ab8">
            {{ $filter->label }}
        </label>
        <div class="input-group">
            <input class="form-control pull-right"
                autocomplete="disabled-field-unique-string"
                id="text-filter-{{ $filter->key }}"
                type="text"
                @if ($filter->currentValue)
                    value="{{ $filter->currentValue }}"
                @endif
                >

            <div class="input-group-append text-filter-{{ $filter->key }}-button @if (!$filter->currentValue) d-none @endif">
                <button class="input-group-text" href=""><i class="la la-times"></i></button>
            </div>
        </div>
    </div>
</li>

{{-- ########################################### --}}
{{-- Extra CSS and JS for this particular filter --}}


{{-- FILTERS EXTRA JS --}}
{{-- push things in the after_scripts section --}}

@push('crud_list_scripts')
    <!-- include select2 js-->
  <script>
        jQuery(document).ready(function($) {
            function search(e) {
                var parameter = '{{ $filter->name }}';
                var value = $(this).val();

                // behaviour for ajax table
                var ajax_table = $('#crudTable').DataTable();
                var current_url = ajax_table.ajax.url();
                var new_url = addOrUpdateUriParameter(current_url, parameter, value);

                // replace the datatables ajax url with new_url and reload it
                new_url = normalizeAmpersand(new_url.toString());
                ajax_table.ajax.url(new_url).load();

                // add filter to URL
                crud.updateUrl(new_url);

                // mark this filter as active in the navbar-filters
                if (URI(new_url).hasQuery('{{ $filter->name }}', true)) {
                    $('li[filter-key={{ $filter->key }}]').removeClass('active').addClass('active');
                } else {
                    $('li[filter-key={{ $filter->key }}]').trigger('filter:clear');
                }

                if(value) {
                    $(".text-filter-{{ $filter->key }}-button").removeClass('d-none');
                } else {
                    $(".text-filter-{{ $filter->key }}-button").addClass('d-none');
                }
            }

            $('#text-filter-{{ $filter->key }}').on('change', search);

            $('li[filter-key={{ $filter->key }}]').on('filter:clear', function(e) {
                $('li[filter-key={{ $filter->key }}]').removeClass('active');
                $('#text-filter-{{ $filter->key }}').val('');

                $(".text-filter-{{ $filter->key }}-button").addClass('d-none');
            });

            // datepicker clear button
            $(".text-filter-{{ $filter->key }}-button").click(function(e) {
                e.preventDefault();
                $('li[filter-key={{ $filter->key }}]').trigger('filter:clear');
                $('#text-filter-{{ $filter->key }}').val('');
                $('#text-filter-{{ $filter->key }}').trigger('change');

                $(".text-filter-{{ $filter->key }}-button").addClass('d-none');
            })
        });
  </script>
@endpush
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
