<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\UserStatedIdentifiers;

/**
 * Licensed EntityRefs for one conversation. Rebuilt from the append-only trace.
 * Conversation-scoped; not a table.
 */
final class FactRegistry
{
    /** @var array<string, EntityRef> */
    private array $refs = [];

    /**
     * @param  list<EntityRef>  $refs
     */
    public function absorb(EntityRef ...$refs): self
    {
        foreach ($refs as $ref) {
            $this->refs[$ref->type->value.':'.$ref->id] = $ref;
        }

        return $this;
    }

    public function contains(EntityType $type, int $id): bool
    {
        return isset($this->refs[$type->value.':'.$id]);
    }

    /**
     * @return list<int>
     */
    public function ids(EntityType $type): array
    {
        $ids = [];
        foreach ($this->refs as $ref) {
            if ($ref->type === $type) {
                $ids[] = $ref->id;
            }
        }

        return $ids;
    }

    public static function rebuild(AgentPrincipal $principal, ?AgentContext $ctx): self
    {
        $registry = new self;
        $registry->seedContext($principal, $ctx);

        if ($ctx === null) {
            return $registry;
        }

        $siteId = $principal->siteId ?? $ctx->conversation->site_id;
        foreach ($ctx->conversation->messages()
            ->where('role', AgentMessageRole::User->value)
            ->orderBy('sequence')
            ->get() as $message) {
            $body = $message->content;
            if (! is_string($body) || $body === '') {
                continue;
            }
            $registry->absorb(...UserStatedIdentifiers::extract($body, $siteId));
        }

        foreach ($ctx->conversation->toolInvocations()
            ->where('status', ToolInvocationStatus::Ok->value)
            ->orderBy('id')
            ->get() as $invocation) {
            $registry->absorb(...ToolResult::entitiesFromTrace($invocation->result));
        }

        return $registry;
    }

    private function seedContext(AgentPrincipal $principal, ?AgentContext $ctx): void
    {
        $siteId = $principal->siteId ?? $ctx?->conversation->site_id;
        if ($siteId !== null && $siteId > 0) {
            $this->absorb(EntityRef::of(EntityType::Site, $siteId, 'site '.$siteId));
        }

        $contactId = $principal->contactId;
        if ($contactId === null || $contactId <= 0) {
            return;
        }

        $this->absorb(EntityRef::of(EntityType::Contact, $contactId, 'contact '.$contactId));

        $contact = Contact::query()->with(['contracts.unitItem.item'])->find($contactId);
        if ($contact === null) {
            return;
        }

        foreach ($contact->contracts as $contract) {
            if (! $contract instanceof Contract) {
                continue;
            }
            $this->absorb(EntityRef::of(EntityType::Contract, $contract->id, 'contract '.$contract->id));
            $item = $contract->unitItem?->item;
            if (! $item instanceof Unit) {
                continue;
            }
            $this->absorb(EntityRef::unit($item));
            $unitSite = $item->site_id !== null ? Site::query()->find($item->site_id) : null;
            if ($unitSite instanceof Site) {
                $this->absorb(EntityRef::site($unitSite));
            }
        }
    }
}
