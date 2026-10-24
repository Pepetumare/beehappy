<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_apitherapist_registration_persists_the_account_type(): void
    {
        $this->post('/register', [
            'name' => 'Profesional BeeHappy', 'email' => 'profesional@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'apiterapeuta',
        ])->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'profesional@example.com', 'role' => 'apiterapeuta']);
        $this->get('/dashboard')->assertOk()->assertSee('Apiterapeuta')->assertSee('Cuidar también es conectar.');
    }

    public function test_registration_rejects_unknown_account_types(): void
    {
        $this->post('/register', [
            'name' => 'Test', 'email' => 'invalid@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'role' => 'admin',
        ])->assertSessionHasErrors('role');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
    }

    public function test_patient_sees_their_own_dashboard_and_profile(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'paciente']);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Paciente')->assertSee('Tu pausa de bienestar empieza aquí.');
        $this->get('/profile')->assertOk()->assertSee('Paciente');
    }
}
