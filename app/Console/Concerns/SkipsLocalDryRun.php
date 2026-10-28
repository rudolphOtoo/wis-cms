<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Services\MnotifySmsService;

/**
 * Guard for commands that push work to mNotify.
 *
 * Under APP_ENV=local/testing these commands are a no-op unless SMS is
 * configured live (MNOTIFY_DRY_RUN=false) or --force is given. Church PCs
 * run APP_ENV=local with live SMS on purpose, so the environment alone
 * must never be enough to skip.
 *
 * The using command must declare a --force option.
 */
trait SkipsLocalDryRun
{
    protected function skipForLocalDryRun(): bool
    {
        if (! app()->environment('local', 'testing')
            || ! MnotifySmsService::dryRunEnabled()
            || $this->option('force')) {
            return false;
        }

        $this->info('Skipping: APP_ENV is local/testing without MNOTIFY_DRY_RUN=false. Use --force to override.');

        return true;
    }
}
