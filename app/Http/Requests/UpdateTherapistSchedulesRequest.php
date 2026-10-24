<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTherapistSchedulesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('empty_schedule') === '1' && ! $this->has('schedules')) {
            $this->merge(['schedules' => []]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->isTherapist() && $this->user()->is_active;
    }

    public function rules(): array
    {
        return [
            'schedules' => ['present', 'array', 'max:100'],
            'schedules.*' => ['array:day_of_week,start_time,end_time'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = [];
            foreach ($this->input('schedules') as $index => $row) {
                if ($row['start_time'] >= $row['end_time']) {
                    $validator->errors()->add("schedules.$index.end_time", 'El término debe ser posterior al inicio, dentro del mismo día.');
                }
                foreach ($days[$row['day_of_week']] ?? [] as $other) {
                    if ($row['start_time'] < $other['end_time'] && $row['end_time'] > $other['start_time']) {
                        $validator->errors()->add("schedules.$index.start_time", 'Los tramos de un mismo día no pueden solaparse.');
                    }
                }
                $days[$row['day_of_week']][] = $row;
            }
        }];
    }

    public function messages(): array
    {
        return [
            'schedules.*.day_of_week.*' => 'Elige un día de lunes a domingo.',
            'schedules.*.start_time.*' => 'Indica un inicio válido con formato HH:MM.',
            'schedules.*.end_time.*' => 'Indica un término válido con formato HH:MM.',
            'schedules.max' => 'Puedes guardar hasta 100 tramos semanales.',
        ];
    }
}
