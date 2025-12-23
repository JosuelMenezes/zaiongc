<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ZaionGCBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Conta (Tenant)
        $account = Account::firstOrCreate(
            ['slug' => 'domaria-cafe'],
            ['name' => 'Domaria Café', 'plan' => 'trial', 'status' => 'active']
        );

        // 2) Location (Unidade)
        $location = Location::firstOrCreate(
            ['account_id' => $account->id, 'name' => 'Matriz'],
            ['is_active' => true]
        );

        // 3) Roles (mínimo)
        $adminRole = Role::firstOrCreate(['key' => 'admin'], ['name' => 'Administrador']);
        Role::firstOrCreate(['key' => 'user'], ['name' => 'Usuário']);
        Role::firstOrCreate(['key' => 'waiter'], ['name' => 'Garçom']);

        // 4) Usuário Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@zaiongc.test'],
            [
                'name' => 'Admin ZaionGC',
                'password' => Hash::make('123456'),
                'account_id' => $account->id,
                'location_id' => $location->id,
                'is_active' => true,
            ]
        );

        // 5) Vincular role admin ao usuário
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}
