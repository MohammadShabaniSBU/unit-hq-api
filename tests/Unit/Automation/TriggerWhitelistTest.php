<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Enums\AutomationNodeType;
use App\Support\Automation\TriggerConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TriggerWhitelistTest extends TestCase
{
    public function test_unknown_fields_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unknown trigger field [not_a_real_column]');

        TriggerConfigValidator::assertValid([
            [
                'node_key' => 't1',
                'type' => AutomationNodeType::ObjectCreated->value,
                'config' => [
                    'objectType' => 'delinquency',
                    'filters' => [
                        'logic' => 'and',
                        'conditions' => [
                            [
                                'field' => 'not_a_real_column',
                                'operator' => 'equals',
                                'value' => 'x',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_known_billing_fields_accepted(): void
    {
        TriggerConfigValidator::assertValid([
            [
                'node_key' => 't1',
                'type' => AutomationNodeType::ObjectCreated->value,
                'config' => [
                    'objectType' => 'delinquency',
                    'filters' => [
                        'logic' => 'and',
                        'conditions' => [
                            [
                                'field' => 'days_overdue',
                                'operator' => 'gte',
                                'value' => 1,
                            ],
                            [
                                'field' => 'overdue_base',
                                'operator' => 'gt',
                                'value' => '0.00',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'node_key' => 't2',
                'type' => AutomationNodeType::ObjectUpdated->value,
                'config' => [
                    'objectType' => 'autopay_attempt',
                    'property' => 'status',
                    'conditions' => [
                        ['operator' => 'equals', 'value' => 'failed'],
                    ],
                ],
            ],
            [
                'node_key' => 't3',
                'type' => AutomationNodeType::ObjectCreated->value,
                'config' => [
                    'objectType' => 'payment',
                    'filters' => [
                        'logic' => 'and',
                        'conditions' => [
                            [
                                'field' => 'amount',
                                'operator' => 'gt',
                                'value' => '0',
                            ],
                            [
                                'field' => 'method',
                                'operator' => 'equals',
                                'value' => 'cash',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_payment_object_updated_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('payment supports object_created only');

        TriggerConfigValidator::assertValid([
            [
                'node_key' => 't1',
                'type' => AutomationNodeType::ObjectUpdated->value,
                'config' => [
                    'objectType' => 'payment',
                    'property' => 'amount',
                    'conditions' => [],
                ],
            ],
        ]);
    }
}
