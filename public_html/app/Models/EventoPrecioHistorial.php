<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoPrecioHistorial extends Model
{
    protected $table = 'evento_precio_historial';

    protected $fillable = [
        'evento_id',
        'monto_anterior',
        'monto_nuevo',
        'registrado_por',
    ];

    protected $casts = [
        'monto_anterior' => 'decimal:2',
        'monto_nuevo'    => 'decimal:2',
    ];

    public function evento()
    {
        return $this->belongsTo(Evento::class);
    }

    public function registrador()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}