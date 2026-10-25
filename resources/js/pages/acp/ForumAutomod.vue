<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useConfirmDialog } from '@/composables/useConfirmDialog';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { AutomodAction, AutomodRule, AutomodRuleType, AutomodTried } from '@/types/moderation';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

/**
 * The rules of the forum's automatic moderation (docs/automod.md): what each looks for, what it does to a post that has it, who it is
 * for, and how often it has caught something. A box at the top tries a text against them without posting anything.
 */
const props = defineProps<{
    rules: AutomodRule[];
    /** What the rules made of the text that was tried: null until one was. */
    tested: AutomodTried[] | null;
    test: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forums', href: route('acp.forums.index') },
    { title: 'Automod rules', href: route('acp.forums.automod.index') },
];

const { confirmDialogState, confirmDialogDescription, openConfirmDialog, handleConfirmDialogConfirm, handleConfirmDialogCancel } = useConfirmDialog();

const typeLabels: Record<AutomodRuleType, string> = {
    words: 'Words and phrases',
    links: 'Links',
    duplicate: 'The same text again',
    rate: 'Too many posts',
};

const when = (value: string | null) => (value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never');

/** What a rule looks for, in a line. */
function summary(rule: AutomodRule): string {
    const s = rule.settings;

    switch (rule.type) {
        case 'words': {
            const phrases = s.phrases ?? [];
            const shown = phrases.slice(0, 3).join(', ');

            return `${s.whole_words ? 'Whole words' : 'Anywhere'}: ${shown}${phrases.length > 3 ? ` and ${phrases.length - 3} more` : ''}`;
        }
        case 'links': {
            const parts: string[] = [];

            if (s.max_links !== null && s.max_links !== undefined) {
                parts.push(`more than ${s.max_links} ${s.max_links === 1 ? 'link' : 'links'}`);
            }

            if (s.blocked_domains?.length) {
                parts.push(
                    `any link to ${s.blocked_domains.slice(0, 3).join(', ')}${s.blocked_domains.length > 3 ? ` and ${s.blocked_domains.length - 3} more` : ''}`,
                );
            }

            return parts.join(', or ');
        }
        case 'duplicate':
            return `The same text posted again (${s.copies ?? 1} earlier ${s.copies === 1 ? 'copy' : 'copies'} in ${s.minutes} minutes)`;
        default:
            return `${s.posts} posts in ${s.minutes} minutes`;
    }
}

// --- Trying a text -----------------------------------------------------------------------------------------------------------

const testText = ref(props.test);

function check() {
    router.get(
        route('acp.forums.automod.index'),
        { test: testText.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['tested', 'test'] },
    );
}

// --- Making and changing a rule ----------------------------------------------------------------------------------------------

const editing = ref<AutomodRule | null>(null);
const open = ref(false);

const blank = () => ({
    name: '',
    type: 'words' as AutomodRuleType,
    action: 'hold' as AutomodAction,
    only_new_days: '' as string | number,
    is_enabled: true,
    phrases: '',
    whole_words: false,
    max_links: '' as string | number,
    blocked_domains: '',
    minutes: 60 as string | number,
    copies: 1 as string | number,
    posts: 5 as string | number,
    rate_minutes: 10 as string | number,
});

const form = useForm(blank()).transform((data) => ({
    name: data.name,
    type: data.type,
    action: data.action,
    only_new_days: data.only_new_days === '' ? null : data.only_new_days,
    is_enabled: data.is_enabled,
    settings:
        data.type === 'words'
            ? { phrases: data.phrases.split('\n'), whole_words: data.whole_words }
            : data.type === 'links'
              ? { max_links: data.max_links === '' ? null : data.max_links, blocked_domains: data.blocked_domains.split('\n') }
              : data.type === 'duplicate'
                ? { minutes: data.minutes, copies: data.copies }
                : { posts: data.posts, minutes: data.rate_minutes },
}));

/** An error the server gave for a field of the rule's settings (they come back as `settings.phrases` and so on). */
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function create() {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    open.value = true;
}

function edit(rule: AutomodRule) {
    editing.value = rule;
    form.defaults({
        ...blank(),
        name: rule.name,
        type: rule.type,
        action: rule.action,
        only_new_days: rule.only_new_days ?? '',
        is_enabled: rule.is_enabled,
        phrases: (rule.settings.phrases ?? []).join('\n'),
        whole_words: rule.settings.whole_words ?? false,
        max_links: rule.settings.max_links ?? '',
        blocked_domains: (rule.settings.blocked_domains ?? []).join('\n'),
        minutes: rule.settings.minutes ?? 60,
        copies: rule.settings.copies ?? 1,
        posts: rule.settings.posts ?? 5,
        rate_minutes: rule.type === 'rate' ? (rule.settings.minutes ?? 10) : 10,
    }).reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    const done = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (editing.value) {
        form.put(route('acp.forums.automod.update', { rule: editing.value.id }), done);
    } else {
        form.post(route('acp.forums.automod.store'), done);
    }
}

function toggle(rule: AutomodRule, value: boolean) {
    router.patch(route('acp.forums.automod.enabled', { rule: rule.id }), { is_enabled: value }, { preserveScroll: true });
}

function remove(rule: AutomodRule) {
    openConfirmDialog({
        title: `Delete "${rule.name}"`,
        description:
            'Posts it has already held stay in the review queue, with the reason it gave. Posts it would have caught from now on are not caught.',
        confirmLabel: 'Delete rule',
        onConfirm: () => router.delete(route('acp.forums.automod.destroy', { rule: rule.id }), { preserveScroll: true }),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Automod rules" />

        <AdminLayout>
            <div class="flex w-full flex-1 flex-col gap-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Automod rules</h1>
                        <p class="text-sm text-muted-foreground">
                            What the forum looks for in every new thread and reply. Staff are never checked. A post that breaks a rule is held for a
                            moderator or refused, as the rule says.
                        </p>
                    </div>
                    <Button type="button" @click="create"><Plus class="mr-2 size-4" aria-hidden="true" /> Add a rule</Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Try a text</CardTitle>
                        <CardDescription>
                            See which rules would catch it. Nothing is posted and nothing is counted. Rules about how a member has been posting (the
                            same text again, too many posts) need a member, so they are not tried here.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <form class="space-y-3" @submit.prevent="check">
                            <Label for="automod-test" class="sr-only">Text to try</Label>
                            <Textarea id="automod-test" v-model="testText" rows="3" maxlength="5000" placeholder="Paste a post here" />
                            <Button type="submit" variant="secondary">Check it</Button>
                        </form>

                        <template v-if="tested !== null">
                            <p v-if="tested.length === 0" class="text-sm text-muted-foreground">No rule would catch this.</p>
                            <ul v-else class="space-y-2 text-sm">
                                <li v-for="(row, index) in tested" :key="index" class="flex flex-wrap items-center gap-2 rounded-md border p-2">
                                    <Badge :variant="row.action === 'reject' ? 'destructive' : 'highlight'">{{
                                        row.action === 'reject' ? 'Refused' : 'Held'
                                    }}</Badge>
                                    <span class="font-medium">{{ row.rule }}</span>
                                    <span class="text-muted-foreground">{{ row.detail }}</span>
                                </li>
                            </ul>
                        </template>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-0">
                        <p v-if="rules.length === 0" class="p-6 text-sm text-muted-foreground">
                            There are no rules yet, so every post goes straight out. Add one to start holding what looks like spam.
                        </p>
                        <Table v-else>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-16">On</TableHead>
                                    <TableHead>Rule</TableHead>
                                    <TableHead>For</TableHead>
                                    <TableHead>Does</TableHead>
                                    <TableHead class="text-right">Caught</TableHead>
                                    <TableHead class="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="rule in rules" :key="rule.id" :class="rule.is_enabled ? '' : 'opacity-60'">
                                    <TableCell>
                                        <Switch
                                            :model-value="rule.is_enabled"
                                            :aria-label="`${rule.name} is ${rule.is_enabled ? 'on' : 'off'}`"
                                            @update:model-value="(value) => toggle(rule, value === true)"
                                        />
                                    </TableCell>
                                    <TableCell class="max-w-sm">
                                        <p class="font-medium">{{ rule.name }}</p>
                                        <p class="text-xs text-muted-foreground">{{ typeLabels[rule.type] }}</p>
                                        <p class="truncate text-xs text-muted-foreground" :title="summary(rule)">{{ summary(rule) }}</p>
                                    </TableCell>
                                    <TableCell class="text-sm">
                                        {{
                                            rule.only_new_days
                                                ? `Accounts under ${rule.only_new_days} ${rule.only_new_days === 1 ? 'day' : 'days'} old`
                                                : 'Everybody but staff'
                                        }}
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="rule.action === 'reject' ? 'destructive' : 'highlight'">{{
                                            rule.action === 'reject' ? 'Refuses' : 'Holds'
                                        }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-right text-sm tabular-nums">
                                        {{ rule.hits }}
                                        <p class="text-xs text-muted-foreground">{{ when(rule.last_hit_at) }}</p>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex justify-end gap-1">
                                            <Button type="button" size="icon" variant="ghost" :aria-label="`Edit ${rule.name}`" @click="edit(rule)"
                                                ><Pencil class="size-4"
                                            /></Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                :aria-label="`Delete ${rule.name}`"
                                                @click="remove(rule)"
                                                ><Trash2 class="size-4 text-destructive"
                                            /></Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog v-model:open="open">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <form class="space-y-4" @submit.prevent="save">
                        <DialogHeader>
                            <DialogTitle>{{ editing ? 'Change the rule' : 'Add a rule' }}</DialogTitle>
                            <DialogDescription>A post that breaks it is held for a moderator, or refused, as you choose below.</DialogDescription>
                        </DialogHeader>

                        <div class="space-y-2">
                            <Label for="rule-name">Name</Label>
                            <Input id="rule-name" v-model="form.name" maxlength="120" required placeholder="Watch adverts" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="space-y-2">
                            <Label for="rule-type">Looks for</Label>
                            <select
                                id="rule-type"
                                v-model="form.type"
                                :disabled="editing !== null"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <InputError :message="form.errors.type" />
                        </div>

                        <template v-if="form.type === 'words'">
                            <div class="space-y-2">
                                <Label for="rule-phrases">Words and phrases (one on each line)</Label>
                                <Textarea id="rule-phrases" v-model="form.phrases" rows="5" placeholder="cheap watches&#10;replica&#10;free money" />
                                <InputError :message="err('settings.phrases')" />
                                <InputError :message="err('settings.phrases.0')" />
                            </div>
                            <div class="flex items-start gap-2">
                                <Checkbox
                                    id="rule-whole"
                                    :model-value="form.whole_words"
                                    @update:model-value="(value) => (form.whole_words = value === true)"
                                />
                                <Label for="rule-whole" class="font-normal">Whole words only, so that "ass" does not catch "class"</Label>
                            </div>
                        </template>

                        <template v-else-if="form.type === 'links'">
                            <div class="space-y-2">
                                <Label for="rule-max-links">More links than this (optional)</Label>
                                <Input id="rule-max-links" v-model="form.max_links" type="number" min="0" max="100" placeholder="2" />
                                <InputError :message="err('settings.max_links')" />
                            </div>
                            <div class="space-y-2">
                                <Label for="rule-domains">Any link to these domains (optional, one on each line)</Label>
                                <Textarea id="rule-domains" v-model="form.blocked_domains" rows="4" placeholder="bad.example" />
                                <p class="text-xs text-muted-foreground">
                                    A domain catches its subdomains too (bad.example catches shop.bad.example).
                                </p>
                                <InputError :message="err('settings.blocked_domains')" />
                            </div>
                        </template>

                        <template v-else-if="form.type === 'duplicate'">
                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-2">
                                    <Label for="rule-copies">Copies already posted</Label>
                                    <Input id="rule-copies" v-model="form.copies" type="number" min="1" max="20" required />
                                    <InputError :message="err('settings.copies')" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="rule-dup-minutes">In the last (minutes)</Label>
                                    <Input id="rule-dup-minutes" v-model="form.minutes" type="number" min="1" max="1440" required />
                                    <InputError :message="err('settings.minutes')" />
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">With 1, the second time a member posts the same words is caught.</p>
                        </template>

                        <template v-else>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-2">
                                    <Label for="rule-posts">Posts already made</Label>
                                    <Input id="rule-posts" v-model="form.posts" type="number" min="1" max="100" required />
                                    <InputError :message="err('settings.posts')" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="rule-rate-minutes">In the last (minutes)</Label>
                                    <Input id="rule-rate-minutes" v-model="form.rate_minutes" type="number" min="1" max="1440" required />
                                    <InputError :message="err('settings.minutes')" />
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Held posts count too, so a wave of spam from one account is caught from its fourth post.
                            </p>
                        </template>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-2">
                                <Label for="rule-action">A post that breaks it is</Label>
                                <select
                                    id="rule-action"
                                    v-model="form.action"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                >
                                    <option value="hold">Held for a moderator</option>
                                    <option value="reject">Refused</option>
                                </select>
                                <InputError :message="form.errors.action" />
                            </div>
                            <div class="space-y-2">
                                <Label for="rule-days">Only for accounts under (days)</Label>
                                <Input id="rule-days" v-model="form.only_new_days" type="number" min="1" max="3650" placeholder="Everybody" />
                                <InputError :message="form.errors.only_new_days" />
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            A refused post is not kept and the member is told it looks like spam. A held post is kept out of sight until a moderator
                            approves it; its author sees it marked as waiting.
                        </p>

                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="rule-enabled"
                                :model-value="form.is_enabled"
                                @update:model-value="(value) => (form.is_enabled = value === true)"
                            />
                            <Label for="rule-enabled" class="font-normal">The rule is on</Label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                            <Button type="submit" :disabled="form.processing">
                                <LoaderCircle v-if="form.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" />
                                {{ editing ? 'Save' : 'Add the rule' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                v-model:open="confirmDialogState.open"
                :title="confirmDialogState.title"
                :description="confirmDialogDescription"
                :confirm-label="confirmDialogState.confirmLabel"
                :confirm-variant="confirmDialogState.confirmVariant"
                @confirm="handleConfirmDialogConfirm"
                @cancel="handleConfirmDialogCancel"
            />
        </AdminLayout>
    </AppLayout>
</template>
