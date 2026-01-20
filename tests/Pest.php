<?php

declare(strict_types=1);

use Maestro\Workflow\Tests\TestCase;
use Ramsey\Uuid\Uuid;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific
| PHPUnit test case class. By default, that class is "PHPUnit\Framework\TestCase".
| Of course, you may need to change it using the "uses()" function to bind a
| different class or trait to your test functions.
|
*/

uses(TestCase::class)->in('Feature', 'Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain
| conditions. Pest provides the "expect()" function which gives you access
| to a set of "expectations" methods that you can use to verify values.
|
*/

expect()->extend('toBeValidUuid', fn () => $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'));

expect()->extend('toBeImmutable', function () {
    $reflection = new ReflectionClass($this->value);

    return $this->and($reflection->isReadOnly())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code
| specific to your project that you don't want to repeat in every file. Here
| you can also expose helpers as global functions to help you reduce the
| number of lines of code in your test files.
|
*/

function fixtures(string $path = ''): string
{
    return __DIR__.'/Fixtures'.($path !== '' && $path !== '0' ? '/'.$path : '');
}

function createWorkflowId(): string
{
    return Uuid::uuid7()->toString();
}
