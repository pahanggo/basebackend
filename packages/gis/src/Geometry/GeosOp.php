<?php

namespace Gis\Geometry;

use Brick\Geo\Geometry;
use Brick\Geo\Io\WktReader;
use Brick\Geo\Io\WktWriter;
use RuntimeException;

/**
 * Running the `geosop` binary, safely and with a bound on how long it may take.
 *
 * `brick/geo` ships its own `GeosOpEngine` and this package does not use it for
 * constructive work, for three reasons that all matter here:
 *
 * - **Its buffer takes the default 8 quadrant segments.** Measured on a 250 m
 *   buffer at Kuantan: 8 gives 248.79 m and -0.647% area error, which breaches
 *   the 0.1% client/server agreement section 11 requires. 32 gives 249.91 m and
 *   -0.046%. The operation is `bufferQuadSegs`, not `buffer`.
 * - **It passes geometry as command-line arguments.** That is not a shell
 *   injection — `proc_open` with an array list never reaches a shell — but a
 *   polygon with fifty thousand vertices is megabytes of WKT, and `ARG_MAX` is
 *   a quarter of a megabyte on this platform. Geometry goes on **stdin**.
 * - **It has no timeout.** A subprocess that never returns is a worker that
 *   never returns.
 *
 * The input is bounded before the process starts, because refusing a
 * forty-megabyte geometry is cheaper than feeding it to GEOS and waiting.
 */
final class GeosOp
{
    /** Read once per call rather than held: Octane keeps this object alive. */
    private function path(): string
    {
        return (string) config('gis.geometry.geosop');
    }

    private function timeout(): int
    {
        return max(1, (int) config('gis.geometry.timeout_seconds'));
    }

    public function available(): bool
    {
        $path = $this->path();

        return $path !== '' && is_executable($path);
    }

    /**
     * Run one operation over one or two geometries.
     *
     * @param  string  $operation  a geosop op name
     * @param  array<int, string|float|int>  $arguments  the op's own arguments
     */
    public function run(string $operation, Geometry $a, ?Geometry $b = null, array $arguments = []): Geometry
    {
        $writer = new WktWriter;
        $input = $writer->write($a);

        $this->assertWithinLimit($input);

        $command = [$this->path(), '-a', 'stdin', '-f', 'wkt'];

        if ($b !== null) {
            $wkt = $writer->write($b);

            $this->assertWithinLimit($wkt);

            // `-b` takes a WKT literal. It is an argv element, not a shell
            // word, so its contents cannot become part of the command —
            // whatever a feature's properties happen to contain.
            $command[] = '-b';
            $command[] = $wkt;
        }

        // `--` first, so a negative distance is read as this op's argument and
        // not as an option. An inward buffer is the ordinary way one arises.
        $command[] = '--';
        $command[] = $operation;

        foreach ($arguments as $argument) {
            $command[] = (string) $argument;
        }

        $output = $this->execute($command, $input);

        if (trim($output) === '') {
            throw new RuntimeException("geosop {$operation} produced no output.");
        }

        return (new WktReader)->read(trim($output));
    }

    /**
     * @param  array<int, string>  $command
     */
    private function execute(array $command, string $input): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start geosop.');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        // Non-blocking, so a process that stops producing output can be timed
        // out rather than blocking this one in `stream_get_contents` forever.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeout();

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException('geosop timed out after '.$this->timeout().' seconds.');
            }

            usleep(2000);
        }

        // One more read: the loop exits the moment the process is gone, and
        // whatever it wrote last is still sitting in the pipe.
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            throw new RuntimeException('geosop failed: '.(trim($stderr) ?: "exit code {$code}"));
        }

        return $stdout;
    }

    private function assertWithinLimit(string $wkt): void
    {
        $limit = (int) config('gis.write.max_wkb_bytes');

        if (strlen($wkt) > $limit * 4) {
            throw new RuntimeException('Geometry is too large for a constructive operation.');
        }
    }
}
