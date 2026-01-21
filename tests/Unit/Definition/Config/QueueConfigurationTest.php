<?php

declare(strict_types=1);

use Maestro\Workflow\Definition\Config\QueueConfiguration;

describe('QueueConfiguration', static function (): void {
    describe('create', static function (): void {
        it('creates configuration with all values', function (): void {
            $queueConfiguration = QueueConfiguration::create('high-priority', 'redis', 30);

            expect($queueConfiguration->queue)->toBe('high-priority');
            expect($queueConfiguration->connection)->toBe('redis');
            expect($queueConfiguration->delaySeconds)->toBe(30);
        });

        it('creates configuration with null values', function (): void {
            $queueConfiguration = QueueConfiguration::create();

            expect($queueConfiguration->queue)->toBeNull();
            expect($queueConfiguration->connection)->toBeNull();
            expect($queueConfiguration->delaySeconds)->toBe(0);
        });

        it('enforces non-negative delay', function (): void {
            $queueConfiguration = QueueConfiguration::create(delaySeconds: -10);

            expect($queueConfiguration->delaySeconds)->toBe(0);
        });
    });

    describe('default', static function (): void {
        it('creates configuration with default values', function (): void {
            $queueConfiguration = QueueConfiguration::default();

            expect($queueConfiguration->queue)->toBeNull();
            expect($queueConfiguration->connection)->toBeNull();
            expect($queueConfiguration->delaySeconds)->toBe(0);
        });
    });

    describe('onQueue', static function (): void {
        it('creates configuration with queue only', function (): void {
            $queueConfiguration = QueueConfiguration::onQueue('emails');

            expect($queueConfiguration->queue)->toBe('emails');
            expect($queueConfiguration->connection)->toBeNull();
        });
    });

    describe('onConnection', static function (): void {
        it('creates configuration with connection only', function (): void {
            $queueConfiguration = QueueConfiguration::onConnection('sqs');

            expect($queueConfiguration->queue)->toBeNull();
            expect($queueConfiguration->connection)->toBe('sqs');
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
            $queueConfiguration = QueueConfiguration::default();
            $updated = $queueConfiguration->withQueue('high');

            expect($updated->queue)->toBe('high');
            expect($queueConfiguration->queue)->toBeNull();
        });

        it('returns new instance with connection', function (): void {
            $queueConfiguration = QueueConfiguration::default();
            $updated = $queueConfiguration->withConnection('redis');

            expect($updated->connection)->toBe('redis');
            expect($queueConfiguration->connection)->toBeNull();
        });

        it('returns new instance with delay', function (): void {
            $queueConfiguration = QueueConfiguration::default();
            $updated = $queueConfiguration->withDelay(60);

            expect($updated->delaySeconds)->toBe(60);
            expect($queueConfiguration->delaySeconds)->toBe(0);
        });
    });

    describe('equals', static function (): void {
        it('returns true for equal configurations', function (): void {
            $queueConfiguration = QueueConfiguration::create('high', 'redis', 30);
            $b = QueueConfiguration::create('high', 'redis', 30);

            expect($queueConfiguration->equals($b))->toBeTrue();
        });

        it('returns false for different configurations', function (): void {
            $queueConfiguration = QueueConfiguration::create('high', 'redis', 30);
            $b = QueueConfiguration::create('low', 'redis', 30);

            expect($queueConfiguration->equals($b))->toBeFalse();
        });
    });
});
