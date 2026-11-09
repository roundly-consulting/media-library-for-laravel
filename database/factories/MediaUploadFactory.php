<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;

/** @extends Factory<MediaUpload> */
final class MediaUploadFactory extends Factory
{
    protected $model = MediaUpload::class;

    /** @return array<model-property<MediaUpload>, mixed> */
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'token_hash' => str_repeat('0', 24).bin2hex(Str::random(20)),
            'media_uuid' => (string) Str::uuid(),
            'disk' => 'public',
            'path' => '_uploads/'.$id,
            'in_place' => false,
            'bucket_name' => 'default',
            'model_type' => null,
            'model_id' => null,
            'draft' => false,
            'file_name' => 'file.bin',
            'declared_size' => 1024,
            'max_size' => 1024,
            'visibility' => 'public',
            'options' => ['method' => 'put'],
            'status' => DirectUploadStatus::Pending,
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => CarbonImmutable::now()->subDay()]);
    }
}
