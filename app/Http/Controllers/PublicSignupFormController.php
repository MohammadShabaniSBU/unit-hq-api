<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SignupForm;
use App\Models\SubscriptionList;
use App\Support\Marketing\SignupSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public form token. No Sanctum. The unguessable public_token is the credential.
 */
class PublicSignupFormController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $form = $this->activeForm($token);
        if ($form === null) {
            return $this->error('Signup form not found.', statusCode: 404);
        }

        $locale = SignupSubmission::locale($request->query('locale') ?? $request->getPreferredLanguage(['en', 'es', 'fr']));
        $text = SignupSubmission::consentText($form->consent_text_key, $locale);
        if ($text === null) {
            return $this->error('Signup form not found.', statusCode: 404);
        }

        $lists = SubscriptionList::query()
            ->whereIn('id', $form->subscription_list_ids)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get()
            ->map(function (SubscriptionList $list) use ($locale): array {
                $labels = $list->labels;

                return [
                    'id' => $list->id,
                    'label' => $labels[$locale] ?? $labels['en'] ?? $list->key,
                ];
            })
            ->all();

        return $this->success([
            'name' => $form->name,
            'channel' => $form->channel,
            'fields' => $form->fields,
            'lists' => $lists,
            'consent_text' => [
                'key' => $text->key,
                'version' => $text->version,
                'locale' => $text->locale,
                'body' => $text->body,
            ],
            'double_opt_in' => $form->double_opt_in,
            'success_redirect_url' => $form->success_redirect_url,
        ], 'Signup form retrieved.');
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $form = $this->activeForm($token);
        if ($form === null) {
            return $this->error('Signup form not found.', statusCode: 404);
        }

        SignupSubmission::submit($form, $request);

        return $this->success(['status' => 'accepted'], 'Signup accepted.');
    }

    public function confirm(string $token): JsonResponse
    {
        $status = SignupSubmission::confirm($token);

        if ($status === 'invalid') {
            return $this->error('This confirmation link is not valid.', statusCode: 404);
        }

        $message = $status === 'expired'
            ? 'Please sign up again.'
            : 'Subscription confirmed.';

        return $this->success(['status' => $status], $message);
    }

    private function activeForm(string $token): ?SignupForm
    {
        return SignupForm::query()
            ->where('public_token', $token)
            ->whereNull('archived_at')
            ->first();
    }
}
