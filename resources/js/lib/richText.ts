// What the forum page and the rich-text editor both need to agree on about the editor's HTML.

/** The text of a piece of editor HTML: markup and non-breaking spaces taken out. */
export const richTextToPlainText = (html: string): string =>
    html
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

/** Whether a piece of editor HTML holds any text (an empty paragraph does not). */
export const hasRichTextContent = (html: string): boolean => richTextToPlainText(html) !== '';

/**
 * `addition` placed after `base`, with an empty paragraph between them so the writer can
 * type there (how a quote follows what a member has already written). When `base` holds
 * no text, the result is `addition` alone.
 */
export const appendRichText = (base: string, addition: string): string => {
    if (!hasRichTextContent(base)) {
        return addition;
    }

    const trimmed = base.trim();
    const withSpacer = trimmed.endsWith('<p></p>') ? trimmed : `${trimmed}<p></p>`;

    return `${withSpacer}${addition}`;
};
