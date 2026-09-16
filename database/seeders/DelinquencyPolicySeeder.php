<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DelinquencyPolicyAction;
use App\Models\DelinquencyPolicy;
use App\Support\Country\CountryProfiles;
use App\Support\Country\EsProfile;
use App\Support\Country\FrProfile;
use App\Support\Country\GbProfile;
use Illuminate\Database\Seeder;

class DelinquencyPolicySeeder extends Seeder
{
    public function run(): void
    {
        $flavour = CountryProfiles::current()->delinquencyFlavour();
        $code = CountryProfiles::current()->code();

        if ($flavour === (new EsProfile)->delinquencyFlavour()) {
            $this->seed($code, 'ES standard', [
                ['offset_days' => 5, 'action' => DelinquencyPolicyAction::AssessLateFee, 'params' => [
                    'type' => 'percent', 'percent' => '10.00', 'cap_per_case' => '50.00',
                ]],
                ['offset_days' => 8, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'overdue',
                ]],
                ['offset_days' => 8, 'action' => DelinquencyPolicyAction::RevokeAccess, 'params' => []],
                ['offset_days' => 12, 'action' => DelinquencyPolicyAction::PlaceOverlock, 'params' => []],
                ['offset_days' => 20, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'final_demand',
                ]],
                ['offset_days' => 20, 'action' => DelinquencyPolicyAction::CreateTask, 'params' => [
                    'title_key' => 'delinquency.task.final_demand', 'urgent' => true,
                ]],
            ]);

            return;
        }

        if ($flavour === (new GbProfile)->delinquencyFlavour()) {
            $this->seed($code, 'GB standard', [
                ['offset_days' => 7, 'action' => DelinquencyPolicyAction::AssessLateFee, 'params' => [
                    'type' => 'flat', 'amount' => '10.00',
                ]],
                ['offset_days' => 10, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'overdue',
                ]],
                ['offset_days' => 10, 'action' => DelinquencyPolicyAction::RevokeAccess, 'params' => []],
                ['offset_days' => 14, 'action' => DelinquencyPolicyAction::PlaceOverlock, 'params' => []],
                ['offset_days' => 21, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'final_demand',
                ]],
                ['offset_days' => 21, 'action' => DelinquencyPolicyAction::CreateTask, 'params' => [
                    'title_key' => 'delinquency.task.final_demand', 'urgent' => true,
                ]],
            ]);

            return;
        }

        if ($flavour === (new FrProfile)->delinquencyFlavour()) {
            $this->seed($code, 'FR draft', [
                ['offset_days' => 8, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'overdue',
                ]],
                ['offset_days' => 15, 'action' => DelinquencyPolicyAction::RevokeAccess, 'params' => []],
                ['offset_days' => 21, 'action' => DelinquencyPolicyAction::PlaceOverlock, 'params' => []],
                ['offset_days' => 30, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                    'notice_type' => 'final_demand',
                ]],
            ], archived: true);
        }
    }

    /**
     * @param  list<array{offset_days: int, action: DelinquencyPolicyAction, params: array<string, mixed>}>  $steps
     */
    private function seed(string $jurisdiction, string $name, array $steps, bool $archived = false): void
    {
        $policy = DelinquencyPolicy::query()->firstOrCreate(
            ['name' => $name],
            [
                'jurisdiction' => $jurisdiction,
                'auto_release_overlock' => true,
                'auto_restore_access' => true,
                'archived_at' => $archived ? now() : null,
            ],
        );

        if ($policy->steps()->exists()) {
            return;
        }

        foreach (array_values($steps) as $sort => $step) {
            $policy->steps()->create([
                'offset_days' => $step['offset_days'],
                'action' => $step['action'],
                'params' => $step['params'],
                'sort' => $sort,
            ]);
        }
    }
}
