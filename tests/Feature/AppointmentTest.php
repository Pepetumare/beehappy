<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\TherapistProfile;
use App\Models\User;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-26 08:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function clock(string $value): void
    {
        $now = CarbonImmutable::parse($value, 'America/Santiago');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function patient(): User
    {
        return User::factory()->create(['role' => User::ROLE_PATIENT]);
    }

    private function therapist(string $start = '09:00', string $end = '13:00'): TherapistProfile
    {
        $profile = User::factory()->create(['role' => User::ROLE_THERAPIST])->therapistProfile;
        $profile->schedules()->create(['day_of_week' => 1, 'start_time' => $start, 'end_time' => $end]);

        return $profile;
    }

    private function book(User $patient, TherapistProfile $therapist, string $time = '09:00', ?string $key = null): Appointment
    {
        $start = CarbonImmutable::parse('2026-10-26 '.$time, 'America/Santiago')->utc()->toIso8601String();

        return app(AppointmentService::class)->create($patient, $therapist->id, '2026-10-26', $start, $key ?? (string) Str::uuid());
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

    public function test_valid_reservation_is_utc_and_removes_only_the_occupied_slot(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $before = app(AvailabilityService::class)->forDate($therapist, '2026-10-26');
        $appointment = $this->book($patient, $therapist);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame($patient->patientProfile->id, $appointment->patient_profile_id);
        $this->assertSame('2026-10-26 12:00:00', $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('12:45', $appointment->ends_at->format('H:i'));
        $this->assertCount(count($before) - 1, app(AvailabilityService::class)->forDate($therapist, '2026-10-26'));
        $this->actingAs($patient)->get(route('appointments.show', $appointment))->assertOk()->assertSee('26/10/2026 09:00')
            ->assertSee('Todos los horarios corresponden a Santiago de Chile.')->assertDontSee(' -03:00');
    }

    public function test_booking_ignores_browser_identity_duration_state_and_end_time(): void
    {
        $patient = $this->patient();
        $other = $this->patient();
        $therapist = $this->therapist();
        $this->actingAs($patient)->post('/reservas', [
            'therapist_id' => $therapist->id, 'date' => '2026-10-26', 'starts_at' => '2026-10-26T12:00:00+00:00',
            'idempotency_key' => (string) Str::uuid(), 'patient_profile_id' => $other->patientProfile->id,
            'duration_minutes' => 1, 'ends_at' => '2026-10-26 12:01:00', 'status' => 'completed',
        ])->assertRedirect();
        $appointment = Appointment::sole();
        $this->assertSame($patient->patientProfile->id, $appointment->patient_profile_id);
        $this->assertSame(45, $appointment->duration_minutes);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame('12:45', $appointment->ends_at->format('H:i'));
    }

    public function test_invalid_dates_past_off_grid_and_blocked_times_are_rejected(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        foreach (['2026-02-30', 'tomorrow', '2026-12-25', '2026-10-25'] as $date) {
            $this->invalid(fn () => app(AppointmentService::class)->create($patient, $therapist->id, $date, '2026-10-26T12:00:00+00:00', (string) Str::uuid()), 'date');
        }
        $this->invalid(fn () => $this->book($patient, $therapist, '09:10'), 'starts_at');
        $this->invalid(fn () => app(AppointmentService::class)->parseStart('2026-02-30T12:00:00+00:00'), 'starts_at');
        $this->actingAs($therapist->user)->post('/mis-horarios/bloqueos', ['date' => '2026-10-26', 'all_day' => 1])->assertSessionHasNoErrors();
        $this->invalid(fn () => $this->book($patient, $therapist), 'starts_at');
        $therapist->blocks()->delete(); // Isolated fixture only.
        $this->clock('2026-10-26 09:00');
        $this->invalid(fn () => $this->book($patient, $therapist), 'starts_at');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_dates_outside_horizon_are_rejected_even_when_the_timestamp_matches(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $this->invalid(fn () => app(AppointmentService::class)->create($patient, $therapist->id, '2026-12-25', '2026-12-25T12:00:00+00:00', (string) Str::uuid()), 'date');
    }

    public function test_same_therapist_cannot_be_booked_twice_and_contiguous_sessions_are_allowed(): void
    {
        $therapist = $this->therapist();
        $this->book($this->patient(), $therapist);
        $this->invalid(fn () => $this->book($this->patient(), $therapist), 'starts_at');
        $this->book($this->patient(), $therapist, '09:45');
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_patient_overlap_across_therapists_is_rejected_but_contiguity_is_allowed(): void
    {
        $patient = $this->patient();
        $this->book($patient, $this->therapist());
        $other = $this->therapist('09:15');
        $this->invalid(fn () => $this->book($patient, $other, '09:15'), 'starts_at');
        $this->book($patient, $this->therapist('09:45'), '09:45');
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_stored_professional_gap_is_respected_after_configuration_changes(): void
    {
        config(['appointments.gap_minutes' => 15]);
        $therapist = $this->therapist();
        $appointment = $this->book($this->patient(), $therapist);
        config(['appointments.gap_minutes' => 0]);
        $this->invalid(fn () => $this->book($this->patient(), $therapist, '09:45'), 'starts_at');
        $this->assertSame('13:00', $appointment->fresh()->busy_until->format('H:i'));
        $this->assertSame(15, $appointment->fresh()->gap_minutes);
        $this->assertSame(45, $appointment->fresh()->duration_minutes);
    }

    public function test_candidate_gap_before_an_existing_session_is_also_checked(): void
    {
        $therapist = $this->therapist('09:15');
        $this->book($this->patient(), $therapist, '10:00');
        config(['appointments.gap_minutes' => 15]);
        $this->invalid(fn () => $this->book($this->patient(), $therapist, '09:15'), 'starts_at');
    }

    public function test_patient_occupancy_does_not_include_the_professionals_gap(): void
    {
        config(['appointments.gap_minutes' => 30]);
        $patient = $this->patient();
        $this->book($patient, $this->therapist());
        $this->book($patient, $this->therapist('09:45'), '09:45');
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_idempotent_retries_return_the_same_appointment_even_after_cancel_or_config_change(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $key = (string) Str::uuid();
        $first = $this->book($patient, $therapist, '09:00', $key);
        config(['appointments.session_minutes' => 30, 'appointments.gap_minutes' => 10]);
        $this->assertSame($first->id, $this->book($patient, $therapist, '09:00', $key)->id);
        app(AppointmentService::class)->cancel($patient, $first, 'Cambio de planes');
        $this->clock('2027-02-01 09:00');
        $retry = $this->book($patient, $therapist, '09:00', $key);
        $this->assertSame(AppointmentStatus::Cancelled, $retry->status);
        $this->assertSame(45, $retry->duration_minutes);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_idempotency_key_reuse_for_different_data_is_rejected(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $key = (string) Str::uuid();
        $this->book($patient, $therapist, '09:00', $key);
        $this->invalid(fn () => $this->book($patient, $therapist, '09:45', $key), 'idempotency_key');
        $this->invalid(fn () => $this->book($patient, $this->therapist(), '09:00', $key), 'idempotency_key');
        $this->book($this->patient(), $this->therapist(), '09:00', $key);
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_cancellation_records_author_and_is_idempotent_and_releases_the_slot(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $appointment = $this->book($patient, $therapist);
        $cancelled = app(AppointmentService::class)->cancel($therapist->user, $appointment, 'No puedo atender');
        $stamp = $cancelled->cancelled_at->toIso8601String();
        $this->clock('2026-10-26 08:30');
        app(AppointmentService::class)->cancel($patient, $appointment, 'Otro motivo');
        $current = $appointment->fresh();
        $this->assertSame($stamp, $current->cancelled_at->toIso8601String());
        $this->assertSame($therapist->user_id, $current->cancelled_by);
        $this->assertSame('No puedo atender', $current->cancellation_reason);
        $this->book($this->patient(), $therapist);
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_only_future_scheduled_appointments_can_be_cancelled(): void
    {
        $patient = $this->patient();
        $appointment = $this->book($patient, $this->therapist());
        $this->clock('2026-10-26 09:00');
        $this->invalid(fn () => app(AppointmentService::class)->cancel($patient, $appointment), 'appointment');
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_only_the_owning_therapist_can_record_an_outcome_after_end_time(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $appointment = $this->book($patient, $therapist);
        $this->actingAs($therapist->user)->patch(route('appointments.outcome', $appointment), ['outcome' => 'completed'])->assertSessionHasErrors('appointment');
        $this->actingAs($patient)->patch(route('appointments.outcome', $appointment), ['outcome' => 'completed'])->assertForbidden();
        $this->clock('2026-10-26 09:45');
        $this->actingAs($therapist->user)->patch(route('appointments.outcome', $appointment), ['outcome' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->patch(route('appointments.outcome', $appointment), ['outcome' => 'no_show'])->assertSessionHasErrors('appointment');
        $this->post(route('appointments.cancel', $appointment))->assertSessionHasErrors('appointment');
        $this->patch(route('appointments.outcome', $appointment), ['outcome' => 'scheduled'])->assertSessionHasErrors('outcome');
    }

    public function test_no_show_and_cancelled_outcomes_are_validated(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $noShow = $this->book($patient, $therapist);
        $cancelled = $this->book($this->patient(), $therapist, '09:45');
        app(AppointmentService::class)->cancel($therapist->user, $cancelled);
        $this->clock('2026-10-26 11:00');
        app(AppointmentService::class)->recordOutcome($therapist->user, $noShow, AppointmentStatus::NoShow);
        $this->assertSame(AppointmentStatus::NoShow, $noShow->fresh()->status);
        $this->invalid(fn () => app(AppointmentService::class)->recordOutcome($therapist->user, $cancelled, AppointmentStatus::Completed), 'appointment');
    }

    public function test_direct_urls_and_mutations_of_other_users_appointments_are_denied(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $appointment = $this->book($patient, $therapist);
        foreach ([$this->patient(), $this->therapist()->user] as $outsider) {
            $this->actingAs($outsider)->get(route('appointments.show', $appointment))->assertNotFound();
            $this->post(route('appointments.cancel', $appointment))->assertNotFound();
            if ($outsider->isTherapist()) {
                $this->patch(route('appointments.outcome', $appointment), ['outcome' => 'completed'])->assertNotFound();
            }
        }
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
        $this->actingAs($therapist->user)->post('/reservas', [])->assertForbidden();
        $this->get('/mis-citas')->assertForbidden();
        $this->actingAs($patient)->get('/agenda')->assertForbidden();
    }

    public function test_schedule_changes_and_blocks_cannot_cover_existing_appointments(): void
    {
        $therapist = $this->therapist();
        $appointment = $this->book($this->patient(), $therapist);
        $this->actingAs($therapist->user);
        $this->put('/mis-horarios', ['schedules' => []])->assertSessionHasErrors('calendar');
        $this->put('/mis-horarios', ['schedules' => [['day_of_week' => 1, 'start_time' => '09:15', 'end_time' => '13:00']]])->assertSessionHasErrors('calendar');
        foreach ([['all_day' => 1], ['all_day' => 0, 'start_time' => '09:44', 'end_time' => '10:00']] as $block) {
            $this->post('/mis-horarios/bloqueos', ['date' => '2026-10-26'] + $block)->assertSessionHasErrors('calendar');
        }
        $this->assertSame(1, $therapist->schedules()->count());
        $this->assertSame(0, $therapist->blocks()->count());
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
        $this->post('/mis-horarios/bloqueos', ['date' => '2026-10-26', 'all_day' => 0, 'start_time' => '09:45', 'end_time' => '10:00'])->assertSessionHasNoErrors();
        app(AppointmentService::class)->cancel($therapist->user, $appointment);
        $this->put('/mis-horarios', ['schedules' => []])->assertSessionHasNoErrors();
    }

    public function test_calendar_changes_preserve_old_duration_and_do_not_require_a_new_slot_grid(): void
    {
        config(['appointments.gap_minutes' => 15]);
        $patient = $this->patient();
        $therapist = $this->therapist();
        $appointment = $this->book($patient, $therapist);
        config(['appointments.session_minutes' => 30, 'appointments.gap_minutes' => 0]);
        $this->actingAs($therapist->user)->put('/mis-horarios', ['schedules' => [['day_of_week' => 1, 'start_time' => '08:40', 'end_time' => '13:00']]])->assertSessionHasNoErrors();
        $this->post('/mis-horarios/bloqueos', ['date' => '2026-10-26', 'all_day' => 0, 'start_time' => '09:50', 'end_time' => '10:00'])->assertSessionHasErrors('calendar');
        $current = $appointment->fresh();
        $this->assertSame(45, $current->duration_minutes);
        $this->assertSame(15, $current->gap_minutes);
        $this->assertSame('12:45', $current->ends_at->format('H:i'));
    }

    public function test_inactive_accounts_or_paused_professionals_cannot_make_new_reservations(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $therapist->update(['accepts_appointments' => false]);
        $this->invalid(fn () => $this->book($patient, $therapist), 'starts_at');
        $therapist->update(['accepts_appointments' => true]);
        $therapist->user->forceFill(['is_active' => false])->save();
        $this->invalid(fn () => $this->book($patient, $therapist), 'starts_at');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_patient_history_agenda_summary_and_next_appointment_render_without_private_data(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $this->actingAs($patient)->get(route('appointments.review', ['therapist_id' => $therapist->id, 'date' => '2026-10-26', 'starts_at' => '2026-10-26T12:00:00+00:00']))->assertOk()->assertSee('Confirmar reserva')
            ->assertSee('09:00–09:45')->assertSee('Todos los horarios corresponden a Santiago de Chile.')
            ->assertDontSee(' -03:00')->assertSee('name="starts_at" value="2026-10-26T12:00:00+00:00"', false);
        $this->assertDatabaseCount('appointments', 0);
        $appointment = $this->book($patient, $therapist);
        $this->get('/dashboard')->assertSee('Ver mi próxima cita')->assertSee('26/10/2026 09:00');
        $this->get('/mis-citas')->assertOk()->assertSee($therapist->user->name)->assertDontSee($therapist->user->email);
        $this->actingAs($therapist->user)->get('/agenda?date=2026-10-26')->assertOk()->assertSee($patient->name)->assertDontSee($patient->email);
        $this->get('/agenda?date=2026-10-27')->assertSee('No hay citas para este día.');
        app(AppointmentService::class)->cancel($patient, $appointment);
        $this->actingAs($patient)->get('/mis-citas?tab=history')->assertOk()->assertSee('Cancelada');
        $this->get('/dashboard')->assertDontSee('Ver mi próxima cita');
    }

    public function test_account_deletion_with_history_is_rejected_without_logging_out_or_erasing_appointments(): void
    {
        $patient = $this->patient();
        $therapist = $this->therapist();
        $appointment = $this->book($patient, $therapist);
        app(AppointmentService::class)->cancel($patient, $appointment);
        foreach ([$patient, $therapist->user] as $owner) {
            $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('account', null, 'userDeletion');
            $this->assertAuthenticatedAs($owner);
            $this->assertDatabaseHas('users', ['id' => $owner->id]);
            $this->get('/profile')->assertSee('Tu cuenta tiene historial de citas');
        }
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_exact_professional_gap_boundary_is_available(): void
    {
        config(['appointments.gap_minutes' => 15]);
        $therapist = $this->therapist();
        $this->book($this->patient(), $therapist);
        $second = $this->book($this->patient(), $therapist, '10:00');
        $this->assertSame('13:00', $second->starts_at->format('H:i'));
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_non_cancelled_states_keep_their_occupancy(): void
    {
        foreach ([AppointmentStatus::Completed, AppointmentStatus::NoShow] as $status) {
            $therapist = $this->therapist();
            $appointment = $this->book($this->patient(), $therapist);
            // Deliberately inconsistent fixture to prove occupancy does not only check scheduled.
            $appointment->update(['status' => $status]);
            $this->invalid(fn () => $this->book($this->patient(), $therapist), 'starts_at');
        }
    }

    public function test_conflict_at_confirmation_returns_to_refreshable_availability(): void
    {
        $therapist = $this->therapist();
        $data = ['therapist_id' => $therapist->id, 'date' => '2026-10-26', 'starts_at' => '2026-10-26T12:00:00+00:00'];
        $patient = $this->patient();
        $this->actingAs($patient)->get(route('appointments.review', $data))->assertOk();
        $this->book($this->patient(), $therapist);
        $refresh = route('therapists.show', ['therapist' => $therapist, 'date' => $data['date']]);
        $this->from(route('appointments.review', $data))->post('/reservas', $data + ['idempotency_key' => (string) Str::uuid()])
            ->assertRedirect($refresh)->assertSessionHasErrors('starts_at');
        $this->get($refresh)->assertOk()->assertSee('Este horario ya no está disponible.');
        $this->get(route('appointments.review', $data))->assertRedirect($refresh);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_booking_repeated_santiago_times_persists_distinct_utc_instants(): void
    {
        config(['appointments.session_minutes' => 30]);
        $this->clock('2026-04-04 08:00');
        $therapist = $this->therapist();
        $therapist->schedules()->update(['day_of_week' => 6, 'start_time' => '22:00', 'end_time' => '23:59']);
        $patient = $this->patient();
        $slots = app(AvailabilityService::class)->forDate($therapist, '2026-04-04');
        $repeated = array_values(array_filter($slots, fn ($slot) => str_starts_with($slot['label'], '23:00')));
        $this->assertCount(2, $repeated);
        foreach ($repeated as $slot) {
            app(AppointmentService::class)->create($patient, $therapist->id, '2026-04-04', $slot['starts_at'], (string) Str::uuid());
        }
        $rows = Appointment::orderBy('starts_at')->get();
        $this->assertSame(60.0, $rows[0]->starts_at->diffInMinutes($rows[1]->starts_at));
        $this->assertSame('23:00 -03:00', $rows[0]->starts_at->setTimezone('America/Santiago')->format('H:i P'));
        $this->assertSame('23:00 -04:00', $rows[1]->starts_at->setTimezone('America/Santiago')->format('H:i P'));
    }

    public function test_history_is_paginated_and_never_contains_another_patients_appointments(): void
    {
        $therapist = $this->therapist();
        $patient = $this->patient();
        for ($i = 0; $i < 13; $i++) {
            $appointment = $this->book($patient, $therapist);
            app(AppointmentService::class)->cancel($patient, $appointment);
        }
        $foreign = $this->book($this->patient(), $therapist);
        $this->actingAs($patient)->get('/mis-citas?tab=history')->assertOk()
            ->assertViewHas('appointments', fn ($rows) => $rows->total() === 13 && $rows->count() === 12);
        $this->get('/mis-citas?tab=history&page=2')->assertOk()
            ->assertViewHas('appointments', fn ($rows) => $rows->count() === 1 && $rows->first()->id !== $foreign->id);
    }

    public function test_foreign_keys_prevent_deleting_an_account_and_its_history_directly(): void
    {
        $patient = $this->patient();
        $this->book($patient, $this->therapist());
        try {
            $patient->delete();
            $this->fail('Expected restrictive appointment foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $patient->id]);
            $this->assertDatabaseCount('appointments', 1);
        }
    }
}
