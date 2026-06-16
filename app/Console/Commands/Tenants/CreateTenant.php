<?php

namespace App\Console\Commands\Tenants;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Domain;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create
                            {name : Human-readable tenant name}
                            {slug : URL-safe slug used as tenant ID}
                            {domain : Domain or subdomain for the tenant}';

    protected $description = 'Create a tenant and its domain together';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = $this->argument('slug');
        $domain = $this->argument('domain');

        if (Tenant::query()->where('slug', $slug)->orWhere('id', $slug)->exists()) {
            $this->error("A tenant with slug [{$slug}] already exists.");

            return self::FAILURE;
        }

        if (Domain::query()->where('domain', $domain)->exists()) {
            $this->error("Domain [{$domain}] is already in use.");

            return self::FAILURE;
        }

        $tenant = Tenant::create([
            'id' => $slug,
            'name' => $name,
            'slug' => $slug,
        ]);

        $tenant->createDomain($domain);

        $this->info("Tenant [{$tenant->name}] created successfully.");
        $this->line("  ID: {$tenant->id}");
        $this->line("  Domain: {$domain}");

        return self::SUCCESS;
    }
}
