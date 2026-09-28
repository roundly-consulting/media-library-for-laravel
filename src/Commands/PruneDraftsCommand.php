<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

/**
 * Prunes expired, never-bound draft media (rows + files) — `MediaLibrary::pruneDrafts()`.
 *
 * Only rows that still carry a `draft_token` and whose `draft_expires_at` is in the past are
 * touched — bound media (token cleared) and unexpired drafts are left entirely alone. Hosts
 * typically schedule this command.
 */
final class PruneDraftsCommand extends Command
{
    protected $signature = 'media:prune-drafts';

    protected $description = 'Delete expired, never-bound draft media (rows and files)';

    public function handle(MediaLibraryManager $media): int
    {
        $count = $media->pruneDrafts();

        $this->info("Pruned {$count} expired draft media.");

        return self::SUCCESS;
    }
}
