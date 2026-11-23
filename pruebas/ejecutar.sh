#!/usr/bin/env bash
# Reinicia los datos, carga los datos de prueba y ejecuta las pruebas de aceptación de las entregas 1 y 2.
set -e
cd "$(dirname "$0")/.."
php reset.php
php demo.php > /dev/null
node pruebas/aceptacion.js
node pruebas/aceptacion2.js
node pruebas/aceptacion3.js
node pruebas/aceptacion4.js
node pruebas/aceptacion5.js
