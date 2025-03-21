<?php

namespace App\Livewire;

use App\Services\WidgetService;
use Livewire\Component;

use function PHPUnit\Framework\isArray;

class Dashboard extends Component
{
    public $editing = false;
    public $widgets = [];
    public $available = [];
    public $widgetToAdd;

    public function mount()
    {
        $this->widgets = user()->widgets;
        $this->available = WidgetService::all();
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
        $this->editing = !$this->editing;

        if(!$this->editing) {
            $this->saveWidgets();
        }
    }

    public function addRow()
    {
        $widgets = $this->widgets;
        $widgets[] = [];
        $this->widgets = $widgets;
    }

    public function removeRow($index)
    {
        $widgets = $this->widgets;
        array_splice($widgets, $index, 1);
        $this->widgets = $widgets;
    }

    public function addWidget($index, $value)
    {
        if($value) {
            $widgets = $this->widgets;
            $widgets[$index][] = $value;
            $this->widgets = $widgets;
        }
    }

    public function removeWidget($index)
    {
        $widgets = $this->widgets;
        array_pop($widgets[$index]);
        $this->widgets = $widgets;
    }
}
