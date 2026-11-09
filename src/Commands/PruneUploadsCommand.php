<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

/**
 * Expires direct uploads never adopted and removes what they left in storage —
 * `MediaLibrary::pruneUploads()`. Schedule it when direct uploads are on.
 */
final class PruneUploadsCommand extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Expire unadopted direct uploads and remove their leftovers';

    public function handle(MediaLibraryManager $media): int
    {
        $count = $media->pruneUploads();

        $this->info("Pruned {$count} direct upload(s) and leftovers.");

        return self::SUCCESS;
    }
}
