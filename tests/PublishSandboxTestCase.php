<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;

/**
 * Boots the package with `database/` pointed at a throwaway directory per test, set before the
 * provider boots — the migration publish destination (`database_path('migrations')`) is fixed then.
 *
 * Publishing into the shared testbench skeleton raced the parallel suite: every process migrates
 * from that skeleton's `laravel/database/migrations`, so a `create_media_table` being written or
 * deleted at that moment ran twice, or half-read, in whichever test happened to be migrating.
 *
 * @see TestCase
 */
abstract class PublishSandboxTestCase extends TestCase
{
    private string $sandbox = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/media-publish-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useDatabasePath($this->sandbox.'/database');
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
