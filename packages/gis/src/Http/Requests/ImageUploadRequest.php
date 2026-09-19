<?php

namespace Gis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The one multipart write in v1: an overlay image on its way to being placed.
 *
 * **Every rule here is about the file's content, not its name.** An extension
 * is a claim the uploader makes and `Content-Type` is a claim their browser
 * makes; neither is evidence. Laravel's `image` rule and `dimensions` both read
 * the decoded file, and the mime check below is `mimetypes`, which sniffs,
 * rather than `mimes`, which trusts the extension.
 *
 * The size and dimension caps are here rather than in the controller so the
 * request is rejected before anything is written to disk, and so the message
 * names the value that was actually too big — "10,400 px" tells the user to
 * downscale, "invalid image" tells them nothing.
 */
class ImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (config('gis.overlays.max_bytes') / 1024);

        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimetypes:'.implode(',', (array) config('gis.overlays.mime_types')),
                'max:'.$maxKilobytes,
            ],
        ];
    }

    /**
     * The longer-edge cap, checked after the file is known to be an image.
     *
     * Not a `dimensions` rule: that one caps width and height separately, so a
     * 9,000 x 9,000 sheet and a 400 x 12,000 strip would need two rules that
     * disagree about which failed. The longer edge is the single thing being
     * bounded — it is what decides how much memory the re-encode needs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $file = $this->file('image');

            if ($file === null || $validator->errors()->has('image')) {
                return;
            }

            $size = @getimagesize($file->getPathname());

            if ($size === false) {
                $validator->errors()->add('image', __('That file could not be read as an image.'));

                return;
            }

            $limit = (int) config('gis.overlays.max_edge_px');
            $longest = max($size[0], $size[1]);

            if ($longest > $limit) {
                $validator->errors()->add('image', __('That image is :actual px on its longer edge; the limit is :limit px.', [
                    'actual' => number_format($longest),
                    'limit' => number_format($limit),
                ]));
            }
        });
    }
}
