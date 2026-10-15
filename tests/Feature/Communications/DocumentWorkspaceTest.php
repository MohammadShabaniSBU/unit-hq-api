<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Templates\Assist\BlockOpApplier;
use App\Support\Templates\Assist\DocumentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function op_and_inverse_round_trip(): void
    {
        foreach ($this->parityCases() as $case) {
            $doc = $case['doc'];
            foreach ($case['ops'] as $op) {
                $forward = BlockOpApplier::apply($doc, $op);
                $back = $forward['doc'];
                foreach ($forward['inverse'] as $inverse) {
                    $back = BlockOpApplier::apply($back, $inverse)['doc'];
                }
                $this->assertEquals($doc, $back, $case['name']);
                $doc = $forward['doc'];
            }
        }

        $turn = $this->makeTurn($this->emptyDocument(), TemplatePurpose::General);
        $result = DocumentWorkspace::forTurn($turn->id)->addSection(0, '100');
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['ids']);

        $turn->refresh();
        $this->assertSame(1, $turn->tool_call_count);
        $this->assertSame(1, $turn->op_summary['sections_added']);
        $this->assertCount(1, $turn->ops);
        $stored = $turn->ops[0];
        $back = $turn->working_document;
        foreach ($stored['inverse'] as $inverse) {
            $back = BlockOpApplier::apply($back, $inverse)['doc'];
        }
        $this->assertEquals($this->emptyDocument(), $back);
    }

    #[Test]
    public function parity_with_panel_fixtures(): void
    {
        foreach ($this->parityCases() as $case) {
            $doc = $case['doc'];
            foreach ($case['ops'] as $op) {
                $doc = BlockOpApplier::apply($doc, $op)['doc'];
            }

            $known = $this->knownIds($case['doc'], $case['ops']);
            $this->assertEquals(
                $this->normalizeIds($case['expect'], $known),
                $this->normalizeIds($doc, $known),
                $case['name'],
            );
        }
    }

    #[Test]
    public function invalid_op_leaves_document(): void
    {
        $document = $this->columnDocument([
            $this->heading('blk_cccccccc', 'Rate'),
        ]);
        $turn = $this->makeTurn($document, TemplatePurpose::General);
        $workspace = DocumentWorkspace::forTurn($turn->id);

        $badLevel = $workspace->apply([
            'op' => 'update_block',
            'block_id' => 'blk_cccccccc',
            'params' => ['level' => 9],
        ]);
        $this->assertFalse($badLevel['ok']);
        $this->assertSame('assist.document_invalid', $badLevel['error_key']);
        $this->assertNotSame('', $badLevel['path']);

        $unknownLayout = $workspace->apply([
            'op' => 'set_section_layout',
            'section_id' => 'sec_aaaaaaaa',
            'layout' => 'nope',
        ]);
        $this->assertFalse($unknownLayout['ok']);
        $this->assertSame('assist.document_invalid', $unknownLayout['error_key']);

        $turn->refresh();
        $this->assertEquals($document, $turn->working_document);
        $this->assertSame([], $turn->ops);
        $this->assertSame(0, (int) $turn->tool_call_count);
    }

    #[Test]
    public function assistant_only_checks(): void
    {
        $newsletter = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument(), TemplatePurpose::Newsletter)->id,
        );
        $token = $newsletter->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>{{contract.balance_owed}}</p>'],
        ]]);
        $this->assertFalse($token['ok']);
        $this->assertSame('assist.token_unavailable', $token['error_key']);

        $allowedToken = $newsletter->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>{{unsubscribe_url}}</p>'],
        ]]);
        $this->assertTrue($allowedToken['ok']);

        $raw = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument([
                ['id' => 'blk_dddddddd', 'type' => 'raw_html', 'params' => ['html' => '<p>Legacy</p>']],
            ]), TemplatePurpose::General)->id,
        );
        $kept = $raw->insertBlocks('col_bbbbbbbb', 1, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Still here</p>'],
        ]]);
        $this->assertTrue($kept['ok']);
        $forbidden = $raw->insertBlocks('col_bbbbbbbb', 1, [[
            'type' => 'raw_html',
            'params' => ['html' => '<p>No</p>'],
        ]]);
        $this->assertFalse($forbidden['ok']);
        $this->assertSame('assist.raw_html_forbidden', $forbidden['error_key']);

        $image = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument(), TemplatePurpose::General)->id,
        );
        $external = $image->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'image',
            'params' => [
                'url' => 'https://example.test/photo.png',
                'alt' => 'Photo',
                'width_percent' => 100,
            ],
        ]]);
        $this->assertFalse($external['ok']);
        $this->assertSame('assist.image_requires_asset', $external['error_key']);

        $marketing = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument(), TemplatePurpose::Marketing)->id,
        );
        $footer = $marketing->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Please unsubscribe</p>'],
        ]]);
        $this->assertFalse($footer['ok']);
        $this->assertSame('assist.legal_footer_forbidden', $footer['error_key']);

        $spanish = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument(), TemplatePurpose::Newsletter, 'es')->id,
        );
        $spanishFooter = $spanish->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Puede darse de baja</p>'],
        ]]);
        $this->assertFalse($spanishFooter['ok']);
        $this->assertSame('assist.legal_footer_forbidden', $spanishFooter['error_key']);

        $transactional = DocumentWorkspace::forTurn(
            $this->makeTurn($this->columnDocument(), TemplatePurpose::Debt)->id,
        );
        $notice = $transactional->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Please unsubscribe is not a footer here, but the word is allowed.</p>'],
        ]]);
        $this->assertTrue($notice['ok'], $notice['hint'] ?? '');

        $general = $this->makeTurn($this->columnDocument(), TemplatePurpose::General);
        $variant = TemplateVariant::query()->where('template_version_id', $general->template_version_id)->firstOrFail();
        $subject = DocumentWorkspace::forTurn($general->id);
        $badSubject = $subject->setSubject('Issue {{newsletter.name}}');
        $this->assertFalse($badSubject['ok']);
        $this->assertSame('assist.token_unavailable', $badSubject['error_key']);

        $set = $subject->setSubject('Your rate');
        $this->assertTrue($set['ok']);
        $again = $subject->setSubject('A shorter note');
        $this->assertTrue($again['ok']);
        $general->refresh();
        $this->assertSame('Hello', $general->subject_change['from']);
        $this->assertSame('A shorter note', $general->subject_change['to']);
        $this->assertSame(2, (int) $general->tool_call_count);
        $this->assertSame('Hello', $variant->refresh()->subject);
    }

    #[Test]
    public function tool_budget(): void
    {
        $document = $this->columnDocument();
        $turn = $this->makeTurn($document, TemplatePurpose::Newsletter);
        $workspace = DocumentWorkspace::forTurn($turn->id);

        $rejected = $workspace->insertBlocks('col_bbbbbbbb', 0, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>{{contract.balance_owed}}</p>'],
        ]]);
        $this->assertSame('assist.token_unavailable', $rejected['error_key']);
        $this->assertSame(0, (int) $turn->refresh()->tool_call_count);
        $this->assertEquals($document, $turn->working_document);

        for ($index = 0; $index < 12; $index++) {
            $result = $workspace->insertBlocks('col_bbbbbbbb', $index, [[
                'type' => 'paragraph',
                'params' => ['html' => '<p>Line '.($index + 1).'</p>'],
            ]]);
            $this->assertTrue($result['ok'], $result['hint'] ?? '');
        }

        $turn->refresh();
        $this->assertSame(12, (int) $turn->tool_call_count);
        $last = $turn->working_document;

        $exhausted = $workspace->insertBlocks('col_bbbbbbbb', 12, [[
            'type' => 'paragraph',
            'params' => ['html' => '<p>Too many</p>'],
        ]]);
        $this->assertFalse($exhausted['ok']);
        $this->assertSame('assist.tool_budget_exhausted', $exhausted['error_key']);

        $turn->refresh();
        $this->assertSame(TemplateAssistTurnStatus::Running, $turn->status);
        $this->assertSame('assist.tool_budget_exhausted', $turn->error_key);
        $this->assertSame(12, (int) $turn->tool_call_count);
        $this->assertEquals($last, $turn->working_document);
        $this->assertCount(12, $turn->working_document['sections'][0]['columns'][0]['blocks']);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function columnDocument(array $blocks = []): array
    {
        return [
            'version' => 2,
            'settings' => ['background_color' => null],
            'sections' => [[
                'id' => 'sec_aaaaaaaa',
                'layout' => '100',
                'params' => [
                    'background_color' => null,
                    'padding' => 'md',
                    'stack_on_mobile' => true,
                ],
                'columns' => [[
                    'id' => 'col_bbbbbbbb',
                    'blocks' => $blocks,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDocument(): array
    {
        return [
            'version' => 2,
            'settings' => ['background_color' => null],
            'sections' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heading(string $id, string $text): array
    {
        return [
            'id' => $id,
            'type' => 'heading',
            'params' => ['text' => $text, 'level' => 2],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function makeTurn(
        array $document,
        TemplatePurpose $purpose,
        string $locale = 'en',
    ): TemplateAssistTurn {
        $employee = Employee::factory()->manager()->create();
        $family = TemplateFamily::factory()->create([
            'purpose' => $purpose,
            'name' => $purpose->value.' template',
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => $locale,
            'subject' => 'Hello',
            'blocks' => $document,
        ]);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Assist',
            'site_scope_snapshot' => null,
        ]);
        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $family->id,
            'locale' => $locale,
            'conversation_id' => $conversation->id,
            'employee_id' => $employee->id,
        ]);

        return TemplateAssistTurn::query()->create([
            'session_id' => $session->id,
            'call_id' => (string) Str::uuid(),
            'client_message_id' => (string) Str::uuid(),
            'template_version_id' => $version->id,
            'base_document' => $document,
            'base_hash' => hash('sha256', (string) json_encode($document)),
            'working_document' => $document,
            'ops' => [],
            'op_summary' => [],
            'status' => TemplateAssistTurnStatus::Running,
            'prompt_version' => (string) config('ai.template_assist.prompt_version'),
        ]);
    }

    /**
     * @return list<array{name: string, doc: array<string, mixed>, ops: list<array<string, mixed>>, expect: array<string, mixed>}>
     */
    private function parityCases(): array
    {
        $path = base_path('tests/Fixtures/template-assist/parity/cases.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, true>
     */
    private function knownIds(array $doc, array $ops): array
    {
        /** @var array<string, true> $ids */
        $ids = [];
        $walk = function (mixed $node) use (&$walk, &$ids): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($value) && preg_match('/^(sec|col|blk)_[0-9a-f]{8}$/', $value) === 1) {
                    if ($key === 'id' || (is_string($key) && str_ends_with($key, '_id'))) {
                        $ids[$value] = true;
                    }
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($doc);
        $walk($ops);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, true>  $known
     * @return array<string, mixed>
     */
    private function normalizeIds(array $doc, array $known): array
    {
        $seq = 0;
        /** @var array<string, string> $map */
        $map = [];
        $walk = function (mixed $node) use (&$walk, &$seq, &$map, $known): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $key => $value) {
                if ($key === 'id' && is_string($value) && ! isset($known[$value])) {
                    if (! isset($map[$value])) {
                        $seq++;
                        $map[$value] = '__id'.$seq.'__';
                    }
                    $out[$key] = $map[$value];

                    continue;
                }
                $out[$key] = is_array($value) ? $walk($value) : $value;
            }

            return $out;
        };

        /** @var array<string, mixed> $normalized */
        $normalized = $walk($doc);

        return $normalized;
    }
}
