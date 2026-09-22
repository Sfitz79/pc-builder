<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CreateAdminRole extends Command
{
    protected $signature = 'app:create-admin-role
        {--email=simon@pctechguyonline.com : Email of the admin user to assign the role to}';

    protected $description = 'Create the Filament admin role, grant CRUD permissions on every resource, and assign it to the admin user';

    /**
     * Filament v4 resources registered in this panel. Permission names follow
     * the Filament Shield convention (view_any::resource etc.) so a future
     * Shield install can pick them up without renaming.
     *
     * @var list<string>
     */
    private const RESOURCES = [
        'component',
        'category',
        'build',
        'order',
        'software_product',
    ];

    private const ACTIONS = [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'delete_any',
    ];

    public function handle(): int
    {
        $email = (string) $this->option('email');

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $permissions = collect(self::RESOURCES)
            ->crossJoin(self::ACTIONS)
            ->map(fn (array $pair): string => $pair[1] . '::' . $pair[0])
            ->map(fn (string $name): Permission => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));

        $role->syncPermissions($permissions);

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email {$email}. Run `php artisan make:filament-user` first.");

            return self::FAILURE;
        }

        $user->assignRole('admin');

        $this->info(sprintf(
            'Admin role ready: %d permissions granted to role "%s", assigned to %s (user id %d).',
            $permissions->count(),
            $role->name,
            $user->email,
            $user->id,
        ));

        return self::SUCCESS;
    }
}