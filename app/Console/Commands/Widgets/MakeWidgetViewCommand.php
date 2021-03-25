<?php

namespace App\Console\Commands\Widgets;

use Illuminate\Console\GeneratorCommand;

class MakeWidgetViewCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:widget-view';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:widget-view {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Dashboard Widget View';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'View';

    /**
     * Get the destination class path.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getPath($name)
    {
        $baseName = str_replace($this->getNamespace($name).'\\', '', $name);
        return resource_path('views/widgets/' . strtolower($baseName) . '.blade.php');
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return base_path('stubs/widget-view.stub');
    }

    /**
     * Replace the table name for the given stub.
     *
     * @param string $stub
     * @param string $name
     *
     * @return string
     */
    protected function replaceNameStrings(&$stub, $name)
    {
        $baseName = str_replace($this->getNamespace($name).'\\', '', $name);
        $stub = str_replace('DummyClass', $baseName, $stub);
        return $this;
    }

    /**
     * Build the class with the given name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        $this->replaceNameStrings($stub, $name);

        return $stub;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [

        ];
    }
}
