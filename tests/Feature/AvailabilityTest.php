<?php

namespace Tests\Feature;

use App\Models\TherapistProfile;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 08:00', 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function therapist(): TherapistProfile
    {
        return User::factory()->create(['role' => 'apiterapeuta'])->therapistProfile;
    }

    private function shift(TherapistProfile $profile, string $start = '09:00', string $end = '12:00', int $day = 1): void
    {
        $profile->schedules()->create(['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
    }

    private function slots(TherapistProfile $profile, string $date = '2026-10-26'): array
    {
        return app(AvailabilityService::class)->forDate($profile, $date);
    }

    private function block(TherapistProfile $profile, string $start, string $end): void
    {
        $profile->blocks()->create([
            'starts_at' => CarbonImmutable::parse($start, 'America/Santiago')->utc()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($end, 'America/Santiago')->utc()->format('Y-m-d H:i:s'),
            'reason' => 'Motivo secreto',
        ]);
    }

    public function test_schedule_updates_are_owned_and_ignore_browser_owner_ids(): void
    {
        $mine = $this->therapist();
        $other = $this->therapist();
        $this->shift($other);
        $this->actingAs($mine->user)->put(route('therapist.schedules.update'), [
            'therapist_profile_id' => $other->id,
            'schedules' => [['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:00']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $mine->schedules()->count());
        $this->assertSame(1, $other->schedules()->count());
        $this->assertSame('09:00', substr($other->schedules()->first()->start_time, 0, 5));
        $this->actingAs($other->user)->get(route('therapist.schedules.edit'))->assertOk();
    }

    public function test_overlap_is_rejected_but_adjacent_shifts_are_allowed(): void
    {
        $profile = $this->therapist();
        $this->actingAs($profile->user);
        $rows = [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
            ['day_of_week' => 1, 'start_time' => '12:59', 'end_time' => '18:00'],
        ];
        $this->put(route('therapist.schedules.update'), ['schedules' => $rows])->assertSessionHasErrors('schedules.1.start_time');
        $this->assertSame(0, $profile->schedules()->count());
        $rows[1]['start_time'] = '13:00';
        $this->put(route('therapist.schedules.update'), ['schedules' => $rows])->assertSessionHasNoErrors();
        $this->assertSame(2, $profile->schedules()->count());
    }

    public function test_invalid_and_overnight_shifts_do_not_replace_existing_rows(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        $this->actingAs($profile->user);
        foreach ([['22:00', '01:00'], ['09:00', '09:00'], ['25:00', '26:00']] as [$start, $end]) {
            $this->put(route('therapist.schedules.update'), [
                'schedules' => [['day_of_week' => 1, 'start_time' => $start, 'end_time' => $end]],
            ])->assertSessionHasErrors();
        }
        $this->assertSame(1, $profile->schedules()->count());
        $this->put(route('therapist.schedules.update'), ['schedules' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $profile->schedules()->count());
    }

    public function test_multiple_shifts_only_generate_complete_sessions_without_crossing_breaks(): void
    {
        $profile = $this->therapist();
        $this->shift($profile, '09:00', '10:40');
        $this->shift($profile, '14:00', '15:30');
        $slots = $this->slots($profile);
        $this->assertCount(4, $slots);
        $this->assertSame(['12:00', '12:45', '17:00', '17:45'], array_map(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->format('H:i'), $slots));
        foreach ($slots as $slot) {
            $this->assertSame(45.0, CarbonImmutable::parse($slot['starts_at'])->diffInMinutes(CarbonImmutable::parse($slot['ends_at'])));
        }
    }

    public function test_partial_blocks_use_half_open_intervals(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        // Blocks exactly the second slot. First ends at 09:45 and third starts at 10:30.
        $this->block($profile, '2026-10-26 09:45', '2026-10-26 10:30');
        $slots = $this->slots($profile);
        $this->assertCount(3, $slots);
        $this->assertSame(['12:00', '13:30', '14:15'], array_map(fn ($s) => CarbonImmutable::parse($s['starts_at'])->format('H:i'), $slots));
    }

    public function test_any_partial_overlap_excludes_the_whole_session(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        $this->block($profile, '2026-10-26 09:44', '2026-10-26 09:46');
        $this->assertCount(2, $this->slots($profile));
    }

    public function test_past_sessions_are_excluded_and_a_session_starting_now_is_available(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:45', 'America/Santiago'));
        $slots = $this->slots($profile);
        $this->assertCount(3, $slots);
        $this->assertSame('12:45', CarbonImmutable::parse($slots[0]['starts_at'])->format('H:i'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:45:01', 'America/Santiago'));
        $this->assertCount(2, $this->slots($profile));
    }

    public function test_inactive_or_not_accepting_profiles_have_no_availability(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        $profile->user->forceFill(['is_active' => false])->save();
        $this->assertSame([], $this->slots($profile));
        $profile->user->forceFill(['is_active' => true])->save();
        $profile->update(['accepts_appointments' => false]);
        $this->assertSame([], $this->slots($profile));
    }

    public function test_strict_dates_and_horizon_are_enforced_in_the_service(): void
    {
        $profile = $this->therapist();
        foreach (['2026-02-30', '2026-1-1', 'tomorrow', '2026-10-26junk', '2026-10-25', '2026-12-25'] as $date) {
            try {
                $this->slots($profile, $date);
                $this->fail('Expected invalid date: '.$date);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('date', $exception->errors());
            }
        }
        $this->assertSame([], $this->slots($profile, '2026-12-24'));
    }

    public function test_duration_and_gap_come_from_configuration(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        config(['appointments.session_minutes' => 30, 'appointments.gap_minutes' => 15]);
        $slots = $this->slots($profile);
        $this->assertCount(4, $slots);
        $this->assertSame(30.0, CarbonImmutable::parse($slots[0]['starts_at'])->diffInMinutes(CarbonImmutable::parse($slots[0]['ends_at'])));
        $this->assertSame(45.0, CarbonImmutable::parse($slots[0]['starts_at'])->diffInMinutes(CarbonImmutable::parse($slots[1]['starts_at'])));
    }

    public function test_block_creation_listing_and_deletion_are_scoped_to_the_owner(): void
    {
        $mine = $this->therapist();
        $other = $this->therapist();
        $this->block($other, '2026-10-26 09:00', '2026-10-26 10:00');
        $foreign = $other->blocks()->first();
        $this->actingAs($mine->user)->post(route('therapist.blocks.store'), [
            'therapist_profile_id' => $other->id, 'date' => '2026-10-26', 'all_day' => 1, 'reason' => 'Mi descanso',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $mine->blocks()->count());
        $this->assertSame(1, $other->blocks()->count());
        $this->get(route('therapist.blocks.index'))->assertOk()->assertSee('Mi descanso')->assertDontSee('Motivo secreto');
        $this->delete(route('therapist.blocks.destroy', $foreign))->assertNotFound();
        $this->assertDatabaseHas('schedule_blocks', ['id' => $foreign->id]);
        $own = $mine->blocks()->first();
        $this->delete(route('therapist.blocks.destroy', $own))->assertRedirect();
        $this->assertDatabaseMissing('schedule_blocks', ['id' => $own->id]);
    }

    public function test_full_day_block_removes_all_sessions_and_is_persisted_in_utc(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        $this->actingAs($profile->user)->post(route('therapist.blocks.store'), ['date' => '2026-10-26', 'all_day' => 1])->assertSessionHasNoErrors();
        $this->assertSame([], $this->slots($profile));
        $this->assertDatabaseHas('schedule_blocks', [
            'therapist_profile_id' => $profile->id,
            'starts_at' => '2026-10-26 03:00:00', 'ends_at' => '2026-10-27 03:00:00',
        ]);
    }

    public function test_partial_block_round_trips_between_santiago_and_utc(): void
    {
        $profile = $this->therapist();
        $this->actingAs($profile->user)->post(route('therapist.blocks.store'), [
            'date' => '2026-10-26', 'all_day' => 0, 'start_time' => '14:00', 'end_time' => '15:00',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('schedule_blocks', ['starts_at' => '2026-10-26 17:00:00', 'ends_at' => '2026-10-26 18:00:00']);
        $this->get(route('therapist.blocks.index'))->assertOk()->assertSee('26/10/2026 14:00 -03:00');
        $this->post(route('therapist.blocks.store'), ['date' => '2026-10-26', 'all_day' => 0, 'start_time' => '15:00', 'end_time' => '14:00'])->assertSessionHasErrors('end_time');
    }

    public function test_full_day_blocks_respect_both_dst_transitions(): void
    {
        $profile = $this->therapist();
        $this->actingAs($profile->user);
        // Chile repeats 23:00 on April 4, and skips 00:00 on September 6 in 2026.
        foreach ([['2026-04-04', 25], ['2026-09-06', 23]] as [$date, $hours]) {
            $this->post(route('therapist.blocks.store'), ['date' => $date, 'all_day' => 1])->assertSessionHasNoErrors();
            $block = $profile->blocks()->latest('id')->first();
            $this->assertSame((float) $hours, $block->starts_at->diffInHours($block->ends_at));
            $this->assertSame($date, $block->starts_at->setTimezone('America/Santiago')->toDateString());
            $this->assertSame('00:00', $block->ends_at->setTimezone('America/Santiago')->format('H:i'));
        }
    }

    public function test_nonexistent_local_block_time_is_rejected(): void
    {
        $profile = $this->therapist();
        $this->actingAs($profile->user)->post(route('therapist.blocks.store'), [
            'date' => '2026-09-06', 'all_day' => 0, 'start_time' => '00:15', 'end_time' => '02:00',
        ])->assertSessionHasErrors('start_time');
        $this->assertSame(0, $profile->blocks()->count());
    }

    public function test_availability_uses_seasonal_timezone_offsets(): void
    {
        $profile = $this->therapist();
        $this->shift($profile);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 08:00', 'America/Santiago'));
        $winter = $this->slots($profile, '2026-07-06');
        $this->assertSame('13:00', CarbonImmutable::parse($winter[0]['starts_at'])->format('H:i'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 08:00', 'America/Santiago'));
        $summer = $this->slots($profile);
        $this->assertSame('12:00', CarbonImmutable::parse($summer[0]['starts_at'])->format('H:i'));
    }

    public function test_service_traverses_the_repeated_hour_without_duplicate_instants(): void
    {
        config(['appointments.session_minutes' => 30]);
        $profile = $this->therapist();
        $this->shift($profile, '22:00', '23:59', 6);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 08:00', 'America/Santiago'));
        $slots = $this->slots($profile, '2026-04-04');
        $this->assertCount(4, $slots);
        $starts = array_column($slots, 'starts_at');
        $this->assertSame(count($starts), count(array_unique($starts)));
        foreach ($slots as $slot) {
            $this->assertSame(30.0, CarbonImmutable::parse($slot['starts_at'])->diffInMinutes(CarbonImmutable::parse($slot['ends_at'])));
        }
    }

    public function test_role_and_inactive_account_restrictions_cover_all_endpoints(): void
    {
        $profile = $this->therapist();
        $this->actingAs(User::factory()->create(['role' => 'paciente']));
        foreach ([['GET', '/mis-horarios'], ['PUT', '/mis-horarios'], ['PATCH', '/mis-horarios/perfil-profesional'], ['GET', '/mis-horarios/bloqueos'], ['POST', '/mis-horarios/bloqueos'], ['DELETE', '/mis-horarios/bloqueos/1']] as [$method, $url]) {
            $this->call($method, $url)->assertForbidden();
        }
        $this->actingAs($profile->user)->get('/apiterapeutas')->assertForbidden();
        $this->get('/apiterapeutas/'.$profile->id)->assertForbidden();
        $profile->user->forceFill(['is_active' => false])->save();
        $this->get('/mis-horarios')->assertForbidden();
    }

    public function test_repeated_clock_hour_does_not_allow_a_session_to_cross_a_local_break(): void
    {
        $profile = $this->therapist();
        $this->shift($profile, '22:00', '23:15', 6);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 08:00', 'America/Santiago'));
        $slots = $this->slots($profile, '2026-04-04');
        $this->assertCount(1, $slots);
        $this->assertSame('22:00', CarbonImmutable::parse($slots[0]['starts_at'])->setTimezone('America/Santiago')->format('H:i'));
    }

    public function test_patient_directory_is_paginated_filters_profiles_and_exposes_no_private_data(): void
    {
        $profile = $this->therapist();
        $profile->update(['commune' => 'Valdivia', 'introduction' => 'Presentación visible']);
        $this->shift($profile);
        $this->block($profile, '2026-10-26 10:00', '2026-10-26 11:00');
        $inactive = $this->therapist();
        $inactive->user->forceFill(['is_active' => false])->save();
        $paused = $this->therapist();
        $paused->update(['accepts_appointments' => false]);
        for ($i = 0; $i < 12; $i++) {
            $this->therapist();
        }
        $this->actingAs(User::factory()->create(['role' => 'paciente']));
        $this->get('/apiterapeutas')->assertOk()->assertSee('Valdivia')->assertSee('Presentación visible')
            ->assertDontSee($profile->user->email)->assertDontSee($inactive->user->name)->assertDontSee($paused->user->name)
            ->assertViewHas('therapists', fn ($rows) => $rows->count() === 12 && $rows->total() === 13);
        $this->get('/apiterapeutas/'.$profile->id.'?date=2026-10-26')->assertOk()
            ->assertSee('09:00–09:45')->assertSee('Todos los horarios corresponden a Santiago de Chile.')
            ->assertDontSee('09:00 -03:00')->assertDontSee('Motivo secreto')->assertDontSee($profile->user->email)
            ->assertSee('No crea una reserva.', false);
        $this->get('/apiterapeutas/'.$inactive->id)->assertNotFound();
        $this->get('/apiterapeutas/'.$paused->id)->assertNotFound();
        $this->get('/apiterapeutas/'.$profile->id.'?date=2026-10-27')->assertSee('No hay horarios disponibles');
        $this->assertDatabaseCount('schedule_blocks', 1);
    }

    public function test_http_date_validation_cannot_be_bypassed(): void
    {
        $profile = $this->therapist();
        $this->actingAs(User::factory()->create(['role' => 'paciente']));
        foreach (['2026-02-30', '2026-12-25', '2026-10-25', 'tomorrow'] as $date) {
            $this->getJson('/apiterapeutas/'.$profile->id.'?date='.$date)->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->getJson('/apiterapeutas/'.$profile->id.'?date[]=2026-10-26')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->get('/apiterapeutas/'.$profile->id.'?date=2026-12-24')->assertOk();
    }

    public function test_professional_data_updates_are_owned_and_allow_pausing_the_listing(): void
    {
        $mine = $this->therapist();
        $other = $this->therapist();
        $this->actingAs($mine->user)->patch('/mis-horarios/perfil-profesional', [
            'therapist_profile_id' => $other->id, 'commune' => 'Mariquina',
            'introduction' => 'Mi presentación', 'accepts_appointments' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($mine->fresh()->accepts_appointments);
        $this->assertSame('Mariquina', $mine->fresh()->commune);
        $this->assertNull($other->fresh()->commune);
        $this->assertTrue($other->fresh()->accepts_appointments);
    }

    public function test_guests_and_inactive_patients_cannot_access_availability(): void
    {
        $this->get('/mis-horarios')->assertRedirect(route('login'));
        $this->get('/mis-horarios/bloqueos')->assertRedirect(route('login'));
        $this->get('/apiterapeutas')->assertRedirect(route('login'));
        $patient = User::factory()->create(['role' => 'paciente']);
        $patient->forceFill(['is_active' => false])->save();
        $this->actingAs($patient)->get('/apiterapeutas')->assertForbidden();
    }

    public function test_invalid_block_dates_do_not_persist_and_foreign_blocks_do_not_affect_availability(): void
    {
        $mine = $this->therapist();
        $other = $this->therapist();
        $this->shift($mine);
        $this->block($other, '2026-10-26 00:00', '2026-10-27 00:00');
        $this->assertCount(4, $this->slots($mine));
        $this->actingAs($mine->user);
        foreach (['2026-02-30', 'yesterday', '2026-1-2'] as $date) {
            $this->post('/mis-horarios/bloqueos', ['date' => $date, 'all_day' => 1])->assertSessionHasErrors('date');
        }
        $this->assertSame(0, $mine->blocks()->count());
    }

    public function test_additive_migration_can_resume_without_overwriting_professional_data(): void
    {
        $profile = $this->therapist();
        $profile->update(['commune' => 'Valdivia', 'accepts_appointments' => false]);
        $this->shift($profile);
        $migration = require database_path('migrations/2026_10_24_000000_create_therapist_availability_tables.php');
        $migration->up();
        $this->assertSame(1, TherapistProfile::count());
        $this->assertSame('Valdivia', $profile->fresh()->commune);
        $this->assertFalse($profile->fresh()->accepts_appointments);
        $this->assertSame(1, $profile->schedules()->count());
    }

    public function test_schema_has_profile_foreign_keys_and_interval_indexes(): void
    {
        foreach (['therapist_schedules', 'schedule_blocks'] as $table) {
            $keys = Schema::getForeignKeys($table);
            $this->assertSame('therapist_profiles', $keys[0]['foreign_table']);
            $this->assertSame(['therapist_profile_id'], $keys[0]['columns']);
        }
        $this->assertTrue(Schema::hasIndex('therapist_schedules', 'therapist_schedule_day_start'));
        $this->assertTrue(Schema::hasIndex('schedule_blocks', 'therapist_block_interval'));
    }
}
