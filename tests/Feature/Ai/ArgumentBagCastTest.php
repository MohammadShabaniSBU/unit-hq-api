<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Http\Resources\AgentToolInvocationResource;
use App\Models\AgentToolInvocation;
use App\Support\Ai\Tools\ArgumentBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArgumentBagCastTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function empty_argument_bag_persists_and_serialises_as_a_json_object(): void
    {
        $invocation = AgentToolInvocation::factory()->create([
            'arguments' => [],
        ]);

        $raw = DB::table('agent_tool_invocations')->where('id', $invocation->id)->value('arguments');
        $this->assertSame('{}', $raw);
        $this->assertSame([], $invocation->fresh()->arguments);

        $resource = (new AgentToolInvocationResource($invocation->fresh()))->toArray(Request::create('/'));
        $this->assertSame('{}', ArgumentBag::encode(
            ArgumentBag::normalise($resource['arguments'] instanceof \stdClass ? [] : $resource['arguments']),
        ));
        $this->assertSame('{}', json_encode($resource['arguments']));
    }
}
