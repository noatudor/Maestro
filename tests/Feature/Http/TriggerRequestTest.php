<?php

declare(strict_types=1);

use Maestro\Workflow\Http\Requests\TriggerRequest;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('TriggerRequest', function () {
    it('validates trigger_type is required', function () {
        $request = new TriggerRequest();
        $request->setContainer(app());

        $validator = validator([], $request->rules());

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has('trigger_type'))->toBeTrue();
    });

    it('validates trigger_type is string', function () {
        $request = new TriggerRequest();
        $request->setContainer(app());

        $validator = validator(['trigger_type' => 123], $request->rules());

        expect($validator->fails())->toBeTrue();
    });

    it('validates payload is optional array', function () {
        $request = new TriggerRequest();
        $request->setContainer(app());

        $validator = validator(['trigger_type' => 'test'], $request->rules());

        expect($validator->fails())->toBeFalse();
    });

    it('validates payload must be array when provided', function () {
        $request = new TriggerRequest();
        $request->setContainer(app());

        $validator = validator([
            'trigger_type' => 'test',
            'payload' => 'not-an-array',
        ], $request->rules());

        expect($validator->fails())->toBeTrue();
    });

    it('always authorizes', function () {
        $request = new TriggerRequest();

        expect($request->authorize())->toBeTrue();
    });

    it('extracts trigger type', function () {
        $request = TriggerRequest::create('/test', 'POST', ['trigger_type' => 'payment_received']);
        $request->setContainer(app());
        $request->validateResolved();

        expect($request->getTriggerType())->toBe('payment_received');
    });

    it('extracts trigger payload', function () {
        $request = TriggerRequest::create('/test', 'POST', [
            'trigger_type' => 'payment_received',
            'payload' => ['amount' => 100, 'currency' => 'USD'],
        ]);
        $request->setContainer(app());
        $request->validateResolved();

        $payload = $request->getTriggerPayload();

        expect($payload)->toBeInstanceOf(TriggerPayload::class)
            ->and($payload->toArray())->toBe(['amount' => 100, 'currency' => 'USD']);
    });

    it('returns empty payload when not provided', function () {
        $request = TriggerRequest::create('/test', 'POST', ['trigger_type' => 'test']);
        $request->setContainer(app());
        $request->validateResolved();

        $payload = $request->getTriggerPayload();

        expect($payload->toArray())->toBe([]);
    });
});
