<?php

namespace Gis\Http\Controllers\Api;

use Gis\Http\Requests\ImageUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * `POST /api/geo/images` — an overlay image, stored and handed back as a path.
 *
 * **Upload and layer creation are two steps.** The command endpoint stays
 * JSON-only, which is what keeps it atomic, idempotent and replayable; a
 * multipart body would cost all three. So the file is stored the moment it is
 * picked and only the returned path travels in the `layer.create` that follows
 * — the same shape the application's own `AjaxUploadController` uses.
 *
 * **The re-encode is the security control, not the resize.** A file that sniffs
 * as a valid PNG can still carry an HTML or script payload in an ancillary
 * chunk, and that matters the moment it is served from a domain holding a
 * session. Decoding to a pixel buffer and writing a fresh file discards
 * everything that is not an image — including EXIF, and including the GPS
 * coordinates a phone may have written into a photographed site plan without
 * the uploader ever knowing (specification section 20).
 *
 * Nothing here trusts the filename. The type comes from the decoded content,
 * the extension is derived from that type, and the stored name is regenerated.
 */
class ImageUploadController extends Controller
{
    public function __invoke(ImageUploadRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $disk = (string) config('gis.overlays.disk');
        $folder = trim((string) config('gis.overlays.folder'), '/');

        // Read the type from the bytes. `getMimeType()` on an UploadedFile
        // sniffs the temporary file rather than echoing the client's header,
        // which is the only reason it is usable here.
        $mime = (string) $file->getMimeType();
        $extension = $mime === 'image/png' ? 'png' : 'jpg';

        $image = ImageManager::gd()->read($file->getPathname());

        $encoded = $extension === 'png'
            ? (string) $image->toPng()
            : (string) $image->toJpeg(90);

        $basename = Str::slug(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'overlay';
        $path = $folder.'/'.Str::limit($basename, 60, '').'-'.Str::lower(Str::random(8)).'.'.$extension;

        if (! Storage::disk($disk)->put($path, $encoded)) {
            throw new RuntimeException('The overlay image could not be stored.');
        }

        // Read back from the re-encoded bytes, never from the upload: the
        // client sizes the initial placement from these, and they have to
        // describe the file that was actually written.
        return new JsonResponse([
            'disk' => $disk,
            'path' => $path,
            'url' => self::rootRelative(Storage::disk($disk)->url($path)),
            'naturalWidth' => $image->width(),
            'naturalHeight' => $image->height(),
            'bytes' => strlen($encoded),
        ], 201);
    }

    /**
     * The path part of a storage URL, with the host dropped.
     *
     * **A URL that travels inside JSON must be root-relative.** `Storage::url()`
     * builds an absolute one from `APP_URL`, which is the deployment's public
     * hostname and not necessarily the host the page was served from. Behind
     * the Cloudflare Tunnel that is reliably wrong twice over: the scheme comes
     * back `http` for an `https` page, and Automatic HTTPS Rewrites repairs
     * links in the HTML while leaving a URL inside a JSON payload untouched.
     * The symptom is an overlay that silently fails to load, or loads
     * cross-origin from a domain the developer is not looking at.
     *
     * This URL is stored in `source_config` and read back on every page load,
     * so getting it wrong outlives the request that made it.
     */
    protected static function rootRelative(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return $path.($query === null ? '' : '?'.$query);
    }
}
