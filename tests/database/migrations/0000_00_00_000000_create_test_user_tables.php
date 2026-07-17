<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned tables the suite's media owners live in — one keyed by auto-increment id,
 * one by uuid, because `media.model_id` is a string morph precisely so both work.
 *
 * These were `Schema::create()` calls inlined into `TestCase::defineDatabaseMigrations()`.
 * They have to be real migrations now: on a real engine the base case resets state by
 * dropping every table and re-migrating, so anything created outside the migrator exists
 * for exactly one test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_test_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
};
