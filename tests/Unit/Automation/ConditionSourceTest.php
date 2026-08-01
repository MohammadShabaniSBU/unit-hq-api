<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Enums\ConditionSource;
use App\Models\Contact;
use App\Support\Automation\ConditionContext;
use App\Support\Automation\ConditionEvaluator;
use App\Support\Automation\CustomAttributeBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule 5: same tree, different source, different results after mutation.
 */
class ConditionSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_vs_live(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Snapshot',
            'last_name' => 'Name',
            'email' => 'source-'.uniqid().'@example.com',
        ]);

        $tree = [
            'logic' => 'and',
            'conditions' => [
                [
                    'field' => 'first_name',
                    'operator' => 'equals',
                    'value' => 'Snapshot',
                ],
            ],
        ];

        $snapshotValues = array_merge(
            array_filter(
                $contact->attributesToArray(),
                fn (mixed $v) => is_scalar($v) || $v === null,
            ),
            CustomAttributeBag::forEntity('contact', $contact->id),
        );

        $snapshotCtx = new ConditionContext(
            source: ConditionSource::Snapshot,
            entityType: 'contact',
        );
        $liveCtx = new ConditionContext(
            source: ConditionSource::Live,
            entityType: 'contact',
        );

        $this->assertTrue(
            ConditionEvaluator::evaluate($tree, $snapshotValues, $snapshotCtx)->passed,
        );
        $this->assertTrue(
            ConditionEvaluator::evaluate($tree, $snapshotValues, $liveCtx)->passed,
        );

        // Mutate live subject; snapshot bag stays frozen.
        $contact->update(['first_name' => 'LiveNow']);

        $liveValues = array_merge(
            array_filter(
                $contact->fresh()->attributesToArray(),
                fn (mixed $v) => is_scalar($v) || $v === null,
            ),
            CustomAttributeBag::forEntity('contact', $contact->id),
        );

        $snapAfter = ConditionEvaluator::evaluate($tree, $snapshotValues, $snapshotCtx);
        $liveAfter = ConditionEvaluator::evaluate($tree, $liveValues, $liveCtx);

        $this->assertTrue($snapAfter->passed, 'snapshot still sees Snapshot');
        $this->assertFalse($liveAfter->passed, 'live sees LiveNow');
        $this->assertSame(ConditionSource::Snapshot, $snapshotCtx->source);
        $this->assertSame(ConditionSource::Live, $liveCtx->source);
    }
}
