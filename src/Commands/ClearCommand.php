<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Models\Media;

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

    public function handle(DeleteMediaAction $action): int
    {
        $bucketArg = $this->input->getArgument('bucket');
        $bucket = is_string($bucketArg) ? $bucketArg : 'default';

        $count = 0;

        $this->query($bucket)->each(function (Media $media) use ($action, &$count): void {
            $action->execute($media);
            $count++;
        });

        $this->info("Cleared {$count} media from bucket '{$bucket}'.");

        return self::SUCCESS;
    }

    /** @return Builder<Media> */
    private function query(string $bucket): Builder
    {
        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');

        $query = $modelClass::query()->where('bucket_name', $bucket);

        $model = $this->argument('model');

        if (is_string($model) && $model !== '') {
            $query->where('model_type', $model);
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return $query;
    }
}
