<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the Kitchen Sink CRUD. They live on the "kitchensink" SQLite
 * connection, so run this with:
 *   php artisan kitchensink:install
 */
return new class extends Migration
{
    protected $connection = 'kitchensink';

    public function up(): void
    {
        Schema::connection('kitchensink')->create('kitchen_sink_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::connection('kitchensink')->create('kitchen_sink_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_sink_group_id')->nullable()->constrained('kitchen_sink_groups')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->string('name');
            $table->integer('lft')->nullable();
            $table->integer('rgt')->nullable();
            $table->integer('depth')->nullable();
            $table->timestamps();
        });

        Schema::connection('kitchensink')->create('kitchen_sink_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::connection('kitchensink')->create('kitchen_sinks', function (Blueprint $table) {
            $table->id();

            // ReorderOperation (nested set)
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('lft')->nullable();
            $table->integer('rgt')->nullable();
            $table->integer('depth')->nullable();

            // Simple inputs
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('secret')->nullable();
            $table->string('hidden_token')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('agreed')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->nullable();
            $table->string('gender')->nullable();
            $table->string('size')->nullable();
            $table->json('sizes')->nullable();

            // Dates and times
            $table->date('published_on')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->time('opens_at')->nullable();
            $table->date('birthday')->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('billing_month')->nullable();
            $table->string('week')->nullable();

            // Colors and icons
            $table->string('color')->nullable();
            $table->string('accent_color')->nullable();
            $table->string('icon')->nullable();

            // Rich text
            $table->text('body_ckeditor')->nullable();
            $table->text('body_tinymce')->nullable();
            $table->text('body_summernote')->nullable();
            $table->text('body_wysiwyg')->nullable();
            $table->text('body_simplemde')->nullable();
            $table->text('body_easymde')->nullable();

            // Addresses and media
            $table->json('address_google')->nullable();
            $table->string('image')->nullable();
            $table->string('avatar')->nullable();
            $table->string('attachment')->nullable();
            $table->json('attachments')->nullable();
            $table->string('ajax_file')->nullable();
            $table->json('ajax_files')->nullable();
            $table->json('video')->nullable();

            // Structured data
            $table->json('extras')->nullable();
            $table->json('lines')->nullable();
            $table->json('ordered_sizes')->nullable();
            $table->json('metadata')->nullable();
            $table->json('location')->nullable();
            $table->decimal('price_money', 12, 2)->nullable();
            $table->string('phone_my')->nullable();
            $table->string('identity_number')->nullable();
            $table->string('identity_type', 20)->nullable();
            $table->foreignId('parent_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('child_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->json('tags_json')->nullable();
            $table->date('event_date')->nullable();
            $table->time('opens_from')->nullable();
            $table->time('opens_to')->nullable();

            // Relations
            $table->foreignId('kitchen_sink_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('ajax_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('nested_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('grouped_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('select2_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();
            $table->foreignId('select2_grouped_category_id')->nullable()->constrained('kitchen_sink_categories')->nullOnDelete();

            $table->timestamps();
        });

        Schema::connection('kitchensink')->create('kitchen_sink_kitchen_sink_tag', function (Blueprint $table) {
            $table->foreignId('kitchen_sink_id')->constrained('kitchen_sinks')->cascadeOnDelete();
            $table->foreignId('kitchen_sink_tag_id')->constrained('kitchen_sink_tags')->cascadeOnDelete();
            $table->string('relation')->default('tags');
            $table->primary(['kitchen_sink_id', 'kitchen_sink_tag_id', 'relation']);
        });
    }

    public function down(): void
    {
        Schema::connection('kitchensink')->dropIfExists('kitchen_sink_kitchen_sink_tag');
        Schema::connection('kitchensink')->dropIfExists('kitchen_sinks');
        Schema::connection('kitchensink')->dropIfExists('kitchen_sink_tags');
        Schema::connection('kitchensink')->dropIfExists('kitchen_sink_categories');
        Schema::connection('kitchensink')->dropIfExists('kitchen_sink_groups');
    }
};
