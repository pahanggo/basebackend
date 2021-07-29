<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CreatePermissionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backpack:permission {name : The name of the permission}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates new permission';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $name = $this->argument('name');
        $seeder = base_path('database/seeders/UserSeeder.php');
        $content = file_get_contents($seeder);
        $content = str_replace('                // more permissions', "                '$name'," . PHP_EOL . '                // more permissions', $content);
        file_put_contents($seeder, $content);

        $this->call('db:seed', ['class' => 'UserSeeder']);
        return 0;
    }
}
