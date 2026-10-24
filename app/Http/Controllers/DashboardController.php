<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $nextAppointment = $request->user()->isPatient()
            ? $request->user()->patientProfile?->appointments()->where('status', AppointmentStatus::Scheduled)
                ->where('starts_at', '>', now('UTC')->format('Y-m-d H:i:s'))->with('therapistProfile.user:id,name')->orderBy('starts_at')->first()
            : null;

        return view('dashboard', compact('nextAppointment'));
    }
}
