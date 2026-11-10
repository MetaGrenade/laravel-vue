<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Switch two-factor off for a member who is locked out (see docs/two-factor.md, "When a member is locked out"). A reason is asked for and
 * kept in the audit log, and the member is emailed. `refusal` is why this staff member may not do it to this account (their own, a member of
 * staff when they are not an administrator, or nothing set up), when that is so; `canEmail` is false for an address the site made up.
 */
const props = defineProps<{
    user: { id: number; nickname: string };
    state: 'on' | 'pending' | 'off';
    refusal: string | null;
    canEmail: boolean;
}>();

const open = ref(false);

const form = useForm({ reason: '' });

const status = computed(() => {
    switch (props.state) {
        case 'on':
            return 'Two-factor is on: signing in asks for a code from their authenticator app.';
        case 'pending':
            return 'A setup was started and never confirmed, so signing in does not ask for a code.';
        default:
            return 'Two-factor is not set up.';
    }
});

// The server answers with a redirect back to this page (with a notice), so the form is closed and cleared when it works.
function reset() {
    form.delete(route('acp.users.two-factor.destroy', { user: props.user.id }), {
        preserveScroll: true,
        onSuccess: () => cancel(),
    });
}

function cancel() {
    open.value = false;
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <Card data-testid="reset-two-factor-card">
        <CardHeader>
            <CardTitle>Two-factor authentication</CardTitle>
            <CardDescription>For a member who has lost their authenticator app and their recovery codes and cannot sign in.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-2 text-sm text-muted-foreground">
            <p data-testid="two-factor-state">{{ status }}</p>
            <p>
                Turning it off clears the secret and the recovery codes: they sign in with their password alone and can set it up again in Settings →
                Security. Be sure the person asking is the member, as you would before changing the address on an account. It is written to the audit
                log with your reason<span v-if="props.canEmail">, and the member is emailed that it happened</span>.
            </p>
            <p v-if="!props.canEmail" class="font-medium text-foreground">
                This account's address cannot receive mail, so the member will not be told.
            </p>
            <p v-if="props.refusal" class="font-medium text-foreground" data-testid="two-factor-refusal">{{ props.refusal }}</p>
        </CardContent>
        <CardFooter>
            <Button type="button" variant="destructive" :disabled="props.refusal !== null" @click="open = true">Turn two-factor off</Button>
        </CardFooter>
    </Card>

    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-4" @submit.prevent="reset">
                <DialogHeader>
                    <DialogTitle>Turn two-factor off for {{ props.user.nickname }}</DialogTitle>
                    <DialogDescription>
                        They will sign in with their password alone from now on. This cannot be undone from here: they set it up again themselves.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-2">
                    <Label for="two-factor-reset-reason">Why are you turning it off?</Label>
                    <Textarea
                        id="two-factor-reset-reason"
                        v-model="form.reason"
                        rows="3"
                        maxlength="500"
                        placeholder="A ticket number, or a sentence"
                        required
                        autofocus
                    />
                    <p class="text-xs text-muted-foreground">Kept in the audit log with your name.</p>
                    <InputError :message="form.errors.reason" />
                    <InputError :message="(form.errors as Record<string, string>).two_factor" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="cancel">Cancel</Button>
                    <Button type="submit" variant="destructive" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="mr-2 size-4 animate-spin" aria-hidden="true" />
                        Turn two-factor off
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
