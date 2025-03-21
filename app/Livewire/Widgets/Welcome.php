<?php

namespace App\Livewire\Widgets;

use App\Traits\DashboardWidgetTrait;
use Livewire\Component;

class Welcome extends Component
{
    use DashboardWidgetTrait;

    public $name = 'Welcome';

    public $user;

    public function render()
    {
        $this->user = user();
        return view('livewire.widgets.welcome');
    }
}
