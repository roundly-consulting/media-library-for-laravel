<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Verifies stored media against their recorded checksum baselines (§7.2).
 *
 * Reports media whose stored original is missing or whose bytes drifted from the checksum, and
 * exits non-zero when any failure is found — useful after disk migrations or to detect bit-rot.
 *
 * Examples:
 *   php artisan media:verify
 *   php artisan media:verify "App\Models\User"
 *   php artisan media:verify --ids=1,2,3
 */
final class VerifyCommand extends Command
{
    protected $signature = 'media:verify
        {model? : Optional morph alias or model class to limit to}
        {--ids= : Comma-separated media ids to limit to}';

    protected $description = 'Verify stored media against their checksum baselines';

    public function handle(): int
    {
        $checked = 0;
        $missing = 0;
        $mismatched = 0;

        $this->query()->each(function (Media $media) use (&$checked, &$missing, &$mismatched): void {
            $checked++;

            if (! Storage::disk($media->disk)->exists($media->getPath())) {
                $missing++;
                $this->error("Missing: media #{$media->id} ({$media->uuid}) at {$media->disk}:{$media->getPath()}");

                return;
            }

            if (! is_string($media->checksum) || $media->checksum === '') {
                return;
            }

            if (! $media->verifyIntegrity()) {
                $mismatched++;
                $this->error("Checksum mismatch: media #{$media->id} ({$media->uuid})");
            }
        });

        $failures = $missing + $mismatched;

        if ($failures === 0) {
            $this->info("Verified {$checked} media: all files present and intact.");

            return self::SUCCESS;
        }

        $this->error("Verified {$checked} media: {$missing} missing, {$mismatched} mismatched.");

        return self::FAILURE;
    }

    /** @return Builder<Media> */
    private function query(): Builder
    {

        $query = MediaModel::query();

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $model);
        }

        $ids = $this->ids();

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query;
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
