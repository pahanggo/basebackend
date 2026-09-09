{{-- Text Backpack CRUD filter: same markup and look as the datatable search box --}}

<li filter-name="{{ $filter->name }}"
    filter-type="{{ $filter->type }}"
    filter-key="{{ $filter->key }}"
    class="nav-item text-filter {{ $filter->currentValue ? 'active' : '' }}">
    <input class="form-control"
        autocomplete="off"
        id="text-filter-{{ $filter->key }}"
        type="search"
        placeholder="{{ $filter->label }}"
        aria-label="{{ $filter->label }}"
        value="{{ $filter->currentValue ?? '' }}">
</li>

{{-- ########################################### --}}
{{-- Extra CSS and JS for this particular filter --}}

@push('crud_list_scripts')
    <script>
        jQuery(document).ready(function($) {
            var $li = $('li[filter-key={{ $filter->key }}]');
            var $input = $('#text-filter-{{ $filter->key }}');

            function search() {
                var parameter = '{{ $filter->name }}';
                var value = $input.val();

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
                    $li.addClass('active');
                    $('#remove_filters_button').removeClass('invisible');
                } else {
                    $li.removeClass('active');
                }
            }

            // "search" fires on Enter and when the native clear (x) is clicked; "change" on blur
            $input.on('search change', search);

            $li.on('filter:clear', function() {
                $li.removeClass('active');
                $input.val('');
            });
        });
    </script>
@endpush
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
