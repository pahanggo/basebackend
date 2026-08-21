<?php

namespace App\Livewire;

use App\Services\WidgetService;
use Illuminate\Support\Str;
use Livewire\Component;

class Dashboard extends Component
{
    public $editing = false;

    public $widgets = [];

    public $available = [];

    public $startAt;

    public $endAt;

    public function mount()
    {
        $this->widgets = $this->migrateLegacyWidgets(user()->widgets);
        $this->available = WidgetService::all();
        $this->startAt = now()->subDays(29)->startOfDay()->toDateString();
        $this->endAt = now()->endOfDay()->toDateString();
    }

    /**
     * Historically `users.widgets` stored an array of rows, each row a list
     * of widget slug strings (e.g. `[['welcome'], ['usercounter']]`), edited
     * via addRow/removeRow/addWidget($index,...). GridStack instead needs a
     * flat list of positioned items (`{id, widget, x, y, w, h}`). This
     * converts either shape into the flat form so old layouts keep working,
     * laying legacy rows out left-to-right/top-to-bottom using each widget's
     * default size, and drops any 'welcome' entry - that widget no longer
     * exists as a standalone tile, its content now lives in the dashboard
     * shell header (see dashboard.blade.php).
     *
     * @return array<int, array{id: string, widget: string, x: int, y: int, w: int, h: int}>
     */
    protected function migrateLegacyWidgets($stored): array
    {
        if (collect($stored)->every(fn ($widget) => is_array($widget) && array_key_exists('id', $widget))) {
            return collect($stored)->reject(fn ($widget) => $widget['widget'] === 'welcome')->values()->all();
        }

        $y = 0;
        $items = [];

        foreach ($stored as $row) {
            $x = 0;
            $rowHeight = 0;

            foreach ((array) $row as $slug) {
                if ($slug === 'welcome') {
                    continue;
                }

                $size = WidgetService::defaultSizeFor($slug);

                $items[] = [
                    'id' => 'w-'.Str::random(10),
                    'widget' => $slug,
                    'x' => $x,
                    'y' => $y,
                    'w' => $size['w'],
                    'h' => $size['h'],
                ];

                $x += $size['w'];
                $rowHeight = max($rowHeight, $size['h']);
            }

            if ($rowHeight > 0) {
                $y += $rowHeight;
            }
        }

        return $items;
    }

    public function render()
    {
        return view('livewire.dashboard');
    }

    protected function saveWidgets()
    {
        $user = user();
        $user->widgets = $this->widgets;
        $user->save();
    }

    public function toggleEdit()
    {
        $this->editing = ! $this->editing;

        if (! $this->editing) {
            $this->saveWidgets();
            $this->dispatch('notify', type: 'success', text: 'Tetapan disimpan.');
        }
    }

    /**
     * Stages new positions/sizes reported by GridStack after a drag or
     * resize. In-memory only - not persisted until toggleEdit() saves.
     *
     * @param  array<int, array{id: string, x: int, y: int, w: int, h: int}>  $items
     */
    public function updateLayout(array $items)
    {
        $byId = collect($this->widgets)->keyBy('id');

        foreach ($items as $item) {
            if ($byId->has($item['id'])) {
                $byId[$item['id']] = array_merge($byId[$item['id']], [
                    'x' => (int) $item['x'],
                    'y' => (int) $item['y'],
                    'w' => (int) $item['w'],
                    'h' => (int) $item['h'],
                ]);
            }
        }

        $this->widgets = $byId->values()->all();
    }

    /**
     * The GridStack container is rendered wire:ignore (see
     * resources/views/livewire/dashboard.blade.php) so drag/resize and edit
     * toggling never fight Livewire's DOM morphing. Adding/removing a widget
     * therefore can't rely on a normal Blade re-render to update that
     * subtree - instead we dispatch a browser event carrying whatever the
     * client needs (rendered HTML for an add, the id for a remove) and let
     * JS apply it directly via the GridStack API.
     */
    public function addWidget(string $path)
    {
        if (! $path) {
            return;
        }

        $size = WidgetService::defaultSizeFor($path);
        $y = collect($this->widgets)->reduce(fn ($carry, $widget) => max($carry, $widget['y'] + $widget['h']), 0);

        $item = [
            'id' => 'w-'.Str::random(10),
            'widget' => $path,
            'x' => 0,
            'y' => $y,
            'w' => $size['w'],
            'h' => $size['h'],
        ];

        $widgets = $this->widgets;
        $widgets[] = $item;
        $this->widgets = $widgets;

        $this->dispatch('widget-added', html: view('livewire.partials.grid-item', ['item' => $item])->render());
    }

    public function removeWidget(string $id)
    {
        $this->widgets = collect($this->widgets)->reject(fn ($widget) => $widget['id'] === $id)->values()->all();

        $this->dispatch('widget-removed', id: $id);
    }
}
