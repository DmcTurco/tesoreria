<?php

namespace App\Observers;

use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Padre;

class PadreObserver
{
    public function created(Padre $padre): void
    {
        Evento::where('estado', Evento::ESTADO_ACTIVO)
            ->whereIn('tipo', [Evento::TIPO_CUOTA, Evento::TIPO_REUNION])
            ->get()
            ->each(function (Evento $evento) use ($padre) {
                EventoPadre::firstOrCreate([
                    'evento_id' => $evento->id,
                    'padre_id'  => $padre->id,
                    'fecha'     => null,
                ], [
                    'estado' => EventoPadre::ESTADO_PENDIENTE,
                ]);
            });
    }
}
