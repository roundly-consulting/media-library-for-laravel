<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Commands\Concerns\ResolvesModelArgument;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Re-runs variant generation for stored media — `MediaLibrary::regenerate()` per matching media.
 *
 * Examples:
 *   php artisan media:regenerate
 *   php artisan media:regenerate "App\Models\User"
 *   php artisan media:regenerate --ids=1,2,3
 *   php artisan media:regenerate --only=thumb,display --force
 */
final class RegenerateVariantsCommand extends Command
{
    use ResolvesModelArgument;

    protected $signature = 'media:regenerate
        {model? : Optional morph alias or model class to limit to}
        {--ids= : Comma-separated media ids to limit to}
        {--only= : Comma-separated variant names to regenerate}
        {--force : Regenerate variants even if already generated}';

    protected $description = 'Regenerate image variants for stored media';

    public function handle(MediaLibraryManager $media): int
    {
        $only = $this->namesOption('only');
        $force = $this->option('force') === true;

        $count = 0;

        $this->query()->each(function (Media $item) use ($media, $only, $force, &$count): void {
            if ($media->regenerate($item, $only, $force) !== []) {
                $count++;
            }
        });

        $this->info("Regenerated variants for {$count} media.");

        return self::SUCCESS;
    }

    /** @return Builder<Media> */
    private function query(): Builder
    {
        $query = MediaModel::query();

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $this->morphTypeFor($model));
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
