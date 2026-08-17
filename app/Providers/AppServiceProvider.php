<?php

namespace App\Providers;

use App\Events\ChannelDeliveryFailed;
use App\Events\ModelCreated;
use App\Events\ModelDeleted;
use App\Events\ModelUpdated;
use App\Listeners\BroadcastCopilotFailed;
use App\Listeners\BroadcastCopilotToolInvoking;
use App\Listeners\IncrementAiUsageToolCalls;
use App\Listeners\QueueAutomationMatching;
use App\Listeners\RecordAgentFailoverUsage;
use App\Listeners\RecordAgentUsage;
use App\Listeners\SettleFailedAiUsage;
use App\Listeners\WriteChannelSuppression;
use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessSuspension;
use App\Models\AgentConversation;
use App\Models\AutopayAttempt;
use App\Models\BillingRun;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractNotice;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Invoice;
use App\Models\Note;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Session\MorphDatabaseSessionHandler;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Agents\SalesAgentDefinition;
use App\Support\Ai\Agents\SupportAgentDefinition;
use App\Support\Ai\Drivers\CassetteDriver;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\LaravelAiDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Guards\CompositeGuardrailPipeline;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\HandoffEvaluator;
use App\Support\Ai\Guards\HandoffRules;
use App\Support\Ai\Tools\AccessStatusTool;
use App\Support\Ai\Tools\BillingBalanceTool;
use App\Support\Ai\Tools\BillingInvoicesTool;
use App\Support\Ai\Tools\BillingNextChargeTool;
use App\Support\Ai\Tools\ContractSummaryTool;
use App\Support\Ai\Tools\CrmCreateContactTool;
use App\Support\Ai\Tools\CrmCreateDealTool;
use App\Support\Ai\Tools\CrmCreateNoteTool;
use App\Support\Ai\Tools\CrmCreateTaskTool;
use App\Support\Ai\Tools\EscalateTool;
use App\Support\Ai\Tools\FacilityAvailabilityTool;
use App\Support\Ai\Tools\FacilitySiteInfoTool;
use App\Support\Ai\Tools\KbFaqLookupTool;
use App\Support\Ai\Tools\PricingDiscountsTool;
use App\Support\Ai\Tools\PricingQuoteTool;
use App\Support\Ai\Tools\SalesProposeOfferTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Communications\ProviderRegistry;
use App\Support\Communications\ProviderResolver;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\Insights\AnalyticsProviderRegistry;
use App\Support\RequestId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ProviderResolver::class);
        $this->app->singleton(ESignProviderRegistry::class);
        $this->app->singleton(AccessProviderRegistry::class);
        $this->app->singleton(AnalyticsProviderRegistry::class);

        $this->app->singleton(AgentRegistry::class, function (): AgentRegistry {
            $registry = new AgentRegistry;
            $registry->register(new SupportAgentDefinition);
            $registry->register(new SalesAgentDefinition);

            return $registry;
        });

        $this->app->singleton(ToolRegistry::class, function (): ToolRegistry {
            $registry = new ToolRegistry;
            $registry->register(new FacilityAvailabilityTool);
            $registry->register(new FacilitySiteInfoTool);
            $registry->register(new PricingQuoteTool);
            $registry->register(new PricingDiscountsTool);
            $registry->register(new SalesProposeOfferTool);
            $registry->register(new CrmCreateContactTool);
            $registry->register(new CrmCreateDealTool);
            $registry->register(new CrmCreateTaskTool);
            $registry->register(new CrmCreateNoteTool);
            $registry->register(new ContractSummaryTool);
            $registry->register(new BillingBalanceTool);
            $registry->register(new BillingNextChargeTool);
            $registry->register(new BillingInvoicesTool);
            $registry->register(new AccessStatusTool);
            $registry->register(new KbFaqLookupTool);
            $registry->register(new EscalateTool);

            return $registry;
        });

        $this->app->singleton(HandoffEvaluator::class, HandoffRules::class);
        $this->app->singleton(GuardrailPipeline::class, CompositeGuardrailPipeline::class);

        $this->app->singleton(ModelDriver::class, function ($app): ModelDriver {
            return match ((string) config('agents.driver')) {
                'fake' => new FakeModelDriver,
                'cassette' => new CassetteDriver,
                default => $app->make(LaravelAiDriver::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('invitation', function (Request $request) {
            $token = (string) $request->route('token', '');

            return Limit::perMinute(10)->by($token.'|'.$request->ip());
        });

        RateLimiter::for('insights-embed', function (Request $request) {
            $employeeId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $key = (string) $request->route('key', '');

            return Limit::perMinute(1)->by('insights-embed|'.$employeeId.'|'.$key);
        });

        RateLimiter::for('ai-turns', function (Request $request) {
            $employeeId = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(30)->by('ai-turns|'.$employeeId);
        });

        Relation::morphMap([
            'employee' => Employee::class,
            'contact' => Contact::class,
            'deal' => Deal::class,
            'offer' => Offer::class,
            'reservation' => Reservation::class,
            'unit' => Unit::class,
            'contract' => Contract::class,
            'insurance' => Insurance::class,
            'unit_class_rate' => UnitClassRate::class,
            'insurance_rate' => InsuranceRate::class,
            'task' => Task::class,
            'note' => Note::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'autopay_attempt' => AutopayAttempt::class,
            'billing_run' => BillingRun::class,
            'delinquency' => Delinquency::class,
            'contract_notice' => ContractNotice::class,
            'access_point' => AccessPoint::class,
            'access_suspension' => AccessSuspension::class,
            'access_grant' => AccessGrant::class,
            'access_provider_account' => AccessProviderAccount::class,
            'access_event' => AccessEvent::class,
            'agent_conversation' => AgentConversation::class,
        ]);

        Session::extend('database', function ($app) {
            $config = $app->make('config');
            $connection = $app['db']->connection($config->get('session.connection'));

            return new MorphDatabaseSessionHandler(
                $connection,
                $config->get('session.table'),
                $config->get('session.lifetime'),
                $app,
            );
        });

        Queue::createPayloadUsing(function (): array {
            $requestId = RequestId::get();

            return $requestId !== null ? ['request_id' => $requestId] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            $requestId = $event->job->payload()['request_id'] ?? null;

            if (is_string($requestId) && $requestId !== '') {
                RequestId::set($requestId);
            }
        });

        Event::listen(ModelCreated::class, [QueueAutomationMatching::class, 'handle']);
        Event::listen(ModelUpdated::class, [QueueAutomationMatching::class, 'handle']);
        Event::listen(ModelDeleted::class, [QueueAutomationMatching::class, 'handle']);
        Event::listen(ChannelDeliveryFailed::class, [WriteChannelSuppression::class, 'handle']);
        Event::listen(InvokingTool::class, [BroadcastCopilotToolInvoking::class, 'handle']);
        Event::listen(JobFailed::class, [BroadcastCopilotFailed::class, 'handle']);
        Event::listen(JobFailed::class, [SettleFailedAiUsage::class, 'handle']);
        Event::listen(AgentPrompted::class, [RecordAgentUsage::class, 'handle']);
        Event::listen(AgentStreamed::class, [RecordAgentUsage::class, 'handle']);
        Event::listen(ToolInvoked::class, [IncrementAiUsageToolCalls::class, 'handle']);
        Event::listen(AgentFailedOver::class, [RecordAgentFailoverUsage::class, 'handle']);
    }
}
