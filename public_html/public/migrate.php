<?php

// Cambiar este token por algo secreto tuyo
define('SECRET_TOKEN', 'L4GyBcdQ9WoH9wjU2MgTZUJf5H1L4v66yAy9YVYQG0U4zUZ45AOzfqOpsVKDXOmd');

$token = $_GET['token'] ?? '';

if ($token !== SECRET_TOKEN) {
    http_response_code(403);
    echo 'Acceso denegado.';
    exit;
}

// Subir al directorio raíz de Laravel
chdir(__DIR__ . '/..');

echo '<pre>';
echo "Ejecutando migrate:fresh --seed --force ...\n\n";

passthru('/usr/local/php82/bin/php artisan migrate:fresh --seed --force 2>&1', $exitCode);

echo "\n\nCódigo de salida: " . $exitCode;
echo ($exitCode === 0) ? "\n✅ Listo." : "\n❌ Hubo un error.";
echo '</pre>';
