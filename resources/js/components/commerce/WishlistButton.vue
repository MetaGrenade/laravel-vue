<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Heart } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        productId: number;
        productName: string;
        wishlisted: boolean;
        /** A round icon button for a picture's corner, or a full button with words. */
        display?: 'icon' | 'button';
    }>(),
    { display: 'button' },
);

const page = usePage<SharedData>();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const busy = ref(false);

const label = computed(() => (props.wishlisted ? `Remove ${props.productName} from your wishlist` : `Save ${props.productName} to your wishlist`));

const toggle = () => {
    const options = {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    };

    if (props.wishlisted) {
        router.delete(route('shop.wishlist.destroy', props.productId), options);
    } else {
        router.post(route('shop.wishlist.store'), { product_id: props.productId }, options);
    }
};
</script>

<template>
    <!-- Someone who is not signed in is sent to sign in: there is no wishlist without an account. -->
    <Button
        v-if="!signedIn"
        as-child
        :variant="display === 'icon' ? 'secondary' : 'outline'"
        :size="display === 'icon' ? 'icon' : 'default'"
        :class="display === 'icon' && 'rounded-full shadow-xs'"
    >
        <Link :href="route('login')" :aria-label="`Sign in to save ${productName} to your wishlist`" :title="'Sign in to save to your wishlist'">
            <Heart class="size-4" aria-hidden="true" />
            <span v-if="display === 'button'">Save</span>
        </Link>
    </Button>

    <Button
        v-else
        type="button"
        :variant="display === 'icon' ? 'secondary' : 'outline'"
        :size="display === 'icon' ? 'icon' : 'default'"
        :class="display === 'icon' && 'rounded-full shadow-xs'"
        :aria-pressed="wishlisted"
        :aria-label="label"
        :title="wishlisted ? 'Remove from your wishlist' : 'Save to your wishlist'"
        :disabled="busy"
        @click="toggle"
    >
        <Heart class="size-4" :class="wishlisted && 'fill-destructive text-destructive'" aria-hidden="true" />
        <span v-if="display === 'button'">{{ wishlisted ? 'Saved' : 'Save' }}</span>
    </Button>
</template>
