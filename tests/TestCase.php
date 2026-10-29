<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Candado: las pruebas Feature reinician el esquema. Nunca deben tocar una base que no sea de pruebas.
     */
    protected function setUpTraits(): array
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Las pruebas solo pueden usar una base *_test; se intentó usar '{$database}'.");
        }

        return parent::setUpTraits();
    }
}
