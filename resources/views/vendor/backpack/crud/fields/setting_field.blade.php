<!-- text input -->
@php
$fields = [];
foreach(scandir('../resources/views/vendor/backpack/crud/fields') as $file) {
    if(is_dir('../resources/views/vendor/backpack/crud/fields/' . $file)) continue;
    if(in_array($file, ['.', '..', 'setting_field.blade.php'])) continue;
    if($name = substr($file, 0, strlen($file) - 10)) {
        $fields[] = $name;
    }
}
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    <input type="hidden" name="field[name]" value="value">
    <input type="hidden" name="field[value]" value="">
    <select class="form-control" name="field[type]" id="">
        @foreach($fields as $field)
        <option value="{{$field}}">{{$field}}</option>
        @endforeach
    </select>
</div>
