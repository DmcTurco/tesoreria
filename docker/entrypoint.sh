#!/bin/bash
set -e

# Registrar el crontab del scheduler de Laravel
cron

# Iniciar Apache en foreground (proceso principal del contenedor)
exec apache2-foreground
