<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set Stripe keys for testing (using test keys)
        config(['services.stripe.secret' => 'sk_test_test_key']);
        config(['services.stripe.key' => 'pk_test_test_key']);
    }


    public function test_super_admin_can_list_subscription_plans(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);
        
        SubscriptionPlan::factory()->create(['name' => 'Basic Plan']);
        SubscriptionPlan::factory()->create(['name' => 'Pro Plan']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/subscription-plans/admin');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_non_super_admin_cannot_list_all_plans(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyAdmin($company, ['email' => 'company@test.com']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/subscription-plans/admin');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_subscription_plan(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);

        $planData = [
            'name' => 'Test Plan',
            'description' => 'Test Description',
            'amount' => 4999, // €49.99 in cents
            'currency' => 'eur',
            'interval' => 'month',
            'features' => ['Feature 1', 'Feature 2'],
            'is_active' => true,
        ];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/subscription-plans', $planData);

        $response->assertStatus(201)
            ->assertJson([
                'name' => 'Test Plan',
                'amount' => 4999,
                'currency' => 'eur',
                'interval' => 'month',
            ]);

        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Test Plan',
            'amount' => 4999,
        ]);
    }

    public function test_super_admin_can_update_subscription_plan(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/subscription-plans/{$plan->id}", [
                'name' => 'Updated Name',
                'amount' => 5999,
                'currency' => 'eur',
                'interval' => 'month',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'name' => 'Updated Name',
                'amount' => 5999,
            ]);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_super_admin_can_delete_subscription_plan_without_active_subscriptions(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);
        $plan = SubscriptionPlan::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/subscription-plans/{$plan->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('subscription_plans', ['id' => $plan->id]);
    }

    public function test_super_admin_cannot_delete_plan_with_active_subscriptions(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);
        $plan = SubscriptionPlan::factory()->create();
        $company = Company::factory()->create();
        
        Subscription::factory()->create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/subscription-plans/{$plan->id}");

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Cannot delete plan with active subscriptions',
            ]);
    }

    public function test_company_admin_can_view_their_subscription(): void
    {
        $company = $this->createCompany(['status' => 'approved', 'subscription_status' => 'approved']);
        $user = $this->createCompanyAdmin($company, ['email' => 'company@test.com']);
        $plan = SubscriptionPlan::factory()->create();
        
        Subscription::factory()->create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/subscription');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'subscription' => [
                    'id',
                    'status',
                    'plan',
                ],
            ]);
    }

    public function test_company_admin_can_create_checkout_session(): void
    {
        $company = $this->createCompany(['status' => 'approved', 'subscription_status' => 'approved']);
        $user = $this->createCompanyAdmin($company, ['email' => 'company@test.com']);
        $plan = SubscriptionPlan::factory()->create(['is_active' => true]);

        // Mock Stripe service to avoid actual API calls
        $this->mock(\App\Services\StripeService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn('https://checkout.stripe.com/test-session');
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/subscription/checkout');

        $response->assertStatus(200)
            ->assertJsonStructure(['checkout_url']);
    }

    public function test_company_admin_can_cancel_subscription(): void
    {
        $company = $this->createCompany(['status' => 'approved', 'subscription_status' => 'approved']);
        $user = $this->createCompanyAdmin($company, ['email' => 'company@test.com']);
        $plan = SubscriptionPlan::factory()->create();
        
        $subscription = Subscription::factory()->create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/subscription/cancel-subscription', [
                'at_period_end' => true,
            ]);

        $response->assertStatus(200);
        
        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
    }

    public function test_plan_creation_validates_required_fields(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/subscription-plans', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'amount', 'currency', 'interval']);
    }

    public function test_plan_amount_must_be_positive(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/subscription-plans', [
                'name' => 'Test',
                'amount' => -100,
                'currency' => 'eur',
                'interval' => 'month',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_plan_interval_must_be_valid(): void
    {
        $admin = $this->createSuperAdmin(['email' => 'admin@test.com']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/subscription-plans', [
                'name' => 'Test',
                'amount' => 1000,
                'currency' => 'eur',
                'interval' => 'invalid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interval']);
    }
}

