<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentRuleException;
use App\Support\Marketing\PreferenceCenter;
use App\Support\Marketing\PreferenceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public preference center. The token is the credential. GET never writes.
 */
class PreferenceCenterController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $claims = PreferenceToken::resolve($token);
        if ($claims === null) {
            return $this->error('Invalid preference token.', statusCode: 404);
        }

        return $this->success(PreferenceCenter::payload($claims), 'Preferences retrieved.');
    }

    public function update(Request $request, string $token): JsonResponse
    {
        $claims = PreferenceToken::resolve($token);
        if ($claims === null) {
            return $this->error('Invalid preference token.', statusCode: 404);
        }

        $validated = $request->validate([
            'changes' => ['sometimes', 'array'],
            'changes.*.list_id' => ['required', 'integer'],
            'changes.*.channel' => ['required', 'string', Rule::in([
                Channel::Email->value,
                Channel::Sms->value,
                Channel::Whatsapp->value,
            ])],
            'changes.*.subscribed' => ['required', 'boolean'],
            'unsubscribe_all' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{list_id: int, channel: string, subscribed: bool}> $changes */
        $changes = array_map(static function (array $change): array {
            return [
                'list_id' => (int) $change['list_id'],
                'channel' => (string) $change['channel'],
                'subscribed' => (bool) $change['subscribed'],
            ];
        }, $validated['changes'] ?? []);

        $evidence = PreferenceCenter::evidence($request, $claims);

        try {
            if ($request->boolean('unsubscribe_all')) {
                PreferenceCenter::unsubscribeAll($claims, $evidence);
            } else {
                PreferenceCenter::apply($claims, $changes, $evidence);
            }
        } catch (ConsentRuleException $exception) {
            return $this->error(__('errors.consent.'.$exception->errorKey), statusCode: 422);
        }

        return $this->success(PreferenceCenter::payload($claims), 'Preferences updated.');
    }
}
