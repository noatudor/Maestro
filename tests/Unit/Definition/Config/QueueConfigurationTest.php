<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\QueueConfiguration;

describe('QueueConfiguration', static function (): void {
    describe('create', static function (): void {
        it('creates configuration with all values', function (): void {
            $config = QueueConfiguration::create('high-priority', 'redis', 30);

            expect($config->queue)->toBe('high-priority');
            expect($config->connection)->toBe('redis');
            expect($config->delaySeconds)->toBe(30);
        });

        it('creates configuration with null values', function (): void {
            $config = QueueConfiguration::create();

            expect($config->queue)->toBeNull();
            expect($config->connection)->toBeNull();
            expect($config->delaySeconds)->toBe(0);
        });

        it('enforces non-negative delay', function (): void {
            $config = QueueConfiguration::create(delaySeconds: -10);

            expect($config->delaySeconds)->toBe(0);
        });
    });

    describe('default', static function (): void {
        it('creates configuration with default values', function (): void {
            $config = QueueConfiguration::default();

            expect($config->queue)->toBeNull();
            expect($config->connection)->toBeNull();
            expect($config->delaySeconds)->toBe(0);
        });
    });

    describe('onQueue', static function (): void {
        it('creates configuration with queue only', function (): void {
            $config = QueueConfiguration::onQueue('emails');

            expect($config->queue)->toBe('emails');
            expect($config->connection)->toBeNull();
        });
    });

    describe('onConnection', static function (): void {
        it('creates configuration with connection only', function (): void {
            $config = QueueConfiguration::onConnection('sqs');

            expect($config->queue)->toBeNull();
            expect($config->connection)->toBe('sqs');
        });
    });

    describe('has methods', static function (): void {
        it('returns correct values for hasQueue', function (): void {
            expect(QueueConfiguration::onQueue('test')->hasQueue())->toBeTrue();
            expect(QueueConfiguration::default()->hasQueue())->toBeFalse();
        });

        it('returns correct values for hasConnection', function (): void {
            expect(QueueConfiguration::onConnection('redis')->hasConnection())->toBeTrue();
            expect(QueueConfiguration::default()->hasConnection())->toBeFalse();
        });

        it('returns correct values for hasDelay', function (): void {
            expect(QueueConfiguration::create(delaySeconds: 30)->hasDelay())->toBeTrue();
            expect(QueueConfiguration::default()->hasDelay())->toBeFalse();
        });
    });

    describe('with methods', static function (): void {
        it('returns new instance with queue', function (): void {
            $original = QueueConfiguration::default();
            $updated = $original->withQueue('high');

            expect($updated->queue)->toBe('high');
            expect($original->queue)->toBeNull();
        });

        it('returns new instance with connection', function (): void {
            $original = QueueConfiguration::default();
            $updated = $original->withConnection('redis');

            expect($updated->connection)->toBe('redis');
            expect($original->connection)->toBeNull();
        });

        it('returns new instance with delay', function (): void {
            $original = QueueConfiguration::default();
            $updated = $original->withDelay(60);

            expect($updated->delaySeconds)->toBe(60);
            expect($original->delaySeconds)->toBe(0);
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal configurations', function (): void {
            $a = QueueConfiguration::create('high', 'redis', 30);
            $b = QueueConfiguration::create('high', 'redis', 30);

            expect($a->equals($b))->toBeTrue();
        });

        it('returns false for different configurations', function (): void {
            $a = QueueConfiguration::create('high', 'redis', 30);
            $b = QueueConfiguration::create('low', 'redis', 30);

            expect($a->equals($b))->toBeFalse();
        });
    });
});
