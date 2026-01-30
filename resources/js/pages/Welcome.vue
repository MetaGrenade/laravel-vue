<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Anvil, Vote, Hammer, Users, Shield, MapPin, CheckCircle2 } from 'lucide-vue-next';
import { Card, CardContent } from '@/components/ui/card'
import Autoplay from 'embla-carousel-autoplay'
import { Carousel, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious } from '@/components/ui/carousel'
import AppLayout from '@/layouts/AppLayout.vue';

const page = usePage<SharedData>();

interface PollOption {
    id: number;
    label: string;
    votesCount: number;
    votePercent: number;
}

interface Poll {
    id: number;
    title: string;
    description: string | null;
    endsAt: string | null;
    totalVotes: number;
    options: PollOption[];
}

const props = defineProps<{
    activePolls?: Poll[];
}>();

const websiteSections = computed(() => {
    const defaults = { blog: true, forum: true, support: true, commerce: false } as const;
    const settings = page.props.settings?.website_sections ?? defaults;

    return {
        blog: settings.blog ?? defaults.blog,
        forum: settings.forum ?? defaults.forum,
        support: settings.support ?? defaults.support,
        commerce: settings.commerce ?? defaults.commerce,
    } as const;
});

/**
 * Load raw SVG strings (inline) from resources/images/tech-icons
 * We use 'raw' so we can insert SVG markup into the page and recolor it with currentColor.
 */
const rawIconModules = import.meta.glob('../../images/tech-icons/*.svg', { as: 'raw', eager: true }) as Record<string, string>;

function sanitizeAndPrepareSvg(rawSvg: string) {
    if (!rawSvg) return '';

    // remove XML prologue and doctype
    let svg = rawSvg.replace(/<\?xml[\s\S]*?\?>/gi, '').replace(/<!DOCTYPE[\s\S]*?>/gi, '');

    // Remove any HTML comments
    svg = svg.replace(/<!--[\s\S]*?-->/g, '');

    // Replace the opening <svg ...> tag:
    // - remove width/height attributes
    // - remove existing style attribute (we'll add our own sizing style)
    // - inject a small inline style to force consistent height
    // - set role/focusable attributes for accessibility
    svg = svg.replace(/<svg([^>]*)>/i, (match, attrs) => {
        // strip width/height/style attributes from attrs
        const cleaned = attrs
            .replace(/\s(width|height)=["'][^"']*["']/gi, '')
            .replace(/\s(style)=["'][^"']*["']/gi, '');

        // ensure there's a space between <svg and attributes if attrs not empty
        const attrsFragment = (cleaned && cleaned.trim().length) ? ' ' + cleaned.trim() : '';

        // add inline style for consistent height (3rem -> 48px equals Tailwind h-12)
        // display:block prevents inline-gap issues in some browsers
        const inlineStyle = 'style="height:3rem;width:auto;display:block"';

        return `<svg${attrsFragment} ${inlineStyle} role="img" focusable="false" aria-hidden="false">`;
    });

    // (optional) remove unnecessary xmlns:xlink attributes to reduce clutter
    svg = svg.replace(/\s+xmlns:[a-zA-Z]+=["'][^"']*["']/g, '');

    return svg.trim();
}

const techIconsInline = Object.keys(rawIconModules)
    .map((fullPath) => {
        const parts = fullPath.split('/');
        const filename = parts[parts.length - 1];
        const name = filename.replace('.svg', '');
        const raw = rawIconModules[fullPath] ?? '';
        return {
            name,
            svg: sanitizeAndPrepareSvg(raw),
        };
    })
    .sort((a, b) => a.name.localeCompare(b.name));

/* ---------- SEO and Campaign Data ---------- */
const seo = {
    title: 'MetaGrenade for Mayor of Joevah — Ashes of Creation Campaign Platform',
    description:
        'Join MetaGrenade (Level 25 Fighter) in the campaign for Mayor of Joevah on the Vyra realm. Vote on town construction, connect with crafters and guilds, and help shape the future of our city.',
    url: 'https://metagrenade.com',
    keywords: 'MetaGrenade, Joevah, Ashes of Creation, Mayor, Vyra realm, town construction, polls, crafters, guilds',
};

// Campaign platform data - polls come from props
const activePolls = computed(() => props.activePolls ?? []);

const affiliatedCrafters = [
    { name: 'Raz', specialization: 'Armorsmith', level: 25, city: 'Joevah', itemLink: 'https://ashescodex.com/db/item/Gear_Armor_Heavy_RosariumGuard_Chest' },
    { name: 'Lust', specialization: 'Leatherworker', level: 25, city: 'Joevah', itemLink: 'https://ashescodex.com/db/item/Leather_Armor' },
    { name: 'Dymera', specialization: 'Alchemist & Scribe', level: 25, city: 'Joevah', itemLink: 'https://ashescodex.com/db/item/Health_Potion' },
    { name: 'Alexxandrya', specialization: 'Weaponsmith', level: 25, city: 'Joevah', itemLink: 'https://ashescodex.com/db/item/Steel_Sword' },
    { name: 'Arthas', specialization: 'Carpenter', level: 25, city: 'Joevah', itemLink: 'https://ashescodex.com/db/item/Wooden_Shield' },
];

const supportingGuilds = [
    { name: 'Apex Order', members: 250, focus: 'Military & Defense', city: 'Miraleth' },
    { name: 'The Enclave', members: 155, focus: 'Trade & Commerce', city: 'Joevah' },
    { name: 'INVICTA', members: 60, focus: 'Crafting & Production', city: 'Joevah' },
    { name: 'Jungle Boys', members: 20, focus: 'Adventure & Resources', city: 'Tangled Post' },
];

const supportingMayors = [
    { name: 'Syrene', city: 'Dhurhrum', realm: 'Vyra', level: 25 },
    { name: '???', city: 'Tangled Post', realm: 'Vyra', level: 25 },
    { name: 'Syclonee', city: 'Miraleth', realm: 'Vyra', level: 25 },
    { name: '???', city: '???', realm: 'Vyra', level: 1 },
    { name: '???', city: 'Halcyon', realm: 'Vyra', level: 1 },
    { name: '???', city: '???', realm: 'Vyra', level: 1 },
];
</script>

<template>
    <AppLayout>
        <!-- SEO + Social meta -->
        <Head>
            <title>{{ seo.title }}</title>
            <meta name="description" :content="seo.description" />
            <meta name="keywords" :content="seo.keywords" />
            <meta property="og:type" content="website" />
            <meta property="og:title" :content="seo.title" />
            <meta property="og:description" :content="seo.description" />
            <meta property="og:url" :content="seo.url" />
            <meta name="twitter:card" content="summary_large_image" />
        </Head>

        <div class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a]">
            <main class="flex flex-1 justify-center p-6">
                <div class="flex w-full max-w-7xl flex-col gap-12">
                    <section class="overflow-hidden rounded-xl bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] lg:flex lg:items-center lg:gap-12 lg:p-12">
                        <div class="flex-1 space-y-6">
                            <div class="space-y-4">
                                <h1 class="mt-4 text-3xl font-semibold leading-tight tracking-tight text-[#1b1b18] dark:text-[#EDEDEC] sm:text-4xl">
                                    MetaGrenade for Mayor of Joevah
                                </h1>
                                <p class="mt-4 max-w-2xl text-base text-[#706f6c] dark:text-[#A1A09A]">
                                    A proven Level 25 <a href="https://ashescodex.com/db/class/Fighter" class="text-[#d4a574] dark:text-[#f4c430] hover:underline">Fighter</a> running for Mayor of <a href="https://ashescodex.com/db/poi/Joevah" class="text-[#d4a574] dark:text-[#f4c430] hover:underline">Joevah</a> on the <a href="https://ashescodex.com/db/poi/Vyra" class="text-[#d4a574] dark:text-[#f4c430] hover:underline">Vyra</a> realm. Join our campaign to build a stronger, more prosperous city through community-driven decisions and strategic partnerships.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <div class="inline-flex items-center rounded-full bg-[#e6f9ed] px-3 py-1 text-xs font-medium text-[#008b2c] dark:bg-[#142619] dark:text-[#9ef3b6]">
                                    Level 25 Fighter
                                </div>
                                <div class="inline-flex items-center rounded-full bg-[#f9f3e6] px-3 py-1 text-xs font-medium text-[#8b5a00] dark:bg-[#261f14] dark:text-[#f3d29e]">
                                    <MapPin class="mr-1 h-3 w-3" />
                                    <a href="https://ashescodex.com/db/poi/Joevah" class="hover:underline">Joevah</a>, <a href="https://ashescodex.com/db/poi/Vyra" class="hover:underline">Vyra Realm</a>
                                </div>
                                <div class="inline-flex items-center rounded-full bg-[#e6eeff] px-3 py-1 text-xs font-medium text-[#1b1b18] dark:bg-[#1a1d26] dark:text-[#9ebff3]">
                                    Campaign Active
                                </div>
                            </div>

                            <!-- Primary CTAs -->
                            <div class="flex flex-wrap gap-3">
                                <Link
                                    v-if="websiteSections.forum"
                                    :href="route('forum.index')"
                                    class="inline-flex items-center justify-center rounded-sm bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white shadow-[0px_1px_2px_rgba(0,0,0,0.12)] transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    Join the Discussion
                                </Link>
                                <Link
                                    v-if="websiteSections.blog"
                                    :href="route('blogs.index')"
                                    class="inline-flex items-center justify-center rounded-sm border border-[#19140035] px-5 py-2 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Read Campaign Updates
                                </Link>
                            </div>
                            <div class="flex flex-wrap gap-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Community-driven town development
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Strong partnerships with crafters and guilds
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Transparent voting on construction projects
                                </div>
                            </div>
                        </div>
                        <div class="mt-10 flex flex-1 justify-center lg:mt-0">
                            <div class="w-full max-w-md rounded-lg bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] p-6 text-[#1b1b18] shadow-[0px_10px_40px_rgba(0,0,0,0.08)] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] dark:text-[#EDEDEC]">
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Campaign Platform</p>
                                        <h2 class="text-xl font-semibold">Your Voice Matters</h2>
                                        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Participate in shaping Joevah's future through democratic voting and community engagement.</p>
                                    </div>
                                    <ul class="space-y-3 text-sm">
                                        <li class="flex items-start gap-3">
                                            <Vote class="mt-1 h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e]" />
                                            <div>
                                                <p class="font-medium">Active Polls</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Vote on construction projects and town direction decisions.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <Hammer class="mt-1 h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e]" />
                                            <div>
                                                <p class="font-medium">Affiliated Crafters</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Access to high-level crafters for all your needs.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <Users class="mt-1 h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e]" />
                                            <div>
                                                <p class="font-medium">Guild Support</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Backed by major guilds committed to Joevah's prosperity.</p>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ACTIVE POLLS SECTION -->
                    <section aria-labelledby="polls-heading" class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Democratic Participation</p>
                            <h2 id="polls-heading" class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Active Town Construction Polls</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Your voice shapes Joevah's future. Vote on upcoming construction projects and help decide the direction of our city's development.
                            </p>
                        </div>
                        <div v-if="activePolls.length > 0" class="grid gap-4 lg:grid-cols-3">
                            <div
                                v-for="poll in activePolls"
                                :key="poll.id"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <Vote class="h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e] flex-shrink-0 mt-0.5" />
                                    <span v-if="poll.endsAt" class="text-xs text-[#706f6c] dark:text-[#A1A09A]">Ends {{ poll.endsAt }}</span>
                                </div>
                                <h3 class="mt-3 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ poll.title }}</h3>
                                <p v-if="poll.description" class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ poll.description }}</p>
                                <div class="mt-4 space-y-3">
                                    <div
                                        v-for="option in poll.options"
                                        :key="option.id"
                                        class="space-y-1.5"
                                    >
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-[#706f6c] dark:text-[#A1A09A]">{{ option.label }}</span>
                                            <span class="font-semibold text-[#d4a574] dark:text-[#f4c430]">{{ option.votesCount }} votes ({{ option.votePercent }}%)</span>
                                        </div>
                                        <div v-if="poll.totalVotes > 0" class="h-2 w-full overflow-hidden rounded-full bg-[#f9f7f2] dark:bg-[#1c1b17]">
                                            <div
                                                class="h-full bg-gradient-to-r from-[#d4a574] to-[#f4c430] dark:from-[#f4c430] dark:to-[#d4a574] transition-all"
                                                :style="{ width: `${option.votePercent}%` }"
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">No active polls at this time. Check back soon for new construction proposals!</p>
                        </div>
                    </section>

                    <!-- AFFILIATED CRAFTERS SECTION -->
                    <section aria-labelledby="crafters-heading" class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Crafting Excellence</p>
                            <h2 id="crafters-heading" class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Affiliated High-Level Crafters</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Access to the finest crafters in Joevah. These master artisans are committed to supporting our campaign and serving the citizens of our great city.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="(crafter, index) in affiliatedCrafters"
                                :key="index"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <Hammer class="h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e] flex-shrink-0 mt-0.5" />
                                    <span class="text-xs font-semibold text-[#d4a574] dark:text-[#f4c430]">Level {{ crafter.level }}</span>
                                </div>
                                <h3 class="mt-3 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ crafter.name }}</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    {{ crafter.specialization }}
                                    <span v-if="crafter.itemLink" class="ml-2">
                                        <a :href="crafter.itemLink" class="text-[#d4a574] dark:text-[#f4c430] hover:underline text-xs">View Example Item</a>
                                    </span>
                                </p>
                                <div class="mt-4 flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    <MapPin class="h-3 w-3" />
                                    <span>{{ crafter.city }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- SUPPORTING GUILDS SECTION -->
                    <section aria-labelledby="guilds-heading" class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Guild Support</p>
                            <h2 id="guilds-heading" class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Supporting Guilds</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Major guilds across Joevah have pledged their support to MetaGrenade's campaign, recognizing the value of strong leadership and community-driven governance.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div
                                v-for="(guild, index) in supportingGuilds"
                                :key="index"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <Users class="h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e] flex-shrink-0 mt-0.5" />
                                    <span class="text-xs font-semibold text-[#008b2c] dark:text-[#9ef3b6]">{{ guild.members }} members</span>
                                </div>
                                <h3 class="mt-3 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ guild.name }}</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ guild.focus }}</p>
                                <div class="mt-4 flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    <MapPin class="h-3 w-3" />
                                    <span>{{ guild.city }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- SUPPORTING MAYORS SECTION -->
                    <section aria-labelledby="mayors-heading" class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Inter-City Relations</p>
                            <h2 id="mayors-heading" class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Supporting Mayors</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Fellow mayors from across the Vyra realm have endorsed MetaGrenade's candidacy, recognizing the importance of strong leadership and collaborative governance.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div
                                v-for="(mayor, index) in supportingMayors"
                                :key="index"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <Shield class="h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e] flex-shrink-0 mt-0.5" />
                                    <CheckCircle2 class="h-5 w-5 text-[#008b2c] dark:text-[#9ef3b6] flex-shrink-0" />
                                </div>
                                <h3 class="mt-3 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ mayor.name }}</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Mayor of {{ mayor.city }}</p>
                                <div class="mt-4 space-y-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                    <div class="flex items-center gap-2">
                                        <MapPin class="h-3 w-3" />
                                        <span>{{ mayor.city }}, {{ mayor.realm }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span>Level {{ mayor.level }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- INFORMATION HUB SECTION -->
                    <section aria-labelledby="info-heading" class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Resources</p>
                            <h2 id="info-heading" class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Information Hub</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                A central resource for citizens of Joevah and mayors from other cities. Stay informed about campaign updates, town development, and inter-city relations.
                            </p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div
                                v-if="websiteSections.blog"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Campaign Updates</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Blog & Announcements</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Read the latest campaign updates, town development news, and policy announcements.
                                </p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <Link
                                        :href="route('blogs.index')"
                                        class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                    >
                                        View Blog
                                    </Link>
                                </div>
                            </div>

                            <div
                                v-if="websiteSections.forum"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Community</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Forum Discussion</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Join discussions about town policies, construction projects, and campaign initiatives.
                                </p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <Link
                                        :href="route('forum.index')"
                                        class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                    >
                                        Visit Forum
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Ensure every shape inside the inlined SVG uses currentColor (text color on wrapper).
   This forces single-color icons that follow the wrapper's text color.
   We use !important to override hard-coded fills/strokes that may be baked into the SVG.
*/
.tech-icon svg,
.tech-icon svg * {
    /* The wrapper .tech-icon sets the color via Tailwind classes
       (text-[#8b5a00] dark:text-[#f3d29e]) and the following forces svg elements to inherit. */
    fill: currentColor !important;
    stroke: currentColor !important;
}

/* Make sure the SVG scales nicely inside the card content */
.tech-icon svg {
    display: block; /* removes baseline gaps */
    max-height: 3rem; /* same sizing as inline style; this is defensive */
    width: auto;
}
</style>
