<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AutomationRunResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'trigger_node_id' => $this->trigger_node_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'root_run_id' => $this->root_run_id,
            'depth' => $this->depth,
            'status' => $this->status,
            'trigger_payload' => $this->trigger_payload,
            'guard' => $this->guard,
            'error' => $this->error,
            'cancel_cause' => $this->cancel_cause,
            'cancelled_by' => $this->resolveCancelledBy(),
            'waiting_until' => $this->datetime($this->waiting_until),
            'current_node_id' => $this->current_node_id,
            'started_at' => $this->datetime($this->started_at),
            'completed_at' => $this->datetime($this->completed_at),
            'subject' => $this->whenLoaded('subject', fn () => $this->resolveSubject()),
            'causer' => $this->whenLoaded('causer', fn () => $this->resolveCauser()),
            'trigger_node' => $this->whenLoaded('triggerNode', function () {
                if ($this->triggerNode === null) {
                    return null;
                }

                $type = $this->triggerNode->type;

                return [
                    'id' => $this->triggerNode->id,
                    'type' => $type instanceof \BackedEnum ? $type->value : (string) $type,
                    'label' => $this->triggerNode->label,
                    'node_key' => $this->triggerNode->node_key,
                ];
            }),
            'steps' => AutomationRunStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }

    /** @return array{type: string|null, id: int|null, name: string|null, href_hint: string|null}|null */
    private function resolveSubject(): ?array
    {
        if ($this->subject_id === null && $this->subject_type === null) {
            return null;
        }

        $type = $this->subject_type;
        $id = $this->subject_id !== null ? (int) $this->subject_id : null;
        $subject = $this->subject;

        if ($subject === null) {
            return [
                'type' => $type,
                'id' => $id,
                'name' => $id !== null ? '#'.$id : null,
                'href_hint' => $this->hrefHintForType($type),
            ];
        }

        return [
            'type' => $type,
            'id' => $id,
            'name' => $this->displayNameForSubject($subject, $type, $id),
            'href_hint' => $this->hrefHintForType($type),
        ];
    }

    /** @return array{type: string|null, id: int|null, name: string|null}|null */
    private function resolveCauser(): ?array
    {
        if ($this->causer === null) {
            return null;
        }

        $name = $this->causer->name
            ?? trim(($this->causer->first_name ?? '').' '.($this->causer->last_name ?? ''));

        return [
            'type' => $this->causer_type,
            'id' => $this->causer->id !== null ? (int) $this->causer->id : null,
            'name' => $name !== '' ? $name : null,
        ];
    }

    /** @return array{id: int, name: string|null}|null */
    private function resolveCancelledBy(): ?array
    {
        if ($this->cancelled_by === null) {
            return null;
        }

        $id = (int) $this->cancelled_by;

        if ($this->relationLoaded('cancelledBy') && $this->cancelledBy !== null) {
            $name = trim((string) ($this->cancelledBy->name ?? ''));

            return [
                'id' => $id,
                'name' => $name !== '' ? $name : null,
            ];
        }

        return [
            'id' => $id,
            'name' => null,
        ];
    }

    private function displayNameForSubject(Model $subject, ?string $type, ?int $id): ?string
    {
        if ($subject instanceof Contact) {
            $name = trim($subject->first_name.' '.$subject->last_name);
            if ($name !== '') {
                return $name;
            }

            return $subject->email ?? ($id !== null ? '#'.$id : null);
        }

        if ($subject instanceof Deal) {
            $label = 'Deal #'.$subject->id;
            $contact = $subject->relationLoaded('contact') ? $subject->contact : null;
            if ($contact !== null) {
                $contactName = trim($contact->first_name.' '.$contact->last_name);
                if ($contactName !== '') {
                    return $label.' · '.$contactName;
                }
            }

            return $label;
        }

        $name = $subject->name
            ?? trim(($subject->first_name ?? '').' '.($subject->last_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $id !== null ? '#'.$id : null;
    }

    private function hrefHintForType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return match ($type) {
            'contact', Contact::class => 'contact',
            'deal', Deal::class => 'deal',
            default => null,
        };
    }
}
