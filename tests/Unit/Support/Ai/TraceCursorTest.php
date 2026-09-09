<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Trace\TraceCursor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TraceCursorTest extends TestCase
{
    #[Test]
    public function resync_advances_past_a_higher_table_max(): void
    {
        $cursor = new TraceCursor(1, 1, 'claude-sonnet-4-6', 'pv', 1, 5);

        $cursor->resync(8);

        $this->assertSame(9, $cursor->allocateSeq());
    }

    #[Test]
    public function resync_does_not_move_the_cursor_backwards(): void
    {
        $cursor = new TraceCursor(1, 1, 'claude-sonnet-4-6', 'pv', 1, 8);

        $cursor->resync(3);

        $this->assertSame(9, $cursor->allocateSeq());
    }
}
