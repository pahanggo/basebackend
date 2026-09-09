<?php

namespace App\Models\KitchenSink;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Showcase model for the Kitchen Sink CRUD. One column per field type.
 */
class KitchenSink extends Model
{
    use CrudTrait;

    protected $connection = 'kitchensink';

    protected $table = 'kitchen_sinks';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'agreed' => 'boolean',
        'price' => 'decimal:2',
        'rating' => 'integer',
        'published_on' => 'date',
        'published_at' => 'datetime',
        'birthday' => 'date',
        'remind_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'sizes' => 'array',
        'address_google' => 'array',
        'attachments' => 'array',
        'video' => 'array',
        'extras' => 'array',
        'lines' => 'array',
        'ordered_sizes' => 'array',
        'metadata' => 'array',
        'location' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | FUNCTIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Used by the model_function column.
     */
    public function titleWithRating(): string
    {
        return $this->title.' ('.($this->rating ?? 0).'/10)';
    }

    /**
     * Used by the base64_image field ("src" option) to preload the current avatar.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    /**
     * Used by the model_function_attribute column.
     */
    public function primaryCategory(): ?KitchenSinkCategory
    {
        return $this->category;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'kitchen_sink_category_id');
    }

    public function ajaxCategory(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'ajax_category_id');
    }

    public function nestedCategory(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'nested_category_id');
    }

    public function groupedCategory(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'grouped_category_id');
    }

    public function select2Category(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'select2_category_id');
    }

    public function select2GroupedCategory(): BelongsTo
    {
        return $this->belongsTo(KitchenSinkCategory::class, 'select2_grouped_category_id');
    }

    /**
     * Several multi-select field types each need their own relation, so they all
     * share one pivot table and are told apart by the "relation" pivot column.
     */
    protected function tagsFor(string $relation): BelongsToMany
    {
        return $this->belongsToMany(KitchenSinkTag::class, 'kitchen_sink_kitchen_sink_tag', 'kitchen_sink_id', 'kitchen_sink_tag_id')
            ->wherePivot('relation', $relation)
            ->withPivotValue('relation', $relation);
    }

    public function tags(): BelongsToMany
    {
        return $this->tagsFor('tags');
    }

    public function checklistTags(): BelongsToMany
    {
        return $this->tagsFor('checklist');
    }

    public function selectTags(): BelongsToMany
    {
        return $this->tagsFor('select_multiple');
    }

    public function select2Tags(): BelongsToMany
    {
        return $this->tagsFor('select2_multiple');
    }

    public function ajaxTags(): BelongsToMany
    {
        return $this->tagsFor('ajax_multiple');
    }

    /*
    |--------------------------------------------------------------------------
    | MUTATORS
    |--------------------------------------------------------------------------
    */

    public function setImageAttribute(?string $value): void
    {
        $this->storeBase64Image('image', $value, 'kitchensink/images');
    }

    public function setAvatarAttribute(?string $value): void
    {
        $this->storeBase64Image('avatar', $value, 'kitchensink/avatars');
    }

    public function setAttachmentAttribute(mixed $value): void
    {
        $this->uploadFileToDisk($value, 'attachment', 'public', 'kitchensink/attachments');
    }

    public function setAttachmentsAttribute(mixed $value): void
    {
        $this->uploadMultipleFilesToDisk($value, 'attachments', 'public', 'kitchensink/attachments');
    }

    /**
     * Backpack image fields post a base64 data URL; persist it to the public disk
     * and keep only the relative path on the model.
     */
    protected function storeBase64Image(string $attribute, ?string $value, string $folder): void
    {
        if ($value === null || $value === '') {
            if (! empty($this->{$attribute})) {
                Storage::disk('public')->delete($this->{$attribute});
            }
            $this->attributes[$attribute] = null;

            return;
        }

        if (! Str::startsWith($value, 'data:image')) {
            $this->attributes[$attribute] = $value;

            return;
        }

        $extension = Str::between($value, 'data:image/', ';') ?: 'png';
        $path = $folder.'/'.Str::random(20).'.'.$extension;

        Storage::disk('public')->put($path, base64_decode(Str::after($value, ',')));

        if (! empty($this->{$attribute})) {
            Storage::disk('public')->delete($this->{$attribute});
        }

        $this->attributes[$attribute] = $path;
    }
}
