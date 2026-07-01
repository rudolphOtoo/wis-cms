<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'branch_id' => $this->branch_id,
            'is_active' => $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'last_login_at' => $this->last_login_at?->diffForHumans(),
            'last_login' => $this->last_login_at?->diffForHumans(),
            'created_at' => $this->created_at->format('Y-m-d'),
            'role' => $this->roles->first()?->name,
            'role_label' => $this->roles->first() ? ucwords(str_replace('_', ' ', $this->roles->first()->name)) : null,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'member_id' => $this->member_id,
            'member' => $this->relationLoaded('member') && $this->member ? [
                'id' => $this->member->id,
                'first_name' => $this->member->first_name,
                'last_name' => $this->member->last_name,
                'member_number' => $this->member->member_number,
                'phone' => $this->member->phone,
            ] : null,
        ];
    }
}
