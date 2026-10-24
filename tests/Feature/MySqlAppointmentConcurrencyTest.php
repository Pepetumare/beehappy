<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real InnoDB tests. No RefreshDatabase, transaction wrapper, truncation or cleanup of data. */
class MySqlAppointmentConcurrencyTest extends TestCase
{
    private function prepareExclusiveDatabase(): void
    {
        if (getenv('BEEHAPPY_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Concurrencia MySQL omitida: requiere BEEHAPPY_RUN_MYSQL_CONCURRENCY=1 y base exclusiva beehappy_concurrency_test.');
        }
        $config = config('database.connections.mysql');
        if (! in_array($config['host'], ['localhost', '127.0.0.1'], true)) {
            $this->markTestSkipped('Solo se permite una conexión MySQL local para la prueba real.');
        }
        // Reuse installed environment credentials; only the database is selected in test config.
        $config['url'] = null;
        $config['database'] = 'information_schema';
        config(['database.connections.concurrency_admin' => $config]);
        try {
            DB::connection('concurrency_admin')->statement('CREATE DATABASE IF NOT EXISTS beehappy_concurrency_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $config['database'] = 'beehappy_concurrency_test';
            config(['database.connections.concurrency' => $config]);
            DB::setDefaultConnection('concurrency');
            $actual = DB::selectOne('select database() as name');
        } catch (\Throwable) {
            $this->markTestSkipped('MySQL local no disponible o sin permiso para la base exclusiva beehappy_concurrency_test; concurrencia real pendiente.');
        }
        $this->assertSame('beehappy_concurrency_test', $actual->name);
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
        $engines = DB::select("select ENGINE as engine from information_schema.TABLES where TABLE_SCHEMA = 'beehappy_concurrency_test' and TABLE_NAME in ('appointments','patient_profiles','therapist_profiles')");
        $this->assertCount(3, $engines);
        foreach ($engines as $engine) {
            $this->assertSame('InnoDB', $engine->engine);
        }
    }

    private function race(bool $samePatient): void
    {
        $this->prepareExclusiveDatabase();
        $day = CarbonImmutable::now(config('appointments.timezone'))->addDay();
        $date = $day->toDateString();
        $therapistA = User::factory()->create(['role' => User::ROLE_THERAPIST])->therapistProfile;
        $therapistB = $samePatient ? User::factory()->create(['role' => User::ROLE_THERAPIST])->therapistProfile : $therapistA;
        foreach (collect([$therapistA, $therapistB])->unique('id') as $therapist) {
            $therapist->schedules()->create(['day_of_week' => $day->isoWeekday(), 'start_time' => '09:00', 'end_time' => '13:00']);
        }
        $patientA = User::factory()->create(['role' => User::ROLE_PATIENT]);
        $patientB = $samePatient ? $patientA : User::factory()->create(['role' => User::ROLE_PATIENT]);
        $start = CarbonImmutable::parse($date.' 09:00', config('appointments.timezone'))->utc()->toIso8601String();
        $barrier = sys_get_temp_dir().'/beehappy-race-'.Str::uuid();
        mkdir($barrier, 0700, true);
        $processes = [];
        foreach ([[$patientA, $therapistA], [$patientB, $therapistB]] as $i => [$patient, $therapist]) {
            $processes[] = $process = new Process([
                PHP_BINARY, base_path('tests/Support/appointment-race-worker.php'), (string) $patient->id,
                (string) $therapist->id, $date, $start, (string) Str::uuid(), $barrier, (string) $i,
            ], base_path(), null, null, 30);
            $process->start();
        }
        try {
            $deadline = microtime(true) + 15;
            while (! (file_exists($barrier.'/ready-0') && file_exists($barrier.'/ready-1'))) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), 'Worker stopped before the barrier: '.$process->getErrorOutput());
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Independent workers did not reach the barrier.');
                }
                usleep(20000);
            }
            file_put_contents($barrier.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertEqualsCanonicalizing(['created', 'conflict'], array_column($results, 'result'));
            $this->assertSame(['starts_at'], collect($results)->firstWhere('result', 'conflict')['fields']);
            $this->assertSame(1, Appointment::whereIn('patient_profile_id', [$patientA->patientProfile->id, $patientB->patientProfile->id])->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_two_patients_racing_for_the_same_therapist_slot_create_only_one_appointment(): void
    {
        $this->race(false);
    }

    public function test_one_patient_racing_for_two_therapists_creates_only_one_appointment(): void
    {
        $this->race(true);
    }
}
