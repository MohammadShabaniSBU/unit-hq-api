<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\Note;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\SizeGuide;
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\Enums\EntityType;

final readonly class EntityRef
{
    public function __construct(
        public EntityType $type,
        public int $id,
        public string $label,
        public ?string $context = null,
    ) {}

    public static function of(EntityType $type, int $id, string $label, ?string $context = null): self
    {
        return new self($type, $id, $label, $context);
    }

    public static function site(Site $site, ?string $context = null): self
    {
        return new self(EntityType::Site, $site->id, $site->name, $context);
    }

    public static function unitClass(UnitClass $class, ?Site $site = null): self
    {
        return new self(
            EntityType::UnitClass,
            $class->id,
            $class->label,
            $site?->name,
        );
    }

    public static function contact(Contact $contact, ?string $context = null): self
    {
        $label = trim($contact->first_name.' '.$contact->last_name);
        if ($label === '') {
            $label = 'contact '.$contact->id;
        }

        return new self(EntityType::Contact, $contact->id, $label, $context);
    }

    public static function deal(Deal $deal, ?string $context = null): self
    {
        return new self(EntityType::Deal, $deal->id, 'deal '.$deal->id, $context);
    }

    public static function offer(Offer $offer, ?string $context = null): self
    {
        return new self(EntityType::Offer, $offer->id, 'offer '.$offer->id, $context);
    }

    public static function reservation(Reservation $reservation, ?string $context = null): self
    {
        return new self(EntityType::Reservation, $reservation->id, 'reservation '.$reservation->id, $context);
    }

    public static function contract(Contract $contract, ?string $context = null): self
    {
        return new self(EntityType::Contract, $contract->id, 'contract '.$contract->id, $context);
    }

    public static function discount(Discount $discount, ?string $context = null): self
    {
        return new self(EntityType::Discount, $discount->id, $discount->name, $context);
    }

    public static function sizeGuide(SizeGuide $guide, ?string $context = null): self
    {
        $label = $guide->unitClass?->label;
        if ($label === null || $label === '') {
            $min = $guide->min_size;
            $max = $guide->max_size;
            $label = ($min !== null && $max !== null) ? "{$min}–{$max} m²" : 'size guide '.$guide->id;
        }

        return new self(EntityType::SizeGuide, $guide->id, $label, $context ?? $guide->site?->name);
    }

    public static function task(Task $task, ?string $context = null): self
    {
        return new self(EntityType::Task, $task->id, $task->title !== '' ? $task->title : 'task '.$task->id, $context);
    }

    public static function note(Note $note, ?string $context = null): self
    {
        return new self(EntityType::Note, $note->id, 'note '.$note->id, $context);
    }

    public static function invoice(Invoice $invoice, ?string $context = null): self
    {
        $label = $invoice->full_number !== null && $invoice->full_number !== ''
            ? (string) $invoice->full_number
            : 'invoice '.$invoice->id;

        return new self(EntityType::Invoice, $invoice->id, $label, $context);
    }

    public static function unit(Unit $unit, ?string $context = null): self
    {
        return new self(EntityType::Unit, $unit->id, (string) $unit->unit_number, $context);
    }

    /**
     * @param  array{type: string, id: int, label: string, context?: string|null}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            EntityType::from($row['type']),
            (int) $row['id'],
            (string) $row['label'],
            isset($row['context']) ? (is_string($row['context']) ? $row['context'] : null) : null,
        );
    }

    /**
     * @return array{type: string, id: int, label: string, context: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'label' => $this->label,
            'context' => $this->context,
        ];
    }
}
