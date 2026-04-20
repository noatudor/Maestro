<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\ResolutionDecisionId;

describe('ResolutionDecisionId', function () {
    it('generates a new id', function () {
        $id = ResolutionDecisionId::generate();

        expect($id)->toBeInstanceOf(ResolutionDecisionId::class)
            ->and($id->value)->toBeValidUuid();
    });

    it('creates from string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = ResolutionDecisionId::fromString($value);

        expect($id->value)->toBe($value);
    });

    it('compares equality correctly', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id1 = ResolutionDecisionId::fromString($value);
        $id2 = ResolutionDecisionId::fromString($value);
        $id3 = ResolutionDecisionId::generate();

        expect($id1->equals($id2))->toBeTrue()
            ->and($id1->equals($id3))->toBeFalse();
    });

    it('converts to string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = ResolutionDecisionId::fromString($value);

        expect($id->toString())->toBe($value);
    });
});
