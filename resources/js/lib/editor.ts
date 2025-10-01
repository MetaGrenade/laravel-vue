export const sanitizeEditorMarkup = (value: string | null | undefined): string => {
    if (!value) {
        return '';
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return '';
    }

    return trimmed;
};

const stripHtml = (value: string): string =>
    value
        .replace(/<p>(\s|&nbsp;|<br\s*\/?\s*>)*<\/p>/gi, ' ')
        .replace(/<br\s*\/?\s*>/gi, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

export const isEditorContentEmpty = (value: string | null | undefined): boolean => {
    if (!value) {
        return true;
    }

    if (value.trim() === '') {
        return true;
    }

    return stripHtml(value) === '';
};

export const normalizeEditorContent = (value: string | null | undefined): string => {
    const sanitized = sanitizeEditorMarkup(value);

    if (isEditorContentEmpty(sanitized)) {
        return '';
    }

    return sanitized;
};
