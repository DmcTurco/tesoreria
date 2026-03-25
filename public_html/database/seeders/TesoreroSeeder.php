<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class TesoreroSeeder extends Seeder
{
    public function run(): void
    {
        // Evita duplicar si ya existe
        if (User::where('username', 'tesorero')->exists()) {
            $this->command->info('⚠️  El usuario tesorero ya existe, se omite.');
            return;
        }

        User::create([
            'name'      => 'David Mamani',
            'username'  => 'tesorero',
            'password'  => Hash::make('tesoreria2025'),
            'role'      => User::ROLE_TESORERO,  // 0
            'padre_id'  => null,
        ]);

        $this->command->info('✅  Tesorero creado → usuario: tesorero / contraseña: apafa2025');
        $this->command->warn('🔐  Cambia la contraseña después del primer ingreso.');
    }
}
