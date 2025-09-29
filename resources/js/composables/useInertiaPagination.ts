import { computed, ref, unref, watch, type ComputedRef, type MaybeRefOrGetter, type Ref } from 'vue';

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface UseInertiaPaginationOptions {
    meta: MaybeRefOrGetter<Partial<PaginationMeta> | null | undefined>;
    itemsLength?: MaybeRefOrGetter<number | null | undefined>;
    defaultPerPage?: number;
    itemLabel?: string;
    itemLabelPlural?: string;
    emptyLabel?: string;
    onNavigate?: (page: number) => void;
}

export interface SetPageOptions {
    emitNavigate?: boolean;
}

export interface UseInertiaPaginationResult {
    meta: ComputedRef<PaginationMeta>;
    page: Ref<number>;
    setPage: (page: number, options?: SetPageOptions) => void;
    pageCount: ComputedRef<number>;
    rangeLabel: ComputedRef<string>;
}

function normalizeNumber(value: unknown, fallback: number): number {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : fallback;
}

export function useInertiaPagination(options: UseInertiaPaginationOptions): UseInertiaPaginationResult {
    const {
        meta: metaSource,
        itemsLength = 0,
        defaultPerPage = 15,
        itemLabel = 'item',
        itemLabelPlural,
        emptyLabel,
        onNavigate,
    } = options;

    const itemsCount = computed(() => Math.max(0, normalizeNumber(unref(itemsLength), 0)));

    const meta = computed<PaginationMeta>(() => {
        const raw = unref(metaSource) ?? {};

        const total = normalizeNumber(raw.total, itemsCount.value);
        const perPageDefault = itemsCount.value > 0 ? itemsCount.value : defaultPerPage;
        const perPage = Math.max(1, normalizeNumber(raw.per_page, perPageDefault) || perPageDefault);
        const currentPage = Math.max(1, normalizeNumber(raw.current_page, 1));
        const derivedLastPage = Math.max(Math.ceil(total / Math.max(perPage, 1)), 1);
        const lastPage = Math.max(1, normalizeNumber(raw.last_page, derivedLastPage));

        const hasItems = total > 0;
        const from = raw.from ?? (hasItems ? (currentPage - 1) * perPage + 1 : null);
        const to = raw.to ?? (hasItems ? Math.min(currentPage * perPage, total) : null);

        return {
            current_page: currentPage,
            last_page: lastPage,
            per_page: perPage,
            total,
            from,
            to,
        };
    });

    const page = ref(meta.value.current_page);

    const pageCount = computed(() => {
        const derived = Math.ceil(meta.value.total / Math.max(meta.value.per_page, 1));
        return Math.max(meta.value.last_page, derived || 1, 1);
    });

    const pluralLabel = computed(() => itemLabelPlural ?? `${itemLabel}s`);
    const emptyMessage = computed(() => emptyLabel ?? `No ${pluralLabel.value} to display`);

    const rangeLabel = computed(() => {
        if (meta.value.total === 0) {
            return emptyMessage.value;
        }

        const from = meta.value.from ?? (meta.value.current_page - 1) * meta.value.per_page + 1;
        const to = meta.value.to ?? Math.min(meta.value.current_page * meta.value.per_page, meta.value.total);
        const label = meta.value.total === 1 ? itemLabel : pluralLabel.value;

        return `Showing ${from}-${to} of ${meta.value.total} ${label}`;
    });

    watch(
        () => meta.value.current_page,
        (newPage) => {
            if (page.value !== newPage) {
                page.value = newPage;
            }
        },
    );

    let skipNextNavigate = false;

    watch(
        page,
        (newPage) => {
            const safePage = Math.min(Math.max(newPage, 1), pageCount.value);

            if (safePage !== newPage) {
                page.value = safePage;
                skipNextNavigate = false;
                return;
            }

            if (skipNextNavigate) {
                skipNextNavigate = false;
                return;
            }

            if (safePage === meta.value.current_page) {
                return;
            }

            onNavigate?.(safePage);
        },
    );

    const setPage = (value: number, options: SetPageOptions = {}) => {
        skipNextNavigate = options.emitNavigate === false;
        page.value = value;
    };

    return {
        meta,
        page,
        setPage,
        pageCount,
        rangeLabel,
    };
}
