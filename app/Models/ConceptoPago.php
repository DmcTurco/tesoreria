<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptoPago extends Model
{
    protected $fillable = [
        'nombre',
        'monto_default',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'monto_default' => 'decimal:2',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function pagos()
    {
        return $this->hasMany(Pago::class);
    }
}