<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'ativo' => (bool) $this->ativo,
            'tenant_id' => $this->tenant_id,
            'papeis' => $this->getRoleNames(),
            'tenants' => $this->whenLoaded('tenants', fn () => $this->tenants->map(
                fn ($t) => ['id' => $t->id, 'nome' => $t->nome],
            )),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
