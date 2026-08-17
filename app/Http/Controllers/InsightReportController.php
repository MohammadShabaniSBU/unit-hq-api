<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Http\Resources\InsightNavItemResource;
use App\Http\Resources\InsightReportResource;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Models\InsightReport;
use App\Models\InsightReportParam;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Auth\Permission;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\AnalyticsProviderRegistry;
use App\Support\Insights\Contracts\SignsEmbedTokens;
use App\Support\Insights\DynamicParamContext;
use App\Support\Insights\DynamicParams;
use App\Support\Insights\Exceptions\EmbedUrlException;
use App\Support\Insights\Exceptions\UnknownDynamicParamKey;
use App\Support\Insights\NativeReports;
use App\Support\Insights\ReportValidator;
use App\Support\Insights\Results\ValidationResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Insight report registry (S21-03): nav feed + settings CRUD.
 * Archive-only; system native rows may be archived/unarchived but never repointed.
 */
class InsightReportController extends Controller
{
    public function nav(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReportView->value);

        /** @var Employee|null $employee */
        $employee = $request->user();

        $reports = InsightReport::query()
            ->visibleTo($employee)
            ->with('analyticsAccount')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(
            InsightNavItemResource::collection($reports)->resolve(),
            'Insights retrieved successfully.'
        );
    }

    public function embed(Request $request, string $key, AnalyticsProviderRegistry $registry): JsonResponse
    {
        Gate::authorize(Permission::ReportView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $report = InsightReport::query()
            ->visibleTo($employee)
            ->where('key', $key)
            ->with(['params', 'analyticsAccount'])
            ->first();

        if ($report === null) {
            return $this->notFound('Insight report not found.');
        }

        if ($report->source === InsightReportSource::Native) {
            $this->recordEmbedFailed($report, 'report_is_native');

            return $this->error('report_is_native', [], 400);
        }

        $account = $report->analyticsAccount;

        if ($account === null || $account->isArchived()) {
            $this->recordEmbedFailed($report, 'account_archived');

            return $this->error('account_archived', [], 409);
        }

        if (CredentialMasker::isUnreadable($account, 'credentials')) {
            $this->recordEmbedFailed($report, 'credentials_unreadable', $account->id);

            return $this->error('credentials_unreadable', [], 409);
        }

        $provider = $registry->forAccount($account);

        if (! $provider instanceof SignsEmbedTokens) {
            $this->recordEmbedFailed($report, 'provider_not_embeddable', $account->id);

            return $this->error('provider_not_embeddable', [], 409);
        }

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $requestedSiteId = array_key_exists('site_id', $validated)
            ? ($validated['site_id'] !== null ? (int) $validated['site_id'] : null)
            : null;

        $applySiteScope = $report->site_scope_mode === InsightSiteScopeMode::Inherit;
        $site = null;
        $siteId = null;

        if ($applySiteScope && $requestedSiteId !== null) {
            $granted = $employee->siteIdsFor(Permission::ReportView);

            if ($granted === [] || ($granted !== null && ! in_array($requestedSiteId, $granted, true))) {
                throw ValidationException::withMessages([
                    'site_id' => [__('errors.forbidden')],
                ]);
            }

            $site = Site::query()->active()->find($requestedSiteId);
            if ($site === null) {
                throw ValidationException::withMessages([
                    'site_id' => [__('errors.forbidden')],
                ]);
            }

            $siteId = $site->id;
        }

        $context = new DynamicParamContext(
            employee: $employee,
            siteId: $siteId,
            site: $site,
            locale: app()->getLocale(),
            applySiteScope: $applySiteScope,
        );

        try {
            $resolved = $this->resolveEmbedParams($report, $context);
            $ttl = (int) config('insights.embed_ttl_minutes', 10);
            $expiresAt = now()->addMinutes($ttl);
            $url = $provider->embedUrl($report, $resolved);
        } catch (UnknownDynamicParamKey $e) {
            $this->recordEmbedFailed($report, 'unknown_dynamic_key', $account->id, [$e->key]);

            return $this->error('unknown_dynamic_key', ['dynamic_key' => $e->key], 422);
        } catch (EmbedUrlException $e) {
            $paramNames = isset($e->errors['param']) ? [(string) $e->errors['param']] : [];
            $this->recordEmbedFailed($report, $e->reasonKey, $account->id, $paramNames);

            return $this->error($e->reasonKey, $e->errors, $e->statusCode);
        } catch (ValidationException $e) {
            $this->recordEmbedFailed($report, 'iframe_host_rejected', $account->id);

            throw $e;
        }

        SystemEvent::record('insights.embed.minted', $report, [
            'report_key' => $report->key,
            'account_id' => $account->id,
            'param_names' => array_keys($resolved),
        ]);

        return $this->success([
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 'Embed URL minted successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = InsightReport::query()
            ->with(['params', 'analyticsAccount'])
            ->orderBy('sort_order')
            ->orderBy('id');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        return $this->success(
            InsightReportResource::collection($query->get())->resolve(),
            'Insight reports retrieved successfully.'
        );
    }

    public function store(Request $request, ReportValidator $validator): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $this->validateDefinition($request, creating: true);
        $validation = $this->runProviderValidation($validated, null, $validator);

        /** @var Employee $employee */
        $employee = $request->user();

        $report = DB::transaction(function () use ($validated, $employee, $validation): InsightReport {
            $maxOrder = (int) InsightReport::query()->max('sort_order');

            $report = InsightReport::query()->create([
                'key' => $validated['key'],
                'source' => $validated['source'],
                'native_key' => $validated['native_key'] ?? null,
                'analytics_account_id' => $validated['analytics_account_id'] ?? null,
                'resource_kind' => $validated['resource_kind'] ?? null,
                'resource_ref' => $validated['resource_ref'] ?? null,
                'labels' => $validated['labels'] ?? null,
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'section' => $validated['section'] ?? null,
                'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
                'visibility' => $validated['visibility'] ?? InsightVisibility::All->value,
                'site_scope_mode' => $validated['site_scope_mode'] ?? InsightSiteScopeMode::Inherit->value,
                'options' => $validated['options'] ?? [],
                'is_system' => false,
                'created_by' => $employee->id,
                'last_validated_at' => $validation?->validatedAt,
                'validation_status' => $validation?->status->value ?? InsightValidationStatus::Unknown->value,
                'validation_detail' => $validation?->detail,
            ]);

            $this->replaceParams($report, $validated['params'] ?? []);

            return $report->load(['params', 'analyticsAccount']);
        });

        $data = InsightReportResource::make($report)->resolve();
        if ($validation?->isUnreachable()) {
            $data['validation_warning'] = true;
        }

        return $this->created($data, 'Insight report created successfully.');
    }

    public function show(InsightReport $insightReport): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $insightReport->load(['params', 'analyticsAccount']);

        return $this->success(
            InsightReportResource::make($insightReport)->resolve(),
            'Insight report retrieved successfully.'
        );
    }

    public function update(Request $request, InsightReport $insightReport, ReportValidator $validator): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $this->validateDefinition($request, creating: false, report: $insightReport);

        if ($insightReport->is_system) {
            $this->assertSystemImmutable($insightReport, $validated);
        }

        $insightReport->loadMissing(['params', 'analyticsAccount']);
        $validation = $this->runProviderValidation($validated, $insightReport, $validator);

        $report = DB::transaction(function () use ($validated, $insightReport, $validation): InsightReport {
            $insightReport->fill([
                'key' => $validated['key'] ?? $insightReport->key,
                'labels' => array_key_exists('labels', $validated) ? $validated['labels'] : $insightReport->labels,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $insightReport->description,
                'icon' => array_key_exists('icon', $validated) ? $validated['icon'] : $insightReport->icon,
                'section' => array_key_exists('section', $validated) ? $validated['section'] : $insightReport->section,
                'visibility' => $validated['visibility'] ?? $insightReport->visibility->value,
                'site_scope_mode' => $validated['site_scope_mode'] ?? $insightReport->site_scope_mode->value,
                'options' => $validated['options'] ?? $insightReport->options,
            ]);

            if (! $insightReport->is_system) {
                $insightReport->fill([
                    'source' => $validated['source'] ?? $insightReport->source->value,
                    'native_key' => array_key_exists('native_key', $validated) ? $validated['native_key'] : $insightReport->native_key,
                    'analytics_account_id' => array_key_exists('analytics_account_id', $validated)
                        ? $validated['analytics_account_id']
                        : $insightReport->analytics_account_id,
                    'resource_kind' => array_key_exists('resource_kind', $validated)
                        ? $validated['resource_kind']
                        : $insightReport->resource_kind?->value,
                    'resource_ref' => array_key_exists('resource_ref', $validated)
                        ? $validated['resource_ref']
                        : $insightReport->resource_ref,
                ]);
            }

            if ($validation !== null) {
                $insightReport->fill([
                    'last_validated_at' => $validation->validatedAt,
                    'validation_status' => $validation->status->value,
                    'validation_detail' => $validation->detail,
                ]);
            }

            $insightReport->save();

            if (array_key_exists('params', $validated)) {
                $this->replaceParams($insightReport, $validated['params'] ?? []);
            }

            return $insightReport->load(['params', 'analyticsAccount']);
        });

        $data = InsightReportResource::make($report)->resolve();
        if ($validation?->isUnreachable()) {
            $data['validation_warning'] = true;
        }

        return $this->success($data, 'Insight report updated successfully.');
    }

    public function validateReport(InsightReport $insightReport, ReportValidator $validator): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $insightReport->load(['params', 'analyticsAccount']);
        $result = $validator->validate($insightReport);

        $insightReport->fill([
            'last_validated_at' => $result->validatedAt,
            'validation_status' => $result->status->value,
            'validation_detail' => $result->detail,
        ]);
        $insightReport->save();

        return $this->success([
            'status' => $result->status->value,
            'detail' => $result->detail,
            'validated_at' => $result->validatedAt->toIso8601String(),
        ], 'Insight report validation completed.');
    }

    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values($validated['ids']);

        $existingIds = InsightReport::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($ids) !== count($existingIds) || array_diff($existingIds, $ids) !== []) {
            throw ValidationException::withMessages([
                'ids' => [__('errors.insights.reorder_incomplete')],
            ]);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                InsightReport::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });

        $reports = InsightReport::query()
            ->active()
            ->with(['params', 'analyticsAccount'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(
            InsightReportResource::collection($reports)->resolve(),
            'Insight reports reordered successfully.'
        );
    }

    public function archive(InsightReport $insightReport): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $this->archiveReport($insightReport);

        return $this->success(
            InsightReportResource::make($insightReport->fresh()->load(['params', 'analyticsAccount']))->resolve(),
            'Insight report archived successfully.'
        );
    }

    public function unarchive(InsightReport $insightReport): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        if (! $insightReport->isArchived()) {
            throw ValidationException::withMessages([
                'insight_report' => [__('errors.insights.report_not_archived')],
            ]);
        }

        $insightReport->archived_at = null;
        $insightReport->save();

        SystemEvent::record('insights.report.unarchived', $insightReport, [
            'report_key' => $insightReport->key,
            'is_system' => $insightReport->is_system,
            'native_key' => $insightReport->native_key,
        ]);

        return $this->success(
            InsightReportResource::make($insightReport->fresh()->load(['params', 'analyticsAccount']))->resolve(),
            'Insight report unarchived successfully.'
        );
    }

    public function destroy(InsightReport $insightReport): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $this->archiveReport($insightReport);

        return $this->success(
            InsightReportResource::make($insightReport->fresh()->load(['params', 'analyticsAccount']))->resolve(),
            'Insight report archived successfully.'
        );
    }

    private function archiveReport(InsightReport $report): void
    {
        if ($report->isArchived()) {
            throw ValidationException::withMessages([
                'insight_report' => [__('errors.insights.report_already_archived')],
            ]);
        }

        $report->archived_at = now();
        $report->save();

        SystemEvent::record('insights.report.archived', $report, [
            'report_key' => $report->key,
            'is_system' => $report->is_system,
            'native_key' => $report->native_key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertSystemImmutable(InsightReport $report, array $validated): void
    {
        if (isset($validated['native_key']) && $validated['native_key'] !== $report->native_key) {
            throw ValidationException::withMessages([
                'native_key' => [__('errors.insights.system_report_immutable')],
            ]);
        }

        if (isset($validated['source']) && $validated['source'] !== $report->source->value) {
            throw ValidationException::withMessages([
                'source' => [__('errors.insights.system_report_immutable')],
            ]);
        }

        foreach (['analytics_account_id', 'resource_kind', 'resource_ref'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $current = match ($field) {
                'resource_kind' => $report->resource_kind?->value,
                default => $report->{$field},
            };

            if ($validated[$field] !== $current) {
                throw ValidationException::withMessages([
                    $field => [__('errors.insights.system_report_immutable')],
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDefinition(Request $request, bool $creating, ?InsightReport $report = null): array
    {
        $sourceValues = array_column(InsightReportSource::cases(), 'value');
        $kindValues = array_column(InsightResourceKind::cases(), 'value');
        $visibilityValues = array_column(InsightVisibility::cases(), 'value');
        $scopeValues = array_column(InsightSiteScopeMode::cases(), 'value');
        $valueSources = array_column(InsightParamValueSource::cases(), 'value');
        $bindings = array_column(InsightParamBinding::cases(), 'value');
        $locales = config('insights.locales', ['en', 'es', 'fr']);

        $rules = [
            'key' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('insight_reports', 'key')
                    ->whereNull('archived_at')
                    ->ignore($report?->id),
            ],
            'source' => [$creating ? 'required' : 'sometimes', 'string', Rule::in($sourceValues)],
            'native_key' => ['nullable', 'string', 'max:64'],
            'analytics_account_id' => [
                'nullable',
                'integer',
                Rule::exists('analytics_accounts', 'id')->whereNull('archived_at'),
            ],
            'resource_kind' => ['nullable', 'string', Rule::in($kindValues)],
            'resource_ref' => ['nullable', 'string', 'max:64'],
            'labels' => ['nullable', 'array'],
            'description' => ['nullable', 'array'],
            'icon' => ['nullable', 'string', 'max:48'],
            'section' => ['nullable', 'string', 'max:48'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'visibility' => ['sometimes', 'string', Rule::in($visibilityValues)],
            'site_scope_mode' => ['sometimes', 'string', Rule::in($scopeValues)],
            'options' => ['sometimes', 'array'],
            'params' => [$creating ? 'sometimes' : 'sometimes', 'array'],
            'params.*.name' => ['required_with:params', 'string', 'max:64'],
            'params.*.value_source' => ['required_with:params', 'string', Rule::in($valueSources)],
            'params.*.static_value' => ['nullable'],
            'params.*.dynamic_key' => ['nullable', 'string', 'max:64', Rule::in(DynamicParams::keys())],
            'params.*.binding' => ['sometimes', 'string', Rule::in($bindings)],
            'params.*.is_required' => ['sometimes', 'boolean'],
            'params.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];

        $validated = $request->validate($rules);

        $source = $validated['source'] ?? $report?->source->value;

        if ($source === InsightReportSource::Native->value) {
            $nativeKey = $validated['native_key'] ?? $report?->native_key;
            if ($nativeKey === null || $nativeKey === '') {
                throw ValidationException::withMessages([
                    'native_key' => [__('errors.insights.native_requires_native_key')],
                ]);
            }
            if (! NativeReports::has($nativeKey) && ! ($report?->is_system ?? false)) {
                // Operator-created native rows must still point at a registry key.
                throw ValidationException::withMessages([
                    'native_key' => [__('errors.insights.unknown_native_key')],
                ]);
            }
        }

        if ($source === InsightReportSource::Embedded->value) {
            $accountId = $validated['analytics_account_id'] ?? $report?->analytics_account_id;
            $kind = $validated['resource_kind'] ?? $report?->resource_kind?->value;
            $ref = $validated['resource_ref'] ?? $report?->resource_ref;

            $errors = [];
            if ($accountId === null) {
                $errors['analytics_account_id'] = [__('errors.insights.embedded_requires_account')];
            }
            if ($kind === null || $kind === '') {
                $errors['resource_kind'] = [__('errors.insights.embedded_requires_resource')];
            }
            if ($ref === null || $ref === '') {
                $errors['resource_ref'] = [__('errors.insights.embedded_requires_resource')];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($accountId !== null && ! AnalyticsAccount::query()->active()->whereKey($accountId)->exists()) {
                throw ValidationException::withMessages([
                    'analytics_account_id' => [__('errors.insights.embedded_requires_account')],
                ]);
            }
        }

        if (array_key_exists('labels', $validated) && $validated['labels'] !== null) {
            $this->validateLocaleMap($validated['labels'], 'labels', $locales);
        }

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $this->validateLocaleMap($validated['description'], 'description', $locales, allowEmpty: true);
        }

        if (isset($validated['params'])) {
            $this->validateParams($validated['params']);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<string>  $locales
     */
    private function validateLocaleMap(array $map, string $field, array $locales, bool $allowEmpty = false): void
    {
        if ($map === [] && ! $allowEmpty) {
            throw ValidationException::withMessages([
                $field => [__('errors.insights.labels_require_locale')],
            ]);
        }

        foreach ($map as $locale => $value) {
            if (! in_array($locale, $locales, true)) {
                throw ValidationException::withMessages([
                    "{$field}.{$locale}" => [__('errors.insights.labels_locale_invalid')],
                ]);
            }
            if (! is_string($value) || $value === '') {
                throw ValidationException::withMessages([
                    "{$field}.{$locale}" => [__('errors.insights.labels_value_invalid')],
                ]);
            }
            if (mb_strlen($value) > 120) {
                throw ValidationException::withMessages([
                    "{$field}.{$locale}" => [__('errors.insights.labels_value_too_long')],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $params
     */
    private function validateParams(array $params): void
    {
        $names = [];

        foreach ($params as $index => $param) {
            $name = $param['name'] ?? null;
            if (is_string($name)) {
                if (isset($names[$name])) {
                    throw ValidationException::withMessages([
                        "params.{$index}.name" => [__('errors.insights.param_name_duplicate')],
                    ]);
                }
                $names[$name] = true;
            }

            $valueSource = $param['value_source'] ?? null;
            $binding = $param['binding'] ?? InsightParamBinding::Locked->value;

            if ($valueSource === InsightParamValueSource::Dynamic->value) {
                if ($binding !== InsightParamBinding::Locked->value) {
                    throw ValidationException::withMessages([
                        "params.{$index}.binding" => [__('errors.insights.dynamic_param_must_be_locked')],
                    ]);
                }
                $dynamicKey = $param['dynamic_key'] ?? null;
                if ($dynamicKey === null || $dynamicKey === '') {
                    throw ValidationException::withMessages([
                        "params.{$index}.dynamic_key" => [__('errors.insights.dynamic_key_required')],
                    ]);
                }
                if (! DynamicParams::has($dynamicKey)) {
                    throw ValidationException::withMessages([
                        "params.{$index}.dynamic_key" => [__('errors.insights.unknown_dynamic_key')],
                    ]);
                }
            }

            if ($valueSource === InsightParamValueSource::Static->value
                && (! array_key_exists('static_value', $param) || $param['static_value'] === null)
            ) {
                throw ValidationException::withMessages([
                    "params.{$index}.static_value" => [__('errors.insights.static_value_required')],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $params
     */
    private function replaceParams(InsightReport $report, array $params): void
    {
        $report->params()->delete();

        foreach (array_values($params) as $index => $param) {
            InsightReportParam::query()->create([
                'insight_report_id' => $report->id,
                'name' => $param['name'],
                'value_source' => $param['value_source'],
                'static_value' => $param['static_value'] ?? null,
                'dynamic_key' => $param['dynamic_key'] ?? null,
                'binding' => $param['binding'] ?? InsightParamBinding::Locked->value,
                'is_required' => $param['is_required'] ?? true,
                'sort_order' => $param['sort_order'] ?? $index,
            ]);
        }
    }

    /**
     * Provider round-trip for embedded definitions. Blocks save on mismatch;
     * unreachable is allowed through with a warning status.
     *
     * @param  array<string, mixed>  $validated
     */
    private function runProviderValidation(
        array $validated,
        ?InsightReport $existing,
        ReportValidator $validator,
    ): ?ValidationResult {
        $source = $validated['source'] ?? $existing?->source->value;
        if ($source !== InsightReportSource::Embedded->value) {
            return $existing !== null && $existing->source === InsightReportSource::Native
                ? new ValidationResult(InsightValidationStatus::Valid, null, now())
                : ($source === InsightReportSource::Native->value
                    ? new ValidationResult(InsightValidationStatus::Valid, null, now())
                    : null);
        }

        $provisional = $this->provisionalEmbeddedReport($validated, $existing);
        $result = $validator->validate($provisional);

        if ($result->blocksSave()) {
            throw new HttpResponseException(response()->json([
                'message' => $result->status->value,
                'errors' => [
                    'validation_detail' => $result->detail,
                    'validation_status' => $result->status->value,
                ],
            ], 422));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function provisionalEmbeddedReport(array $validated, ?InsightReport $existing): InsightReport
    {
        $report = new InsightReport([
            'key' => $validated['key'] ?? $existing?->key ?? 'provisional',
            'source' => InsightReportSource::Embedded->value,
            'analytics_account_id' => $validated['analytics_account_id'] ?? $existing?->analytics_account_id,
            'resource_kind' => $validated['resource_kind'] ?? $existing?->resource_kind?->value,
            'resource_ref' => $validated['resource_ref'] ?? $existing?->resource_ref,
            'site_scope_mode' => $validated['site_scope_mode']
                ?? $existing?->site_scope_mode->value
                ?? InsightSiteScopeMode::Inherit->value,
            'visibility' => $validated['visibility']
                ?? $existing?->visibility->value
                ?? InsightVisibility::All->value,
            'options' => $validated['options'] ?? $existing?->options ?? [],
            'is_system' => false,
        ]);

        $accountId = $report->analytics_account_id;
        $account = $accountId !== null
            ? AnalyticsAccount::query()->find($accountId)
            : null;
        $report->setRelation('analyticsAccount', $account);

        if (array_key_exists('params', $validated)) {
            $report->setRelation('params', $this->provisionalParams($validated['params'] ?? []));
        } elseif ($existing !== null) {
            $report->setRelation('params', $existing->params);
        } else {
            $report->setRelation('params', new Collection);
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $params
     * @return Collection<int, InsightReportParam>
     */
    private function provisionalParams(array $params): Collection
    {
        $collection = new Collection;

        foreach (array_values($params) as $index => $param) {
            $collection->push(new InsightReportParam([
                'name' => $param['name'],
                'value_source' => $param['value_source'],
                'static_value' => $param['static_value'] ?? null,
                'dynamic_key' => $param['dynamic_key'] ?? null,
                'binding' => $param['binding'] ?? InsightParamBinding::Locked->value,
                'is_required' => $param['is_required'] ?? true,
                'sort_order' => $param['sort_order'] ?? $index,
            ]));
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEmbedParams(InsightReport $report, DynamicParamContext $context): array
    {
        $resolved = [];

        foreach ($report->params->sortBy('sort_order') as $param) {
            $value = match ($param->value_source) {
                InsightParamValueSource::Static => $param->static_value,
                InsightParamValueSource::Dynamic => DynamicParams::resolve(
                    (string) $param->dynamic_key,
                    $context
                ),
            };

            if ($value === null && $param->is_required) {
                if ($param->value_source === InsightParamValueSource::Dynamic
                    && $param->dynamic_key === 'current_site_id'
                ) {
                    throw EmbedUrlException::siteRequired();
                }

                throw EmbedUrlException::paramUnresolved($param->name);
            }

            if ($value === null) {
                continue;
            }

            $resolved[$param->name] = $value;
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $paramNames
     */
    private function recordEmbedFailed(
        InsightReport $report,
        string $reason,
        ?int $accountId = null,
        array $paramNames = [],
    ): void {
        SystemEvent::record('insights.embed.failed', $report, [
            'report_key' => $report->key,
            'account_id' => $accountId ?? $report->analytics_account_id,
            'param_names' => $paramNames,
            'reason' => $reason,
        ]);
    }
}
