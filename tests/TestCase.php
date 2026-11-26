<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Como no ambiente local: campo que o model não aceita vira erro. Em produção ele é descartado SEM AVISO, e foi assim que o "desde
        // quando" e a origem de uma limitação se perderam com a suíte inteira verde.
        Model::preventSilentlyDiscardingAttributes(true);
    }
}
