<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplatePurpose;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateVariant;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\SendClass;
use App\Support\Templates\TokenCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mutates one running turn's working document. Reads the turn row, never panel state.
 */
final class DocumentWorkspace
{
    public function __construct(private readonly int $turnId) {}

    public static function forTurn(int $turnId): self
    {
        return new self($turnId);
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array{ok: true, ids: list<string>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function apply(array $op): array
    {
        return DB::transaction(function () use ($op): array {
            $turn = $this->lockTurn();
            if (is_array($turn)) {
                return $turn;
            }

            /** @var array<string, mixed> $before */
            $before = is_array($turn->working_document) ? $turn->working_document : [];
            $applied = BlockOpApplier::apply($before, $op);
            if ($applied['inverse'] === []) {
                return self::success([]);
            }

            try {
                EmailBlockDocument::validate($applied['doc']);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
                $path = (string) array_key_first($errors);
                $messages = $errors[$path] ?? [];
                $hint = is_array($messages) ? (string) ($messages[0] ?? 'Document is invalid.') : 'Document is invalid.';

                return self::failure('assist.document_invalid', $path, $hint);
            }

            $check = $this->assistantFailure($turn, $before, $applied['doc']);
            if ($check !== null) {
                return $check;
            }

            $ops = is_array($turn->ops) && array_is_list($turn->ops) ? $turn->ops : [];
            $ops[] = [
                'op' => $applied['op'],
                'inverse' => $applied['inverse'],
            ];
            $turn->working_document = $applied['doc'];
            $turn->ops = $ops;
            $turn->op_summary = self::summarize(
                is_array($turn->op_summary) ? $turn->op_summary : [],
                $applied['op'],
                $before,
            );
            $turn->tool_call_count = (int) $turn->tool_call_count + 1;
            $turn->save();

            return self::success(self::newIds($before, $applied['doc']));
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: true, ids: list<string>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function addSection(int $index, string $layout, array $params = []): array
    {
        if (! array_key_exists($layout, EmailBlockDocument::LAYOUTS)) {
            return self::failure('assist.document_invalid', 'layout', 'Unknown layout.');
        }

        $sectionParams = [
            'background_color' => null,
            'padding' => 'md',
            'stack_on_mobile' => true,
        ];
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $sectionParams[$key] = $value;
            }
        }

        $columns = [];
        $count = EmailBlockDocument::LAYOUTS[$layout];
        for ($i = 0; $i < $count; $i++) {
            $columns[] = ['blocks' => []];
        }

        return $this->apply([
            'op' => 'add_section',
            'index' => $index,
            'section' => [
                'layout' => $layout,
                'params' => $sectionParams,
                'columns' => $columns,
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{ok: true, ids: list<string>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function insertBlocks(string $columnId, int $index, array $blocks): array
    {
        $clean = [];
        foreach ($blocks as $position => $block) {
            if (! is_array($block)) {
                return self::failure('assist.document_invalid', 'blocks', 'Each block needs a type and params.');
            }
            $extra = array_diff(array_keys($block), ['type', 'params']);
            if ($extra !== []) {
                return self::failure('assist.unknown_field', 'blocks.'.array_values($extra)[0], 'Unknown field.');
            }
            if (! is_string($block['type'] ?? null) || $block['type'] === '') {
                return self::failure('assist.document_invalid', "blocks.{$position}.type", 'Block type is required.');
            }
            if (isset($block['params']) && ! is_array($block['params'])) {
                return self::failure('assist.document_invalid', "blocks.{$position}.params", 'Block params must be an object.');
            }
            $clean[] = [
                'type' => $block['type'],
                'params' => is_array($block['params'] ?? null) ? $block['params'] : [],
            ];
        }

        return $this->apply([
            'op' => 'insert_blocks',
            'column_id' => $columnId,
            'index' => $index,
            'blocks' => $clean,
        ]);
    }

    /**
     * @return array{ok: true, ids: list<string>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function removeNode(string $id): array
    {
        $turn = TemplateAssistTurn::query()->find($this->turnId);
        if (! $turn instanceof TemplateAssistTurn) {
            return self::failure('assist.turn_missing', 'turn', 'Turn not found.');
        }

        $doc = is_array($turn->working_document) ? $turn->working_document : [];
        if (self::sectionExists($doc, $id)) {
            return $this->apply(['op' => 'remove_section', 'section_id' => $id]);
        }
        if (self::blockExists($doc, $id)) {
            return $this->apply(['op' => 'remove_block', 'block_id' => $id]);
        }

        return self::success([]);
    }

    /**
     * @return array{ok: true, ids: list<string>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function setSubject(string $subject): array
    {
        $subject = trim($subject);

        return DB::transaction(function () use ($subject): array {
            $turn = $this->lockTurn();
            if (is_array($turn)) {
                return $turn;
            }

            if ($subject === '') {
                return self::failure('assist.subject_empty', 'subject', 'Subject is empty.');
            }
            if (mb_strlen($subject) > 120) {
                return self::failure('assist.subject_too_long', 'subject', 'Subject must be 120 characters or fewer.');
            }

            $turn->loadMissing('session.family');
            $session = $turn->session;
            if ($session === null || $session->family === null) {
                return self::failure('assist.turn_missing', 'session', 'Turn has no session.');
            }
            $purpose = $session->family->purpose;
            $locale = $session->locale;
            $token = self::firstUnavailableToken($subject, $purpose);
            if ($token !== null) {
                return self::failure(
                    'assist.token_unavailable',
                    'subject',
                    'Token {{'.$token.'}} is not available for this template.',
                );
            }
            if (SendClass::forTemplatePurpose($purpose) === SendClass::Marketing && self::hasFooter($subject, $locale)) {
                return self::failure(
                    'assist.legal_footer_forbidden',
                    'subject',
                    'Marketing mail already appends the legal footer.',
                );
            }

            $variant = TemplateVariant::query()
                ->where('template_version_id', $turn->template_version_id)
                ->where('locale', $locale)
                ->first();

            $existing = is_array($turn->subject_change) ? $turn->subject_change : null;
            $current = $existing['to'] ?? $variant?->subject;
            if ($current === $subject) {
                return self::success([]);
            }

            $from = is_array($existing) && array_key_exists('from', $existing)
                ? $existing['from']
                : $variant?->subject;

            $turn->subject_change = [
                'from' => $from,
                'to' => $subject,
            ];
            $turn->tool_call_count = (int) $turn->tool_call_count + 1;
            $turn->save();

            return self::success([]);
        });
    }

    /**
     * @return array{ok: true, ids: list<string>, document: array<string, mixed>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function readDocument(): array
    {
        $turn = TemplateAssistTurn::query()->find($this->turnId);
        if (! $turn instanceof TemplateAssistTurn) {
            return self::failure('assist.turn_missing', 'turn', 'Turn not found.');
        }

        return [
            'ok' => true,
            'ids' => [],
            'document' => self::compactDocument(is_array($turn->working_document) ? $turn->working_document : []),
        ];
    }

    /**
     * @return array{ok: true, ids: list<string>, tokens: list<array{path: string, type: string, description: string, sample: string}>}|array{ok: false, error_key: string, path: string, hint: string}
     */
    public function readTokens(): array
    {
        $turn = TemplateAssistTurn::query()->with('session.family')->find($this->turnId);
        if (! $turn instanceof TemplateAssistTurn || $turn->session === null) {
            return self::failure('assist.turn_missing', 'turn', 'Turn not found.');
        }

        $locale = $turn->session->locale;
        $tokens = [];
        foreach (TokenCatalog::forPurpose($turn->session->family->purpose) as $token) {
            $tokens[] = [
                'path' => $token->path,
                'type' => $token->type->value,
                'description' => __($token->descriptionKey, [], $locale),
                'sample' => $token->sample,
            ];
        }

        return [
            'ok' => true,
            'ids' => [],
            'tokens' => $tokens,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{ok: true, ids: list<string>}
     */
    public static function success(array $ids): array
    {
        return [
            'ok' => true,
            'ids' => array_values($ids),
        ];
    }

    /**
     * @return array{ok: false, error_key: string, path: string, hint: string}
     */
    public static function failure(string $key, string $path, string $hint): array
    {
        return [
            'ok' => false,
            'error_key' => $key,
            'path' => $path,
            'hint' => $hint,
        ];
    }

    /**
     * @return TemplateAssistTurn|array{ok: false, error_key: string, path: string, hint: string}
     */
    private function lockTurn(): TemplateAssistTurn|array
    {
        $turn = TemplateAssistTurn::query()->lockForUpdate()->find($this->turnId);
        if (! $turn instanceof TemplateAssistTurn) {
            return self::failure('assist.turn_missing', 'turn', 'Turn not found.');
        }
        if ($turn->status !== TemplateAssistTurnStatus::Running) {
            return self::failure('assist.turn_frozen', 'status', 'Turn is not running.');
        }

        $max = (int) config('ai.template_assist.max_tool_calls');
        if ((int) $turn->tool_call_count >= $max) {
            if ($turn->error_key !== 'assist.tool_budget_exhausted') {
                $turn->error_key = 'assist.tool_budget_exhausted';
                $turn->save();
            }

            return self::failure(
                'assist.tool_budget_exhausted',
                'tool_call_count',
                'Tool budget is exhausted.',
            );
        }

        return $turn;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{ok: false, error_key: string, path: string, hint: string}|null
     */
    private function assistantFailure(TemplateAssistTurn $turn, array $before, array $after): ?array
    {
        $turn->loadMissing('session.family');
        $session = $turn->session;
        if ($session === null || $session->family === null) {
            return self::failure('assist.turn_missing', 'session', 'Turn has no session.');
        }

        $purpose = $session->family->purpose;
        $locale = $session->locale;
        $touched = self::touchedBlocks($before, $after);

        foreach ($touched as $block) {
            $token = self::firstUnavailableToken(implode("\n", self::stringsIn($block['params'] ?? [])), $purpose);
            if ($token !== null) {
                $id = is_string($block['id'] ?? null) ? $block['id'] : '';

                return self::failure(
                    'assist.token_unavailable',
                    self::blockPath($after, $id).'.params',
                    'Token {{'.$token.'}} is not available for this template.',
                );
            }
        }

        foreach ($touched as $block) {
            if (($block['type'] ?? null) !== 'raw_html') {
                continue;
            }
            $id = is_string($block['id'] ?? null) ? $block['id'] : '';

            return self::failure(
                'assist.raw_html_forbidden',
                self::blockPath($after, $id).'.type',
                'raw_html blocks cannot be inserted.',
            );
        }

        foreach ($touched as $block) {
            if (($block['type'] ?? null) !== 'image') {
                continue;
            }
            $params = is_array($block['params'] ?? null) ? $block['params'] : [];
            $assetId = $params['asset_id'] ?? null;
            if ($assetId === null || $assetId === '') {
                $id = is_string($block['id'] ?? null) ? $block['id'] : '';

                return self::failure(
                    'assist.image_requires_asset',
                    self::blockPath($after, $id).'.params.asset_id',
                    'Images need an asset_id. External URLs are not allowed.',
                );
            }
        }

        if (SendClass::forTemplatePurpose($purpose) === SendClass::Marketing) {
            foreach ($touched as $block) {
                foreach (self::stringsIn($block['params'] ?? []) as $text) {
                    if (! self::hasFooter($text, $locale)) {
                        continue;
                    }
                    $id = is_string($block['id'] ?? null) ? $block['id'] : '';

                    return self::failure(
                        'assist.legal_footer_forbidden',
                        self::blockPath($after, $id).'.params',
                        'Marketing mail already appends the legal footer.',
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private static function touchedBlocks(array $before, array $after): array
    {
        $previous = [];
        foreach (self::walkBlocks($before) as $block) {
            $id = $block['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $previous[$id] = $block;
            }
        }

        $touched = [];
        foreach (self::walkBlocks($after) as $block) {
            $id = $block['id'] ?? null;
            if (! is_string($id) || $id === '' || ! isset($previous[$id])) {
                $touched[] = $block;

                continue;
            }
            $prior = $previous[$id];
            if (($prior['type'] ?? null) !== ($block['type'] ?? null)) {
                $touched[] = $block;

                continue;
            }
            if (json_encode($prior['params'] ?? null) !== json_encode($block['params'] ?? null)) {
                $touched[] = $block;
            }
        }

        return $touched;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $before
     * @return array{added: int, edited: int, removed: int, moved: int, sections_added: int}
     */
    private static function summarize(array $summary, array $op, array $before): array
    {
        $counts = [
            'added' => (int) ($summary['added'] ?? 0),
            'edited' => (int) ($summary['edited'] ?? 0),
            'removed' => (int) ($summary['removed'] ?? 0),
            'moved' => (int) ($summary['moved'] ?? 0),
            'sections_added' => (int) ($summary['sections_added'] ?? 0),
        ];

        $name = $op['op'] ?? null;
        if ($name === 'add_section') {
            $counts['sections_added']++;
        } elseif ($name === 'insert_blocks') {
            $blocks = $op['blocks'] ?? [];
            $counts['added'] += is_array($blocks) ? count($blocks) : 0;
        } elseif ($name === 'update_block' || $name === 'update_section' || $name === 'set_section_layout') {
            $counts['edited']++;
        } elseif ($name === 'move_block' || $name === 'move_section') {
            $counts['moved']++;
        } elseif ($name === 'remove_block') {
            $counts['removed']++;
        } elseif ($name === 'remove_section') {
            $sectionId = is_string($op['section_id'] ?? null) ? $op['section_id'] : '';
            $counts['removed'] += 1 + self::blockCount($before, $sectionId);
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function blockCount(array $doc, string $sectionId): int
    {
        foreach ($doc['sections'] ?? [] as $section) {
            if (! is_array($section) || ($section['id'] ?? null) !== $sectionId) {
                continue;
            }
            $count = 0;
            foreach ($section['columns'] ?? [] as $column) {
                if (is_array($column) && is_array($column['blocks'] ?? null)) {
                    $count += count($column['blocks']);
                }
            }

            return $count;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{sections: list<array<string, mixed>>}
     */
    private static function compactDocument(array $doc): array
    {
        $sections = [];
        foreach ($doc['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $columns = [];
            foreach ($section['columns'] ?? [] as $column) {
                if (! is_array($column)) {
                    continue;
                }
                $blocks = [];
                foreach ($column['blocks'] ?? [] as $block) {
                    if (! is_array($block)) {
                        continue;
                    }
                    $params = is_array($block['params'] ?? null) ? $block['params'] : [];
                    $blocks[] = [
                        'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                        'type' => is_string($block['type'] ?? null) ? $block['type'] : '',
                        'preview' => self::preview($params),
                    ];
                }
                $columns[] = [
                    'id' => is_string($column['id'] ?? null) ? $column['id'] : '',
                    'blocks' => $blocks,
                ];
            }
            $sections[] = [
                'id' => is_string($section['id'] ?? null) ? $section['id'] : '',
                'layout' => is_string($section['layout'] ?? null) ? $section['layout'] : '',
                'columns' => $columns,
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function preview(array $params): string
    {
        $strings = self::stringsIn($params);
        if ($strings === []) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $strings[0]));
        if (mb_strlen($text) <= 80) {
            return $text;
        }

        return mb_substr($text, 0, 80);
    }

    private static function firstUnavailableToken(string $text, TemplatePurpose $purpose): ?string
    {
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $text, $matches) < 1) {
            return null;
        }

        foreach ($matches[1] as $path) {
            if (! TokenCatalog::isAvailable($path, $purpose)) {
                return $path;
            }
        }

        return null;
    }

    private static function hasFooter(string $text, string $locale): bool
    {
        $stripped = preg_replace('/\{\{\s*[a-zA-Z0-9_.]+\s*\}\}/', '', $text);
        $haystack = mb_strtolower(is_string($stripped) ? $stripped : $text);
        foreach (self::footerPhrases($locale) as $phrase) {
            if (mb_stripos($haystack, mb_strtolower($phrase)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function footerPhrases(string $locale): array
    {
        $english = ['unsubscribe', 'opt out', 'opt-out'];
        $lang = strtolower(substr($locale, 0, 2));
        $local = match ($lang) {
            'es' => ['darse de baja', 'cancelar suscripción', 'cancelar suscripcion'],
            'fr' => ['se désabonner', 'se desabonner', 'désabonnement', 'desabonnement'],
            default => [],
        };

        return array_values(array_unique([...$english, ...$local]));
    }

    /**
     * @return list<string>
     */
    private static function stringsIn(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            foreach (self::stringsIn($item) as $string) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function walkBlocks(array $doc): array
    {
        $blocks = [];
        foreach ($doc['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ($section['columns'] ?? [] as $column) {
                if (! is_array($column)) {
                    continue;
                }
                foreach ($column['blocks'] ?? [] as $block) {
                    if (is_array($block)) {
                        $blocks[] = $block;
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function blockPath(array $doc, string $id): string
    {
        foreach ($doc['sections'] ?? [] as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ($section['columns'] ?? [] as $columnIndex => $column) {
                if (! is_array($column)) {
                    continue;
                }
                foreach ($column['blocks'] ?? [] as $blockIndex => $block) {
                    if (is_array($block) && ($block['id'] ?? null) === $id) {
                        return "sections[{$sectionIndex}].columns[{$columnIndex}].blocks[{$blockIndex}]";
                    }
                }
            }
        }

        return 'blocks';
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private static function newIds(array $before, array $after): array
    {
        $known = array_fill_keys(self::idList($before), true);
        $ids = [];
        foreach (self::idList($after) as $id) {
            if (! isset($known[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    private static function idList(array $doc): array
    {
        $ids = [];
        foreach ($doc['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            if (is_string($section['id'] ?? null) && $section['id'] !== '') {
                $ids[] = $section['id'];
            }
            foreach ($section['columns'] ?? [] as $column) {
                if (! is_array($column)) {
                    continue;
                }
                if (is_string($column['id'] ?? null) && $column['id'] !== '') {
                    $ids[] = $column['id'];
                }
                foreach ($column['blocks'] ?? [] as $block) {
                    if (is_array($block) && is_string($block['id'] ?? null) && $block['id'] !== '') {
                        $ids[] = $block['id'];
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function sectionExists(array $doc, string $id): bool
    {
        foreach ($doc['sections'] ?? [] as $section) {
            if (is_array($section) && ($section['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function blockExists(array $doc, string $id): bool
    {
        foreach (self::walkBlocks($doc) as $block) {
            if (($block['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
