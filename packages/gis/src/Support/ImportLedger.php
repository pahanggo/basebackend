<?php

namespace Gis\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Which windows of an import have been committed.
 *
 * An import of three and a half million features over an unauthenticated public
 * service will be interrupted — by a timeout, a deploy, a laptop lid. The
 * ledger is what makes the next run continue instead of starting again or,
 * worse, appending a second copy.
 *
 * It is a ledger of windows rather than a high-water mark because the windows
 * are fetched concurrently and therefore finish out of order: after a crash,
 * "the highest id I wrote" would silently skip every window that was still in
 * flight below it. A line is appended only once its window's rows are
 * committed, so a crash mid-window loses that window's work and nothing else.
 *
 * One line per window start, which is a few tens of kilobytes for the largest
 * source here.
 */
final class ImportLedger
{
    private ?array $done = null;

    public function __construct(
        private readonly string $path,
        private readonly Filesystem $disk,
    ) {}

    public static function for(string $key): self
    {
        return new self(
            rtrim((string) config('gis.import.arcgis.progress_folder'), '/')."/{$key}.done",
            Storage::disk(config('gis.import.arcgis.progress_disk')),
        );
    }

    public function has(int $window): bool
    {
        return isset($this->load()[$window]);
    }

    public function count(): int
    {
        return count($this->load());
    }

    /**
     * Record a committed window.
     *
     * Appended, never rewritten: rewriting the whole file on every window turns
     * a crash during the write into a lost ledger, which is the one failure
     * this class exists to prevent.
     */
    public function mark(int $window): void
    {
        $this->load();

        if (isset($this->done[$window])) {
            return;
        }

        $this->disk->append($this->path, (string) $window);
        $this->done[$window] = true;
    }

    public function forget(): void
    {
        $this->disk->delete($this->path);
        $this->done = [];
    }

    public function path(): string
    {
        return $this->disk->path($this->path);
    }

    /** @return array<int, true> */
    private function load(): array
    {
        if ($this->done !== null) {
            return $this->done;
        }

        $this->done = [];

        if (! $this->disk->exists($this->path)) {
            return $this->done;
        }

        foreach (preg_split('/\R/', (string) $this->disk->get($this->path)) as $line) {
            if (trim($line) !== '') {
                $this->done[(int) $line] = true;
            }
        }

        return $this->done;
    }
}
