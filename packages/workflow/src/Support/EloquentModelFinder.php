<?php

namespace Workflow\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Scans app/Models (recursively) for concrete Eloquent model classes, so the
 * workflow definition's "target model" field can offer an ajax-searchable
 * picker instead of requiring a developer to type a fully-qualified class
 * name by hand.
 */
class EloquentModelFinder
{
    /**
     * @return array<int, class-string<Model>>
     */
    public function all(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        return collect(Finder::create()->files()->in($directory)->name('*.php'))
            ->map(fn (SplFileInfo $file) => $this->classFromFile($file, $directory))
            ->filter(fn (?string $class) => $class && $this->isConcreteEloquentModel($class))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function search(?string $term, int $limit = 20): array
    {
        $term = trim((string) $term);

        return collect($this->all())
            ->filter(fn (string $class) => $term === '' || str_contains(strtolower($class), strtolower($term)))
            ->take($limit)
            ->map(fn (string $class) => ['value' => $class, 'label' => class_basename($class).' ('.$class.')'])
            ->values()
            ->all();
    }

    protected function classFromFile(SplFileInfo $file, string $baseDirectory): ?string
    {
        $relativePath = str_replace(
            ['/', '.php'],
            ['\\', ''],
            substr($file->getPathname(), strlen($baseDirectory) + 1)
        );

        return 'App\\Models\\'.$relativePath;
    }

    protected function isConcreteEloquentModel(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isSubclassOf(Model::class) && ! $reflection->isAbstract();
    }
}
