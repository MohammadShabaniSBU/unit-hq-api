<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\EmailBlockDocument;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailBlockDocumentV2Test extends TestCase
{
    public function test_valid_fixtures_pass(): void
    {
        $files = glob(base_path('tests/Fixtures/email-blocks/v2/valid/*.json'));
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $validated = EmailBlockDocument::validate(self::decode($file));
            $this->assertSame(EmailBlockDocument::VERSION_2, $validated['version'], $file);
        }
    }

    /**
     * @param  array<string, string>  $replace
     */
    #[DataProvider('invalidFixtures')]
    public function test_invalid_fixtures_fail_with_key(string $key, string $path, array $replace = []): void
    {
        $this->assertInvalid($key, $path, $replace);
    }

    public function test_raw_html_only_in_full_width(): void
    {
        $this->assertInvalid(
            'blocks_raw_html_in_columns',
            'blocks.sections.0.columns.0.blocks.0.type',
        );
    }

    public function test_ids_unique_across_document(): void
    {
        $this->assertInvalid(
            'blocks_duplicate_id',
            'blocks.sections.0.columns.1.blocks.0.id',
        );
    }

    /** @return array<string, array{0: string, 1: string, 2?: array<string, string>}> */
    public static function invalidFixtures(): array
    {
        return [
            'blocks_illegal_nesting' => ['blocks_illegal_nesting', 'blocks.sections.0.blocks'],
            'blocks_layout_mismatch' => ['blocks_layout_mismatch', 'blocks.sections.0.columns'],
            'blocks_duplicate_id' => ['blocks_duplicate_id', 'blocks.sections.0.columns.1.blocks.0.id'],
            'blocks_invalid_id' => ['blocks_invalid_id', 'blocks.sections.0.id'],
            'blocks_raw_html_in_columns' => ['blocks_raw_html_in_columns', 'blocks.sections.0.columns.0.blocks.0.type'],
            'blocks_unknown_layout' => ['blocks_unknown_layout', 'blocks.sections.0.layout', ['layout' => '25_75']],
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function assertInvalid(string $key, string $path, array $replace = []): void
    {
        try {
            EmailBlockDocument::validate(self::decode(
                base_path('tests/Fixtures/email-blocks/v2/invalid/'.$key.'.json'),
            ));
            $this->fail($key.' was accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [$path => [__('errors.templates.'.$key, $replace)]],
                $exception->errors(),
            );
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
