<?php

namespace Database\Seeders;

use App\Models\Padre;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PadreSeeder extends Seeder
{
    public function run(): void
    {
        $grado = '3 Añitos - Amor';

        $padres = [
            ['nombre' => 'María Elena Quispe Huanca',    'hijo' => 'Lucía Fernández Quispe',    'telefono' => '987654321'],
            ['nombre' => 'Juan Carlos Mamani Torres',    'hijo' => 'Santiago Mamani Ramos',     'telefono' => '976543210'],
            ['nombre' => 'Rosa Angélica Flores Paredes', 'hijo' => 'Valentina Flores Díaz',     'telefono' => '965432109'],
            ['nombre' => 'Pedro Antonio Huamán Ccopa',   'hijo' => 'Mateo Huamán Salcedo',      'telefono' => '954321098'],
            ['nombre' => 'Carmen Lucía Vargas Apaza',    'hijo' => 'Isabella Vargas León',      'telefono' => '943210987'],
            ['nombre' => 'Luis Alberto Condori Puma',    'hijo' => 'Sebastián Condori Vega',    'telefono' => '932109876'],
            ['nombre' => 'Ana Cristina Ríos Cárdenas',   'hijo' => 'Emilia Ríos Mendoza',       'telefono' => '921098765'],
            ['nombre' => 'Roberto Carlos Lazo Medina',   'hijo' => 'Thiago Lazo Paredes',       'telefono' => '910987654'],
            ['nombre' => 'Gloria Esther Pinto Chávez',   'hijo' => 'Camila Pinto Soto',         'telefono' => '909876543'],
            ['nombre' => 'Héctor Miguel Salas Ochoa',    'hijo' => 'Benjamín Salas Quispe',     'telefono' => '998765432'],
            ['nombre' => 'Silvia Marisol Tuco Hancco',   'hijo' => 'Valeria Tuco Ramos',        'telefono' => '987123456'],
            ['nombre' => 'Eduardo Raúl Cusi Mamani',     'hijo' => 'Nicolás Cusi Flores',       'telefono' => '976234567'],
            ['nombre' => 'Patricia Noemí Ramos Huanca',  'hijo' => 'Sofía Ramos Condori',       'telefono' => '965345678'],
            ['nombre' => 'Ángel Augusto Vilca Paucar',   'hijo' => 'Rodrigo Vilca Torres',      'telefono' => '954456789'],
            ['nombre' => 'Norma Beatriz Cruz Atauchi',   'hijo' => 'Ximena Cruz Lazo',          'telefono' => '943567890'],
            ['nombre' => 'César Enrique Puma Ccallo',    'hijo' => 'Alejandro Puma Vega',       'telefono' => '932678901'],
            ['nombre' => 'Janet Rosario Calderón Apaza', 'hijo' => 'Ariana Calderón Medina',    'telefono' => '921789012'],
            ['nombre' => 'Víctor Hugo Sucari Ticona',    'hijo' => 'Gabriel Sucari Chávez',     'telefono' => '910890123'],
            ['nombre' => 'Milagros Yessenia Lipa Marca', 'hijo' => 'Renata Lipa Ochoa',         'telefono' => '909901234'],
            ['nombre' => 'Franklin David Ayma Catacora', 'hijo' => 'Samuel Ayma Hancco',        'telefono' => '998012345'],
        ];

        foreach ($padres as $index => $item) {
            // Mismo patrón que PadreController@store: PAD-0001, PAD-0002 …
            $numero = $index + 1;
            $codigo = 'PAD-' . str_pad($numero, 4, '0', STR_PAD_LEFT);

            $padre = Padre::create([
                'codigo'   => $codigo,
                'nombre'   => $item['nombre'],
                'hijo'     => $item['hijo'],
                'grado'    => $grado,
                'telefono' => $item['telefono'],
            ]);

            User::create([
                'name'     => $item['nombre'],
                'username' => $codigo,           // PAD-0001 es el username
                'password' => Hash::make($codigo), // contraseña inicial = el propio código
                'role'     => User::ROLE_PADRE,
                'padre_id' => $padre->id,
            ]);
        }
    }
}
