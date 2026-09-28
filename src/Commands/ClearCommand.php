<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Clears a bucket — deletes every media (row + files) in it.
 *
 * Examples:
 *   php artisan media:clear                       # global 'default' bucket
 *   php artisan media:clear "App\Models\User"     # the model's 'default' bucket
 *   php artisan media:clear "App\Models\User" avatar
 *   php artisan media:clear "" brand              # global 'brand' bucket
 */
final class ClearCommand extends Command
{
    protected $signature = 'media:clear
        {model? : Optional morph alias/model class; omit or "" for global media}
        {bucket=default : The bucket to clear}';

    protected $description = 'Delete every media in a model or global bucket';

    public function handle(MediaLibraryManager $media): int
    {
        $bucketArg = $this->input->getArgument('bucket');
        $bucket = is_string($bucketArg) ? $bucketArg : 'default';

        $count = 0;

        // Keyset iteration: deleting while paging by offset would skip every other chunk.
        $this->query($bucket)->lazyById()->each(function (Media $item) use ($media, &$count): void {
            $media->delete($item);
            $count++;
        });

        $this->info("Cleared {$count} media from bucket '{$bucket}'.");

        return self::SUCCESS;
    }

    /** @return Builder<Media> */
    private function query(string $bucket): Builder
    {

        $query = MediaModel::query()->where('bucket_name', $bucket);

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $model);
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return $query;
    }
}
