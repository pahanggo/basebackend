<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AjaxUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receives files from the ajax_upload / ajax_multi_upload fields as soon as they
 * are picked and stores them under config('ajax_upload.base_path') on a
 * whitelisted disk. The form later submits only the returned path.
 */
class AjaxUploadController extends Controller
{
    public function store(AjaxUploadRequest $request): JsonResponse
    {
        $disk = $request->input('disk') ?: config('ajax_upload.default_disk');
        $folder = trim(config('ajax_upload.base_path'), '/');

        $subfolder = collect(explode('/', (string) $request->input('path')))
            ->filter(fn (string $segment) => $segment !== '' && $segment !== '.' && $segment !== '..')
            ->implode('/');

        if ($subfolder !== '') {
            $folder .= '/'.$subfolder;
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $basename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $filename = Str::limit($basename, 60, '').'-'.Str::lower(Str::random(8)).($extension ? '.'.$extension : '');

        $path = $file->storeAs($folder, $filename, $disk);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ]);
    }
}
