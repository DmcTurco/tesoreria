<?php

namespace App\Services;

use App\Models\Padre;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Laravel\Firebase\Facades\Firebase;

/**
 * Envío de notificaciones push (FCM) a los padres.
 *
 * Requiere que el padre tenga `fcm_token` registrado (ver
 * PadreController::guardarFcmToken / POST /fcm-token) y que el backend
 * tenga configurado un proyecto de Firebase (ver config/firebase.php y
 * la variable de entorno FIREBASE_CREDENTIALS).
 *
 * Si Firebase no está configurado o el token es inválido, los errores se
 * registran en el log y no interrumpen el flujo principal (registrar un
 * abono, generar una multa, etc. nunca debe fallar por un problema de push).
 */
class PushNotificationService
{
    /**
     * Envía una notificación push a un padre específico.
     *
     * @param array<string, string> $data Datos adicionales (ej. ['tipo' => 'abono', 'id' => '123'])
     */
    public function enviarAPadre(Padre $padre, string $titulo, string $cuerpo, array $data = []): void
    {
        if (empty($padre->fcm_token)) {
            return;
        }

        if (!config('firebase.projects.app.credentials.file')) {
            // Firebase no configurado todavía (faltan credenciales) — no hacer nada.
            return;
        }

        try {
            $message = CloudMessage::new()
                ->withTarget('token', $padre->fcm_token)
                ->withNotification(FcmNotification::create($titulo, $cuerpo))
                ->withData($data)
                ->withDefaultSounds();

            Firebase::messaging()->send($message);
        } catch (NotFound|InvalidArgument $e) {
            // Token inválido o expirado: lo limpiamos para no seguir intentando.
            Log::info('Token FCM inválido para padre ' . $padre->id . ', se elimina.', ['error' => $e->getMessage()]);
            $padre->update(['fcm_token' => null, 'fcm_platform' => null]);
        } catch (\Throwable $e) {
            Log::warning('Error enviando notificación push a padre ' . $padre->id, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envía la misma notificación a varios padres.
     *
     * @param iterable<Padre> $padres
     * @param array<string, string> $data
     */
    public function enviarAPadres(iterable $padres, string $titulo, string $cuerpo, array $data = []): void
    {
        foreach ($padres as $padre) {
            $this->enviarAPadre($padre, $titulo, $cuerpo, $data);
        }
    }
}
