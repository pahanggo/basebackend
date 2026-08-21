<?php

namespace App\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeWidgetCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'make:widget';

    protected $signature = 'make:widget {name} {--force : Overwrite the widget class/view if they already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new dashboard widget (Livewire component + view)';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Widget';

    public function handle()
    {
        $className = ucfirst(Str::camel($this->argument('name')));
        $this->input->setArgument('name', $className);

        if (parent::handle() === false) {
            return false;
        }

        $this->writeWidgetView($className);
    }

    protected function getStub()
    {
        return base_path('stubs/widget.stub');
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Livewire\Widgets';
    }

    protected function buildClass($name)
    {
        $stub = parent::buildClass($name);

        $className = class_basename($name);

        return str_replace(
            ['DummyTitle', 'DummyView'],
            [Str::headline($className), 'livewire.widgets.'.Str::kebab($className)],
            $stub
        );
    }

    protected function writeWidgetView(string $className)
    {
        $path = resource_path('views/livewire/widgets/'.Str::kebab($className).'.blade.php');

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error('Widget view already exists!');

            return;
        }

        $this->makeDirectory($path);

        $stub = $this->files->get(base_path('stubs/widget-view.stub'));
        $stub = str_replace('DummyTitle', Str::headline($className), $stub);

        $this->files->put($path, $stub);

        $this->components->info('Widget view created successfully.');
    }

}
