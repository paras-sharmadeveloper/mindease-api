<?php

namespace App\Http\Resources;

use App\Services\ProfilePhoto;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `user`: { id, name, email, createdAt }, plus the profile fields
 * { firstName, lastName, preferredName, mobile, photoUrl, remindersEnabled, pendingEmail }.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'createdAt' => Iso::utc($this->created_at),
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'preferredName' => $this->preferred_name,
            'mobile' => $this->mobile,
            'photoUrl' => ProfilePhoto::url($this->photo_path),
            'remindersEnabled' => (bool) ($this->reminders_enabled ?? true),
            'pendingEmail' => $this->pending_email,
        ];
    }
}
