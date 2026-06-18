<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Prunes expired, never-bound draft media (rows + files) via the refcount-guarded delete path.
 *
 * Only rows that still carry a `draft_token` and whose `draft_expires_at` is in the past are
 * touched — bound media (token cleared) and unexpired drafts are left entirely alone. Hosts
 * typically schedule this command.
 */
final class PruneDraftsCommand extends Command
{
    protected $signature = 'media:prune-drafts';

    protected $description = 'Delete expired, never-bound draft media (rows and files)';

    public function handle(DeleteMediaAction $action): int
    {
        $now = CarbonImmutable::now();

        $count = 0;

        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');

        $modelClass::query()
            ->whereNotNull('draft_token')
            ->where('draft_expires_at', '<', $now)
            ->each(function (Media $media) use ($action, &$count): void {
                $action->execute($media);
                $count++;
            });

        $this->info("Pruned {$count} expired draft media.");

        return self::SUCCESS;
    }
}
