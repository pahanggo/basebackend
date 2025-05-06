<?php

namespace App\Console\Commands\Backpack;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CrudControllerBackpackCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backpack:crud-controller {name} {--settings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Backpack CRUD controller';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Controller';

    /**
     * Class imports.
     *
     * @var array
     */
    protected $imports = [];

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
        return $this->laravel['path'].'/'.str_replace('\\', '/', $name).'CrudController.php';
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if($this->option('settings')) {
            return base_path('stubs/crud-settings-controller.stub');
        }
        return base_path('stubs/crud-controller.stub');
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
            return $rootNamespace.'\Http\Controllers\Admin\Settings';
        }
        return $rootNamespace.'\Http\Controllers\Admin';
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
        $nameTitle = Str::of($name)->afterLast('\\');
        $nameKebab = $nameTitle->kebab();
        $nameSingular = $nameKebab->replace('-', ' ');
        $namePlural = $nameSingular->plural();

        $stub = str_replace('DummyClass', $nameTitle, $stub);
        $stub = str_replace('dummy-class', $nameKebab, $stub);
        $stub = str_replace('dummy singular', $nameSingular, $stub);
        $stub = str_replace('dummy plural', $namePlural, $stub);

        return $this;
    }

    protected function getAttributes($model)
    {
        $attributes = [];
        $model = new $model;

        // if fillable was defined, use that as the attributes
        if (count($model->getFillable())) {
            $attributes = $model->getFillable();
        } else {
            // otherwise, if guarded is used, just pick up the columns straight from the bd table
            $attributes = Schema::getColumnListing($model->getTable());
        }

        return $attributes;
    }

    /**
     * Replace the table name for the given stub.
     *
     * @param string $stub
     * @param string $name
     *
     * @return string
     */
    protected function replaceSetFromDb(&$stub, $name)
    {
        $class = Str::afterLast($name, '\\');
        $model = "App\\Models\\$class";
        if($this->option('settings')) {
            $model = "App\\Models\\Settings\\$class";
        }

        if (! class_exists($model)) {
            return $this;
        }

        $attributes = $this->getAttributes($model);

        $instance = new $model;

        $fields = [];
        foreach($attributes as $field) {
            if(in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'])) continue;
            $columnType = Schema::getColumnType($instance->getTable(), $field);
            $label = Str::of($field)->replace('_', ' ')->title();

            if(strstr($field, '_id') && in_array($columnType, [
                'bigint',
                'integer',
            ])) {
                $baseName = Str::of($field)
                    ->before('_id');
                $modelName = $baseName->studly();
                $entity = $modelName->camel();
                $label = $baseName->replace('_', ' ')->title();
                $modelFqdn = 'use App\\Models\\' . $modelName . ';';
                if(!in_array($modelFqdn, $this->imports)) {
                    $this->imports[] = $modelFqdn;
                }
                $fields[] = "[
                'label'     => __('$label'),
                'name'      => '$field',
                'type'      => 'select2',
                'entity'    => '$entity',
                'attribute' => 'name',
                'model'     => $modelName::class,
            ],";
            } else {
                $type = null;
                switch ($columnType) {
                    case 'bigint':
                        $type = 'number';
                        break;
                    case 'date':
                        $type = 'date';
                        break;
                    case 'datetime':
                        $type = 'datetime';
                        break;
                    case 'tinyint':
                    case 'boolean':
                        $type = 'boolean';
                        break;
                    case 'decimal':
                        $type = 'number';
                        break;
                    case 'text':
                        $type = 'textarea';
                        break;
                    case 'string':
                    default:
                        $type = 'text';
                        break;
                }
                $fields[] = "[
                'label' => __('$label'),
                'name'  => '$field',
                'type'  => '$type',
            ],";
            }
        }

        $fields = 'return [' . PHP_EOL . '            ' . implode(PHP_EOL.'            ', $fields). PHP_EOL .'        ];';

        $columns = str_replace('select2', 'select', $fields);

        // replace setFromDb with actual fields and columns
        $stub = str_replace('return []; // fields', $fields, $stub);

        $stub = str_replace('return []; // columns', $columns, $stub);

        $stub = str_replace('// class imports', implode(PHP_EOL, $this->imports), $stub);

        return $this;
    }

    /**
     * Replace the class name for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     *
     * @return string
     */
    protected function replaceModel(&$stub, $name)
    {
        $class = str_replace($this->getNamespace($name).'\\', '', $name);
        $stub = str_replace(['DummyClass', '{{ class }}', '{{class}}'], $class, $stub);

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

        $this->replaceNamespace($stub, $name)
                ->replaceNameStrings($stub, $name)
                ->replaceModel($stub, $name)
                ->replaceSetFromDb($stub, $name);

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
