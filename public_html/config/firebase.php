<?php

return [

    'default' => env('FIREBASE_PROJECT', 'app'),

    'projects' => [

        'app' => [

            'credentials' => [
                /*
                 * Ruta al JSON de la cuenta de servicio descargado desde
                 * Firebase Console > Configuración del proyecto > Cuentas de servicio
                 * > Generar nueva clave privada.
                 *
                 * Coloca el archivo (por ejemplo) en storage/app/firebase/service-account.json
                 * y define en .env:
                 *   FIREBASE_CREDENTIALS=storage/app/firebase/service-account.json
                 *
                 * Mientras esta variable no esté definida o el archivo no exista,
                 * PushNotificationService no enviará notificaciones (falla silenciosa).
                 */
                'file' => env('FIREBASE_CREDENTIALS')
                    ? base_path(env('FIREBASE_CREDENTIALS'))
                    : null,
            ],

        ],

    ],

];
