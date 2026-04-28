<?php

namespace Kompo\Auth\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kompo\Auth\Facades\UserModel;
use Kompo\Auth\Teams\Cache\UserCacheVersion;

class EnsureCurrentTeamRoleIsValidCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:ensure-current-team-role-is-valid';

    public function handle(): int
    {
        $initialQuery = $this->invalidUsersQuery();
        $total = (clone $initialQuery)->count();

        $this->info($total . ' users to be fixed');

        $batchFixed = 0;

        (clone $initialQuery)
            ->with(['activeTeamRoles' => fn($query) => $query->select('id', 'user_id')->orderBy('id')])
            ->chunkById(2000, function ($users) use (&$batchFixed) {
                $updates = $users->map(function ($user) {
                    $firstActiveTeamRoleId = $user->activeTeamRoles->first()?->id;

                    if (!$firstActiveTeamRoleId) {
                        return null;
                    }

                    return [
                        'id' => $user->id,
                        'current_team_role_id' => $firstActiveTeamRoleId,
                    ];
                })->filter()->values()->all();

                if (!$updates) {
                    return;
                }

                $this->batchUpdateCurrentTeamRole($updates);
                app(UserCacheVersion::class)->bumpMany(array_column($updates, 'id'));

                $batchFixed += count($updates);
                $this->info(count($updates) . ' users batch-fixed');
            });

        $fallbackQuery = $this->invalidUsersQuery();
        $remaining = (clone $fallbackQuery)->count();

        $this->info($remaining . ' users remaining for fallback fix');

        $fallbackFixed = 0;

        (clone $fallbackQuery)->chunkById(2000, function ($users) use (&$fallbackFixed) {
            foreach ($users as $user) {
                if ($user->switchToFirstTeamRole()) {
                    $fallbackFixed++;
                }
            }

            $this->info($users->count() . ' users fallback-processed');
        });

        $this->info("Batch-fixed {$batchFixed} users");
        $this->info("Fallback-fixed {$fallbackFixed} users");
        $this->info((clone $this->invalidUsersQuery())->count() . ' users still invalid');

        return self::SUCCESS;
    }

    protected function invalidUsersQuery()
    {
        return UserModel::whereDoesntHave('currentTeamRole.team')
            ->whereHas('activeTeamRoles');
    }

    protected function batchUpdateCurrentTeamRole(array $updates): void
    {
        $userModelClass = UserModel::getClass();
        $userModel = new $userModelClass();
        $connection = $userModel->getConnection();
        $grammar = $connection->getQueryGrammar();
        $keyName = $userModel->getKeyName();
        $wrappedKeyName = $grammar->wrap($keyName);

        $ids = [];
        $cases = [];

        foreach ($updates as $update) {
            $ids[] = $update['id'];
            $cases[] = 'WHEN ' . $this->sqlValue($update['id'], $connection) . ' THEN ' . $this->sqlValue($update['current_team_role_id'], $connection);
        }

        $caseSql = "CASE {$wrappedKeyName} " . implode(' ', $cases) . ' END';

        $userModelClass::query()
            ->whereKey($ids)
            ->update([
                'current_team_role_id' => DB::raw($caseSql),
            ]);
    }

    protected function sqlValue($value, $connection): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $connection->getPdo()->quote((string) $value);
    }
}
