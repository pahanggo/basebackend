<div>
    @foreach($widgets as $rowIndex => $rows)
    <div class="row">
        @foreach($rows as $widgetIndex => $widget)
            @livewire('widgets.' . $widget, [], key('widget.' . $rowIndex . '.' . $widgetIndex))
        @endforeach
    </div>

    @if($editing)
    <hr/>
        <select class="add-widget" wire:change="addWidget({{$rowIndex}}, event.target.value)">
            <option value="">
                Tambah Widget
            </option>
            @foreach($available as $aWidget)
            <option value="{{$aWidget['path']}}">{{$aWidget['name']}}</option>
            @endforeach
        </select>
        @if(count($rows) > 0)
        <button wire:click="removeWidget({{$rowIndex}})" class="btn btn-sm btn-default">
            <i class="la la-trash"></i> Hapus Widget
        </button>
        @else
        <button wire:click="removeRow({{$rowIndex}})" class="btn btn-sm btn-default">
            <i class="la la-trash"></i> Hapus Row
        </button>
        @endif
    <hr/>
    @endif
    @endforeach

    <button class="btn btn-sm btn-default" wire:click="toggleEdit">
        @if($editing)
        <i class="la la-save"></i> Simpan
        @else
        <i class="la la-pencil"></i> Kemas Kini
        @endif
    </button>

    @if($editing)
    <button wire:click="addRow" class="btn btn-sm btn-default">
        <i class="la la-plus"></i> Tambah Row
    </button>
    @endif
    <style>
        .add-widget {
            border-color: transparent;
            height: 30px;
            border-radius: 4px;
            padding: 2px 6px;
        }
    </style>
</div>

