{{-- "view" filter: any blade, here a simple toggle that behaves like the simple filter --}}
<li filter-name="{{ $filter->name }}"
    filter-type="{{ $filter->type }}"
    filter-key="{{ $filter->key }}"
    class="nav-item {{ Request::get($filter->name) ? 'active' : '' }}">
    <a class="nav-link" href="" parameter="{{ $filter->name }}"><i class="la la-eye"></i> {{ $filter->label }}</a>
</li>

@push('crud_list_scripts')
    <script>
        jQuery(document).ready(function($) {
            $("li[filter-key={{ $filter->key }}] a").click(function(e) {
                e.preventDefault();
                var parameter = $(this).attr('parameter');
                var ajax_table = $("#crudTable").DataTable();
                var current_url = ajax_table.ajax.url();
                var new_url = URI(current_url).hasQuery(parameter)
                    ? URI(current_url).removeQuery(parameter, true)
                    : URI(current_url).addQuery(parameter, true);

                new_url = normalizeAmpersand(new_url.toString());
                ajax_table.ajax.url(new_url).load();
                crud.updateUrl(new_url);

                if (URI(new_url).hasQuery('{{ $filter->name }}', true)) {
                    $("li[filter-key={{ $filter->key }}]").addClass('active');
                    $('#remove_filters_button').removeClass('invisible');
                } else {
                    $("li[filter-key={{ $filter->key }}]").trigger("filter:clear");
                }
            });

            $("li[filter-key={{ $filter->key }}]").on('filter:clear', function() {
                $("li[filter-key={{ $filter->key }}]").removeClass('active');
            });
        });
    </script>
@endpush
