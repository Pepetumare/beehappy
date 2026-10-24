<?php

namespace App\Services;

use App\Models\SessionOffer;
use App\Models\TherapistProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SessionOfferService
{
    private function mutate(User $actor, ?int $id, ?int $target, callable $operation): SessionOffer
    {
        abort_unless($actor->is_active && ($actor->isTherapist() || $actor->isAdministrator()), 403);
        $existing = $id ? SessionOffer::manageableBy($actor)->findOrFail($id) : null;
        $profileId = $existing?->therapist_profile_id
            ?? ($actor->isAdministrator() ? $target : $actor->therapistProfile()->firstOrCreate()->id);

        return DB::transaction(function () use ($actor, $id, $profileId, $operation) {
            // Same professional mutex as calendar and purchases; never acquire a patient after this.
            $profile = TherapistProfile::whereKey($profileId)->lockForUpdate()->firstOrFail();
            $users = User::whereIn('id', [$actor->id, $profile->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($users->has($actor->id), 404);
            $currentActor = $users[$actor->id];
            abort_unless($currentActor->is_active && ($currentActor->isAdministrator()
                || ($currentActor->isTherapist() && $profile->user_id === $currentActor->id)), 404);
            if (! $id) {
                abort_unless($users[$profile->user_id]->isTherapist(), 422, 'El vendedor debe ser apiterapeuta.');
            }
            $offer = $id ? $profile->sessionOffers()->whereKey($id)->lockForUpdate()->firstOrFail() : new SessionOffer;
            $operation($offer);
            $profile->sessionOffers()->save($offer);

            return $offer;
        }, 3);
    }

    public function save(User $actor, array $data, ?int $id = null): SessionOffer
    {
        return $this->mutate($actor, $id, $data['therapist_profile_id'] ?? null,
            fn ($offer) => $offer->fill(Arr::only($data, ['name', 'description', 'type', 'sessions_count', 'price_clp', 'active'])));
    }

    public function setActive(User $actor, int $id, bool $active): SessionOffer
    {
        return $this->mutate($actor, $id, null, fn ($offer) => $offer->fill(['active' => $active]));
    }
}
