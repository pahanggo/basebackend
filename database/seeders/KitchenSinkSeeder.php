<?php

namespace Database\Seeders;

use App\Models\KitchenSink\KitchenSink;
use App\Models\KitchenSink\KitchenSinkCategory;
use App\Models\KitchenSink\KitchenSinkGroup;
use App\Models\KitchenSink\KitchenSinkTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class KitchenSinkSeeder extends Seeder
{
    public function run(): void
    {
        $groups = collect(['Hardware', 'Software'])->map(fn (string $name) => KitchenSinkGroup::firstOrCreate(['name' => $name]));

        $categories = collect([
            ['name' => 'Laptops', 'group' => 0],
            ['name' => 'Phones', 'group' => 0],
            ['name' => 'Operating Systems', 'group' => 1],
            ['name' => 'Editors', 'group' => 1],
        ])->map(fn (array $c) => KitchenSinkCategory::firstOrCreate(['name' => $c['name']], ['kitchen_sink_group_id' => $groups[$c['group']]->id]));

        collect(['Ultrabook', 'Gaming', 'Android', 'iOS'])->each(function (string $name, int $i) use ($categories) {
            KitchenSinkCategory::firstOrCreate(['name' => $name], [
                'parent_id' => $categories[$i < 2 ? 0 : 1]->id,
                'kitchen_sink_group_id' => $categories[0]->kitchen_sink_group_id,
            ]);
        });

        $tags = collect(['New', 'Popular', 'Discounted', 'Archived'])->map(fn (string $name) => KitchenSinkTag::firstOrCreate(['name' => $name]));

        if (KitchenSink::count() > 0) {
            return;
        }

        $disk = Storage::disk('public');

        foreach (range(1, 10) as $i) {
            $image = $this->sampleImage($disk, "kitchensink/images/sample-{$i}.png", "Item {$i}", ['#5f0461', '#269740', '#467fd0'][($i - 1) % 3]);
            $avatar = $this->sampleImage($disk, "kitchensink/avatars/avatar-{$i}.png", "A{$i}", '#fd9644');
            $attachments = [
                $this->sampleFile($disk, "kitchensink/attachments/notes-{$i}.txt", "Notes for sample item {$i}\n"),
                $this->sampleFile($disk, "kitchensink/attachments/prices-{$i}.csv", "sku,price\nSKU-{$i},".(1234.5 * $i)."\n"),
            ];

            $sink = KitchenSink::create([
                'title' => "Sample item {$i}",
                'slug' => "sample-item-{$i}",
                'description' => "A longer description for sample item {$i}.\nSecond line.",
                'email' => "sample{$i}@example.com",
                'website' => 'https://example.com',
                'phone' => '+60 12-345 678'.$i,
                'secret' => 'secret',
                'hidden_token' => 'token-'.$i,
                'price' => 1234.5 * $i,
                'rating' => 3 * $i,
                'is_active' => $i % 2 === 1,
                'agreed' => true,
                'is_featured' => $i === 1,
                'status' => ['draft', 'published', 'archived'][($i - 1) % 3],
                'gender' => $i % 2 ? 'male' : 'female',
                'size' => ['S', 'M', 'L'][($i - 1) % 3],
                'sizes' => ['S', 'M'],
                'published_on' => now()->subDays($i),
                'published_at' => now()->subHours($i),
                'opens_at' => '09:00:00',
                'birthday' => now()->subYears(20 + $i),
                'remind_at' => now()->addDays($i),
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->endOfMonth(),
                'billing_month' => now()->format('Y-m'),
                'week' => now()->format('Y-\WW'),
                'color' => '#5f0461',
                'accent_color' => '#269740',
                'icon' => 'fa-star',
                'body_ckeditor' => '<p><strong>CKEditor</strong> body</p>',
                'body_tinymce' => '<p><em>TinyMCE</em> body</p>',
                'body_summernote' => '<p>Summernote body</p>',
                'body_wysiwyg' => '<p>WYSIWYG body</p>',
                'body_simplemde' => "# SimpleMDE\n\nMarkdown **body**",
                'body_easymde' => "# EasyMDE\n\nMarkdown _body_",
                'address_google' => ['value' => 'Kuantan, Pahang', 'latlng' => ['lat' => 3.8077, 'lng' => 103.326]],
                'video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Sample video', 'image' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/default.jpg'],
                'extras' => [['key' => 'weight', 'value' => '1.2kg'], ['key' => 'origin', 'value' => 'MY']],
                'lines' => [['sku' => 'SKU-'.$i, 'qty' => $i, 'note' => 'first'], ['sku' => 'SKU-'.($i + 10), 'qty' => $i * 2, 'note' => 'second']],
                'ordered_sizes' => ['M', 'S'],
                'metadata' => ['author' => ['name' => 'Zulfa', 'role' => 'admin'], 'flags' => ['beta' => true]],
                'location' => [['lat' => 3.8077, 'lng' => 103.326], ['lat' => 3.1390, 'lng' => 101.6869], ['lat' => 4.1793, 'lng' => 102.0500]][($i - 1) % 3],
                'price_money' => 1999.9 * $i,
                'phone_my' => '+60123456'.sprintf('%03d', $i),
                'identity_number' => '900101-06-'.sprintf('%04d', 5000 + $i),
                'identity_type' => 'mykad',
                'parent_category_id' => KitchenSinkCategory::where('name', 'Laptops')->value('id'),
                'child_category_id' => KitchenSinkCategory::where('name', $i % 2 ? 'Ultrabook' : 'Gaming')->value('id'),
                'tags_json' => ['laravel', 'sample-'.$i],
                'event_date' => now()->addDays($i),
                'opens_from' => '09:00',
                'opens_to' => '17:30',
                'kitchen_sink_category_id' => $categories[($i - 1) % 4]->id,
                'ajax_category_id' => $categories[$i % 4]->id,
                'nested_category_id' => $categories[($i + 1) % 4]->id,
                'grouped_category_id' => $categories[($i + 2) % 4]->id,
                'select2_category_id' => $categories[($i + 3) % 4]->id,
                'select2_grouped_category_id' => $categories[$i % 4]->id,
            ]);

            // Written directly: the model's upload mutators expect request files, not seeded paths.
            $sink->newQuery()->whereKey($sink->id)->update([
                'image' => $image,
                'avatar' => $avatar,
                'attachment' => $attachments[0],
                'attachments' => json_encode($attachments),
                'ajax_file' => $image,
                'ajax_files' => json_encode([$image, $attachments[1]]),
            ]);

            $sink->tags()->sync($tags->slice(0, $i)->pluck('id'));
            $sink->checklistTags()->sync($tags->slice(1, $i)->pluck('id'));
            $sink->selectTags()->sync($tags->slice(0, 2)->pluck('id'));
            $sink->select2Tags()->sync($tags->slice(2, 2)->pluck('id'));
            $sink->ajaxTags()->sync($tags->slice(1, 2)->pluck('id'));
        }
    }

    /**
     * Generate a small labelled PNG on the given disk and return its relative path.
     */
    private function sampleImage(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, string $label, string $hex): string
    {
        $canvas = imagecreatetruecolor(300, 300);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $x = (int) ((300 - imagefontwidth(5) * strlen($label)) / 2);
        imagestring($canvas, 5, $x, 142, $label, $white);

        ob_start();
        imagepng($canvas);
        $disk->put($path, ob_get_clean());
        imagedestroy($canvas);

        return $path;
    }

    private function sampleFile(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, string $contents): string
    {
        $disk->put($path, $contents);

        return $path;
    }
}
