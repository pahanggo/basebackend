<?php

namespace Gis\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One feature layer of an ArcGIS MapServer, read in windows of OBJECTID.
 *
 * **The paging is by OBJECTID range, not by `resultOffset`.** The service
 * supports offset paging and it is the obvious way to page, but the cost grows
 * with the offset: measured against
 * `GTsemasa_06`, three records at offset 2,000,000 took 49 s, while the
 * thousand records with OBJECTID between 2,000,001 and 2,001,000 took 3.4 s.
 * Over 2,864 pages that difference is the whole job.
 *
 * Range paging is also what makes the import resumable and concurrent: a window
 * is defined by its bounds alone, so it can be retried, reordered or skipped
 * without reference to any other. Gaps in the OBJECTID sequence are harmless —
 * a window simply returns fewer rows — and there is no risk of the silent
 * truncation an `objectIds` list or a page size near `maxRecordCount` would
 * carry.
 *
 * Nothing here is authenticated: the iPLAN and SCHARMS services are public and
 * read-only.
 */
final class ArcGisSource
{
    public function __construct(
        private readonly string $queryUrl,
        private readonly string $filter,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly int $retrySleepMs,
    ) {}

    public static function make(string $service, int $layer, string $filter = '1=1'): self
    {
        $base = rtrim((string) config('gis.import.arcgis.base_url'), '/');

        return new self(
            "{$base}/{$service}/MapServer/{$layer}/query",
            $filter,
            (int) config('gis.import.arcgis.timeout_seconds'),
            (int) config('gis.import.arcgis.retries'),
            (int) config('gis.import.arcgis.retry_sleep_ms'),
        );
    }

    /**
     * The OBJECTID range and the row count, in one statistics query.
     *
     * The count is what the progress bar is drawn against and the range is what
     * the windows are cut from; asking for them separately would be two round
     * trips for one answer.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function range(): array
    {
        $body = $this->decode($this->request([
            'where' => $this->filter,
            'f' => 'json',
            'outStatistics' => json_encode([
                ['statisticType' => 'min', 'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'lo'],
                ['statisticType' => 'max', 'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'hi'],
                ['statisticType' => 'count', 'onStatisticField' => 'OBJECTID', 'outStatisticFieldName' => 'n'],
            ]),
        ]));

        $attributes = $body['features'][0]['attributes'] ?? null;

        if ($attributes === null) {
            throw new RuntimeException("{$this->queryUrl}: the service returned no OBJECTID statistics.");
        }

        return [(int) $attributes['lo'], (int) $attributes['hi'], (int) $attributes['n']];
    }

    /**
     * The request parameters for one window.
     *
     * Returned rather than sent, so the caller can put several of them in one
     * `Http::pool` — the fetch is latency-bound, not bandwidth-bound, and the
     * windows are independent by construction.
     *
     * OBJECTID is always requested: it becomes the feature's `_src` property,
     * which is the only provenance an imported row carries.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string>
     */
    public function windowParameters(int $from, int $to, array $fields): array
    {
        return [
            'where' => "({$this->filter}) AND OBJECTID>={$from} AND OBJECTID<={$to}",
            'outFields' => implode(',', array_values(array_unique(['OBJECTID', ...$fields]))),

            // The services declare their extent in GDM2000 (4742); the storage
            // column is 4326 and so is every consumer of this package. Asking
            // the service to reproject is one fewer transform to get wrong.
            'outSR' => '4326',
            'returnGeometry' => 'true',
            'f' => 'geojson',
        ];
    }

    /**
     * One window's features, working around the records the service cannot
     * serve.
     *
     * Some rows are simply unservable: `GTsemasa_06` OBJECTID 1460 answers
     * `{"error":{"code":400,"message":"Failed to execute query."}}` on its own
     * and poisons every window containing it, deterministically. Left alone
     * that is 1,000 features lost per bad record, and the ledger would record
     * the window as done and nobody would ever find out.
     *
     * So a window the service refuses is halved and each half retried, down to
     * a single OBJECTID, which is then recorded as a reject and skipped. A
     * healthy window costs one request; a window with one bad record costs
     * about 2*log2(window) and keeps the other 999 features.
     *
     * Only an ArcGIS error document triggers this. Transport failures are the
     * retry policy's business — bisecting on a network blip would turn one
     * timeout into a thousand requests.
     *
     * @param  array<int, string>  $fields
     * @param  array<int, int>  $rejects  ids the service refused, appended to
     * @return array<int, array<string, mixed>>
     */
    public function window(int $from, int $to, array $fields, array &$rejects): array
    {
        try {
            return $this->featureCollection($this->request($this->windowParameters($from, $to, $fields)));
        } catch (ServiceRefusedWindow $refusal) {
            if ($from >= $to) {
                $rejects[] = $from;

                return [];
            }
        }

        $middle = intdiv($from + $to, 2);

        return array_merge(
            $this->window($from, $middle, $fields, $rejects),
            $this->window($middle + 1, $to, $fields, $rejects),
        );
    }

    /**
     * Validate one window's response.
     *
     * The service answers an error with HTTP 200 and an `error` object, so a
     * successful status proves nothing. A window that came back malformed must
     * raise rather than import zero features, or the ledger would record it as
     * done and the gap would never be noticed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function featureCollection(Response $response): array
    {
        $body = $this->decode($response);

        if (($body['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException(sprintf(
                '%s: expected a FeatureCollection, got %s.',
                $this->queryUrl,
                json_encode(array_slice($body, 0, 3)),
            ));
        }

        return $body['features'] ?? [];
    }

    /**
     * Build one pooled request for a window.
     *
     * @param  \Illuminate\Http\Client\Pool|\Illuminate\Http\Client\PendingRequest  $pool
     * @param  array<int, string>  $fields
     */
    public function poolWindow(object $pool, int $from, int $to, array $fields): object
    {
        return $pool
            ->asForm()
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retrySleepMs)
            ->post($this->queryUrl, $this->windowParameters($from, $to, $fields));
    }

    public function url(): string
    {
        return $this->queryUrl;
    }

    /** @param array<string, string> $parameters */
    private function request(array $parameters): Response
    {
        return Http::asForm()
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retrySleepMs)
            ->post($this->queryUrl, $parameters)
            ->throw();
    }

    /** @return array<string, mixed> */
    private function decode(Response|Throwable $response): array
    {
        if ($response instanceof Throwable) {
            throw new RuntimeException("{$this->queryUrl}: {$response->getMessage()}", 0, $response);
        }

        $body = $response->throw()->json();

        if (! is_array($body)) {
            throw new RuntimeException("{$this->queryUrl}: the service did not return JSON.");
        }

        if (isset($body['error'])) {
            throw new ServiceRefusedWindow(sprintf(
                '%s: %s',
                $this->queryUrl,
                $body['error']['message'] ?? json_encode($body['error']),
            ));
        }

        return $body;
    }
}
