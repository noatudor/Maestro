<?php

declare(strict_types=1);

use Maestro\Workflow\ValueObjects\BranchDecisionId;

describe('BranchDecisionId', function () {
    it('generates a new id', function () {
        $id = BranchDecisionId::generate();

        expect($id)->toBeInstanceOf(BranchDecisionId::class)
            ->and($id->value)->toBeValidUuid();
    });

    it('creates from string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = BranchDecisionId::fromString($value);

        expect($id->value)->toBe($value);
    });

    it('compares equality correctly', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id1 = BranchDecisionId::fromString($value);
        $id2 = BranchDecisionId::fromString($value);
        $id3 = BranchDecisionId::generate();

        expect($id1->equals($id2))->toBeTrue()
            ->and($id1->equals($id3))->toBeFalse();
    });

    it('converts to string', function () {
        $value = '01923456-7890-7abc-def0-123456789abc';

        $id = BranchDecisionId::fromString($value);

        expect($id->toString())->toBe($value);
    });
});
