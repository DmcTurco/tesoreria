<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Abono extends Model
{
    const ESTADO_ACTIVO  = 0;
    const ESTADO_ANULADO = 1;

    const TIPO_MULTA = 'multa';
    const TIPO_COBRO = 'cobro';
    const TIPO_CUOTA = 'cuota';

    protected $fillable = [
        'padre_id',
        'tipo_deuda',
        'deuda_id',
        'monto',
        'fecha',
        'registrado_por',
        'estado',
        'motivo_anulacion',
        'anulado_por',
        'anulado_at',
        'deuda_perdonada',
    ];

    protected $casts = [
        'fecha'           => 'date',
        'monto'           => 'decimal:2',
        'estado'          => 'integer',
        'deuda_perdonada' => 'boolean',
        'anulado_at'      => 'datetime',
    ];

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }
    public function registrador()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
    public function anulador()
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
