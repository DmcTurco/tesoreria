<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de notificaciones push (FCM) de un dispositivo asociado a un padre.
 *
 * Un mismo padre (código de familia) puede tener varios dispositivos
 * registrados (ej. celular del papá y celular de la mamá): todos reciben
 * las notificaciones push de ese padre.
 */
class PadreFcmToken extends Model
{
    protected $fillable = [
        'padre_id',
        'token',
        'plataforma',
    ];

    public function padre()
    {
        return $this->belongsTo(Padre::class);
    }
}
