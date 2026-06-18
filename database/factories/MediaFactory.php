<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Models\Media;

/** @extends Factory<Media> */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<model-property<Media>, mixed> */
    public function definition(): array
    {
        $name = $this->faker->word();

        return [
            'uuid' => (string) Str::uuid(),
            'model_type' => null,
            'model_id' => null,
            'bucket_name' => 'default',
            'name' => $name,
            'file_name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'disk' => 'public',
            'variants_disk' => null,
            'path' => null,
            'size' => $this->faker->numberBetween(1024, 1048576),
            'visibility' => 'public',
            'custom_properties' => [],
            'generated_variants' => [],
            'checksum' => null,
            'width' => null,
            'height' => null,
            'placeholders' => null,
            'draft_token' => null,
            'draft_expires_at' => null,
            'order_column' => null,
        ];
    }

    public function global(): self
    {
        return $this->state(fn (): array => [
            'model_type' => null,
            'model_id' => null,
        ]);
    }

    public function private(): self
    {
        return $this->state(fn (): array => [
            'visibility' => 'private',
        ]);
    }

    public function inBucket(string $bucket): self
    {
        return $this->state(fn (): array => [
            'bucket_name' => $bucket,
        ]);
    }
}
