{{-- "view" field: renders any blade inside the form; $field and $crud are available --}}
<div class="form-group col-sm-12">
    <div class="alert alert-secondary mb-0">
        <strong>view</strong> field: this block is <code>{{ $field['view'] }}</code>, rendered for
        <code>{{ $crud->entity_name }}</code>. Use it for read-only summaries or custom widgets inside a form.
    </div>
</div>
