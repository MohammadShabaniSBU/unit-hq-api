<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Enums\VoiceBridgeProtocol;
use App\Support\Ai\VoiceBridgeInboundTurn;
use App\Support\Ai\VoiceBridgeWireFormat;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceBridgeWireFormatTest extends TestCase
{
    #[Test]
    public function http_extracts_snake_and_camel_fields(): void
    {
        $inbound = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turnId' => 'turn-camel',
            'sessionId' => 'session-camel',
            'from' => '+34911000001',
        ]));

        $this->assertSame(VoiceBridgeProtocol::Http, $inbound->protocol);
        $this->assertSame('Do you have a small unit?', $inbound->query);
        $this->assertSame('turn-camel', $inbound->turnId);
        $this->assertSame('session-camel', $inbound->sessionId);
        $this->assertSame('+34911000001', $inbound->callerNumber);
        $this->assertNull($inbound->jsonRpcId);
    }

    #[Test]
    public function http_missing_session_stays_null(): void
    {
        $inbound = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turn_id' => 'turn-1',
        ]));

        $this->assertSame(VoiceBridgeProtocol::Http, $inbound->protocol);
        $this->assertNull($inbound->sessionId);
    }

    #[Test]
    public function a2a_extracts_text_message_id_and_context_id(): void
    {
        $inbound = VoiceBridgeWireFormat::parse($this->a2aRequest([
            'messageId' => 'msg-1',
            'contextId' => 'ctx-1',
            'parts' => [
                ['kind' => 'text', 'text' => 'Do you have a small unit?'],
            ],
            'metadata' => ['caller_number' => '+34911000001'],
        ], 'req-1'));

        $this->assertSame(VoiceBridgeProtocol::A2a, $inbound->protocol);
        $this->assertSame('Do you have a small unit?', $inbound->query);
        $this->assertSame('msg-1', $inbound->turnId);
        $this->assertSame('ctx-1', $inbound->sessionId);
        $this->assertSame('+34911000001', $inbound->callerNumber);
        $this->assertNull($inbound->callerUtterance);
        $this->assertSame('req-1', $inbound->jsonRpcId);
    }

    #[Test]
    public function a2a_accepts_snake_case_and_type_parts(): void
    {
        $inbound = VoiceBridgeWireFormat::parse($this->a2aRequest([
            'message_id' => 'msg-snake',
            'context_id' => 'ctx-snake',
            'parts' => [
                ['type' => 'text', 'text' => 'First.'],
                ['type' => 'text', 'text' => 'Second.'],
            ],
        ]));

        $this->assertSame('First. Second.', $inbound->query);
        $this->assertSame('msg-snake', $inbound->turnId);
        $this->assertSame('ctx-snake', $inbound->sessionId);
    }

    #[Test]
    public function a2a_without_context_id_generates_a_uuid(): void
    {
        $inbound = VoiceBridgeWireFormat::parse($this->a2aRequest([
            'messageId' => 'msg-new',
            'parts' => [
                ['kind' => 'text', 'text' => 'Hello'],
            ],
        ]));

        $this->assertNotNull($inbound->sessionId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $inbound->sessionId,
        );
    }

    #[Test]
    public function a2a_missing_text_parts_yields_a_null_query(): void
    {
        $inbound = VoiceBridgeWireFormat::parse($this->a2aRequest([
            'messageId' => 'msg-empty',
            'parts' => [
                ['kind' => 'data', 'data' => ['x' => 1]],
            ],
        ]));

        $this->assertNull($inbound->query);
        $this->assertSame('msg-empty', $inbound->turnId);
        $this->assertSame(VoiceBridgeProtocol::A2a, $inbound->protocol);
    }

    #[Test]
    public function http_extracts_caller_utterance_snake_and_camel(): void
    {
        $snake = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turn_id' => 'turn-1',
            'session_id' => 'session-1',
            'caller_utterance' => 'so if I wanted the ten square meter one, what would that run me',
        ]));

        $this->assertSame(
            'so if I wanted the ten square meter one, what would that run me',
            $snake->callerUtterance,
        );

        $camel = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turn_id' => 'turn-1',
            'session_id' => 'session-1',
            'callerUtterance' => 'so if I wanted the ten square meter one, what would that run me',
        ]));

        $this->assertSame(
            'so if I wanted the ten square meter one, what would that run me',
            $camel->callerUtterance,
        );
    }

    #[Test]
    public function http_missing_caller_utterance_stays_null(): void
    {
        $inbound = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turn_id' => 'turn-1',
            'session_id' => 'session-1',
        ]));

        $this->assertNull($inbound->callerUtterance);
    }

    #[Test]
    public function http_empty_caller_utterance_stays_null(): void
    {
        $inbound = VoiceBridgeWireFormat::parse(Request::create('/bridge', 'POST', [
            'query' => 'Do you have a small unit?',
            'turn_id' => 'turn-1',
            'session_id' => 'session-1',
            'caller_utterance' => '   ',
        ]));

        $this->assertNull($inbound->callerUtterance);
    }

    #[Test]
    public function respond_http_is_passthrough(): void
    {
        $inbound = new VoiceBridgeInboundTurn(
            VoiceBridgeProtocol::Http,
            'q',
            't',
            's',
            null,
            null,
            null,
        );

        $body = ['text' => 'We have units available.', 'transfer' => false];

        $this->assertSame($body, VoiceBridgeWireFormat::respond($inbound, $body));
    }

    #[Test]
    public function respond_a2a_wraps_a_message_with_context_id_and_transfer_metadata(): void
    {
        $inbound = new VoiceBridgeInboundTurn(
            VoiceBridgeProtocol::A2a,
            'q',
            't',
            'ctx-1',
            null,
            null,
            'req-9',
        );

        $response = VoiceBridgeWireFormat::respond($inbound, [
            'text' => 'Let me put you through to someone who can help.',
            'transfer' => true,
            'destination' => 'main_line',
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame('req-9', $response['id']);
        $this->assertSame('message', $response['result']['kind']);
        $this->assertSame('agent', $response['result']['role']);
        $this->assertSame('ctx-1', $response['result']['contextId']);
        $this->assertSame('Let me put you through to someone who can help.', $response['result']['parts'][0]['text']);
        $this->assertTrue($response['result']['metadata']['transfer']);
        $this->assertSame('main_line', $response['result']['metadata']['destination']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $response['result']['messageId'],
        );
        $this->assertArrayNotHasKey('error', $response);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function a2aRequest(array $message, string|int|null $id = 'req-1'): Request
    {
        return Request::create('/bridge', 'POST', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'message/send',
            'params' => [
                'message' => array_merge([
                    'role' => 'user',
                ], $message),
            ],
        ]);
    }
}
