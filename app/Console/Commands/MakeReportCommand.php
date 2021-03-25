<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:report {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a Dashboard Report Controller and View';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->argument('name');
        $this->call('make:report-controller', ['name' => $name]);
        $this->call('make:report-view', ['name' => $name]);
    }
}
