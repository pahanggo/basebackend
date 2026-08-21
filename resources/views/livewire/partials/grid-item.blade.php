<div class="grid-stack-item"
     gs-id="{{ $item['id'] }}"
     gs-x="{{ $item['x'] }}"
     gs-y="{{ $item['y'] }}"
     gs-w="{{ $item['w'] }}"
     gs-h="{{ $item['h'] }}"
     wire:key="widget-{{ $item['id'] }}">
    <div class="grid-stack-item-content">
        <button type="button" class="widget-remove-btn" wire:click="removeWidget('{{ $item['id'] }}')" title="Hapus Widget">
            <i class="la la-trash"></i>
        </button>
        @livewire('widgets.' . $item['widget'], [], key('widget.' . $item['id']))
    </div>
</div>
