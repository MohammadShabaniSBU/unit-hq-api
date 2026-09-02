<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Tools\ArgumentBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArgumentBagTest extends TestCase
{
    #[Test]
    public function empty_stdclass_normalises_to_an_array(): void
    {
        $this->assertSame([], ArgumentBag::normalise(new \stdClass));
    }

    #[Test]
    public function stdclass_properties_become_associative_keys(): void
    {
        $raw = new \stdClass;
        $raw->site_id = 1;

        $this->assertSame(['site_id' => 1], ArgumentBag::normalise($raw));
    }

    #[Test]
    public function json_ready_empty_bag_round_trips_through_normalise(): void
    {
        $ready = ArgumentBag::jsonReady([]);

        $this->assertInstanceOf(\stdClass::class, $ready);
        $this->assertSame([], ArgumentBag::normalise($ready));
    }
}
