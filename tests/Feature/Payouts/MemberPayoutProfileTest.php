<?php

namespace Tests\Feature\Payouts;

use App\Domain\Operations\OperationsScanner;
use App\Domain\Payouts\MemberPayoutProfileService;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Filament\Actions\RevealPayoutDetailsActionFactory;
use App\Filament\Resources\AmbassadorResource;
use App\Filament\Resources\AmbassadorResource\Pages\ViewAmbassador;
use App\Filament\Resources\RewardResource;
use App\Filament\Resources\RewardResource\Pages\ViewReward;
use App\Livewire\AmbassadorPayoutSettings;
use App\Models\AmbassadorProfile;
use App\Models\AuditLog;
use App\Models\MemberPayoutProfile;
use App\Models\OperationsItem;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\MissingPayoutMethodNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MemberPayoutProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeVerifiedMember(array $userAttrs = []): array
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt(self::PASSWORD),
        ], $userAttrs));
        $user->assignRole(Role::Ambassador->value);
        $profile = AmbassadorProfile::factory()->for($user)->create();

        return [$user->fresh(), $profile->fresh()];
    }

    private function makePanelUser(Role $role): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt(self::PASSWORD),
            'mfa_enabled' => false,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    public function test_bank_transfer_is_the_supported_sensitive_payout_method(): void
    {
        $options = PayoutMethod::configurableOptions();

        $this->assertArrayHasKey(PayoutMethod::BankTransfer->value, $options);
        $this->assertArrayHasKey(PayoutMethod::AccountCredit->value, $options);
        $this->assertArrayNotHasKey(PayoutMethod::PayPal->value, $options);
        $this->assertSame($options, PayoutMethod::options());
    }

    public function test_rewards_member_can_open_payout_settings(): void
    {
        [$user] = $this->makeVerifiedMember();

        $this->actingAs($user)
            ->get(route('ambassador.payout-settings'))
            ->assertOk()
            ->assertSee('Payout Settings')
            ->assertSee('data-testid="ambassador-payout-settings-page"', false)
            ->assertSee('data-testid="nav-payout-settings"', false)
            ->assertSee('Bank Transfer')
            ->assertSee('Account Credit')
            ->assertDontSee('>PayPal<', false)
            ->assertDontSee('value="paypal"', false);
    }

    public function test_unverified_user_is_blocked(): void
    {
        [$user] = $this->makeVerifiedMember(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('ambassador.payout-settings'))
            ->assertRedirect();
    }

    public function test_non_member_user_is_blocked(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('ambassador.payout-settings'))
            ->assertForbidden();
    }

    public function test_bank_details_save_successfully_and_are_masked(): void
    {
        [$user] = $this->makeVerifiedMember();

        Livewire::actingAs($user)
            ->test(AmbassadorPayoutSettings::class)
            ->set('preferredMethod', PayoutMethod::BankTransfer->value)
            ->set('accountHolderName', 'Alex Example')
            ->set('sortCode', '12-34-56')
            ->set('accountNumber', '12345678')
            ->set('confirmPassword', self::PASSWORD)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('**-**-56')
            ->assertSee('****5678')
            ->assertSee('data-testid="payout-masked-account"', false)
            ->assertDontSee('Sort code: 12-34-56');

        $this->assertDatabaseCount('member_payout_profiles', 1);
        $this->assertTrue(
            AuditLog::query()->where('action', 'payout_profile.created')->exists()
        );
    }

    public function test_sort_code_and_account_number_are_encrypted_at_rest(): void
    {
        [$user, $profile] = $this->makeVerifiedMember();

        app(MemberPayoutProfileService::class)->save(
            $profile,
            $user,
            [
                'preferred_method' => PayoutMethod::BankTransfer,
                'account_holder_name' => 'Alex Example',
                'sort_code' => '12-34-56',
                'account_number' => '12345678',
            ],
            self::PASSWORD,
        );

        $raw = DB::table('member_payout_profiles')->where('ambassador_profile_id', $profile->id)->first();
        $this->assertNotSame('12-34-56', $raw->sort_code);
        $this->assertNotSame('12345678', $raw->account_number);
        $this->assertStringNotContainsString('12345678', (string) $raw->account_number);
        $this->assertStringNotContainsString('12-34-56', (string) $raw->sort_code);

        $model = MemberPayoutProfile::query()->where('ambassador_profile_id', $profile->id)->firstOrFail();
        $this->assertSame('12-34-56', $model->sort_code);
        $this->assertSame('12345678', $model->account_number);
    }

    public function test_plaintext_bank_details_do_not_appear_in_logs_or_audit(): void
    {
        [$user, $profile] = $this->makeVerifiedMember();

        Log::spy();

        app(MemberPayoutProfileService::class)->save(
            $profile,
            $user,
            [
                'preferred_method' => PayoutMethod::BankTransfer,
                'account_holder_name' => 'Alex Example',
                'sort_code' => '98-76-54',
                'account_number' => '87654321',
            ],
            self::PASSWORD,
        );

        $audit = AuditLog::query()->where('action', 'payout_profile.created')->latest('id')->first();
        $this->assertNotNull($audit);
        $payload = json_encode([$audit->before, $audit->after, $audit->context]);
        $this->assertStringNotContainsString('87654321', (string) $payload);
        $this->assertStringNotContainsString('98-76-54', (string) $payload);
        $this->assertStringNotContainsString('Alex Example', (string) $payload);
    }

    public function test_paypal_cannot_be_newly_configured(): void
    {
        [$user, $profile] = $this->makeVerifiedMember();

        Livewire::actingAs($user)
            ->test(AmbassadorPayoutSettings::class)
            ->set('preferredMethod', PayoutMethod::PayPal->value)
            ->set('confirmPassword', self::PASSWORD)
            ->call('save')
            ->assertHasErrors(['preferredMethod']);

        $this->expectException(ValidationException::class);
        app(MemberPayoutProfileService::class)->save(
            $profile,
            $user,
            [
                'preferred_method' => PayoutMethod::PayPal,
                'paypal_email' => 'payouts@example.com',
            ],
            self::PASSWORD,
        );
    }

    public function test_account_credit_requires_no_bank_details(): void
    {
        [$user] = $this->makeVerifiedMember();

        Livewire::actingAs($user)
            ->test(AmbassadorPayoutSettings::class)
            ->set('preferredMethod', PayoutMethod::AccountCredit->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('data-testid="payout-password-panel"', false);

        $model = MemberPayoutProfile::first();
        $this->assertSame(PayoutMethod::AccountCredit, $model->preferred_method);
        $this->assertTrue($model->isConfigured());
        $this->assertNull($model->paypal_email);
        $this->assertNull($model->account_number);
    }

    public function test_invalid_bank_details_are_rejected(): void
    {
        [$user] = $this->makeVerifiedMember();

        Livewire::actingAs($user)
            ->test(AmbassadorPayoutSettings::class)
            ->set('preferredMethod', PayoutMethod::BankTransfer->value)
            ->set('accountHolderName', 'Alex')
            ->set('sortCode', '123')
            ->set('accountNumber', '12')
            ->set('confirmPassword', self::PASSWORD)
            ->call('save')
            ->assertHasErrors(['sortCode', 'accountNumber']);
    }

    public function test_sensitive_updates_require_password_confirmation(): void
    {
        [$user] = $this->makeVerifiedMember();

        Livewire::actingAs($user)
            ->test(AmbassadorPayoutSettings::class)
            ->set('preferredMethod', PayoutMethod::BankTransfer->value)
            ->set('accountHolderName', 'Alex Example')
            ->set('sortCode', '12-34-56')
            ->set('accountNumber', '12345678')
            ->set('confirmPassword', 'wrong-password')
            ->call('save')
            ->assertHasErrors(['confirmPassword']);

        $this->assertDatabaseCount('member_payout_profiles', 0);
    }

    public function test_member_cannot_access_another_members_payout_profile(): void
    {
        [$userA, $profileA] = $this->makeVerifiedMember();
        [$userB] = $this->makeVerifiedMember();

        MemberPayoutProfile::factory()->forProfile($profileA)->bankTransfer()->create();

        $this->assertFalse(Gate::forUser($userB)->allows(
            'update',
            MemberPayoutProfile::query()->where('ambassador_profile_id', $profileA->id)->first()
        ));
        $this->assertFalse(Gate::forUser($userB)->allows(
            'view',
            MemberPayoutProfile::query()->where('ambassador_profile_id', $profileA->id)->first()
        ));

        Livewire::actingAs($userB)
            ->test(AmbassadorPayoutSettings::class)
            ->assertSet('isConfigured', false)
            ->assertDontSee('****5678');
    }

    public function test_switching_method_clears_irrelevant_encrypted_fields(): void
    {
        [$user, $profile] = $this->makeVerifiedMember();
        $service = app(MemberPayoutProfileService::class);

        $service->save($profile, $user, [
            'preferred_method' => PayoutMethod::BankTransfer,
            'account_holder_name' => 'Alex Example',
            'sort_code' => '12-34-56',
            'account_number' => '12345678',
        ], self::PASSWORD);

        $service->save($profile, $user, [
            'preferred_method' => PayoutMethod::AccountCredit,
        ], self::PASSWORD);

        $model = MemberPayoutProfile::first();
        $this->assertSame(PayoutMethod::AccountCredit, $model->preferred_method);
        $this->assertNull($model->sort_code);
        $this->assertNull($model->account_number);
        $this->assertNull($model->account_holder_name);
        $this->assertNull($model->paypal_email);
        $this->assertTrue(
            AuditLog::query()->where('action', 'payout_profile.method_changed')->exists()
        );
    }

    public function test_support_cannot_reveal_full_bank_details(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer()->create();
        $support = $this->makePanelUser(Role::Support);
        $payout = $profile->payoutProfile;

        $this->assertFalse(Gate::forUser($support)->allows('reveal', $payout));

        $this->actingAs($support);
        Livewire::test(ViewAmbassador::class, ['record' => $profile->id])
            ->assertOk()
            ->assertActionHidden('revealPayoutDetails');
    }

    public function test_admin_reveal_opens_modal_and_is_audited_without_plaintext(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer(
            holder: 'Alex Example',
            sortCode: '12-34-56',
            accountNumber: '12345678',
        )->create();
        $admin = $this->makePanelUser(Role::Admin);
        $payout = $profile->fresh()->payoutProfile;

        $this->assertTrue(Gate::forUser($admin)->allows('reveal', $payout));

        $this->actingAs($admin);
        Livewire::test(ViewAmbassador::class, ['record' => $profile->id])
            ->assertOk()
            ->assertActionVisible('revealPayoutDetails')
            ->callAction('revealPayoutDetails', data: [
                'reason' => 'Processing approved reward payout',
                'password' => self::PASSWORD,
            ])
            ->assertActionMounted(RevealPayoutDetailsActionFactory::SHOW_ACTION)
            ->assertMountedActionModalSee([
                'Payout details',
                'Alex Example',
                '12-34-56',
                '12345678',
                'Sensitive payout information',
            ]);

        $audit = AuditLog::query()->where('action', 'payout_profile.details_revealed')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $payload = json_encode([$audit->before, $audit->after, $audit->context]);
        $this->assertStringNotContainsString('12345678', (string) $payload);
        $this->assertStringNotContainsString('12-34-56', (string) $payload);
        $this->assertStringNotContainsString('Alex Example', (string) $payload);
        $this->assertStringContainsString('Processing approved reward payout', (string) $payload);
        $this->assertSame('ambassador_admin', data_get($audit->context, 'source'));
    }

    public function test_reward_and_ambassador_reveal_paths_share_secure_behaviour(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer(
            holder: 'Alex Example',
            sortCode: '12-34-56',
            accountNumber: '12345678',
        )->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $admin = $this->makePanelUser(Role::Admin);
        $this->actingAs($admin);

        Livewire::test(ViewReward::class, ['record' => $reward->id])
            ->assertOk()
            ->assertActionVisible('revealPayoutDetails')
            ->callAction('revealPayoutDetails', data: [
                'reason' => 'Manual bank transfer for approved reward',
                'password' => self::PASSWORD,
            ])
            ->assertActionMounted(RevealPayoutDetailsActionFactory::SHOW_ACTION)
            ->assertMountedActionModalSee(['12345678', '12-34-56', 'Alex Example']);

        $audit = AuditLog::query()->where('action', 'payout_profile.details_revealed')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('reward_admin', data_get($audit->context, 'source'));
        $this->assertSame($reward->id, data_get($audit->context, 'reward_id'));
        $payload = json_encode($audit->context);
        $this->assertStringNotContainsString('12345678', (string) $payload);
    }

    public function test_normal_admin_views_expose_only_masked_details(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer(
            holder: 'Alex Example',
            sortCode: '12-34-56',
            accountNumber: '12345678',
        )->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $admin = $this->makePanelUser(Role::Admin);
        $this->actingAs($admin);

        $this->get(RewardResource::getUrl('view', ['record' => $reward]))
            ->assertOk()
            ->assertSeeText('Claimed payout method')
            ->assertSeeText('Current member preference')
            ->assertSeeText('Bank Transfer')
            ->assertSeeText('Destination configured')
            ->assertSeeText('Yes')
            ->assertSeeText('****5678')
            ->assertDontSee('12345678')
            ->assertDontSee('12-34-56')
            ->assertDontSee('Alex Example');

        $this->get(AmbassadorResource::getUrl('view', ['record' => $profile]))
            ->assertOk()
            ->assertSeeText('Payout preference')
            ->assertSeeText('Bank Transfer')
            ->assertSeeText('****5678')
            ->assertDontSee('12345678')
            ->assertDontSee('12-34-56');
    }

    public function test_reward_resource_warns_when_payout_not_configured(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $admin = $this->makePanelUser(Role::Admin);
        $this->actingAs($admin);

        $this->get(RewardResource::getUrl('view', ['record' => $reward]))
            ->assertOk()
            ->assertSeeText('Not configured')
            ->assertSeeText('No payout method configured');
    }

    public function test_approved_reward_can_be_marked_paid_with_payment_metadata(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer()->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $admin = $this->makePanelUser(Role::Admin);

        $ok = app(RewardsEngine::class)->markPaid(
            $reward,
            $admin,
            'Paid via Faster Payments',
            PayoutMethod::BankTransfer->value,
            'FP-REF-1001',
        );

        $this->assertTrue($ok);
        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertNotNull($reward->paid_at);
        $this->assertSame($admin->id, $reward->paid_by_user_id);
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->payment_method);
        $this->assertSame('FP-REF-1001', $reward->payment_reference);
        $this->assertSame('Paid via Faster Payments', $reward->note);
        $this->assertTrue(AuditLog::query()->where('action', 'reward.paid')->exists());
    }

    public function test_mark_paid_action_on_reward_view_records_metadata(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer()->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $admin = $this->makePanelUser(Role::Admin);
        $this->actingAs($admin);

        Livewire::test(ViewReward::class, ['record' => $reward->id])
            ->assertActionVisible('markPaid')
            ->callAction('markPaid', data: [
                'payment_reference' => 'BANK-99',
                'note' => 'Sent manually',
            ]);

        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->payment_method);
        $this->assertSame('BANK-99', $reward->payment_reference);
        $this->assertSame('Sent manually', $reward->note);
        $this->assertSame($admin->id, $reward->paid_by_user_id);
    }

    public function test_no_payout_details_are_stored_in_operations_metadata(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer(
            sortCode: '11-22-33',
            accountNumber: '99887766',
        )->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now()->subHours(100),
            'paid_at' => null,
            'amount_minor' => 5000,
        ]);

        app(OperationsScanner::class)->scan();

        $item = OperationsItem::query()
            ->where('dedupe_key', 'reward-approved-awaiting-payment:'.$reward->id)
            ->first();
        $this->assertNotNull($item);
        $meta = json_encode($item->meta);
        $this->assertStringNotContainsString('99887766', (string) $meta);
        $this->assertStringNotContainsString('11-22-33', (string) $meta);
        $this->assertTrue((bool) data_get($item->meta, 'payout_configured'));
        $this->assertSame('bank_transfer', data_get($item->meta, 'preferred_payout_method'));
    }

    public function test_missing_payout_prompt_is_sent_once_on_reward_approval(): void
    {
        Notification::fake();
        [$user, $profile] = $this->makeVerifiedMember();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'pending_approval',
            'amount_minor' => 5000,
        ]);

        app(RewardsEngine::class)->approve($reward, $this->makePanelUser(Role::Admin));

        Notification::assertSentTo($user, MissingPayoutMethodNotification::class);
        $this->assertNotNull($profile->fresh()->payout_prompt_sent_at);

        $reward2 = Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'pending_approval',
            'amount_minor' => 2500,
            'milestone_index' => 2,
        ]);
        app(RewardsEngine::class)->approve($reward2, $this->makePanelUser(Role::Admin));
        Notification::assertSentToTimes($user, MissingPayoutMethodNotification::class, 1);
    }

    public function test_dashboard_prompts_when_approved_reward_lacks_payout_method(): void
    {
        [$user, $profile] = $this->makeVerifiedMember();
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
        ]);

        $this->actingAs($user)
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->assertSee('Add your payout details to receive your reward.')
            ->assertSee('data-testid="payout-details-prompt"', false);
    }

    public function test_ambassador_admin_view_shows_masked_payout_section(): void
    {
        [, $profile] = $this->makeVerifiedMember();
        MemberPayoutProfile::factory()->forProfile($profile)->bankTransfer()->create();
        $admin = $this->makePanelUser(Role::Admin);

        $this->actingAs($admin)
            ->get(AmbassadorResource::getUrl('view', ['record' => $profile]))
            ->assertOk()
            ->assertSeeText('Payout preference')
            ->assertSeeText('Bank Transfer')
            ->assertSeeText('****5678')
            ->assertDontSee('12345678');
    }
}
