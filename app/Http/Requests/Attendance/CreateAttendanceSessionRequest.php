<?php

namespace App\Http\Requests\Attendance;

use App\Models\Cell;
use App\Models\Department;
use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;

class CreateAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! $user->can('create attendance')) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return true;
        }

        $cellId = $this->input('cell_id');
        $departmentId = $this->input('department_id');

        if ($cellId) {
            return Cell::where('id', $cellId)
                ->where('leader_user_id', $user->id)
                ->exists();
        }

        if ($departmentId) {
            return Department::where('id', $departmentId)
                ->where('leader_user_id', $user->id)
                ->exists();
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'service_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'department_id' => [
                'nullable',
                'uuid',
                'exists:departments,id',
                $this->user()?->hasAnyRole(['super_admin', 'pastor', 'secretary'])
                    ? null
                    : function ($attribute, $value, $fail) {
                        if ($value && ! $this->user()?->hasRole('department_leader')) {
                            $fail('Only department leaders can record department meetings.');
                        }
                    },
            ],
            'cell_id' => [
                'nullable',
                'uuid',
                'exists:cells,id',
                $this->user()?->hasAnyRole(['super_admin', 'pastor', 'secretary'])
                    ? null
                    : function ($attribute, $value, $fail) {
                        if ($value && ! $this->user()?->hasRole('cell_leader')) {
                            $fail('Only cell leaders can record cell meetings.');
                        }
                    },
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('department_id') && $this->input('cell_id')) {
                $validator->errors()->add(
                    'cell_id',
                    'A session cannot be both a department and a cell meeting.'
                );
            }

            $serviceType = ServiceType::find($this->input('service_type_id'));
            if (! $serviceType) {
                return;
            }

            if ($serviceType->type === 'adult' && ! $this->input('cell_id') && ! $this->input('department_id')) {
                $validator->errors()->add(
                    'cell_id',
                    'Adult service attendance must be recorded per cell. Please select a cell.'
                );
            }

            if ($serviceType->type === 'children' && ! $this->input('cell_id')) {
                $validator->errors()->add(
                    'cell_id',
                    'Children service attendance must be recorded per cell. Please select the Children Ministry cell.'
                );
            }
        });
    }
}
