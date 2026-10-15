<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\EmailBlockDocument;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class EmailBlockSchemaParityTest extends TestCase
{
    public function test_json_schema_matches_php_validator(): void
    {
        $schema = json_decode((string) file_get_contents(
            base_path('resources/schemas/email-block-document.v2.json'),
        ));
        $this->assertInstanceOf(\stdClass::class, $schema);
        $validator = new Validator;

        $valid = glob(base_path('tests/Fixtures/email-blocks/v2/valid/*.json'));
        $this->assertNotFalse($valid);
        $this->assertNotEmpty($valid);
        foreach ($valid as $file) {
            $raw = (string) file_get_contents($file);
            $result = $validator->validate(json_decode($raw), $schema);
            $this->assertTrue($result->isValid(), $file.' '.$this->schemaError($result));
            EmailBlockDocument::validate(json_decode($raw, true));
        }

        $invalid = glob(base_path('tests/Fixtures/email-blocks/v2/invalid/*.json'));
        $this->assertNotFalse($invalid);
        $this->assertNotEmpty($invalid);
        foreach ($invalid as $file) {
            $name = basename($file, '.json');
            $raw = (string) file_get_contents($file);
            $result = $validator->validate(json_decode($raw), $schema);

            if ($name === 'blocks_duplicate_id') {
                $this->assertTrue(
                    $result->isValid(),
                    'JSON Schema cannot express ids unique across the tree',
                );
            } else {
                $this->assertFalse($result->isValid(), $file.' was accepted by the schema');
            }

            try {
                EmailBlockDocument::validate(json_decode($raw, true));
                $this->fail($name.' was accepted by the PHP validator');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function schemaError(ValidationResult $result): string
    {
        $error = $result->error();
        if ($error === null) {
            return '';
        }

        return json_encode((new ErrorFormatter)->format($error), JSON_UNESCAPED_SLASHES) ?: '';
    }
}
