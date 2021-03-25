<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeWidgetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:widget {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a Dashboard Widget Controller and View';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->argument('name');
        $this->call('make:widget-controller', ['name' => $name]);
        $this->call('make:widget-view', ['name' => $name]);
    }
}
