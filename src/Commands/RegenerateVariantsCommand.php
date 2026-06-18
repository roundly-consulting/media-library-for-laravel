<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Actions\GenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Variants\Variant;

/**
 * Re-runs variant generation for stored media.
 *
 * Examples:
 *   php artisan media:regenerate
 *   php artisan media:regenerate "App\Models\User"
 *   php artisan media:regenerate --ids=1,2,3
 *   php artisan media:regenerate --only=thumb,display --force
 */
final class RegenerateVariantsCommand extends Command
{
    protected $signature = 'media:regenerate
        {model? : Optional morph alias or model class to limit to}
        {--ids= : Comma-separated media ids to limit to}
        {--only= : Comma-separated variant names to regenerate}
        {--force : Regenerate variants even if already generated}';

    protected $description = 'Regenerate image variants for stored media';

    public function handle(GenerateVariantsAction $action): int
    {
        $only = $this->namesOption('only');

        $count = 0;

        $this->query()->each(function (Media $media) use ($action, $only, &$count): void {
            $variants = $this->variantsFor($media, $only);

            if ($variants === []) {
                return;
            }

            $action->execute($media, $variants);
            $count++;
        });

        $this->info("Regenerated variants for {$count} media.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $only
     * @return list<Variant>
     */
    private function variantsFor(Media $media, array $only): array
    {
        $force = $this->option('force') === true;

        $variants = [];

        foreach ($media->resolveVariants() as $variant) {
            if ($only !== [] && ! in_array($variant->name, $only, true)) {
                continue;
            }

            if (! $force && $media->hasGeneratedVariant($variant->name)) {
                continue;
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    /** @return Builder<Media> */
    private function query(): Builder
    {
        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');

        $query = $modelClass::query();

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $model);
        }

        $ids = $this->namesOption('ids');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function namesOption(string $name): array
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }
}
