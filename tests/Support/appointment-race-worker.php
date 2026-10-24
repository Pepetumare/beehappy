<?php

use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Standalone process: independent PDO connection, coordinated start barrier.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $patientId, $therapistId, $date, $start, $key, $barrier, $number] = $argv;
if (getenv('BEEHAPPY_RUN_MYSQL_CONCURRENCY') !== '1') {
    exit(2);
}
$config = config('database.connections.mysql');
$config['database'] = 'beehappy_concurrency_test';
$config['url'] = null;
config(['database.connections.concurrency' => $config, 'database.default' => 'concurrency']);
DB::setDefaultConnection('concurrency');
file_put_contents($barrier.'/ready-'.$number, 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Barrier timeout');
        exit(3);
    }
    usleep(10000);
}
try {
    $actor = User::findOrFail($patientId);
    $appointment = app(AppointmentService::class)->create($actor, (int) $therapistId, $date, $start, $key);
    echo json_encode(['result' => 'created', 'id' => $appointment->id]);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'conflict', 'fields' => array_keys($exception->errors())]);
}
