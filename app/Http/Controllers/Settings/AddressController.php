<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Support\Commerce\AddressBook;
use App\Support\Commerce\AddressRules;
use App\Support\Commerce\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in customer's address book.
 */
class AddressController extends Controller
{
    public function __construct(private readonly AddressBook $addresses) {}

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Addresses', [
            'addresses' => $this->addresses->for($request->user())->map(fn (Address $address) => [
                'id' => $address->id,
                'label' => $address->label,
                'is_default' => $address->is_default,
                'name' => $address->name,
                'company' => $address->company,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'region' => $address->region,
                'postal_code' => $address->postal_code,
                'country' => $address->country,
                'phone' => $address->phone,
            ])->values(),
            'countries' => Countries::codes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->addresses->create($request->user(), $this->validated($request), makeDefault: (bool) $request->boolean('is_default'));

        return back()->with('success', 'Address saved.');
    }

    public function update(Request $request, Address $address): RedirectResponse
    {
        $this->authorize('update', $address);

        $this->addresses->update($address, $this->validated($request));

        return back()->with('success', 'Address updated.');
    }

    public function default(Address $address): RedirectResponse
    {
        $this->authorize('update', $address);

        $this->addresses->makeDefault($address);

        return back()->with('success', 'Default address updated.');
    }

    public function destroy(Address $address): RedirectResponse
    {
        $this->authorize('delete', $address);

        $this->addresses->delete($address);

        return back()->with('success', 'Address removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge(AddressRules::normalise($request->only('country')));

        return $request->validate([
            ...AddressRules::for('', $request->input('country')),
            'label' => ['nullable', 'string', 'max:60'],
            'is_default' => ['sometimes', 'boolean'],
        ], [], AddressRules::attributes());
    }
}
