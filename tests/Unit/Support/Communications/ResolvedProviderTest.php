<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Models\CommunicationAccount;
use App\Support\Communications\Channel;
use App\Support\Communications\Contracts\SendsEmail;
use App\Support\Communications\Exceptions\UnsupportedCapability;
use App\Support\Communications\Provider;
use App\Support\Communications\Providers\TwilioSmsAdapter;
use App\Support\Communications\ResolvedProvider;
use Tests\TestCase;

class ResolvedProviderTest extends TestCase
{
    public function test_require_throws_unsupported_capability(): void
    {
        $account = new CommunicationAccount([
            'channel' => Channel::Email,
            'provider' => Provider::Twilio,
        ]);

        $resolved = new ResolvedProvider(
            $account,
            TwilioSmsAdapter::make(['account_sid' => 'ACxxx', 'auth_token' => 'tok'])
        );

        $this->expectException(UnsupportedCapability::class);
        $resolved->require(SendsEmail::class, 'sending email');
    }
}
