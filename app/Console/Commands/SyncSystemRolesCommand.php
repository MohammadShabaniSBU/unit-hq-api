<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Console\Command;

/**
 * Re-apply system role permission sets from RbacSystemRoleSeeder without
 * migrate:fresh. Safe to re-run; never touches employee_roles grants.
 */
class SyncSystemRolesCommand extends Command
{
    protected $signature = 'rbac:sync-system-roles';

    protected $description = 'Reset system role permission sets to the current seeder spec (owner gets every Permission case, including ai_summary.*)';

    public function handle(): int
    {
        $before = $this->permissionMaps();

        RbacSystemRoleSeeder::upsertSystemRoles();

        $after = $this->permissionMaps();

        $changed = 0;
        foreach ($after as $key => $permissions) {
            $previous = $before[$key] ?? [];
            $added = array_values(array_diff($permissions, $previous));
            $removed = array_values(array_diff($previous, $permissions));

            if ($added === [] && $removed === []) {
                $this->line("  {$key}: unchanged (".count($permissions).' permissions)');

                continue;
            }

            $changed++;
            $this->info("  {$key}: updated");
            if ($added !== []) {
                $this->line('    + '.implode(', ', $added));
            }
            if ($removed !== []) {
                $this->line('    - '.implode(', ', $removed));
            }
        }

        $owner = $after['owner'] ?? [];
        $hasView = in_array(Permission::AiSummaryView->value, $owner, true);
        $hasGenerate = in_array(Permission::AiSummaryGenerate->value, $owner, true);

        if (! $hasView || ! $hasGenerate) {
            $this->error('Owner is still missing ai_summary permissions after sync.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($changed === 0
            ? 'System roles already matched the seeder.'
            : "Synced {$changed} system role(s). Owner has ai_summary.view and ai_summary.generate.");
        $this->comment('Re-login (or refresh the session) if the panel still hides the card — permission maps are cached per request.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function permissionMaps(): array
    {
        $roles = Role::query()
            ->where('is_system', true)
            ->with('rolePermissions')
            ->get();

        $maps = [];
        foreach ($roles as $role) {
            $maps[$role->key] = $role->rolePermissions
                ->map(static fn ($row): string => $row->permission instanceof Permission
                    ? $row->permission->value
                    : (string) $row->permission)
                ->sort()
                ->values()
                ->all();
        }

        return $maps;
    }
}
