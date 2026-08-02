<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Message;
use App\Support\Communications\Providers\AircallAdapter;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fresh-fetch recording/voicemail through our API — never persist signed URLs.
 */
final class CallRecordingProxy
{
    /**
     * @return StreamedResponse|array{error: string, status: int}
     */
    public static function stream(Message $message): StreamedResponse|array
    {
        if ($message->provider !== Provider::Aircall) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        if (($ref['recording_redacted'] ?? false) === true) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $callId = $message->provider_message_id;
        if ($callId === null || $callId === '') {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $account = $message->communicationAccount
            ?? CallDialer::activeAircallAccount();
        if ($account === null) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $credentials = CredentialMasker::readSafely($account, 'credentials');
        if (! is_array($credentials)) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $media = AircallAdapter::make($credentials)->fetchCallMediaUrl((string) $callId);
        if ($media === null) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        /** @var HttpResponse $upstream */
        $upstream = Http::timeout(30)
            ->withOptions(['stream' => true])
            ->get($media['url']);

        if ($upstream->failed()) {
            return ['error' => 'Recording unavailable', 'status' => 404];
        }

        $contentType = $upstream->header('Content-Type') ?: 'audio/mpeg';
        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);
                if (function_exists('flush')) {
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="call-'.$message->id.'.mp3"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function hasRecording(Message $message): bool
    {
        $ref = is_array($message->source_ref) ? $message->source_ref : [];
        if (($ref['recording_redacted'] ?? false) === true) {
            return false;
        }

        $recording = $ref['recording_url'] ?? null;
        $voicemail = $ref['voicemail_url'] ?? null;

        return (is_string($recording) && $recording !== '')
            || (is_string($voicemail) && $voicemail !== '');
    }

    /**
     * Client-facing source_ref: strip signed media URLs (playback is via proxy).
     *
     * @param  array<string, mixed>|null  $ref
     * @return array<string, mixed>|null
     */
    public static function sanitizeSourceRef(?array $ref): ?array
    {
        if ($ref === null) {
            return null;
        }

        unset($ref['recording_url'], $ref['voicemail_url']);

        if (isset($ref['call']) && is_array($ref['call'])) {
            unset($ref['call']['recording'], $ref['call']['voicemail'], $ref['call']['recording_short_url'], $ref['call']['voicemail_short_url']);
        }

        return $ref;
    }
}
