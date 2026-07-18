<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemEvent;
use App\Support\RequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemEventTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RequestId::clear();
        parent::tearDown();
    }

    public function test_record_captures_request_id(): void
    {
        $id = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        RequestId::set($id);

        SystemEvent::record('test.event', null, ['foo' => 'bar']);

        $row = SystemEvent::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('test.event', $row->event);
        $this->assertSame($id, $row->request_id);
        $this->assertSame(['foo' => 'bar'], $row->payload);
    }

    public function test_record_inside_transaction_writes_after_commit(): void
    {
        RequestId::set('cccccccc-dddd-eeee-ffff-000000000000');

        DB::transaction(function (): void {
            SystemEvent::record('test.after_commit');
            $this->assertSame(0, SystemEvent::query()->count());
        });

        $this->assertSame(1, SystemEvent::query()->count());
        $this->assertSame('test.after_commit', SystemEvent::query()->value('event'));
    }

    public function test_record_inside_rolled_back_transaction_writes_nothing(): void
    {
        try {
            DB::transaction(function (): void {
                SystemEvent::record('test.rollback');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, SystemEvent::query()->count());
    }

    public function test_maintain_command_deletes_old_rows_on_sqlite(): void
    {
        SystemEvent::query()->create([
            'event' => 'old.event',
            'created_at' => now()->subDays(120),
        ]);
        SystemEvent::query()->create([
            'event' => 'new.event',
            'created_at' => now(),
        ]);

        $this->artisan('system-events:maintain')->assertSuccessful();

        $this->assertSame(1, SystemEvent::query()->count());
        $this->assertSame('new.event', SystemEvent::query()->value('event'));
    }
}
