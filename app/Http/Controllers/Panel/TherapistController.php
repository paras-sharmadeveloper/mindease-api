<?php

namespace App\Http\Controllers\Panel;

use App\Models\Therapist;
use App\Models\User;
use App\Services\Booking\PanelPresenter;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TherapistController extends PanelController
{
    /** Admin: every therapist, active or not. */
    public function index(PanelPresenter $presenter): JsonResponse
    {
        $therapists = Therapist::with('user')->get()->sortBy('user.name')->values();

        return response()->json($therapists->map(fn (Therapist $t) => $presenter->therapist($t)));
    }

    /**
     * Admin: create a therapist login + profile with a temporary password (they change it after signing in).
     * Starts with no availability, so no slots are offered until hours are added.
     */
    public function store(Request $request, PanelPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)],
        ] + $this->profileRules(admin: true));

        $therapist = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $user->forceFill(['role' => User::ROLE_THERAPIST])->save();

            return Therapist::create(['user_id' => $user->id, 'specialties' => []] + $this->profileAttributes($data));
        });

        return response()->json($presenter->therapist($therapist->refresh()->load('user')), 201);
    }

    public function show(Request $request, string $therapistId, PanelPresenter $presenter): JsonResponse
    {
        return response()->json($presenter->therapist($this->therapistFor($request, $therapistId)));
    }

    /**
     * Admin: every field, plus `password` to set a new temporary password (signs the therapist out everywhere).
     * Therapist: own bio, specialties, photo and timezone only.
     */
    public function update(Request $request, string $therapistId, PanelPresenter $presenter, TokenService $tokens): JsonResponse
    {
        $therapist = $this->therapistFor($request, $therapistId);
        $admin = $request->user()->isAdmin();

        $data = $request->validate(($admin ? [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($therapist->user_id)],
            'password' => ['sometimes', 'string', Password::min(8)],
        ] : []) + $this->profileRules($admin));

        DB::transaction(function () use ($therapist, $data, $tokens) {
            if (isset($data['password'])) {
                $therapist->user->forceFill(['password' => $data['password']])->save();
                $tokens->revokeAll($therapist->user);
            }
            $therapist->user->fill(array_filter([
                'name' => $data['name'] ?? null,
                'email' => isset($data['email']) ? strtolower($data['email']) : null,
            ]))->save();
            $therapist->update($this->profileAttributes($data));
        });

        return response()->json($presenter->therapist($therapist->refresh()->load('user')));
    }

    private function profileRules(bool $admin): array
    {
        return [
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'specialties' => ['sometimes', 'array', 'max:20'],
            'specialties.*' => ['string', 'max:64'],
            'photoUrl' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'timezone' => ['sometimes', 'timezone:all'],
        ] + ($admin ? [
            'priceCents' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:1000000'], // null = default price
            'active' => ['sometimes', 'boolean'],
        ] : []);
    }

    private function profileAttributes(array $data): array
    {
        $map = ['bio' => 'bio', 'specialties' => 'specialties', 'photoUrl' => 'photo_url',
            'timezone' => 'timezone', 'priceCents' => 'price_cents', 'active' => 'active'];

        $attributes = [];
        foreach ($map as $in => $column) {
            if (array_key_exists($in, $data)) {
                $attributes[$column] = $data[$in];
            }
        }

        return $attributes;
    }
}
