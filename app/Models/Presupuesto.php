<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presupuesto extends Model
{
    protected $fillable = [
        'anio',
        'mes',
        'categoria',
        'descripcion',
        'monto_planificado',
    ];

    protected $casts = [
        'anio'              => 'integer',
        'mes'               => 'integer',
        'monto_planificado' => 'decimal:2',
    ];
}
