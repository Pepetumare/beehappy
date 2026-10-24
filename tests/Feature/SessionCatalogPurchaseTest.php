<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Enums\SessionOfferType;
use App\Models\Appointment;
use App\Models\Purchase;
use App\Models\SessionOffer;
use App\Models\TherapistProfile;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\PurchaseService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SessionCatalogPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        return User::factory()->create(['role' => User::ROLE_PATIENT]);
    }

    private function therapist(): TherapistProfile
    {
        return User::factory()->create(['role' => User::ROLE_THERAPIST])->therapistProfile;
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Pack bienestar', 'description' => 'Diez sesiones con tu profesional.', 'type' => 'pack', 'sessions_count' => 10, 'price_clp' => 220000, 'active' => 1], $overrides);
    }

    private function offer(?TherapistProfile $profile = null, array $overrides = []): SessionOffer
    {
        return ($profile ?? $this->therapist())->sessionOffers()->create($this->data($overrides));
    }

    private function buy(User $patient, SessionOffer $offer, ?string $key = null): Purchase
    {
        return app(PurchaseService::class)->create($patient, $offer->id, $key ?? (string) Str::uuid());
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_therapist_offer_ownership_cannot_be_forged_on_create_edit_or_toggle(): void
    {
        $mine = $this->therapist();
        $other = $this->therapist();
        $foreign = $this->offer($other);
        $this->actingAs($mine->user)->post('/catalogo', $this->data(['therapist_profile_id' => $other->id, 'duration_minutes' => 1]))->assertSessionHasNoErrors();
        $created = SessionOffer::latest('id')->first();
        $this->assertSame($mine->id, $created->therapist_profile_id);
        $this->get(route('offers.edit', $foreign))->assertNotFound();
        $this->put(route('offers.update', $foreign), $this->data())->assertNotFound();
        $this->patch(route('offers.toggle', $foreign), ['active' => 0])->assertNotFound();
        $this->assertTrue($foreign->fresh()->active);
        $this->get('/catalogo')->assertOk()->assertViewHas('offers', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $created->id);
        $this->put(route('offers.update', $created), $this->data(['name' => 'Mi oferta editada', 'therapist_profile_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($mine->id, $created->fresh()->therapist_profile_id);
        $this->assertSame('Mi oferta editada', $created->fresh()->name);
        $this->assertSame(45, config('appointments.session_minutes'));
    }

    public function test_administrator_manages_all_offers_without_reassigning_sellers(): void
    {
        $seller = $this->therapist();
        $other = $this->therapist();
        $offer = $this->offer($seller);
        $admin = $this->administrator();
        $this->actingAs($admin)->get('/catalogo')->assertOk()->assertSee($offer->name);
        $this->get('/catalogo/crear')->assertOk()->assertSee($seller->user->name);
        $this->post('/catalogo', $this->data(['therapist_profile_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('session_offers', ['therapist_profile_id' => $other->id]);
        $this->put(route('offers.update', $offer), $this->data(['name' => 'Actualizada por administración', 'therapist_profile_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($seller->id, $offer->fresh()->therapist_profile_id);
        $this->patch(route('offers.toggle', $offer), ['active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($offer->fresh()->active);
        $this->get('/dashboard')->assertOk()->assertSee('Administración de sesiones y packs');
        $this->get('/mis-horarios')->assertForbidden();
        $this->post('/reservas', [])->assertForbidden();
        $this->get('/apiterapeutas')->assertForbidden();
        $this->assertNull($admin->patientProfile);
        $this->assertNull($admin->therapistProfile);
    }

    public function test_public_registration_cannot_create_or_escalate_to_an_administrator(): void
    {
        $this->get('/register')->assertOk()->assertDontSee('value="administrador"', false);
        foreach (['administrador', 'admin', 'therapist'] as $index => $role) {
            $this->post('/register', ['name' => 'Inválido', 'email' => "invalid-$index@example.com", 'password' => 'password', 'password_confirmation' => 'password', 'role' => $role])->assertSessionHasErrors('role');
            $this->assertGuest();
        }
        $patient = $this->patient();
        $this->actingAs($patient)->patch('/profile', ['name' => $patient->name, 'email' => $patient->email, 'role' => User::ROLE_ADMINISTRATOR])->assertSessionHasNoErrors();
        $this->assertSame(User::ROLE_PATIENT, $patient->fresh()->role);
    }

    public function test_type_quantity_price_and_boolean_fields_are_validated(): void
    {
        $this->actingAs($this->therapist()->user);
        foreach ([['type' => 'unknown'], ['type' => 'single', 'sessions_count' => 2], ['type' => 'pack', 'sessions_count' => 1], ['sessions_count' => 0], ['sessions_count' => 101], ['sessions_count' => 2.5], ['price_clp' => 0], ['price_clp' => -1], ['price_clp' => '25000.50'], ['price_clp' => '25.000'], ['price_clp' => 2147483648], ['active' => 'yes']] as $override) {
            $this->post('/catalogo', $this->data($override))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('session_offers', 0);
        foreach ([['type' => 'single', 'sessions_count' => 1, 'price_clp' => 25000], ['sessions_count' => 2], ['sessions_count' => 100]] as $valid) {
            $this->post('/catalogo', $this->data($valid))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('session_offers', 3);
    }

    public function test_patient_cannot_manage_offers_and_inactive_accounts_are_rejected(): void
    {
        $offer = $this->offer();
        $patient = $this->patient();
        $this->actingAs($patient)->get('/catalogo')->assertForbidden();
        $this->post('/catalogo', $this->data())->assertForbidden();
        $this->put(route('offers.update', $offer), $this->data())->assertForbidden();
        $this->patch(route('offers.toggle', $offer), ['active' => 0])->assertForbidden();
        $patient->forceFill(['is_active' => false])->save();
        $this->get(route('purchases.review', $offer))->assertForbidden();
        $this->post('/compras', [])->assertForbidden();
    }

    public function test_only_available_offers_are_public_and_review_is_informational(): void
    {
        $seller = $this->therapist();
        $active = $this->offer($seller, ['name' => 'Oferta visible']);
        $inactive = $this->offer($seller, ['name' => 'Oferta oculta', 'active' => 0]);
        $patient = $this->patient();
        $this->actingAs($patient)->get(route('therapists.show', $seller))->assertOk()->assertSee('Oferta visible')->assertDontSee('Oferta oculta')->assertSee('$220.000 CLP')->assertDontSee($seller->user->email);
        $this->get(route('purchases.review', $active))->assertOk()->assertSee('Estas sesiones son válidas únicamente con '.$seller->user->name.'.')->assertSee('Confirmar solicitud');
        $this->get(route('purchases.review', $inactive))->assertNotFound();
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_purchase_ignores_browser_price_quantity_identity_status_currency_and_seller(): void
    {
        $patient = $this->patient();
        $otherPatient = $this->patient();
        $seller = $this->therapist();
        $otherSeller = $this->therapist();
        $offer = $this->offer($seller);
        $this->actingAs($patient)->post('/compras', [
            'session_offer_id' => $offer->id, 'idempotency_key' => (string) Str::uuid(),
            'patient_profile_id' => $otherPatient->patientProfile->id, 'therapist_profile_id' => $otherSeller->id,
            'total_clp' => 1, 'price_clp' => 1, 'sessions_count' => 100, 'status' => 'paid', 'currency' => 'USD',
        ])->assertRedirect();
        $purchase = Purchase::sole();
        $this->assertSame($patient->patientProfile->id, $purchase->patient_profile_id);
        $this->assertSame($seller->id, $purchase->therapist_profile_id);
        $this->assertSame($offer->id, $purchase->session_offer_id);
        $this->assertSame(220000, $purchase->total_clp);
        $this->assertSame(10, $purchase->sessions_count);
        $this->assertSame('CLP', $purchase->currency);
        $this->assertSame(PurchaseStatus::PendingPayment, $purchase->status);
        $this->assertNotEmpty($purchase->reference);
        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee('Pendiente de pago')->assertSee('Aún no se han acreditado sesiones.')->assertDontSee($seller->user->email);
    }

    public function test_unavailable_offers_professionals_and_stale_patient_state_are_rechecked(): void
    {
        $patient = $this->patient();
        $seller = $this->therapist();
        $offer = $this->offer($seller);
        $offer->update(['active' => false]);
        $this->invalid(fn () => $this->buy($patient, $offer), 'offer');
        $offer->update(['active' => true]);
        $seller->update(['accepts_appointments' => false]);
        $this->invalid(fn () => $this->buy($patient, $offer), 'offer');
        $this->actingAs($patient)->get(route('purchases.review', $offer))->assertNotFound();
        $seller->update(['accepts_appointments' => true]);
        $seller->user->forceFill(['is_active' => false])->save();
        $this->invalid(fn () => $this->buy($patient, $offer), 'offer');
        $seller->user->forceFill(['is_active' => true, 'role' => User::ROLE_PATIENT])->save();
        $this->invalid(fn () => $this->buy($patient, $offer), 'offer');
        $this->assertDatabaseCount('purchases', 0);
        $seller->user->forceFill(['role' => User::ROLE_THERAPIST])->save();
        User::whereKey($patient->id)->update(['is_active' => false]);
        $this->actingAs($patient)->post('/compras', ['session_offer_id' => $offer->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_snapshots_survive_offer_edits_and_deactivation(): void
    {
        $seller = $this->therapist();
        $offer = $this->offer($seller);
        $patient = $this->patient();
        $purchase = $this->buy($patient, $offer);
        $this->actingAs($seller->user)->put(route('offers.update', $offer), $this->data(['name' => 'Sesión nueva', 'type' => 'single', 'sessions_count' => 1, 'price_clp' => 25000, 'active' => 0]))->assertSessionHasNoErrors();
        $historical = $purchase->fresh();
        $this->assertSame('Pack bienestar', $historical->offer_name);
        $this->assertSame(SessionOfferType::Pack, $historical->offer_type);
        $this->assertSame(10, $historical->sessions_count);
        $this->assertSame(220000, $historical->total_clp);
        $this->assertSame($seller->id, $historical->therapist_profile_id);
        $this->actingAs($patient)->get(route('purchases.show', $purchase))->assertOk()->assertSee('Pack bienestar')->assertSee('$220.000 CLP')->assertDontSee('Sesión nueva');
    }

    public function test_idempotency_is_per_patient_and_cancelled_requests_never_reactivate(): void
    {
        $patient = $this->patient();
        $offer = $this->offer();
        $key = (string) Str::uuid();
        $first = $this->buy($patient, $offer, $key);
        $this->assertSame($first->id, $this->buy($patient, $offer, strtoupper($key))->id);
        $offer->update(['price_clp' => 1, 'sessions_count' => 2, 'active' => false]);
        $this->assertSame(220000, $this->buy($patient, $offer, $key)->total_clp);
        app(PurchaseService::class)->cancel($patient, $first->reference);
        $retry = $this->buy($patient, $offer, $key);
        $this->assertSame(PurchaseStatus::Cancelled, $retry->status);
        $this->assertSame($first->reference, $retry->reference);
        $this->invalid(fn () => $this->buy($patient, $this->offer(), $key), 'idempotency_key');
        $this->buy($this->patient(), $this->offer(), $key);
        $this->assertDatabaseCount('purchases', 2);
    }

    public function test_purchase_authorization_covers_patient_seller_admin_and_cancellation(): void
    {
        $patient = $this->patient();
        $seller = $this->therapist();
        $purchase = $this->buy($patient, $this->offer($seller));
        $otherPatient = $this->patient();
        $otherSeller = $this->therapist();
        $admin = $this->administrator();
        foreach ([$otherPatient, $otherSeller->user] as $outsider) {
            $this->actingAs($outsider)->get(route('purchases.show', $purchase))->assertNotFound();
            $this->get('/compras')->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 0);
        }
        $this->actingAs($otherPatient)->post(route('purchases.cancel', $purchase))->assertNotFound();
        foreach ([$seller->user, $admin] as $reader) {
            $this->actingAs($reader)->get(route('purchases.show', $purchase))->assertOk();
            $this->get('/compras')->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 1);
            $this->post(route('purchases.cancel', $purchase))->assertForbidden();
            $this->post('/compras', ['session_offer_id' => $purchase->session_offer_id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->actingAs($patient)->post(route('purchases.cancel', $purchase))->assertSessionHasNoErrors();
        $stamp = $purchase->fresh()->cancelled_at->toIso8601String();
        $this->post(route('purchases.cancel', $purchase))->assertSessionHasNoErrors();
        $this->assertSame($stamp, $purchase->fresh()->cancelled_at->toIso8601String());
        $this->assertSame(PurchaseStatus::Cancelled, $purchase->fresh()->status);
    }

    public function test_purchases_have_no_effect_on_appointments_availability_or_session_balances(): void
    {
        $seller = $this->therapist();
        $patient = $this->patient();
        $day = CarbonImmutable::now(config('appointments.timezone'))->addDay();
        $seller->schedules()->create(['day_of_week' => $day->isoWeekday(), 'start_time' => '09:00', 'end_time' => '13:00']);
        $before = app(AvailabilityService::class)->forDate($seller, $day->toDateString());
        $this->buy($patient, $this->offer($seller));
        $this->assertSame($before, app(AvailabilityService::class)->forDate($seller, $day->toDateString()));
        $this->assertSame(0, Appointment::count());
        $this->assertSame(['pending_payment', 'cancelled'], array_column(PurchaseStatus::cases(), 'value'));
        foreach (['balance', 'credits', 'credited_sessions', 'remaining_sessions'] as $column) {
            $this->assertFalse(Schema::hasColumn('purchases', $column));
        }
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_catalog_and_purchase_lists_are_paginated_and_admin_sees_all_sellers(): void
    {
        $patient = $this->patient();
        $seller = $this->therapist();
        for ($i = 0; $i < 13; $i++) {
            $this->buy($patient, $this->offer($seller));
        }
        $otherPurchase = $this->buy($this->patient(), $this->offer());
        $this->actingAs($seller->user)->get('/catalogo')->assertOk()->assertViewHas('offers', fn ($rows) => $rows->total() === 13 && $rows->count() === 12);
        $this->get('/compras')->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 13 && $rows->count() === 12);
        $this->actingAs($patient)->get('/compras?page=2')->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 13 && $rows->count() === 1 && $rows->first()->id !== $otherPurchase->id);
        $this->actingAs($this->administrator())->get('/compras')->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->total() === 14);
    }

    public function test_offer_and_account_history_are_preserved_with_managed_errors(): void
    {
        $patient = $this->patient();
        $seller = $this->therapist();
        $offer = $this->offer($seller);
        $purchase = $this->buy($patient, $offer);
        app(PurchaseService::class)->cancel($patient, $purchase->reference);
        foreach ([$patient, $seller->user] as $owner) {
            $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('account', null, 'userDeletion');
            $this->assertAuthenticatedAs($owner);
        }
        try {
            $offer->delete();
            $this->fail('Expected purchase to restrict offer deletion.');
        } catch (QueryException) {
            $this->assertDatabaseHas('session_offers', ['id' => $offer->id]);
        }
        $this->actingAs($seller->user)->delete(route('offers.update', $offer))->assertStatus(405);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_database_prevents_linking_a_purchase_to_another_professionals_offer(): void
    {
        $purchase = $this->buy($this->patient(), $this->offer());
        $differentSeller = $this->therapist();
        try {
            $copy = $purchase->replicate();
            $copy->reference = (string) Str::ulid();
            $copy->idempotency_key = (string) Str::uuid();
            $copy->therapist_profile_id = $differentSeller->id;
            $copy->save();
            $this->fail('Expected a composite foreign key violation.');
        } catch (QueryException) {
            $this->assertDatabaseCount('purchases', 1);
        }
    }

    public function test_http_retries_return_the_original_purchase_and_no_paid_or_credit_actions_exist(): void
    {
        $patient = $this->patient();
        $offer = $this->offer();
        $data = ['session_offer_id' => $offer->id, 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($patient)->post('/compras', $data)->assertRedirect();
        $purchase = Purchase::sole();
        $this->post('/compras', $data)->assertRedirect(route('purchases.show', $purchase));
        $this->patch(route('purchases.show', $purchase), ['status' => 'paid'])->assertStatus(405);
        $this->post(route('purchases.show', $purchase).'/pagar')->assertNotFound();
        $this->post(route('purchases.show', $purchase).'/acreditar')->assertNotFound();
        $this->post(route('purchases.cancel', $purchase))->assertSessionHasNoErrors();
        $this->post('/compras', $data)->assertRedirect(route('purchases.show', $purchase));
        $this->assertSame(PurchaseStatus::Cancelled, $purchase->fresh()->status);
        $this->assertDatabaseCount('purchases', 1);
    }
}
