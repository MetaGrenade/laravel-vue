import { computed, ref, type ComputedRef, watch } from 'vue'

export interface PaginationMeta {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
}

export interface PaginationPayload<T = unknown> {
    data?: T[]
    meta?: Partial<PaginationMeta> | null
    links?: Record<string, unknown> | null
}

interface PaginationLabels {
    singular?: string
    plural?: string
    empty?: string
}

interface UseInertiaPaginationOptions {
    fallbackPerPage?: number
    labels?: PaginationLabels
    onNavigate: (page: number) => void
}

type PaginationAccessor<T> = () => PaginationPayload<T> | null | undefined

function toNumber(value: unknown, fallback: number, min?: number): number {
    const parsed = Number(value)

    if (!Number.isFinite(parsed)) {
        return fallback
    }

    if (typeof min === 'number') {
        return Math.max(parsed, min)
    }

    return parsed
}

export function useInertiaPagination<T>(
    accessor: PaginationAccessor<T> | ComputedRef<PaginationPayload<T> | null | undefined>,
    options: UseInertiaPaginationOptions,
) {
    const payload = computed<PaginationPayload<T>>(() => {
        const raw = typeof accessor === 'function' ? accessor() : accessor.value

        return {
            data: raw?.data ?? [],
            meta: raw?.meta ?? null,
        }
    })

    const items = computed<T[]>(() => (Array.isArray(payload.value.data) ? payload.value.data : []))

    const fallbackMeta = computed<PaginationMeta>(() => {
        const total = items.value.length
        const fallbackPerPage = options.fallbackPerPage ?? (total > 0 ? total : 10)

        return {
            current_page: 1,
            last_page: 1,
            per_page: fallbackPerPage,
            total,
            from: total > 0 ? 1 : null,
            to: total > 0 ? total : null,
        }
    })

    const meta = computed<PaginationMeta>(() => {
        const fallback = fallbackMeta.value
        const rawMeta = payload.value.meta ?? {}

        return {
            current_page: toNumber((rawMeta as Partial<PaginationMeta>).current_page, fallback.current_page, 1),
            last_page: toNumber((rawMeta as Partial<PaginationMeta>).last_page, fallback.last_page, 1),
            per_page: toNumber((rawMeta as Partial<PaginationMeta>).per_page, fallback.per_page, 1),
            total: toNumber((rawMeta as Partial<PaginationMeta>).total, fallback.total, 0),
            from: (rawMeta as Partial<PaginationMeta>).from ?? fallback.from,
            to: (rawMeta as Partial<PaginationMeta>).to ?? fallback.to,
        }
    })

    const pageCount = computed(() => {
        const currentMeta = meta.value
        const derived = Math.ceil(currentMeta.total / Math.max(currentMeta.per_page, 1))

        return Math.max(currentMeta.last_page, derived || 1, 1)
    })

    const hasMultiplePages = computed(() => pageCount.value > 1)

    const labels = computed(() => {
        const singular = options.labels?.singular ?? 'item'
        const plural = options.labels?.plural ?? `${singular}s`
        const empty = options.labels?.empty ?? `No ${plural} to display`

        return { singular, plural, empty }
    })

    const rangeLabel = computed(() => {
        const currentMeta = meta.value
        const currentLabels = labels.value

        if (currentMeta.total === 0) {
            return currentLabels.empty
        }

        const from = currentMeta.from ?? (currentMeta.current_page - 1) * currentMeta.per_page + 1
        const to = currentMeta.to ?? Math.min(currentMeta.current_page * currentMeta.per_page, currentMeta.total)
        const noun = currentMeta.total === 1 ? currentLabels.singular : currentLabels.plural

        return `Showing ${from}-${to} of ${currentMeta.total} ${noun}`
    })

    const page = ref(meta.value.current_page)

    watch(
        () => meta.value.current_page,
        (value) => {
            page.value = value
        },
    )

    function goToPage(target: number) {
        const safePage = Math.min(Math.max(target, 1), pageCount.value)

        if (safePage !== target) {
            page.value = safePage
            return
        }

        if (safePage === meta.value.current_page) {
            return
        }

        options.onNavigate(safePage)
    }

    watch(page, (value) => {
        goToPage(value)
    })

    const itemsPerPage = computed(() => Math.max(meta.value.per_page, 1))

    return {
        page,
        meta,
        pageCount,
        hasMultiplePages,
        rangeLabel,
        itemsPerPage,
        items,
        goToPage,
    }
}
