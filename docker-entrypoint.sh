#!/bin/sh
set -e

# Configurar el puerto dinámico de Render
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# Cachear configuración y rutas si APP_KEY está definida
if [ -n "$APP_KEY" ]; then
    echo "Optimizando Laravel para producción..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# Ejecutar migraciones automáticamente si hay base de datos conectada
if [ "$RUN_MIGRATIONS" = "true" ] || [ -n "$DATABASE_URL" ] || [ -n "$DB_HOST" ]; then
    echo "Ejecutando migraciones de base de datos..."
    php artisan migrate --force || echo "No se pudieron ejecutar las migraciones o ya están al día; continuando arranque..."
fi

echo "Iniciando servidor Apache en puerto ${PORT}..."
exec apache2-foreground
