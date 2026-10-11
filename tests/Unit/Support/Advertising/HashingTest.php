<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Advertising;

use App\Support\Advertising\Hashing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HashingTest extends TestCase
{
    #[Test]
    public function email_is_trimmed_and_lowercased_before_sha256(): void
    {
        $normalised = Hashing::normaliseEmail('  Ada@Example.com ');

        $this->assertSame('ada@example.com', $normalised);
        $this->assertSame(hash('sha256', 'ada@example.com'), Hashing::sha256($normalised));
    }

    #[Test]
    public function phone_keeps_digits_only(): void
    {
        $this->assertSame('34600111222', Hashing::normalisePhoneE164('+34 600 111 222'));
        $this->assertSame(hash('sha256', '34600111222'), Hashing::sha256(Hashing::normalisePhoneE164('+34 600 111 222')));
    }
}
