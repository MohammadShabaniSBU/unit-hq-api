<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class SetSubject extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Set the email subject. Prefer under 60 characters. Maximum 120. This does not change the document.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $subject = $this->stringValue($request, 'subject');
        if ($subject === null) {
            return $this->missing('subject', 'subject is required.');
        }

        return $this->encode($this->workspace()->setSubject($subject));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()
                ->description('Email subject, 120 characters or fewer.')
                ->min(1)
                ->max(120)
                ->required(),
        ];
    }

    protected function fields(): array
    {
        return ['subject'];
    }
}
