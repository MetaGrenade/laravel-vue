import RichTextEditorFallback from '@/components/editor/RichTextEditorFallback.vue';
import { defineAsyncComponent } from 'vue';

// The editor is the heaviest thing on the forum pages (TipTap, ProseMirror and
// their extensions are about 140 kB gzipped), and a page that only shows it behind
// a condition, a reply box a guest never sees or a dialog nobody has opened, would
// still make every visitor download it if it imported the editor outright.
// This loads it the first time one is rendered (docs/bundle-budget.md).
//
// Until the editor has arrived, what the page puts in the v-model (the forum's Quote
// action does) waits in the page's state. The editor takes it up when it is drawn,
// and puts a saved draft before it (loadInitialContent in RichTextEditor.vue).
//
// A page whose whole purpose is writing (ForumThreadCreate) imports the editor
// itself: it is needed at once, and waiting for it would only delay the page.
export const LazyRichTextEditor = defineAsyncComponent({
    loader: () => import('@/components/editor/RichTextEditor.vue'),
    loadingComponent: RichTextEditorFallback,
    errorComponent: RichTextEditorFallback,
    // Show the stand-in at once: the box keeps its place on the page, and the id
    // the "quote" button scrolls to exists before the code has arrived.
    delay: 0,
});
