<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\Guards\CompositeGuardrailPipeline;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class CompositeGuardrailPipelineTest extends TestCase
{
    #[Test]
    public function guard_sequence_is_pinned(): void
    {
        $this->assertSame([
            'duplicate_draft',
            'grounding',
            'voice_number',
            'forbidden_claim',
            'disclosure',
            'channel',
        ], CompositeGuardrailPipeline::GUARD_SEQUENCE);

        $pipeline = app(CompositeGuardrailPipeline::class);
        $keys = array_map(
            static fn (object $guard): string => $guard->key(),
            (new ReflectionClass($pipeline))->getProperty('guards')->getValue($pipeline),
        );

        $this->assertSame(CompositeGuardrailPipeline::GUARD_SEQUENCE, $keys);
    }
}
