<?php

namespace App\Livewire\Widgets;

use App\Models\User;
use App\Traits\DashboardWidgetTrait;
use Livewire\Component;

class UserCounter extends Component
{
    use DashboardWidgetTrait;

    public $name = 'User Counter';

    public $count = 0;

    public function mount()
    {
        $this->count = User::count();
    }

    public function render()
    {
        return view('livewire.widgets.user-counter');
    }
}
