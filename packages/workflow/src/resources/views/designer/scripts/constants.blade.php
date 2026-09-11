<script>
    // Every field type this app's own crud/fields views registers (see
    // resources/views/crud/fields/*.blade.php) — offered as-is in the field
    // policy editor's "type" column rather than hand-maintaining a second,
    // narrower list.
    const WF_BACKPACK_FIELD_TYPES = [
        'address_google', 'ajax_multi_upload', 'ajax_upload', 'base64_image', 'boolean', 'browse',
        'browse_multiple', 'checkbox', 'checklist', 'checklist_dependency', 'ckeditor', 'color',
        'color_picker', 'custom_html', 'date', 'date_only', 'date_picker', 'date_range', 'datetime',
        'datetime_picker', 'dependent_select', 'easymde', 'email', 'enum', 'hidden', 'icon_picker',
        'identity', 'image', 'latlng_picker', 'model_picker', 'money', 'month', 'number',
        'page_or_link', 'password', 'phone', 'radio', 'range', 'relationship', 'repeatable', 'select',
        'select2', 'select2_from_ajax', 'select2_from_ajax_multiple', 'select2_from_array',
        'select2_grouped', 'select2_multiple', 'select2_nested', 'select_and_order',
        'select_from_array', 'select_grouped', 'select_multiple', 'simplemde', 'slug', 'summernote',
        'switch', 'table', 'tags', 'text', 'textarea', 'time', 'time_range', 'tinymce', 'upload',
        'upload_multiple', 'url', 'video', 'view', 'week', 'wysiwyg',
    ];

    // Shown as the "Custom field definition" column's placeholder — written
    // as a literal PHP array, matching how a developer would write this
    // same config by hand in a CrudController. Merged onto the built
    // Backpack field server-side via a token-whitelisted, safe-eval-only
    // parser (see FieldPolicyResolver) — never raw PHP execution.
    const WF_CUSTOM_FIELD_DEFINITION_PLACEHOLDER = `[
    'options' => ['a' => 'A', 'b' => 'B'],
    'tab' => 'Details',
]`;
</script>
