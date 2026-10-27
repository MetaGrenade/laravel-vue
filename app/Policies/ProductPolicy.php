<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * The shop shows a product that is for sale. Creating, changing and deleting
 * one are the commerce permissions. The shop asks this policy directly so an
 * archived product stays hidden there, including from an administrator.
 */
class ProductPolicy
{
    public function view(?User $user, Product $product): bool
    {
        return $product->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can('commerce.acp.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $product->exists && $user->can('commerce.acp.edit');
    }

    public function delete(User $user, Product $product): bool
    {
        return $product->exists && $user->can('commerce.acp.delete');
    }
}
