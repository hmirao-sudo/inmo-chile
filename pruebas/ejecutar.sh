#!/usr/bin/env bash
# Reinicia los datos, carga los datos de prueba y ejecuta las pruebas de aceptación.
set -e
cd "$(dirname "$0")/.."
php reset.php
php demo.php > /dev/null
node pruebas/aceptacion.js
