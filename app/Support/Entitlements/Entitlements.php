<?php

namespace App\Support\Entitlements;

use App\Enums\EntitlementType;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Model;

/**
 * What an owner has been given, from every source (a Tebex purchase today; Stripe plans and the shop's own products when
 * they are wired in). Asked for an owner, not a user: in 1.0 a person's only owner is themselves, and 1.1 adds teams.
 *
 * Every call reads the database (one indexed query per owner) and nothing is remembered, so what it says is never older
 * than the last grant, in a request or in a test. Ask once and keep the set when several things depend on it:
 * `$held = $user->entitlementSet(); $held->has('reports'); $held->limit('seats');`.
 */
class Entitlements
{
    /**
     * Everything one owner holds right now.
     */
    public function for(Model $owner): EntitlementSet
    {
        return $this->load($owner);
    }

    /**
     * Everything a user holds: what every owner they act for holds, together.
     */
    public function forUser(User $user): EntitlementSet
    {
        $set = new EntitlementSet;

        foreach (Ownership::ownersFor($user) as $owner) {
            $set = $set->merge($this->for($owner));
        }

        return $set;
    }

    /**
     * What a user holds, described for them: each entitlement once, with a limit's grants added up.
     *
     * @return list<array{key: string, label: string, description: string|null, type: string, value: int|null}>
     */
    public function held(User $user): array
    {
        $rows = [];

        foreach (Ownership::ownersFor($user) as $owner) {
            $grants = UserEntitlement::query()->ownedBy($owner)->active()->with('entitlement')->get();

            foreach ($grants as $grant) {
                $entitlement = $grant->entitlement;

                $rows[$entitlement->key] ??= [
                    'key' => $entitlement->key,
                    'label' => $entitlement->label,
                    'description' => $entitlement->description,
                    'type' => $entitlement->type->value,
                    'value' => $entitlement->isLimit() ? 0 : null,
                ];

                if ($entitlement->isLimit()) {
                    $rows[$entitlement->key]['value'] += (int) $grant->value;
                }
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $rows;
    }

    private function load(Model $owner): EntitlementSet
    {
        $features = [];
        $limits = [];

        $grants = UserEntitlement::query()->ownedBy($owner)->active()->with('entitlement:id,key,type')->get();

        foreach ($grants as $grant) {
            $entitlement = $grant->entitlement;

            if ($entitlement->type === EntitlementType::Limit) {
                $limits[$entitlement->key] = ($limits[$entitlement->key] ?? 0) + (int) $grant->value;
            } else {
                $features[$entitlement->key] = $entitlement->key;
            }
        }

        return new EntitlementSet(array_values($features), $limits);
    }
}
