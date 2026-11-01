import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

export type FlashMessages = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
};

export function showFlashToasts(flash?: FlashMessages | null): void {
    if (!flash) {
        return;
    }

    if (flash.success) toast.success(flash.success);
    if (flash.error) toast.error(flash.error);
    if (flash.warning) toast.warning(flash.warning);
    if (flash.info) toast.info(flash.info);
}

/**
 * Show session flash messages (`redirect()->with('success', ...)`) as toasts.
 *
 * The `success` event only fires for responses from the server, so restoring a
 * page from browser history does not replay its flash messages.
 */
export function initializeFlashToast(initialFlash?: FlashMessages | null): void {
    router.on('success', (event) => {
        showFlashToasts(event.detail.page.props.flash as FlashMessages | undefined);
    });

    // A refused action (App\Support\Errors\ErrorPages) comes back as an error on the page, so that the form keeps what it had and the
    // visit's onSuccess does not run. `success` does not fire for it, and the message is what the toast says.
    router.on('error', (event) => {
        const refused = (event.detail.errors as Record<string, string> | undefined)?.refused;

        if (refused) toast.error(refused);
    });

    // Wait for the <Toaster> in the layout to mount before showing anything
    // flashed on the initial (full page) load.
    setTimeout(() => showFlashToasts(initialFlash), 0);
}
