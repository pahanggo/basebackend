<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Produces static map PNGs for the latlng_map column and caches them on the local
 * disk. Google Static Maps is the primary source; when Google refuses (no key, key
 * not enabled for the API, quota) the map is composed server-side from the same raster
 * tiles the latlng_picker field uses, so the column keeps working.
 */
class StaticMapService
{
    public const CACHE_TTL_DAYS = 90;

    public const FALLBACK_TTL_DAYS = 1;

    private const TILE_SIZE = 256;

    /**
     * @return array{path: string, bytes: string, fallback: bool, cached_at: int}
     */
    public function get(float $lat, float $lng, int $width, int $height, int $zoom): array
    {
        $key = sha1(implode('|', [round($lat, 6), round($lng, 6), $width, $height, $zoom]));
        $disk = Storage::disk('local');
        $path = "static-maps/{$key}.png";
        $fallbackPath = "static-maps/{$key}.fallback.png";

        if ($disk->exists($path) && $this->isFresh($disk->lastModified($path), self::CACHE_TTL_DAYS)) {
            return ['path' => $path, 'bytes' => $disk->get($path), 'fallback' => false, 'cached_at' => $disk->lastModified($path)];
        }

        if ($bytes = $this->fetchFromGoogle($lat, $lng, $width, $height, $zoom)) {
            $disk->put($path, $bytes);
            $disk->delete($fallbackPath);

            return ['path' => $path, 'bytes' => $bytes, 'fallback' => false, 'cached_at' => time()];
        }

        if ($disk->exists($fallbackPath) && $this->isFresh($disk->lastModified($fallbackPath), self::FALLBACK_TTL_DAYS)) {
            return ['path' => $fallbackPath, 'bytes' => $disk->get($fallbackPath), 'fallback' => true, 'cached_at' => $disk->lastModified($fallbackPath)];
        }

        $bytes = $this->renderFromTiles($lat, $lng, $width, $height, $zoom);
        $disk->put($fallbackPath, $bytes);

        return ['path' => $fallbackPath, 'bytes' => $bytes, 'fallback' => true, 'cached_at' => time()];
    }

    private function isFresh(int $timestamp, int $ttlDays): bool
    {
        return $timestamp > time() - $ttlDays * 86400;
    }

    private function fetchFromGoogle(float $lat, float $lng, int $width, int $height, int $zoom): ?string
    {
        $apiKey = config('services.google_places.key');

        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/staticmap', [
                'center' => "{$lat},{$lng}",
                'zoom' => $zoom,
                'size' => "{$width}x{$height}",
                'scale' => 2,
                'markers' => "color:red|{$lat},{$lng}",
                'key' => $apiKey,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        return $response->body();
    }

    /**
     * Stitch the raster tiles around the point into a canvas and draw a pin on it.
     */
    private function renderFromTiles(float $lat, float $lng, int $width, int $height, int $zoom): string
    {
        $scale = 2;
        $canvasWidth = $width * $scale;
        $canvasHeight = $height * $scale;

        $n = 2 ** $zoom;
        $xTile = ($lng + 180) / 360 * $n;
        $latRad = deg2rad($lat);
        $yTile = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;

        // Pixel position of the point on the world map at this zoom (tiles upscaled to 512px).
        $tilePx = self::TILE_SIZE * $scale;
        $centerX = $xTile * $tilePx;
        $centerY = $yTile * $tilePx;
        $originX = $centerX - $canvasWidth / 2;
        $originY = $centerY - $canvasHeight / 2;

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 229, 227, 223));

        $firstTileX = (int) floor($originX / $tilePx);
        $lastTileX = (int) floor(($originX + $canvasWidth) / $tilePx);
        $firstTileY = (int) floor($originY / $tilePx);
        $lastTileY = (int) floor(($originY + $canvasHeight) / $tilePx);

        for ($tx = $firstTileX; $tx <= $lastTileX; $tx++) {
            for ($ty = $firstTileY; $ty <= $lastTileY; $ty++) {
                if ($ty < 0 || $ty >= $n) {
                    continue;
                }
                $tile = $this->fetchTile((($tx % $n) + $n) % $n, $ty, $zoom);
                if (! $tile) {
                    continue;
                }
                imagecopyresampled(
                    $canvas,
                    $tile,
                    (int) round($tx * $tilePx - $originX),
                    (int) round($ty * $tilePx - $originY),
                    0,
                    0,
                    $tilePx,
                    $tilePx,
                    imagesx($tile),
                    imagesy($tile)
                );
                imagedestroy($tile);
            }
        }

        $this->drawPin($canvas, (int) ($canvasWidth / 2), (int) ($canvasHeight / 2), $scale);

        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return ob_get_clean();
    }

    private function fetchTile(int $x, int $y, int $z): ?\GdImage
    {
        $url = str_replace(['{x}', '{y}', '{z}'], [$x, $y, $z], config('services.map_tiles.url'));

        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $image = @imagecreatefromstring($response->body());

        return $image ?: null;
    }

    /**
     * Red teardrop pin with a white dot, tip at ($x, $y).
     */
    private function drawPin(\GdImage $canvas, int $x, int $y, int $scale): void
    {
        imageantialias($canvas, true);
        $red = imagecolorallocate($canvas, 220, 53, 69);
        $dark = imagecolorallocate($canvas, 150, 30, 40);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        $r = 9 * $scale;
        $cy = $y - 2 * $r;

        imagefilledpolygon($canvas, [$x - $r + 2, $cy + $r / 2, $x + $r - 2, $cy + $r / 2, $x, $y], $red);
        imagefilledellipse($canvas, $x, $cy, 2 * $r, 2 * $r, $red);
        imageellipse($canvas, $x, $cy, 2 * $r, 2 * $r, $dark);
        imagefilledellipse($canvas, $x, $cy, (int) ($r * 0.8), (int) ($r * 0.8), $white);
    }
}
