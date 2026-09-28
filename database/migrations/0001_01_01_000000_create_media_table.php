<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = is_string(config('media.table_name')) ? config('media.table_name') : 'media';

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic owner — null on both columns means global media. `model_id` is a string
            // morph so owners keyed by integer, UUID, or ULID are all stored without coercion.
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();

            $table->string('bucket_name')->default('default')->index();
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();

            $table->string('disk');
            $table->string('variants_disk')->nullable();

            // Relative path of the stored ORIGINAL. Populated on every add; on dedup it points at
            // the canonical row's shared file. Null falls back to uuid-derived path.
            $table->string('path')->nullable();

            $table->unsignedBigInteger('size');
            $table->string('visibility')->default('public');

            $table->jsonb('custom_properties')->nullable();
            $table->jsonb('generated_variants')->nullable();

            // Content hash — dedup key + integrity baseline. 128 characters holds the longest
            // allowed digest (a 512-bit SHA-2/SHA-3, see media.checksum_algorithm).
            $table->string('checksum', 128)->nullable();

            // Image pixel dimensions, extracted on add for images (later phases).
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // LQIP placeholders — { thumbhash, blurhash } (later phases).
            $table->jsonb('placeholders')->nullable();

            // Draft / temporary media (later phases).
            $table->string('draft_token')->nullable()->index();
            $table->timestamp('draft_expires_at')->nullable();

            $table->unsignedInteger('order_column')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['model_type', 'model_id']);
            $table->index(['disk', 'visibility', 'checksum']);

            // The shared-original reference count: which rows point at a stored file.
            $table->index(['disk', 'path']);
        });
    }
};
