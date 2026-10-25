<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { ref } from 'vue';

/**
 * Start a read-only view as this customer (see docs/impersonation.md). A reason is asked for and kept in the audit log.
 * `refusal` is why this person cannot be viewed as (staff, banned, oneself), when that is so.
 */
const props = defineProps<{
    user: { id: number; nickname: string };
    refusal: string | null;
    minutes: number;
}>();

const open = ref(false);

const form = useForm({ reason: '' });

// The server answers with a redirect to the customer's own dashboard, so there is nothing to reset when it works.
function start() {
    form.post(route('acp.users.impersonate', { user: props.user.id }));
}

function cancel() {
    open.value = false;
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>View as this user</CardTitle>
            <CardDescription>See the site as {{ props.user.nickname }} sees it, to help with a support case.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-2 text-sm text-muted-foreground">
            <p>
                It is read only, and ends by itself after {{ props.minutes }} minutes. Nothing can be changed. A few pages stay closed (security
                settings, downloads, checkout), and every page you open is written to the audit log with your reason.
            </p>
            <p v-if="props.refusal" class="font-medium text-foreground">{{ props.refusal }}</p>
        </CardContent>
        <CardFooter>
            <Button type="button" variant="secondary" :disabled="props.refusal !== null" @click="open = true">View as this user</Button>
        </CardFooter>
    </Card>

    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-4" @submit.prevent="start">
                <DialogHeader>
                    <DialogTitle>View as {{ props.user.nickname }}</DialogTitle>
                    <DialogDescription>
                        You will see the site as this person does, read only, for {{ props.minutes }} minutes. It applies to this whole browser
                        session, so every tab shows their site until you stop: use a private window to keep working in the admin area.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-2">
                    <Label for="impersonation-reason">Why are you viewing as this person?</Label>
                    <Textarea
                        id="impersonation-reason"
                        v-model="form.reason"
                        rows="3"
                        maxlength="500"
                        placeholder="A ticket number, or a sentence"
                        required
                        autofocus
                    />
                    <p class="text-xs text-muted-foreground">Kept in the audit log with every page you open.</p>
                    <InputError :message="form.errors.reason" />
                    <InputError :message="(form.errors as Record<string, string>).impersonate" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="cancel">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" />
                        Start viewing
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
