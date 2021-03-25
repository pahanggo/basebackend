<?php

namespace App\Console;

use App\Console\Commands\Backpack\CrudControllerBackpackCommand;
use App\Console\Commands\Backpack\CrudModelBackpackCommand;
use App\Console\Commands\Backpack\CrudRequestBackpackCommand;
use App\Console\Commands\MakeReportCommand;
use App\Console\Commands\MakeWidgetCommand;
use App\Console\Commands\Reports\MakeReportViewCommand;
use App\Console\Commands\Reports\MakeReportControllerCommand;
use App\Console\Commands\Widgets\MakeWidgetControllerCommand;
use App\Console\Commands\Widgets\MakeWidgetViewCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CrudControllerBackpackCommand::class,
        CrudModelBackpackCommand::class,
        CrudRequestBackpackCommand::class,
        MakeWidgetViewCommand::class,
        MakeWidgetControllerCommand::class,
        MakeWidgetCommand::class,
        MakeReportControllerCommand::class,
        MakeReportViewCommand::class,
        MakeReportCommand::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
