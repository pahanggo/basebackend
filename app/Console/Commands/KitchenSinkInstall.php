<?php

namespace App\Console\Commands;

use Database\Seeders\KitchenSinkSeeder;
use Illuminate\Console\Command;

class KitchenSinkInstall extends Command
{
    protected $signature = 'kitchensink:install {--fresh : Drop and recreate the kitchen sink database}';

    protected $description = 'Create, migrate and seed the separate SQLite database used by the Kitchen Sink CRUD';

    public function handle(): int
    {
        if (! config('app.kitchensink')) {
            $this->components->warn('The kitchen sink is disabled in config/app.php. Set "kitchensink" to true first.');

            return self::FAILURE;
        }

        $database = config('database.connections.kitchensink.database');

        if ($this->option('fresh') && file_exists($database)) {
            unlink($database);
        }

        if (! file_exists($database)) {
            touch($database);
            $this->components->info("Created {$database}");
        }

        $this->call('migrate', [
            '--database' => 'kitchensink',
            '--path' => 'database/migrations/kitchensink',
            '--force' => true,
        ]);

        $this->call('db:seed', ['--class' => KitchenSinkSeeder::class, '--force' => true]);

        // Seeded images and attachments live on the public disk, so make sure it is reachable.
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        $this->components->info('Kitchen sink ready at '.backpack_url('kitchensink'));

        return self::SUCCESS;
    }
}
