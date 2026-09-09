<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\KitchenSinkRequest;
use App\Models\KitchenSink\KitchenSink;
use App\Models\KitchenSink\KitchenSinkCategory;
use App\Models\KitchenSink\KitchenSinkTag;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Illuminate\Support\Facades\Storage;

/**
 * Kitchen Sink: one CRUD that renders every column and field type shipped
 * with this base framework, backed by the separate "kitchensink" SQLite
 * database. Toggle it with config('app.kitchensink').
 *
 * Not included because they need services this base does not ship:
 * browse / browse_multiple (elFinder), page_or_link (PageManager) and enum
 * (SQLite has no enum). The address / address_algolia fields were removed from
 * the framework because Algolia Places was shut down.
 * The "checkbox" column is Backpack's bulk-action selector, not a data column.
 *
 * @property-read \Backpack\CRUD\app\Library\CrudPanel\CrudPanel $crud
 */
class KitchenSinkCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CloneOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\FetchOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ReorderOperation;

    public function setup(): void
    {
        abort_unless(config('app.kitchensink'), 404);

        CRUD::setModel(KitchenSink::class);
        CRUD::setRoute(config('backpack.base.route_prefix').'/kitchensink');
        CRUD::setEntityNameStrings(__('Kitchen Sink'), __('Kitchen Sink'));

        // Show only the labelled columns below, not every DB column guessed from the table.
        CRUD::set('show.setFromDb', false);
    }

    protected function setupListOperation(): void
    {
        CRUD::addColumns($this->columns());
        CRUD::orderBy('lft');
        $this->addFilters();
    }

    protected function setupReorderOperation(): void
    {
        CRUD::set('reorder.label', 'title');
        CRUD::set('reorder.max_level', 2);
    }

    protected function setupShowOperation(): void
    {
        CRUD::addColumns($this->columns());
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(KitchenSinkRequest::class);
        CRUD::addFields($this->fields());
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }

    public function fetchCategory()
    {
        return $this->fetch(KitchenSinkCategory::class);
    }

    public function fetchTag()
    {
        return $this->fetch(KitchenSinkTag::class);
    }

    /**
     * Every filter type, labelled by its type name.
     */
    private function addFilters(): void
    {
        CRUD::addFilter(
            ['name' => 'active', 'type' => 'simple', 'label' => 'simple'],
            false,
            fn () => CRUD::addClause('where', 'is_active', true)
        );

        CRUD::addFilter(
            ['name' => 'title', 'type' => 'text', 'label' => 'text'],
            false,
            fn (string $value) => CRUD::addClause('where', 'title', 'like', '%'.$value.'%')
        );

        CRUD::addFilter(
            ['name' => 'status', 'type' => 'dropdown', 'label' => 'dropdown'],
            ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'],
            fn (string $value) => CRUD::addClause('where', 'status', $value)
        );

        CRUD::addFilter(
            ['name' => 'category', 'type' => 'select2', 'label' => 'select2'],
            fn () => KitchenSinkCategory::orderBy('name')->pluck('name', 'id')->toArray(),
            fn (string $value) => CRUD::addClause('where', 'kitchen_sink_category_id', $value)
        );

        CRUD::addFilter(
            ['name' => 'tags', 'type' => 'select2_multiple', 'label' => 'select2_multiple'],
            fn () => KitchenSinkTag::orderBy('name')->pluck('name', 'id')->toArray(),
            fn (string $values) => CRUD::addClause('whereHas', 'tags', fn ($query) => $query->whereIn('kitchen_sink_tags.id', json_decode($values, true)))
        );

        CRUD::addFilter(
            ['name' => 'ajax_category', 'type' => 'select2_ajax', 'label' => 'select2_ajax', 'placeholder' => 'Search categories', 'method' => 'POST', 'minimum_input_length' => 0, 'select_attribute' => 'name', 'select_key' => 'id'],
            backpack_url('kitchensink/fetch/category'),
            fn (string $value) => CRUD::addClause('where', 'ajax_category_id', $value)
        );

        CRUD::addFilter(
            ['name' => 'rating', 'type' => 'range', 'label' => 'range', 'label_from' => 'min', 'label_to' => 'max'],
            false,
            function (string $value) {
                $range = json_decode($value, true);
                if (isset($range['from'])) {
                    CRUD::addClause('where', 'rating', '>=', (int) $range['from']);
                }
                if (isset($range['to'])) {
                    CRUD::addClause('where', 'rating', '<=', (int) $range['to']);
                }
            }
        );

        CRUD::addFilter(
            ['name' => 'published_on', 'type' => 'date', 'label' => 'date'],
            false,
            fn (string $value) => CRUD::addClause('whereDate', 'published_on', $value)
        );

        CRUD::addFilter(
            ['name' => 'published_between', 'type' => 'date_range', 'label' => 'date_range'],
            false,
            function (string $value) {
                $dates = json_decode($value, true);
                CRUD::addClause('where', 'published_at', '>=', $dates['from']);
                CRUD::addClause('where', 'published_at', '<=', $dates['to'].' 23:59:59');
            }
        );

        CRUD::addFilter(
            ['name' => 'has_image', 'type' => 'view', 'label' => 'view', 'view' => 'admin.kitchensink.filter_view'],
            false,
            fn () => CRUD::addClause('whereNotNull', 'image')
        );
    }

    /**
     * Every column type, labelled by its type name so the list doubles as a reference.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columns(): array
    {
        return [
            ['name' => 'row_number', 'type' => 'row_number', 'label' => 'row_number', 'orderable' => false],
            ['name' => 'title', 'type' => 'text', 'label' => 'text'],
            ['name' => 'slug', 'type' => 'text', 'label' => 'text (slug)'],
            ['name' => 'description', 'type' => 'textarea', 'label' => 'textarea', 'limit' => 40],
            ['name' => 'email', 'type' => 'email', 'label' => 'email'],
            ['name' => 'phone', 'type' => 'phone', 'label' => 'phone'],
            ['name' => 'price', 'type' => 'number', 'label' => 'number', 'prefix' => 'RM ', 'decimals' => 2, 'thousands_sep' => ','],
            ['name' => 'is_active', 'type' => 'boolean', 'label' => 'boolean', 'options' => [0 => 'No', 1 => 'Yes']],
            ['name' => 'agreed', 'type' => 'check', 'label' => 'check'],
            ['name' => 'is_featured', 'type' => 'boolean', 'label' => 'boolean (switch)', 'options' => [0 => 'No', 1 => 'Yes']],
            ['name' => 'published_on', 'type' => 'date', 'label' => 'date'],
            ['name' => 'published_at', 'type' => 'datetime', 'label' => 'datetime', 'format' => 'DD MMM YYYY HH:mm'],
            ['name' => 'status', 'type' => 'select_from_array', 'label' => 'select_from_array', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived']],
            ['name' => 'gender', 'type' => 'radio', 'label' => 'radio', 'options' => ['male' => 'Male', 'female' => 'Female']],
            ['name' => 'category', 'type' => 'select', 'label' => 'select', 'entity' => 'category', 'attribute' => 'name', 'model' => KitchenSinkCategory::class],
            ['name' => 'tags', 'type' => 'select_multiple', 'label' => 'select_multiple', 'entity' => 'tags', 'attribute' => 'name', 'model' => KitchenSinkTag::class],
            ['name' => 'ajaxCategory', 'type' => 'relationship', 'label' => 'relationship', 'attribute' => 'name'],
            ['name' => 'checklistTags', 'type' => 'relationship_count', 'label' => 'relationship_count', 'suffix' => ' tags'],
            ['name' => 'sizes', 'type' => 'array', 'label' => 'array'],
            ['name' => 'attachments', 'key' => 'attachments_count', 'type' => 'array_count', 'label' => 'array_count', 'suffix' => ' files'],
            ['name' => 'metadata', 'type' => 'json', 'label' => 'json'],
            ['name' => 'lines', 'type' => 'multidimensional_array', 'label' => 'multidimensional_array', 'visible_key' => 'sku'],
            ['name' => 'extras', 'type' => 'table', 'label' => 'table', 'columns' => ['key' => 'Key', 'value' => 'Value']],
            ['name' => 'body_simplemde', 'type' => 'markdown', 'label' => 'markdown'],
            ['name' => 'image', 'type' => 'image', 'label' => 'image', 'disk' => 'public', 'height' => '40px', 'width' => '40px'],
            ['name' => 'avatar', 'type' => 'image', 'label' => 'image (base64_image)', 'disk' => 'public', 'height' => '40px', 'width' => '40px'],
            ['name' => 'attachment', 'type' => 'closure', 'label' => 'closure (upload link)', 'escaped' => false, 'function' => fn (KitchenSink $entry) => $entry->attachment ? '<a href="'.e(Storage::disk('public')->url($entry->attachment)).'" target="_blank">'.e(basename($entry->attachment)).'</a>' : '-'],
            ['name' => 'attachments', 'type' => 'upload_multiple', 'label' => 'upload_multiple', 'disk' => 'public'],
            ['name' => 'ajax_files', 'type' => 'upload_multiple', 'label' => 'upload_multiple (ajax_multi_upload)', 'disk' => 'public'],
            ['name' => 'video', 'type' => 'video', 'label' => 'video'],
            ['name' => 'location', 'type' => 'latlng_map', 'label' => 'latlng_map', 'width' => 200, 'height' => 120, 'zoom' => 14],
            ['name' => 'title_with_rating', 'type' => 'model_function', 'label' => 'model_function', 'function_name' => 'titleWithRating'],
            ['name' => 'primary_category_name', 'type' => 'model_function_attribute', 'label' => 'model_function_attribute', 'function_name' => 'primaryCategory', 'attribute' => 'name'],
            ['name' => 'closure', 'type' => 'closure', 'label' => 'closure', 'function' => fn (KitchenSink $entry) => strtoupper($entry->size ?? '-').' / '.$entry->rating],
            ['name' => 'custom_html', 'type' => 'custom_html', 'label' => 'custom_html', 'value' => '<span class="badge badge-primary">static html</span>'],
            ['name' => 'view', 'type' => 'view', 'label' => 'view', 'view' => 'admin.kitchensink.column_view'],
        ];
    }

    /**
     * Every field type, grouped into tabs and labelled by its type name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fields(): array
    {
        $tab = fn (string $tab, array $fields) => array_map(fn (array $field) => $field + ['tab' => $tab], $fields);

        return array_merge(
            $tab('Text', [
                ['name' => 'title', 'type' => 'text', 'label' => 'text'],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'slug', 'target' => 'title', 'hint' => 'Follows the text field above until you edit it; clear it to resume.'],
                ['name' => 'description', 'type' => 'textarea', 'label' => 'textarea'],
                ['name' => 'email', 'type' => 'email', 'label' => 'email'],
                ['name' => 'website', 'type' => 'url', 'label' => 'url'],
                ['name' => 'phone', 'type' => 'text', 'label' => 'text (phone)'],
                ['name' => 'secret', 'type' => 'password', 'label' => 'password'],
                ['name' => 'hidden_token', 'type' => 'hidden', 'value' => 'token-'.now()->timestamp],
                ['name' => 'price', 'type' => 'number', 'label' => 'number', 'attributes' => ['step' => '0.01'], 'prefix' => 'RM'],
                ['name' => 'rating', 'type' => 'range', 'label' => 'range', 'attributes' => ['min' => 0, 'max' => 10]],
                ['name' => 'custom_html', 'type' => 'custom_html', 'value' => '<div class="alert alert-info mb-0">custom_html: any markup, no input.</div>'],
            ]),
            $tab('Choices', [
                ['name' => 'is_active', 'type' => 'boolean', 'label' => 'boolean'],
                ['name' => 'agreed', 'type' => 'checkbox', 'label' => 'checkbox'],
                ['name' => 'is_featured', 'type' => 'switch', 'label' => 'switch', 'color' => '#232323', 'onLabel' => '✓', 'offLabel' => '✕'],
                ['name' => 'gender', 'type' => 'radio', 'label' => 'radio', 'options' => ['male' => 'Male', 'female' => 'Female'], 'inline' => true],
                ['name' => 'status', 'type' => 'select_from_array', 'label' => 'select_from_array', 'options' => ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'], 'allows_null' => true],
                ['name' => 'size', 'type' => 'select2_from_array', 'label' => 'select2_from_array', 'options' => ['S' => 'Small', 'M' => 'Medium', 'L' => 'Large'], 'allows_null' => true],
                ['name' => 'sizes', 'type' => 'select2_from_array', 'label' => 'select2_from_array (multiple)', 'options' => ['S' => 'Small', 'M' => 'Medium', 'L' => 'Large'], 'allows_multiple' => true],
                ['name' => 'ordered_sizes', 'type' => 'select_and_order', 'label' => 'select_and_order', 'options' => ['S' => 'Small', 'M' => 'Medium', 'L' => 'Large']],
            ]),
            $tab('Relations', [
                ['name' => 'kitchen_sink_category_id', 'type' => 'select', 'label' => 'select', 'entity' => 'category', 'attribute' => 'name', 'model' => KitchenSinkCategory::class],
                ['name' => 'select2_category_id', 'type' => 'select2', 'label' => 'select2', 'entity' => 'select2Category', 'attribute' => 'name', 'model' => KitchenSinkCategory::class, 'allows_null' => true],
                ['name' => 'grouped_category_id', 'type' => 'select_grouped', 'label' => 'select_grouped', 'entity' => 'groupedCategory', 'attribute' => 'name', 'model' => KitchenSinkCategory::class, 'group_by' => 'group', 'group_by_attribute' => 'name', 'group_by_relationship_back' => 'categories'],
                ['name' => 'select2_grouped_category_id', 'type' => 'select2_grouped', 'label' => 'select2_grouped', 'entity' => 'select2GroupedCategory', 'attribute' => 'name', 'model' => KitchenSinkCategory::class, 'group_by' => 'group', 'group_by_attribute' => 'name', 'group_by_relationship_back' => 'categories'],
                ['name' => 'nested_category_id', 'type' => 'select2_nested', 'label' => 'select2_nested', 'entity' => 'nestedCategory', 'attribute' => 'name', 'model' => KitchenSinkCategory::class],
                ['name' => 'ajax_category_id', 'type' => 'select2_from_ajax', 'label' => 'select2_from_ajax', 'entity' => 'ajaxCategory', 'attribute' => 'name', 'model' => KitchenSinkCategory::class, 'data_source' => backpack_url('kitchensink/fetch/category'), 'placeholder' => 'Type to search categories', 'minimum_input_length' => 0],
                ['name' => 'ajaxCategory', 'type' => 'relationship', 'label' => 'relationship (belongsTo, ajax)', 'attribute' => 'name', 'ajax' => true, 'data_source' => backpack_url('kitchensink/fetch/category'), 'minimum_input_length' => 0],
                ['name' => 'tags', 'type' => 'relationship', 'label' => 'relationship (belongsToMany)', 'attribute' => 'name'],
                ['name' => 'checklistTags', 'type' => 'checklist', 'label' => 'checklist', 'entity' => 'checklistTags', 'attribute' => 'name', 'model' => KitchenSinkTag::class, 'pivot' => true],
                ['name' => 'selectTags', 'type' => 'select_multiple', 'label' => 'select_multiple', 'entity' => 'selectTags', 'attribute' => 'name', 'model' => KitchenSinkTag::class, 'pivot' => true],
                ['name' => 'select2Tags', 'type' => 'select2_multiple', 'label' => 'select2_multiple', 'entity' => 'select2Tags', 'attribute' => 'name', 'model' => KitchenSinkTag::class, 'pivot' => true],
                ['name' => 'ajaxTags', 'type' => 'select2_from_ajax_multiple', 'label' => 'select2_from_ajax_multiple', 'entity' => 'ajaxTags', 'attribute' => 'name', 'model' => KitchenSinkTag::class, 'data_source' => backpack_url('kitchensink/fetch/tag'), 'placeholder' => 'Type to search tags', 'minimum_input_length' => 0, 'pivot' => true],
            ]),
            $tab('Dates', [
                ['name' => 'published_on', 'type' => 'date', 'label' => 'date'],
                ['name' => 'published_at', 'type' => 'datetime', 'label' => 'datetime'],
                ['name' => 'opens_at', 'type' => 'time', 'label' => 'time'],
                ['name' => 'billing_month', 'type' => 'month', 'label' => 'month'],
                ['name' => 'week', 'type' => 'week', 'label' => 'week'],
                ['name' => 'birthday', 'type' => 'date_picker', 'label' => 'date_picker', 'date_picker_options' => ['format' => 'dd/mm/yyyy']],
                ['name' => 'remind_at', 'type' => 'datetime_picker', 'label' => 'datetime_picker', 'datetime_picker_options' => ['format' => 'DD/MM/YYYY HH:mm']],
                ['name' => ['starts_at', 'ends_at'], 'type' => 'date_range', 'label' => 'date_range', 'date_range_options' => ['timePicker' => true, 'locale' => ['format' => 'DD/MM/YYYY HH:mm']]],
            ]),
            $tab('Visual', [
                ['name' => 'color', 'type' => 'color', 'label' => 'color'],
                ['name' => 'accent_color', 'type' => 'color_picker', 'label' => 'color_picker'],
                ['name' => 'icon', 'type' => 'icon_picker', 'label' => 'icon_picker', 'iconset' => 'fontawesome'],
                ['name' => 'image', 'type' => 'image', 'label' => 'image', 'crop' => true, 'aspect_ratio' => 1, 'disk' => 'public'],
                ['name' => 'avatar', 'type' => 'base64_image', 'label' => 'base64_image', 'crop' => true, 'aspect_ratio' => 1, 'src' => 'avatarUrl', 'filename' => null],
                ['name' => 'attachment', 'type' => 'upload', 'label' => 'upload', 'upload' => true, 'disk' => 'public'],
                ['name' => 'attachments', 'type' => 'upload_multiple', 'label' => 'upload_multiple', 'upload' => true, 'disk' => 'public'],
                ['name' => 'ajax_file', 'type' => 'ajax_upload', 'label' => 'ajax_upload', 'path' => 'kitchensink', 'accept' => 'image/*,.pdf', 'max_size' => 2048, 'hint' => 'Uploads immediately; only the path is submitted.'],
                ['name' => 'ajax_files', 'type' => 'ajax_multi_upload', 'label' => 'ajax_multi_upload', 'path' => 'kitchensink', 'hint' => 'Drag to reorder.'],
                ['name' => 'video', 'type' => 'video', 'label' => 'video'],
            ]),
            $tab('Editors', [
                ['name' => 'body_ckeditor', 'type' => 'ckeditor', 'label' => 'ckeditor'],
                ['name' => 'body_tinymce', 'type' => 'tinymce', 'label' => 'tinymce'],
                ['name' => 'body_summernote', 'type' => 'summernote', 'label' => 'summernote'],
                ['name' => 'body_wysiwyg', 'type' => 'wysiwyg', 'label' => 'wysiwyg'],
                ['name' => 'body_simplemde', 'type' => 'simplemde', 'label' => 'simplemde'],
                ['name' => 'body_easymde', 'type' => 'easymde', 'label' => 'easymde'],
            ]),
            $tab('Structured', [
                ['name' => 'location', 'type' => 'latlng_picker', 'label' => 'latlng_picker', 'default' => ['lat' => 3.8077, 'lng' => 103.326], 'zoom' => 13, 'hint' => 'Drag the pin or search a place; stores {"lat", "lng"}.'],
                ['name' => 'address_google', 'type' => 'address_google', 'label' => 'address_google (needs services.google_places.key)', 'store_as_json' => true],
                ['name' => 'extras', 'type' => 'table', 'label' => 'table', 'entity_singular' => 'extra', 'columns' => ['key' => 'Key', 'value' => 'Value'], 'max' => 5, 'min' => 0],
                ['name' => 'lines', 'type' => 'repeatable', 'label' => 'repeatable', 'fields' => [
                    ['name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'wrapper' => ['class' => 'form-group col-md-4']],
                    ['name' => 'qty', 'type' => 'number', 'label' => 'Qty', 'wrapper' => ['class' => 'form-group col-md-4']],
                    ['name' => 'note', 'type' => 'text', 'label' => 'Note', 'wrapper' => ['class' => 'form-group col-md-4']],
                ], 'new_item_label' => 'Add line', 'init_rows' => 1],
                ['name' => 'view_field', 'type' => 'view', 'view' => 'admin.kitchensink.field_view'],
            ]),
        );
    }
}
