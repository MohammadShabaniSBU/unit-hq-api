<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateContact;
use App\Ai\Tools\CreateContactAddress;
use App\Ai\Tools\CreateContactChannel;
use App\Ai\Tools\CreateDeal;
use App\Ai\Tools\CreateNote;
use App\Ai\Tools\CreateOffer;
use App\Ai\Tools\CreateReservation;
use App\Ai\Tools\CreateTask;
use App\Ai\Tools\FetchObjects;
use App\Ai\Tools\SetCustomProperty;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every tool's schema() is only ever exercised by the real laravel/ai gateway
 * classes (via `new JsonSchemaTypeFactory`), never by our own tests — which
 * is exactly how a JsonSchema::union() misuse in SetCustomProperty shipped
 * undetected and only surfaced live. This calls schema() the same way the
 * SDK does, for every tool, so a bad JsonSchema builder call fails here
 * instead of in production.
 */
class AllToolSchemasTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<class-string<Tool>> */
    public static function toolClasses(): array
    {
        return [
            CreateContact::class,
            CreateContactAddress::class,
            CreateContactChannel::class,
            CreateDeal::class,
            CreateNote::class,
            CreateOffer::class,
            CreateReservation::class,
            CreateTask::class,
            FetchObjects::class,
            SetCustomProperty::class,
        ];
    }

    #[Test]
    public function every_tool_schema_builds_without_error(): void
    {
        $employee = Employee::factory()->create();

        foreach (self::toolClasses() as $class) {
            $tool = new $class($employee);
            $schema = $tool->schema(new JsonSchemaTypeFactory);

            $this->assertNotEmpty($schema, "{$class}::schema() returned an empty array.");

            foreach ($schema as $field => $type) {
                // toArray() is what the SDK actually serializes to the provider —
                // exercising it here catches builder misuse the same way schema()
                // itself does, for every field on every tool.
                $type->toArray();
                $this->addToAssertionCount(1);
                unset($field);
            }
        }
    }
}
