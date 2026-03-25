<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ProfesoraSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('username', 'profesora')->exists()) {
            $this->command->info('⚠️  El usuario profesora ya existe, se omite.');
            return;
        }

        User::create([
            'name'     => 'Profesora Celia',
            'username' => 'profesora',
            'password' => Hash::make('tesoreria2025'),
            'role'     => User::ROLE_PROFESORA,  // 1
            'padre_id' => null,
        ]);

        $this->command->info('✅  Profesora creada → usuario: profesora / contraseña: apafa2025');
        $this->command->warn('🔐  Cambia la contraseña después del primer ingreso.');
    }
}
