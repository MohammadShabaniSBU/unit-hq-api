<?php

namespace App\Ai\Tools;

use App\Models\Contact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetContacts implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search and retrieve contacts from the CRM by name, email, status, or other filters.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = Contact::query();

        if ($request->has('search') && $request['search']) {
            $search = $request['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request['status']) {
            $query->where('status', $request['status']);
        }

        $limit = min($request['limit'] ?? 10, 50);
        $contacts = $query->latest()->limit($limit)->get();

        $formatted = $contacts->map(function ($contact) {
            return [
                'id' => $contact->id,
                'name' => "{$contact->first_name} {$contact->last_name}",
                'email' => $contact->email,
                'company' => $contact->company,
                'status' => $contact->status->value,
            ];
        });

        return json_encode([
            'success' => true,
            'total' => $contacts->count(),
            'contacts' => $formatted,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Search term for contact name, email, or company')
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter by contact lifecycle status')
                ->enum(['prospect', 'lead', 'opportunity', 'tenant', 'past_tenant', 'lost'])
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of contacts to return (max 50)')
                ->default(10)
                ->nullable(),
        ];
    }
}
