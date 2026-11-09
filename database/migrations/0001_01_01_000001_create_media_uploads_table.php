<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;

/**
 * Direct-upload tickets: one row per presigned upload, from issue to adoption (or rejection,
 * abort, expiry). Additive — the media table is untouched, and no foreign key ties the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = MediaConfig::uploadsTableName();

        Schema::create($name, function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // sha256 of the ticket's bearer secret; the secret itself is never stored.
            $table->char('token_hash', 64)->unique();

            // The uuid the adopted media gets — unique, so one ticket can never make two rows.
            $table->uuid('media_uuid')->unique();

            $table->string('disk');
            $table->string('path');
            $table->boolean('in_place')->default(false);

            $table->string('bucket_name');
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();
            $table->boolean('draft')->default(false);

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            $table->string('file_name');
            $table->unsignedBigInteger('declared_size')->nullable();
            $table->string('declared_mime')->nullable();
            $table->string('declared_checksum')->nullable();
            $table->unsignedBigInteger('max_size');
            $table->string('visibility');
            $table->jsonb('options')->nullable();

            $table->string('multipart_upload_id', 1024)->nullable();
            $table->unsignedBigInteger('part_size')->nullable();
            $table->unsignedInteger('part_count')->nullable();

            $table->string('status', 16);
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('media_id')->nullable()->index();

            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['model_type', 'model_id', 'status']);
        });
    }
};
