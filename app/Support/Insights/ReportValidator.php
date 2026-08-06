<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Enums\CredentialStatus;
use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightValidationStatus;
use App\Models\InsightReport;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\Contracts\DescribesResourceParams;
use App\Support\Insights\Contracts\ListsResources;
use App\Support\Insights\Exceptions\DiscoveryException;
use App\Support\Insights\Results\ValidationResult;
use Throwable;

/**
 * Save-time / on-demand check of an embedded report against the live provider.
 */
final class ReportValidator
{
    public function __construct(
        private readonly AnalyticsProviderRegistry $registry,
    ) {}

    public function validate(InsightReport $report): ValidationResult
    {
        $at = now();

        if ($report->source !== InsightReportSource::Embedded) {
            return new ValidationResult(InsightValidationStatus::Valid, null, $at);
        }

        $account = $report->analyticsAccount;

        if ($account === null || $account->isArchived()) {
            return new ValidationResult(
                InsightValidationStatus::Unreachable,
                ['reason' => 'account_archived'],
                $at,
            );
        }

        if (CredentialMasker::isUnreadable($account, 'credentials')) {
            return new ValidationResult(
                InsightValidationStatus::Unreachable,
                ['reason' => 'credentials_unreadable'],
                $at,
            );
        }

        if ($account->connection_status === CredentialStatus::Error) {
            return new ValidationResult(
                InsightValidationStatus::Unreachable,
                ['reason' => 'account_error', 'last_error' => $account->last_error],
                $at,
            );
        }

        $kind = $report->resource_kind?->value;
        $ref = $report->resource_ref;

        if ($kind === null || $ref === null || $ref === '') {
            return new ValidationResult(
                InsightValidationStatus::ResourceMissing,
                ['reason' => 'resource_missing', 'message' => 'Resource kind or ref is missing.'],
                $at,
            );
        }

        try {
            $adapter = $this->registry->forAccount($account);

            if (! $adapter instanceof ListsResources || ! $adapter instanceof DescribesResourceParams) {
                return new ValidationResult(
                    InsightValidationStatus::Unreachable,
                    ['reason' => 'provider_not_discoverable'],
                    $at,
                );
            }

            $resources = $adapter->resources($kind);
            $match = null;
            foreach ($resources as $resource) {
                if ((string) ($resource['ref'] ?? '') === (string) $ref) {
                    $match = $resource;
                    break;
                }
            }

            if ($match === null || ! ($match['enabled_for_embedding'] ?? false)) {
                return new ValidationResult(
                    InsightValidationStatus::ResourceMissing,
                    [
                        'reason' => 'resource_missing',
                        'resource_kind' => $kind,
                        'resource_ref' => $ref,
                        'enabled_for_embedding' => (bool) ($match['enabled_for_embedding'] ?? false),
                        'message' => 'Publish the resource for embedding in the analytics provider.',
                    ],
                    $at,
                );
            }

            $providerParams = $adapter->resourceParams($kind, (string) $ref);
            $bySlug = [];
            foreach ($providerParams as $param) {
                $slug = (string) ($param['slug'] ?? '');
                if ($slug !== '') {
                    $bySlug[$slug] = $param;
                }
            }

            $configured = $report->params;
            $unknown = [];
            foreach ($configured as $param) {
                if (! isset($bySlug[$param->name])) {
                    $unknown[] = $param->name;
                }
            }

            if ($unknown !== []) {
                return new ValidationResult(
                    InsightValidationStatus::ParamMismatch,
                    [
                        'reason' => 'unknown_slugs',
                        'unknown_slugs' => $unknown,
                        'message' => 'Configured param slugs are missing on the provider resource.',
                    ],
                    $at,
                );
            }

            $modeMismatches = [];
            foreach ($configured as $param) {
                $provider = $bySlug[$param->name];
                $providerMode = (string) ($provider['embedding_mode'] ?? 'disabled');
                $ourBinding = $param->binding;

                if ($providerMode === 'disabled') {
                    $modeMismatches[] = [
                        'slug' => $param->name,
                        'our_binding' => $ourBinding->value,
                        'provider_mode' => $providerMode,
                        'instruction' => 'Enable or lock `'.$param->name.'` in the resource\'s embed settings.',
                    ];

                    continue;
                }

                if ($ourBinding === InsightParamBinding::Locked && $providerMode !== 'locked') {
                    $modeMismatches[] = [
                        'slug' => $param->name,
                        'our_binding' => $ourBinding->value,
                        'provider_mode' => $providerMode,
                        'instruction' => 'Set `'.$param->name.'` to Locked in the dashboard\'s embed settings.',
                    ];
                }

                if ($ourBinding === InsightParamBinding::Default && $providerMode !== 'enabled') {
                    $modeMismatches[] = [
                        'slug' => $param->name,
                        'our_binding' => $ourBinding->value,
                        'provider_mode' => $providerMode,
                        'instruction' => 'Set `'.$param->name.'` to Editable in the dashboard\'s embed settings.',
                    ];
                }
            }

            if ($modeMismatches !== []) {
                return new ValidationResult(
                    InsightValidationStatus::ParamMismatch,
                    [
                        'reason' => 'embedding_mode_mismatch',
                        'mismatches' => $modeMismatches,
                        'message' => $modeMismatches[0]['instruction'],
                    ],
                    $at,
                );
            }

            $typeMismatches = [];
            foreach ($configured as $param) {
                $providerType = (string) ($bySlug[$param->name]['type'] ?? 'string');
                $ourType = null;

                if ($param->value_source === InsightParamValueSource::Dynamic) {
                    $ourType = DynamicParams::typeOf((string) $param->dynamic_key);
                } elseif (is_array($param->static_value)) {
                    $ourType = 'array';
                } else {
                    $ourType = 'scalar';
                }

                if ($ourType === null) {
                    continue;
                }

                $ourIsArray = str_starts_with($ourType, 'array');
                $providerIsArray = self::providerTypeIsArray($providerType);

                if ($ourIsArray !== $providerIsArray) {
                    $typeMismatches[] = [
                        'slug' => $param->name,
                        'our_type' => $ourType,
                        'provider_type' => $providerType,
                        'instruction' => 'Param `'.$param->name.'` type does not agree with the provider (array vs scalar).',
                    ];
                }
            }

            if ($typeMismatches !== []) {
                return new ValidationResult(
                    InsightValidationStatus::ParamMismatch,
                    [
                        'reason' => 'type_mismatch',
                        'mismatches' => $typeMismatches,
                        'message' => $typeMismatches[0]['instruction'],
                    ],
                    $at,
                );
            }

            $configuredNames = $configured->pluck('name')->all();
            $missingRequired = [];
            foreach ($bySlug as $slug => $provider) {
                if (! ($provider['required'] ?? false)) {
                    continue;
                }
                if (! in_array($slug, $configuredNames, true)) {
                    $missingRequired[] = $slug;
                }
            }

            if ($missingRequired !== []) {
                return new ValidationResult(
                    InsightValidationStatus::ParamMismatch,
                    [
                        'reason' => 'required_params_missing',
                        'missing_slugs' => $missingRequired,
                        'message' => 'Required provider params have no binding: '.implode(', ', $missingRequired),
                    ],
                    $at,
                );
            }

            return new ValidationResult(InsightValidationStatus::Valid, null, $at);
        } catch (DiscoveryException $e) {
            return new ValidationResult(
                InsightValidationStatus::Unreachable,
                ['reason' => $e->reasonKey],
                $at,
            );
        } catch (Throwable) {
            return new ValidationResult(
                InsightValidationStatus::Unreachable,
                ['reason' => 'provider_unreachable'],
                $at,
            );
        }
    }

    /**
     * Metabase has no first-class array param type; treat all declared types
     * as scalar so array<int> dynamic keys fail against typical filters.
     */
    private static function providerTypeIsArray(string $type): bool
    {
        $normalized = strtolower($type);

        return str_contains($normalized, '[]')
            || str_ends_with($normalized, '/list')
            || str_contains($normalized, 'array');
    }
}
