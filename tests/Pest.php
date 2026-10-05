<?php

declare(strict_types=1);

use Livewire\Component;
use SytxLabs\LaravelWebMcp\Tests\TestCase;

require_once __DIR__.'/Fixtures/fixtures.php';

if (class_exists(Component::class)) {
    require_once __DIR__.'/Fixtures/livewire.php';
}

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Contract');
