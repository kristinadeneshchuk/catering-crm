<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Схема в тестах будується заново для кожного тесту, а кеш «чи є таблиця»
        // живе в статиці процесу — інакше «ні» з одного тесту протікало б в інший.
        \App\Support\SchemaReady::flush();
    }
}
