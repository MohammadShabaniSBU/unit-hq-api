<?php

namespace App\Ai\Tools;

use App\Models\Deal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetDeals implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search and retrieve deals from the CRM pipeline by status or other filters, including contact and opportunity details.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = Deal::query()->with('contact');

        if ($request->has('search') && $request['search']) {
            $search = $request['search'];
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request['status']) {
            $query->where('status', $request['status']);
        }

        $limit = min($request['limit'] ?? 10, 50);
        $deals = $query->latest()->limit($limit)->get();

        $formatted = $deals->map(function ($deal) {
            return [
                'id' => $deal->id,
                'contact_name' => "{$deal->contact->first_name} {$deal->contact->last_name}",
                'contact_email' => $deal->contact->email,
                'status' => $deal->status->value,
                'expected_move_in' => $deal->expected_move_in?->format('Y-m-d'),
                'storage_reason' => $deal->storage_reason?->value,
                'desired_size' => $deal->desired_size,
            ];
        });

        return json_encode([
            'success' => true,
            'total' => $deals->count(),
            'deals' => $formatted,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Search term for contact name or email')
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter by deal status in pipeline')
                ->enum(['new', 'contacted', 'qualified', 'offer_sent', 'offer_viewed', 'negotiating', 'closed_won', 'closed_lost', 'unresponsive'])
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of deals to return (max 50)')
                ->default(10)
                ->nullable(),
        ];
    }
}
