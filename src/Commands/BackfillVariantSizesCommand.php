<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\MediaLibrary\Actions\BackfillVariantSizesAction;
use RoundlyConsulting\MediaLibrary\Commands\Concerns\ResolvesModelArgument;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Records the byte size of generated variants written before sizes were, measured from the disk
 * each one lives on — soft-deleted media included, since their files are still stored.
 *
 * Media are read in chunks by id. Variants that already carry a size are skipped, so the command
 * can be stopped and run again at any time; on a large table `--after` skips the ids a previous
 * run already reported as done. A variant whose file cannot be measured keeps an unknown size and
 * is listed.
 *
 * Examples:
 *   php artisan media:backfill-variant-sizes --dry-run
 *   php artisan media:backfill-variant-sizes "App\Models\User" --chunk=200
 *   php artisan media:backfill-variant-sizes --after=125000
 */
final class BackfillVariantSizesCommand extends Command
{
    use ResolvesModelArgument;

    protected $signature = 'media:backfill-variant-sizes
        {model? : Optional morph alias or model class to limit to}
        {--ids= : Comma-separated media ids to limit to}
        {--after= : Only media with an id greater than this (resume a previous run)}
        {--chunk=500 : How many media to read per chunk}
        {--dry-run : Measure and report, but record nothing}';

    protected $description = 'Record the byte size of generated variants that were stored without one';

    public function handle(BackfillVariantSizesAction $backfill): int
    {
        $chunk = $this->positiveInteger('chunk', minimum: 1);
        $after = $this->option('after') === null ? 0 : $this->positiveInteger('after', minimum: 0);

        if ($chunk === null || $after === null) {
            return self::INVALID;
        }

        $dryRun = $this->option('dry-run') === true;
        $media = 0;
        $variants = 0;
        $bytes = 0;
        $unreadable = 0;

        $this->query($after)->chunkById($chunk, function (Collection $chunk) use ($backfill, $dryRun, &$media, &$variants, &$bytes, &$unreadable): void {
            foreach ($chunk as $item) {
                $result = $backfill->execute($item, $dryRun);

                if ($result->recorded !== []) {
                    $media++;
                    $variants += count($result->recorded);
                    $bytes += $result->bytes;
                }

                foreach ($result->unreadable as $name) {
                    $unreadable++;
                    $this->warn("Unreadable: media #{$item->id} variant '{$name}' at {$item->diskFor($name)}:{$item->getPath($name)}");
                }
            }

            $this->line("Checked media through #{$chunk->last()?->id}.");
        });

        $verb = $dryRun ? 'Would record' : 'Recorded';
        $this->info("{$verb} {$variants} variant size(s) ({$bytes} bytes) on {$media} media.");

        if ($unreadable > 0) {
            $this->warn("{$unreadable} variant file(s) could not be measured and keep an unknown size.");
        }

        return self::SUCCESS;
    }

    /** @return Builder<Media> */
    private function query(int $after): Builder
    {
        $query = MediaModel::query()->withTrashed()->whereNotNull('generated_variants');

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $this->morphTypeFor($model));
        }

        $ids = $this->ids();

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ($after > 0) {
            $query->where('id', '>', $after);
        }

        return $query;
    }

    /** The option as an integer of at least `$minimum`, or null (reported) when it is not one. */
    private function positiveInteger(string $option, int $minimum): ?int
    {
        $value = $this->option($option);

        if (is_string($value) && ctype_digit($value) && (int) $value >= $minimum) {
            return (int) $value;
        }

        $this->error("The --{$option} option must be a whole number of at least {$minimum}.");

        return null;
    }

    /** @return list<string> */
    private function ids(): array
    {
        $value = $this->option('ids');

        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v): bool => $v !== ''));
    }
}
