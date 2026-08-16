<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class AllowlistedParent
{
    /** @var list<string> */
    public const TYPES = ['contact', 'deal'];

    public static function resolve(string $type, int $id, AgentPrincipal $principal): Model|ToolResult
    {
        if (! in_array($type, self::TYPES, true)) {
            return ToolResult::error("Parent type [{$type}] is not allowed.");
        }

        $class = Relation::getMorphedModel($type);
        if ($class === null || ! is_a($class, Model::class, true)) {
            return ToolResult::error("Parent type [{$type}] is not allowed.");
        }

        /** @var Model|null $model */
        $model = $class::query()->find($id);
        if ($model === null) {
            return ToolResult::notFound('Parent not found.');
        }

        $contactId = self::contactId($model);
        if ($contactId === null) {
            return ToolResult::error('Parent has no contact.');
        }

        if ($principal->contactId !== null) {
            if (! $principal->ownsContact($contactId)) {
                return ToolResult::denied(
                    ToolDeniedReason::Ownership,
                    'Argument [related_to_id] does not belong to this principal.',
                );
            }

            return $model;
        }

        $contact = $model instanceof Contact
            ? $model
            : Contact::query()->find($contactId);

        if ($contact === null || $contact->source !== ContactSource::AiAgent) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'Anonymous writes may only attach to agent-sourced contacts.',
            );
        }

        return $model;
    }

    public static function contactId(Model $model): ?int
    {
        if ($model instanceof Contact) {
            return (int) $model->id;
        }

        if ($model instanceof Deal) {
            return (int) $model->contact_id;
        }

        $raw = $model->getAttribute('contact_id');

        return $raw !== null ? (int) $raw : null;
    }
}
