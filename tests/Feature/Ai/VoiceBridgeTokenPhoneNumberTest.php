<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\VoiceBridgeToken;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeTokenPhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function two_tokens_cannot_share_the_same_phone_number(): void
    {
        VoiceBridgeToken::factory()->create([
            'phone_number' => '+15551234567',
        ]);

        $this->expectException(QueryException::class);

        VoiceBridgeToken::factory()->create([
            'phone_number' => '+15551234567',
        ]);
    }
};
