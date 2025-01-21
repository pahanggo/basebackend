<?php

namespace App\Console\Commands\Backpack;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrudRequestBackpackCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'backpack:crud-request';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backpack:crud-request {name} {--settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Backpack CRUD request';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Request';

    /**
     * Get the destination class path.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getPath($name)
    {
        $name = str_replace($this->laravel->getNamespace(), '', $name);
        return $this->laravel['path'].'/'.str_replace('\\', '/', $name).'Request.php';
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if($this->option('settings')) {
            return base_path('stubs/crud-request-settings.stub');
        }
        return base_path('stubs/crud-request.stub');
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        if($this->option('settings')) {
            return $rootNamespace.'\Http\Requests\Settings';
        }
        return $rootNamespace.'\Http\Requests';
    }

    protected function addColumns(&$stub, $name)
    {
        $name = substr($name, 0, strlen($name) - 6);
        $name = ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', str_replace($this->getNamespace($name) . '\\', '', $name))), '_');

        $table = Str::snake(Str::plural($name));

        $columns = DB::getSchemaBuilder()->getColumnListing($table);
        $fields = [];
        foreach ($columns as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'parent_id', 'lft', 'rgt', 'depth'])) continue;
            $fields[] = "            '$field' => 'required',";
        }

        $stub = str_replace("            // 'name' => 'required',", implode(PHP_EOL, $fields), $stub);

        return $this;
    }

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)->addColumns($stub, $name)->replaceClass($stub, $name);
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
