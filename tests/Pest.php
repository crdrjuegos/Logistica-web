<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Las pruebas Feature usan Laravel + PostgreSQL real (se reinicia el esquema una vez por ejecución).
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Pruebas de aceptación: un it('CA-xxx-y ...') por criterio del Excel, con sus valores de entrada exactos.
uses(TestCase::class, RefreshDatabase::class)->in('Acceptance');
