<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_existing_spanish_role_values_are_canonical(): void
    {
        $this->assertSame(['paciente', 'apiterapeuta', 'administrador'], array_keys(User::roleOptions()));
        $this->assertSame(['paciente', 'apiterapeuta'], array_keys(User::registrationRoleOptions()));
        $patient = new User(['role' => 'paciente']);
        $therapist = new User(['role' => 'apiterapeuta']);
        $this->assertTrue($patient->isPatient());
        $this->assertFalse($patient->isTherapist());
        $this->assertSame('Paciente', $patient->roleLabel());
        $this->assertTrue($therapist->isTherapist());
        $this->assertFalse($therapist->isPatient());
        $this->assertSame('Apiterapeuta', $therapist->roleLabel());
        foreach (['therapist', 'patient', 'admin', '', 'Apiterapeuta'] as $role) {
            $user = new User(['role' => $role]);
            $this->assertFalse($user->hasKnownRole());
            $this->assertFalse($user->hasRole($role));
            $this->assertFalse($user->isPatient());
            $this->assertFalse($user->isTherapist());
            $this->assertSame('Rol no reconocido', $user->roleLabel());
        }
    }

    public function test_patient_login_destination_menu_panel_and_authorization_agree(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_PATIENT]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk()->assertSee('Mi espacio · Paciente')
            ->assertSee('Ver apiterapeutas')->assertDontSee('Configurar horarios')
            ->assertSee('href="'.route('therapists.index').'"', false)
            ->assertDontSee('href="'.route('therapist.schedules.edit').'"', false);
        $this->get('/apiterapeutas')->assertOk();
        $this->get('/profile')->assertOk()->assertSee('Paciente');
        $this->get('/mis-horarios')->assertForbidden();
        $this->get('/mis-horarios/bloqueos')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => User::ROLE_PATIENT]);
    }

    public function test_therapist_login_destination_menu_panel_and_authorization_agree(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_THERAPIST]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk()->assertSee('Mi espacio · Apiterapeuta')
            ->assertSee('Configurar horarios')->assertDontSee('Ver apiterapeutas')
            ->assertSee('href="'.route('therapist.schedules.edit').'"', false)
            ->assertDontSee('href="'.route('therapists.index').'"', false);
        $this->get('/mis-horarios')->assertOk();
        $this->get('/mis-horarios/bloqueos')->assertOk();
        $this->get('/profile')->assertOk()->assertSee('Apiterapeuta');
        $this->get('/apiterapeutas')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => User::ROLE_THERAPIST]);
    }

    public function test_unknown_stored_roles_are_denied_without_being_rewritten(): void
    {
        foreach (['therapist', 'admin', 'patient', 'unexpected'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            foreach (['/dashboard', '/profile', '/mis-horarios', '/mis-horarios/bloqueos', '/apiterapeutas'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->put('/mis-horarios', ['schedules' => []])->assertForbidden();
            $this->patch('/profile', ['role' => User::ROLE_THERAPIST])->assertForbidden();
            $this->post('/mis-horarios/bloqueos')->assertForbidden();
            $this->delete('/mis-horarios/bloqueos/1')->assertForbidden();
            $this->assertSame($role, $user->fresh()->role);
            $this->assertNull($user->therapistProfile);
            $menu = view('layouts.navigation')->render();
            $this->assertStringNotContainsString(route('therapists.index'), $menu);
            $this->assertStringNotContainsString(route('therapist.schedules.edit'), $menu);
        }
    }

    public function test_unknown_roles_cannot_authenticate_even_with_correct_credentials(): void
    {
        foreach (['therapist', 'admin', 'patient', 'unexpected'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertSame($role, $user->fresh()->role);
        }
    }

    public function test_registration_rejects_noncanonical_roles_and_does_not_create_accounts(): void
    {
        foreach (['therapist', 'admin', 'patient', 'unexpected'] as $index => $role) {
            $this->post('/register', [
                'name' => 'Cuenta rechazada', 'email' => "invalid-$index@example.com",
                'password' => 'password', 'password_confirmation' => 'password', 'role' => $role,
            ])->assertSessionHasErrors('role');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('therapist_profiles', 0);
    }

    public function test_inactive_canonical_roles_cannot_login_or_view_a_role_panel(): void
    {
        foreach (array_keys(User::roleOptions()) as $role) {
            $user = User::factory()->create(['role' => $role]);
            $user->forceFill(['is_active' => false])->save();
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->get('/profile')->assertForbidden();
    }

    public function test_a_matching_unknown_middleware_parameter_does_not_grant_access(): void
    {
        Route::middleware(['web', 'auth', 'role:therapist'])->get('/role-regression', fn () => 'Should not be visible');
        $user = User::factory()->create(['role' => 'therapist']);
        $this->actingAs($user)->get('/role-regression')->assertForbidden();
    }

    public function test_intended_redirect_does_not_bypass_server_role_authorization(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_PATIENT]);
        $this->withSession(['url.intended' => route('therapist.schedules.edit')])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('therapist.schedules.edit'));
        $this->get('/mis-horarios')->assertForbidden();
    }

    public function test_profile_updates_do_not_allow_role_escalation(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_PATIENT]);
        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name, 'email' => $user->email, 'role' => User::ROLE_THERAPIST,
        ])->assertSessionHasNoErrors();
        $this->assertSame(User::ROLE_PATIENT, $user->fresh()->role);
        $this->get('/mis-horarios')->assertForbidden();
    }

    public function test_factory_and_seeder_explicitly_use_the_patient_role(): void
    {
        $this->assertSame(User::ROLE_PATIENT, User::factory()->make()->role);
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(User::where('email', 'test@example.com')->firstOrFail()->isPatient());
    }
}
