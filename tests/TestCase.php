<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;
}
