<?php

namespace App\Http\Resources;

use App\Services\ProfilePhoto;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * App `user`: { id, email, firstName, lastName, preferredName, mobile, photoUrl, remindersEnabled, pendingEmail, createdAt }.
 * No `name`: it would only repeat firstName + lastName. (The web panel has its own shape with `name`.)
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'preferredName' => $this->preferred_name,
            'mobile' => $this->mobile,
            'photoUrl' => ProfilePhoto::url($this->photo_path),
            'remindersEnabled' => (bool) ($this->reminders_enabled ?? true),
            'pendingEmail' => $this->pending_email,
            'createdAt' => Iso::utc($this->created_at),
        ];
    }
}
