<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\VoiceBridgeProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Vocal Bridge inbound/outbound wire formats. Detects the flat HTTP contract
 * vs A2A JSON-RPC (`message/send`) from the body; both map onto the same
 * VoiceBridgeInboundTurn so VoiceBridgeTurn stays protocol-agnostic.
 *
 * A2A field names follow the Agent2Agent spec (camelCase, with snake_case
 * accepted on inbound). Transfer rides in `message.metadata` because the
 * A2A Message has no first-class transfer field. A live Vocal Bridge A2A
 * client has not confirmed those metadata keys.
 */
final class VoiceBridgeWireFormat
{
    public static function parse(Request $request): VoiceBridgeInboundTurn
    {
        $body = $request->all();

        if (is_array($body) && array_key_exists('jsonrpc', $body)) {
            return self::parseA2a($body);
        }

        return self::parseHttp($request);
    }

    /**
     * @param  array{text: string, transfer: bool, destination?: string}  $body
     * @return array<string, mixed>
     */
    public static function respond(VoiceBridgeInboundTurn $inbound, array $body): array
    {
        if ($inbound->protocol === VoiceBridgeProtocol::Http) {
            return $body;
        }

        $metadata = [
            'transfer' => (bool) ($body['transfer'] ?? false),
        ];
        $destination = $body['destination'] ?? null;
        if (($body['transfer'] ?? false) === true && is_string($destination) && $destination !== '') {
            $metadata['destination'] = $destination;
        }

        $message = [
            'kind' => 'message',
            'messageId' => (string) Str::uuid(),
            'role' => 'agent',
            'parts' => [
                [
                    'kind' => 'text',
                    'text' => (string) ($body['text'] ?? ''),
                ],
            ],
            'metadata' => $metadata,
        ];

        if (is_string($inbound->sessionId) && $inbound->sessionId !== '') {
            $message['contextId'] = $inbound->sessionId;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $inbound->jsonRpcId,
            'result' => $message,
        ];
    }

    private static function parseHttp(Request $request): VoiceBridgeInboundTurn
    {
        return new VoiceBridgeInboundTurn(
            VoiceBridgeProtocol::Http,
            self::string($request->input('query')),
            self::string($request->input('turn_id')) ?? self::string($request->input('turnId')),
            self::string($request->input('session_id')) ?? self::string($request->input('sessionId')),
            self::string($request->input('caller_number')) ?? self::string($request->input('from')),
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function parseA2a(array $body): VoiceBridgeInboundTurn
    {
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $message = is_array($params['message'] ?? null) ? $params['message'] : [];
        $metadata = self::mergedMetadata($params, $message);

        $sessionId = self::stringFrom($message, 'contextId', 'context_id');
        if ($sessionId === null) {
            $sessionId = (string) Str::uuid();
        }

        return new VoiceBridgeInboundTurn(
            VoiceBridgeProtocol::A2a,
            self::textFromParts($message['parts'] ?? null),
            self::stringFrom($message, 'messageId', 'message_id'),
            $sessionId,
            self::callerFromMetadata($metadata),
            self::jsonRpcId($body['id'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private static function mergedMetadata(array $params, array $message): array
    {
        $merged = [];
        if (is_array($params['metadata'] ?? null)) {
            $merged = $params['metadata'];
        }
        if (is_array($message['metadata'] ?? null)) {
            $merged = array_merge($merged, $message['metadata']);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function callerFromMetadata(array $metadata): ?string
    {
        foreach (['caller_number', 'callerNumber', 'from', 'caller', 'phone'] as $key) {
            $value = self::string($metadata[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function stringFrom(array $source, string $camel, string $snake): ?string
    {
        return self::string($source[$camel] ?? null) ?? self::string($source[$snake] ?? null);
    }

    private static function textFromParts(mixed $parts): ?string
    {
        if (! is_array($parts)) {
            return null;
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $kind = $part['kind'] ?? $part['type'] ?? null;
            if ($kind !== 'text') {
                continue;
            }

            $text = self::string($part['text'] ?? null);
            if ($text !== null) {
                $chunks[] = $text;
            }
        }

        if ($chunks === []) {
            return null;
        }

        return implode(' ', $chunks);
    }

    private static function jsonRpcId(mixed $id): string|int|null
    {
        if (is_string($id) || is_int($id)) {
            return $id;
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
