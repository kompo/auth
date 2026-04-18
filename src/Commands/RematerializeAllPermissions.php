<?php

namespace Kompo\Auth\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Jobs\RematerializeUserPermissions;

class RematerializeAllPermissions extends Command
{
    protected $signature = 'auth:rematerialize-permissions
        {--chunk=100 : Number of user IDs per dispatch chunk}
        {--sync : Run jobs synchronously instead of dispatching to the queue}
        {--active-days= : Only include users who logged in within the last N days}';

    protected $description = 'Dispatch RematerializeUserPermissions jobs for all (or a filtered set of) users — use after deploys or schema changes';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $sync = (bool) $this->option('sync');
        $activeDays = $this->option('active-days');

        $query = UserModel::query();

        if ($activeDays !== null && Schema::hasColumn('users', 'last_login_at')) {
            $query->where('users.last_login_at', '>', now()->subDays((int) $activeDays));
        }

        $total = (clone $query)->count();
        $this->info("Rematerializing permissions for {$total} user(s)" . ($sync ? ' (sync)' : ' (queued)'));

        $processed = 0;

        $query->chunkById(max($chunk, 1), function ($users) use (&$processed, $sync) {
            foreach ($users as $user) {
                $job = new RematerializeUserPermissions((int) $user->id);

                if ($sync) {
                    $job->handle(
                        app(\Kompo\Auth\Teams\Contracts\PermissionResolverInterface::class),
                        app(\Kompo\Auth\Teams\Cache\UserPermissionSet::class),
                    );
                } else {
                    dispatch($job);
                }

                $processed++;
            }

            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Processed {$processed} user(s).");

        return self::SUCCESS;
    }
}
