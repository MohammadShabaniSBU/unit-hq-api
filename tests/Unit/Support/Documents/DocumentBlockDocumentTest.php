<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Documents;

use App\Support\Documents\DocumentBlockDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentBlockDocumentTest extends TestCase
{
    public function test_rejects_email_only_types(): void
    {
        foreach (['info_rows', 'list', 'callout', 'logo_header', 'footer_social'] as $type) {
            try {
                DocumentBlockDocument::validate([
                    'version' => 1,
                    'blocks' => [[
                        'id' => 'b1',
                        'type' => $type,
                        'params' => [],
                    ]],
                ]);
                $this->fail($type.' was accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('blocks.blocks.0.type', $exception->errors());
            }
        }
    }
}
