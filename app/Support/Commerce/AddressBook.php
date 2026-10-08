<?php

namespace App\Support\Commerce;

use App\Models\Address;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved addresses. Addresses are owned through
 * {@see Ownership}, not a user id, so a team can have an address book in 1.1.
 */
class AddressBook
{
    /**
     * @return Collection<int, Address>
     */
    public function for(User $user): Collection
    {
        return Address::query()
            ->visibleTo($user)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * Save an address, unless the customer already has exactly that one. The
     * first address saved becomes the default.
     */
    public function remember(User $user, AddressData $data): Address
    {
        $owner = Ownership::newRecordOwnerFor($user);

        $existing = Address::query()->ownedBy($owner)->get()->first(
            fn (Address $address) => AddressData::fromModel($address)->toArray() === $data->toArray(),
        );

        if ($existing !== null) {
            return $existing;
        }

        return $this->create($user, $data->toArray(), makeDefault: ! Address::query()->ownedBy($owner)->exists());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes, bool $makeDefault = false): Address
    {
        return DB::transaction(function () use ($user, $attributes, $makeDefault) {
            $owner = Ownership::newRecordOwnerFor($user);

            $address = new Address($attributes);
            $address->assignOwner($owner);
            $address->is_default = false;
            $address->save();

            if ($makeDefault || ($attributes['is_default'] ?? false)) {
                $this->makeDefault($address);
            }

            return $address;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Address $address, array $attributes): Address
    {
        return DB::transaction(function () use ($address, $attributes) {
            $wantsDefault = (bool) ($attributes['is_default'] ?? false);
            unset($attributes['is_default']);

            $address->fill($attributes)->save();

            if ($wantsDefault) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    public function makeDefault(Address $address): void
    {
        DB::transaction(function () use ($address) {
            Address::query()
                ->where('owner_type', $address->owner_type)
                ->where('owner_id', $address->owner_id)
                ->update(['is_default' => false]);

            $address->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Delete an address. If it was the default, the oldest remaining one takes over.
     */
    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                Address::query()
                    ->where('owner_type', $address->owner_type)
                    ->where('owner_id', $address->owner_id)
                    ->orderBy('id')
                    ->first()
                    ?->forceFill(['is_default' => true])
                    ->save();
            }
        });
    }
}
