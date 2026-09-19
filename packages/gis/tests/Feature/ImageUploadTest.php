<?php

use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

beforeEach(function () {
    Storage::fake(config('gis.overlays.disk'));
});

/** A real PNG of the given size, written to a temporary file. */
function pngFile(int $width, int $height, string $name = 'site-plan-rev3.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 40, 40));

    $path = tempnam(sys_get_temp_dir(), 'gis').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return new UploadedFile($path, $name, 'image/png', test: true);
}

/**
 * A JPEG carrying an EXIF APP1 segment with GPS latitude in it.
 *
 * Built rather than committed as a fixture: a real geotagged photograph in the
 * repository is somebody's actual location, and this only has to be a file the
 * EXIF reader agrees carries GPS.
 */
function jpegWithGps(): UploadedFile
{
    $image = imagecreatetruecolor(48, 48);
    imagefilledrectangle($image, 0, 0, 47, 47, imagecolorallocate($image, 10, 90, 160));

    $plain = tempnam(sys_get_temp_dir(), 'gis').'.jpg';
    imagejpeg($image, $plain);
    imagedestroy($image);

    // TIFF header, one IFD0 entry pointing at a GPS IFD, and one GPS entry:
    // GPSLatitude, three rationals. Little-endian throughout.
    $tiff = "II\x2a\x00\x08\x00\x00\x00"
        .pack('v', 1)
        .pack('vvVV', 0x8825, 4, 1, 26)              // GPSInfoIFDPointer -> offset 26
        .pack('V', 0)                                 // no next IFD
        .pack('v', 1)
        .pack('vvVV', 2, 5, 3, 44)                    // GPSLatitude, 3 rationals at offset 44
        .pack('V', 0)
        .pack('VVVVVV', 3, 1, 48, 1, 36, 1);          // 3 deg 48' 36"

    $app1 = "Exif\x00\x00".$tiff;
    $segment = "\xff\xe1".pack('n', strlen($app1) + 2).$app1;

    $jpeg = file_get_contents($plain);
    $withExif = substr($jpeg, 0, 2).$segment.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'gis').'.jpg';
    file_put_contents($path, $withExif);

    return new UploadedFile($path, 'survey.jpg', 'image/jpeg', test: true);
}

function upload(UploadedFile $file, ?\App\Models\User $user = null)
{
    return test()->actingAs($user ?? reader())
        ->postJson(route('gis.api.images.store'), ['image' => $file]);
}

it('stores an image and reports the dimensions the client needs to place it', function () {
    $response = upload(pngFile(400, 300));

    $response->assertStatus(201);

    expect($response->json('naturalWidth'))->toBe(400);
    expect($response->json('naturalHeight'))->toBe(300);

    $path = $response->json('path');

    expect($path)->toStartWith(trim(config('gis.overlays.folder'), '/').'/');
    Storage::disk(config('gis.overlays.disk'))->assertExists($path);
});

it('regenerates the filename rather than trusting the one supplied', function () {
    // The stored name keeps a readable stem so a file is identifiable on disk,
    // and gains a random suffix so two uploads of "plan.png" cannot collide or
    // overwrite one another.
    $first = upload(pngFile(20, 20, 'Site Plan Rev 3.png'))->json('path');
    $second = upload(pngFile(20, 20, 'Site Plan Rev 3.png'))->json('path');

    expect($first)->toContain('site-plan-rev-3-');
    expect($second)->toContain('site-plan-rev-3-');
    expect($first)->not->toBe($second);
});

it('never lets a filename choose where the file lands', function () {
    $path = upload(pngFile(20, 20, '../../../../etc/passwd.png'))->json('path');

    expect($path)->not->toContain('..');
    expect($path)->toStartWith(trim(config('gis.overlays.folder'), '/').'/');
});

it('refuses a file that is not an image whatever it is called', function () {
    $path = tempnam(sys_get_temp_dir(), 'gis').'.png';
    file_put_contents($path, "<?php echo 'not a png'; ?>");

    upload(new UploadedFile($path, 'shell.png', 'image/png', test: true))
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');

    expect(Storage::disk(config('gis.overlays.disk'))->allFiles())->toBe([]);
});

it('refuses an image longer than the edge limit, and says how long it was', function () {
    config(['gis.overlays.max_edge_px' => 100]);

    $response = upload(pngFile(240, 40));

    $response->assertStatus(422);

    expect($response->json('errors.image.0'))->toContain('240');
    expect(Storage::disk(config('gis.overlays.disk'))->allFiles())->toBe([]);
});

it('refuses a file over the size limit before writing anything', function () {
    config(['gis.overlays.max_bytes' => 1024]);

    upload(pngFile(600, 600))->assertStatus(422)->assertJsonValidationErrors('image');

    expect(Storage::disk(config('gis.overlays.disk'))->allFiles())->toBe([]);
});

it('re-encodes, so nothing smuggled past the sniffer survives', function () {
    // A genuine PNG with a payload appended after IEND. It sniffs as an image
    // and decodes fine; serving it from a domain holding a session is the
    // problem, and decoding to pixels and writing fresh is what removes it.
    $file = pngFile(64, 64);
    $payload = '<script>alert(document.cookie)</script>';

    file_put_contents($file->getPathname(), $payload, FILE_APPEND);

    $path = upload($file)->json('path');
    $stored = Storage::disk(config('gis.overlays.disk'))->get($path);

    expect($stored)->not->toContain($payload);
    expect(substr($stored, 1, 3))->toBe('PNG');
});

it('strips EXIF, including GPS the uploader may not know is there', function () {
    // A phone that photographed a site plan writes where it was standing into
    // the file. The uploader sees a picture of a drawing; the file also says
    // which building they were in.
    $file = jpegWithGps();

    expect(@exif_read_data($file->getPathname()) ?: [])->toHaveKey('GPSLatitude');

    $path = upload($file)->json('path');
    $stored = Storage::disk(config('gis.overlays.disk'))->get($path);

    $temporary = tempnam(sys_get_temp_dir(), 'gis').'.jpg';
    file_put_contents($temporary, $stored);

    expect(@exif_read_data($temporary) ?: [])->not->toHaveKey('GPSLatitude');
    expect($stored)->not->toContain('Exif');
});

it('is rate limited per hour, not per minute', function () {
    config(['gis.rate_limits.image_uploads_per_hour' => 2]);

    // ONE user for all three. The limiter keys on the user id, so calling
    // `reader()` per upload would create three people uploading once each.
    $user = reader();

    upload(pngFile(20, 20), $user)->assertStatus(201);
    upload(pngFile(20, 20), $user)->assertStatus(201);
    upload(pngFile(20, 20), $user)->assertStatus(429);
});

it('refuses an anonymous upload', function () {
    test()->postJson(route('gis.api.images.store'), ['image' => pngFile(20, 20)])
        ->assertStatus(401);
});

it('returns a root-relative url, because it is stored and read back later', function () {
    // `Storage::url()` builds an absolute URL from APP_URL, which is not
    // necessarily the host the page came from — and behind the tunnel it is
    // reliably not. This URL lives in `source_config` and is read on every
    // load, so a wrong one outlives the request that made it.
    config(['app.url' => 'https://somewhere-else.example']);

    $url = upload(pngFile(20, 20))->json('url');

    expect($url)->toStartWith('/');
    expect($url)->not->toContain('somewhere-else.example');
    expect($url)->not->toContain('://');
});
