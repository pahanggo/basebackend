<?php

namespace App\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

trait BulkMigration
{
    protected function getColumnName($column)
    {
        if(strstr($column, ':')) {
            $parts = explode(':', $column);
            return $parts[0];
        }

        return $column;
    }

    protected function getForeignTableName($column)
    {
        if(stristr($column, 'profile_id') !== false) {
            return 'profiles';
        }

        return Str::plural(substr($column, 0 , strlen($column) - 3));
    }

    protected function getColumnType($column)
    {
        if(strstr($column, ':')) {
            $parts = explode(':', $column);
            switch($parts[1]) {
                case 's':
                    return 'string';
                case 'b':
                    return 'bool';
                case 'y':
                    return 'year';
                case 'd':
                    return 'date';
                case 'db':
                    return 'double';
                case 'dt':
                    return 'datetime';
                case 'm':
                    return 'money';
                case 'ui':
                    return 'uInteger';
                case 't':
                    return 'text';
            }
        }

        if(substr($column, 0, 3) == 'is_') {
            return 'bool';
        }
        if(substr($column, -3) == '_id') {
            return 'foreign';
        }

        return 'string';
    }

    public function applyColumn(Blueprint $table, $column)
    {
        $columnName = $this->getColumnName($column);
        $type = $this->getColumnType($column);
        switch($type) {
            case 'foreign':
                $table->unsignedBigInteger($columnName)->nullable();
                break;
            case 'bool':
                $table->boolean($columnName)->nullable();
                break;
            case 'year':
                $table->year($columnName)->nullable();
                break;
            case 'date':
                $table->date($columnName)->nullable();
                break;
            case 'datetime':
                $table->dateTime($columnName)->nullable();
                break;
            case 'money':
                $table->decimal($columnName, 14, 2)->nullable();
                break;
            case 'double':
                $table->double($columnName)->nullable();
                break;
            case 'uInteger':
                $table->unsignedInteger($columnName)->nullable();
                break;
            case 'text':
                $table->text($columnName)->nullable();
                break;
            case 'string':
            default:
                $table->string($columnName)->nullable();
                break;
        }
    }

    public function applyForeign(Blueprint $table, $column)
    {
        $columnName = $this->getColumnName($column);
        $type = $this->getColumnType($column);
        $foreignTable = $this->getForeignTableName($columnName);
        switch($type) {
            case 'foreign':
                $table->foreign($columnName)
                    ->references('id')
                    ->on($foreignTable)
                    ->onDelete('set null');
                break;
        }
    }
}