<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { ModerationAction, ModerationMember, ModerationStanding, ModerationSubject } from '@/types/moderation';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Ban, LoaderCircle, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Everything about one member for a moderator (docs/moderation.md): where they stand, what has been done, and the forms to warn them, ban
 * them, lift a ban or take down what they posted. `subject` is what the report this was opened from is about; the forms attach it so the
 * history says why.
 */
const props = defineProps<{
    member: ModerationMember;
    standing: ModerationStanding;
    history: ModerationAction[];
    subject: ModerationSubject | null;
    can: { warn: boolean; ban: boolean; cleanup: boolean };
    refusal: { warn: string | null; ban: string | null; unban: string | null; cleanup: string | null };
    contact: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: route('acp.users.index') },
    { title: props.member.nickname, href: route('acp.users.moderation.show', { user: props.member.id }) },
    { title: 'Moderation', href: route('acp.users.moderation.show', { user: props.member.id }) },
];

const when = (value: string | null) => (value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const day = (value: string | null) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '');

const dialog = ref<'warn' | 'ban' | 'unban' | 'cleanup' | null>(null);

const warnForm = useForm({ reason: '', note: '', notify: true, subject: props.subject?.reference ?? '' });
const banForm = useForm({ reason: '', note: '', until: '', notify: true, cleanup: false, subject: props.subject?.reference ?? '' });
const unbanForm = useForm({ reason: '', notify: true });
const cleanupForm = useForm({ reason: '', note: '' });

/** How many things of theirs are showing, and so how many taking them down would take down. */
const showingTotal = computed(
    () => props.member.showing.threads + props.member.showing.replies + props.member.showing.comments + props.member.showing.reviews,
);

/** Whether the buttons that take their posts down are there: the moderator may, it is allowed for this member, and there is something to take down. */
const canTakeDown = computed(() => props.can.cleanup && props.refusal.cleanup === null && showingTotal.value > 0);

const showingItems = computed(() => [
    { label: 'Threads', value: props.member.showing.threads },
    { label: 'Forum replies', value: props.member.showing.replies },
    { label: 'Blog comments', value: props.member.showing.comments },
    { label: 'Product reviews', value: props.member.showing.reviews },
]);

function close() {
    warnForm.clearErrors();
    banForm.clearErrors();
    unbanForm.clearErrors();
    cleanupForm.clearErrors();
    dialog.value = null;
}

function submitCleanup() {
    cleanupForm.post(route('acp.users.cleanup', { user: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            cleanupForm.reset('reason', 'note');
            dialog.value = null;
        },
    });
}

function submitWarning() {
    warnForm.post(route('acp.users.warn', { user: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            warnForm.reset('reason', 'note');
            dialog.value = null;
        },
    });
}

function submitBan() {
    banForm.put(route('acp.users.ban', { user: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            banForm.reset('reason', 'note', 'until', 'cleanup');
            dialog.value = null;
        },
    });
}

function submitUnban() {
    unbanForm.put(route('acp.users.unban', { user: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            unbanForm.reset('reason');
            dialog.value = null;
        },
    });
}

/** The date this many days from now, as the date field takes it (the end of that day is where the ban stops). */
function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

const tomorrow = computed(() => inDays(1));

const typeLabel = (action: ModerationAction) => {
    switch (action.type) {
        case 'warning':
            return 'Warning';
        case 'ban':
            return action.expires_at ? `Suspended until ${day(action.expires_at)}` : 'Banned';
        case 'removal':
            return 'Posts taken down';
        default:
            return action.actor ? 'Ban lifted' : 'Suspension ended';
    }
};

const actorName = (action: ModerationAction) => action.actor ?? (action.type === 'unban' ? 'The system' : 'A deleted account');

const typeVariant = (action: ModerationAction) => (action.type === 'ban' ? 'destructive' : action.type === 'warning' ? 'highlight' : 'outline');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Moderate ${member.nickname}`" />

        <AdminLayout>
            <div class="flex w-full flex-1 flex-col gap-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Moderate {{ member.nickname }}</h1>
                        <p class="text-sm text-muted-foreground">Where they stand, what has been done, and what you can do.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.users.edit', { user: member.id })">Edit account</Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.users.index')">Back to users</Link>
                        </Button>
                    </div>
                </div>

                <Card v-if="subject" class="border-highlight/40">
                    <CardHeader>
                        <CardTitle class="text-base">Opened from a report</CardTitle>
                        <CardDescription>
                            A warning or ban you give now is recorded as being about
                            <a v-if="subject.url" :href="subject.url" class="underline" target="_blank" rel="noopener">{{ subject.label }}</a>
                            <template v-else>{{ subject.label }}</template
                            >.
                        </CardDescription>
                    </CardHeader>
                </Card>

                <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    <div class="flex min-w-0 flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <Ban v-if="standing.banned" class="size-5 text-destructive" aria-hidden="true" />
                                    <ShieldCheck v-else class="size-5 text-success" aria-hidden="true" />
                                    {{
                                        standing.banned
                                            ? standing.banned_until
                                                ? `Suspended until ${day(standing.banned_until)}`
                                                : 'Banned'
                                            : 'In good standing'
                                    }}
                                </CardTitle>
                                <CardDescription v-if="standing.banned">
                                    Since {{ when(standing.banned_at) }}<template v-if="standing.banned_by"> by {{ standing.banned_by }}</template
                                    >. They cannot sign in.
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm">
                                <div v-if="standing.banned && standing.reason" class="rounded-md border p-3">
                                    <p class="text-xs text-muted-foreground">The reason they were given</p>
                                    <p class="whitespace-pre-line">{{ standing.reason }}</p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        v-if="can.warn"
                                        type="button"
                                        variant="outline"
                                        :disabled="refusal.warn !== null"
                                        @click="dialog = 'warn'"
                                    >
                                        <AlertTriangle class="mr-2 size-4" aria-hidden="true" /> Warn
                                    </Button>
                                    <Button
                                        v-if="can.ban && !standing.banned"
                                        type="button"
                                        variant="destructive"
                                        :disabled="refusal.ban !== null"
                                        @click="dialog = 'ban'"
                                    >
                                        <Ban class="mr-2 size-4" aria-hidden="true" /> Ban
                                    </Button>
                                    <Button
                                        v-if="can.ban && standing.banned"
                                        type="button"
                                        variant="outline"
                                        :disabled="refusal.unban !== null"
                                        @click="dialog = 'unban'"
                                    >
                                        Lift the ban
                                    </Button>
                                </div>
                                <p v-if="refusal.warn || (standing.banned ? refusal.unban : refusal.ban)" class="text-xs text-muted-foreground">
                                    {{ refusal.warn ?? (standing.banned ? refusal.unban : refusal.ban) }}
                                </p>
                                <p v-if="!can.warn && !can.ban" class="text-xs text-muted-foreground">
                                    You can look at this history but your role cannot warn or ban members.
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>What they have posted</CardTitle>
                                <CardDescription
                                    >What is showing now on the forum, in the blog's comments and among the product reviews.</CardDescription
                                >
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm">
                                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div v-for="item in showingItems" :key="item.label" class="rounded-md border p-3">
                                        <dt class="text-xs text-muted-foreground">{{ item.label }}</dt>
                                        <dd class="text-lg font-semibold">{{ item.value }}</dd>
                                    </div>
                                </dl>
                                <template v-if="can.cleanup">
                                    <div>
                                        <Button type="button" variant="outline" :disabled="!canTakeDown" @click="dialog = 'cleanup'">
                                            <Trash2 class="mr-2 size-4" aria-hidden="true" /> Take it all down
                                        </Button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            refusal.cleanup ??
                                            (showingTotal === 0
                                                ? 'Nothing of theirs is showing.'
                                                : 'Nothing is deleted for good that you could want back. They are not told.')
                                        }}
                                    </p>
                                </template>
                                <p v-else class="text-xs text-muted-foreground">Your role cannot take down what a member posted.</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>History</CardTitle>
                                <CardDescription
                                    >Every warning, ban and clean-up, newest first. The members see the reason of a warning or ban, never the staff
                                    note, and are not told of a clean-up.</CardDescription
                                >
                            </CardHeader>
                            <CardContent>
                                <p v-if="history.length === 0" class="text-sm text-muted-foreground">Nothing has been done to this member.</p>
                                <ol v-else class="space-y-4">
                                    <li v-for="action in history" :key="action.id" class="space-y-1.5 rounded-md border p-3 text-sm">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <Badge :variant="typeVariant(action)">{{ typeLabel(action) }}</Badge>
                                            <time class="text-xs text-muted-foreground" :datetime="action.created_at ?? undefined">{{
                                                when(action.created_at)
                                            }}</time>
                                        </div>
                                        <p v-if="action.reason" class="whitespace-pre-line">{{ action.reason }}</p>
                                        <p v-if="action.summary" class="text-xs text-muted-foreground">Their posts: {{ action.summary }}.</p>
                                        <p v-if="action.subject" class="text-xs text-muted-foreground">
                                            About:
                                            <a
                                                v-if="action.subject.url"
                                                :href="action.subject.url"
                                                class="underline"
                                                target="_blank"
                                                rel="noopener"
                                                >{{ action.subject.label }}</a
                                            >
                                            <template v-else>{{ action.subject.label }}</template>
                                        </p>
                                        <p v-if="action.note" class="rounded bg-muted px-2 py-1 text-xs whitespace-pre-line">
                                            <span class="font-medium">Staff note:</span> {{ action.note }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ actorName(action) }}
                                            <template v-if="action.type !== 'removal'">
                                                · {{ action.notified ? 'they were emailed' : 'they were not emailed' }}
                                            </template>
                                        </p>
                                    </li>
                                </ol>
                            </CardContent>
                        </Card>
                    </div>

                    <Card class="h-fit">
                        <CardHeader>
                            <CardTitle>Member</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-2 text-sm">
                            <p class="font-medium">{{ member.nickname }}</p>
                            <p class="break-all text-muted-foreground">{{ member.email }}</p>
                            <div v-if="member.roles.length" class="flex flex-wrap gap-1">
                                <Badge v-for="role in member.roles" :key="role" variant="secondary">{{ role }}</Badge>
                            </div>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 pt-2 text-xs text-muted-foreground">
                                <dt>Joined</dt>
                                <dd>{{ day(member.created_at) }}</dd>
                                <dt>Last active</dt>
                                <dd>{{ when(member.last_activity_at) || 'Never' }}</dd>
                                <dt>Forum threads</dt>
                                <dd>{{ member.forum_threads }}</dd>
                                <dt>Forum replies</dt>
                                <dd>{{ member.forum_posts }}</dd>
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog :open="dialog === 'warn'" @update:open="(open) => !open && close()">
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submitWarning">
                        <DialogHeader>
                            <DialogTitle>Warn {{ member.nickname }}</DialogTitle>
                            <DialogDescription>
                                A warning is a notice: it is kept in their history and they can read it, but nothing happens to their account.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="space-y-2">
                            <Label for="warn-reason">What are you warning them about?</Label>
                            <Textarea id="warn-reason" v-model="warnForm.reason" rows="4" maxlength="1000" required autofocus />
                            <p class="text-xs text-muted-foreground">They are told this, in these words.</p>
                            <InputError :message="warnForm.errors.reason" />
                        </div>
                        <div class="space-y-2">
                            <Label for="warn-note">Note for staff (optional)</Label>
                            <Textarea id="warn-note" v-model="warnForm.note" rows="2" maxlength="1000" />
                            <InputError :message="warnForm.errors.note" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="warn-notify"
                                :model-value="warnForm.notify"
                                @update:model-value="(value) => (warnForm.notify = value === true)"
                            />
                            <Label for="warn-notify" class="font-normal">Email them</Label>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="close">Cancel</Button>
                            <Button type="submit" :disabled="warnForm.processing">
                                <LoaderCircle v-if="warnForm.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" /> Warn
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog :open="dialog === 'ban'" @update:open="(open) => !open && close()">
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submitBan">
                        <DialogHeader>
                            <DialogTitle>Ban {{ member.nickname }}</DialogTitle>
                            <DialogDescription>
                                They are signed out and cannot sign in. When they try, they are told the reason{{
                                    contact ? ' and how to ask about it' : ''
                                }}.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="space-y-2">
                            <Label for="ban-reason">Why are they being banned?</Label>
                            <Textarea id="ban-reason" v-model="banForm.reason" rows="4" maxlength="1000" required autofocus />
                            <p class="text-xs text-muted-foreground">They are told this, in these words.</p>
                            <InputError :message="banForm.errors.reason" />
                        </div>
                        <div class="space-y-2">
                            <Label for="ban-until">Until (optional)</Label>
                            <Input id="ban-until" v-model="banForm.until" type="date" :min="tomorrow" />
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-for="days in [1, 3, 7, 30]"
                                    :key="days"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="banForm.until = inDays(days)"
                                >
                                    {{ days }} {{ days === 1 ? 'day' : 'days' }}
                                </Button>
                                <Button type="button" size="sm" variant="ghost" @click="banForm.until = ''">For good</Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    banForm.until
                                        ? `It ends by itself at the end of ${day(banForm.until + 'T12:00:00')}, in your time zone.`
                                        : 'With no date it lasts until a moderator lifts it.'
                                }}
                            </p>
                            <InputError :message="banForm.errors.until" />
                        </div>
                        <div class="space-y-2">
                            <Label for="ban-note">Note for staff (optional)</Label>
                            <Textarea id="ban-note" v-model="banForm.note" rows="2" maxlength="1000" />
                            <InputError :message="banForm.errors.note" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="ban-notify"
                                :model-value="banForm.notify"
                                @update:model-value="(value) => (banForm.notify = value === true)"
                            />
                            <Label for="ban-notify" class="font-normal">Email them</Label>
                        </div>
                        <div v-if="can.cleanup && showingTotal > 0" class="flex items-start gap-2">
                            <Checkbox
                                id="ban-cleanup"
                                class="mt-0.5"
                                :model-value="banForm.cleanup"
                                @update:model-value="(value) => (banForm.cleanup = value === true)"
                            />
                            <Label for="ban-cleanup" class="font-normal">
                                Also take down what they posted ({{ showingTotal }} {{ showingTotal === 1 ? 'thing' : 'things' }} showing). They are
                                not told, and nothing is deleted for good.
                            </Label>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="close">Cancel</Button>
                            <Button type="submit" variant="destructive" :disabled="banForm.processing">
                                <LoaderCircle v-if="banForm.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" /> Ban
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog :open="dialog === 'cleanup'" @update:open="(open) => !open && close()">
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submitCleanup">
                        <DialogHeader>
                            <DialogTitle>Take down what {{ member.nickname }} posted</DialogTitle>
                            <DialogDescription>
                                Threads they started are unpublished, and the replies other members made in them go out of sight with them (publish a
                                thread again to bring it back). Their replies in other threads are deleted, and their blog comments and product
                                reviews are rejected. What is waiting in the review queue is rejected. They are not told.
                            </DialogDescription>
                        </DialogHeader>
                        <ul class="grid grid-cols-2 gap-2 text-sm">
                            <li v-for="item in showingItems" :key="item.label" class="flex justify-between rounded-md border px-3 py-2">
                                <span class="text-muted-foreground">{{ item.label }}</span>
                                <span class="font-medium">{{ item.value }}</span>
                            </li>
                        </ul>
                        <div class="space-y-2">
                            <Label for="cleanup-reason">Why? (kept in their history)</Label>
                            <Textarea id="cleanup-reason" v-model="cleanupForm.reason" rows="3" maxlength="1000" required autofocus />
                            <InputError :message="cleanupForm.errors.reason" />
                        </div>
                        <div class="space-y-2">
                            <Label for="cleanup-note">Note for staff (optional)</Label>
                            <Textarea id="cleanup-note" v-model="cleanupForm.note" rows="2" maxlength="1000" />
                            <InputError :message="cleanupForm.errors.note" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="close">Cancel</Button>
                            <Button type="submit" variant="destructive" :disabled="cleanupForm.processing">
                                <LoaderCircle v-if="cleanupForm.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" /> Take it all down
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog :open="dialog === 'unban'" @update:open="(open) => !open && close()">
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submitUnban">
                        <DialogHeader>
                            <DialogTitle>Lift the ban on {{ member.nickname }}</DialogTitle>
                            <DialogDescription>They can sign in again at once. The ban stays in their history.</DialogDescription>
                        </DialogHeader>
                        <div class="space-y-2">
                            <Label for="unban-reason">Why? (optional)</Label>
                            <Textarea id="unban-reason" v-model="unbanForm.reason" rows="3" maxlength="1000" />
                            <InputError :message="unbanForm.errors.reason" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="unban-notify"
                                :model-value="unbanForm.notify"
                                @update:model-value="(value) => (unbanForm.notify = value === true)"
                            />
                            <Label for="unban-notify" class="font-normal">Email them</Label>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="close">Cancel</Button>
                            <Button type="submit" :disabled="unbanForm.processing">
                                <LoaderCircle v-if="unbanForm.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" /> Lift the ban
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    </AppLayout>
</template>
