<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

// Import shadcn‑vue components
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Button from '@/components/ui/button/Button.vue';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination'
import { Textarea } from '@/components/ui/textarea'
import RichTextEditor from '@/components/editor/RichTextEditor.vue';
import { useInitials } from '@/composables/useInitials';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Pin,
    PinOff,
    Ellipsis,
    Eye,
    EyeOff,
    Pencil,
    Trash2,
    Lock,
    LockOpen,
    Flag,
    MessageSquareLock,
    Bell,
    BellOff,
    Quote,
    RotateCcw,
} from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { getEcho, leaveEchoChannel } from '@/lib/echo';
import type { PresenceChannel } from 'laravel-echo';

interface BoardSummary {
    title: string;
    slug: string;
    category?: {
        title: string | null;
        slug: string | null;
    } | null;
}

interface ThreadPermissions {
    canModerate: boolean;
    canEdit: boolean;
    canReport: boolean;
    canReply: boolean;
}

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
    is_locked: boolean;
    is_pinned: boolean;
    is_published: boolean;
    views: number;
    author: string | null;
    last_posted_at: string | null;
    is_subscribed: boolean;
    subscribers_count: number;
    permissions: ThreadPermissions;
}

interface PostAuthor {
    id: number | null;
    nickname: string | null;
    joined_at: string | null;
    forum_posts_count: number;
    primary_role: string | null;
    avatar_url: string | null;
    forum_signature: string | null;
    reputation_points: number;
    badges: AuthorBadge[];
}

interface AuthorBadge {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    points_required: number;
    awarded_at: string | null;
}

interface PostPermissions {
    canReport: boolean;
    canEdit: boolean;
    canDelete: boolean;
    canModerate: boolean;
}

interface PostMention {
    id: number;
    nickname: string;
    profile_url: string | null;
}

interface ThreadPost {
    id: number;
    body: string;
    body_raw: string;
    quote_html: string;
    created_at: string;
    edited_at: string | null;
    number: number;
    author: PostAuthor;
    permissions: PostPermissions;
    mentions: PostMention[];
}

interface ThreadPresenceMember {
    id: number;
    nickname: string | null;
    avatar_url: string | null;
}

interface LivePostEvent {
    thread_id: number;
    thread_title: string;
    post: {
        id: number;
        author: { id: number | null; nickname: string | null; avatar_url: string | null };
        excerpt: string | null;
        created_at: string | null;
        url: string;
    };
}

interface PostsPayload {
    data: ThreadPost[];
    meta?: PaginationMeta | null;
    links?: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    } | null;
}

interface ReportReasonOption {
    value: string;
    label: string;
    description?: string | null;
}

const props = defineProps<{
    board: BoardSummary;
    thread: ThreadSummary;
    posts: PostsPayload;
    reportReasons: ReportReasonOption[];
}>();

const page = usePage<SharedData>();
const authUser = computed(() => page.props.auth.user as User | null);
const { getInitials } = useInitials();

const presenceMembers = ref<ThreadPresenceMember[]>([]);
const presencePreviewMembers = computed(() => presenceMembers.value.slice(0, 4));
const presenceCount = computed(() => presenceMembers.value.length);
const presenceNamesSummary = computed(() => {
    if (presenceMembers.value.length === 0) {
        return '';
    }

    const currentUserId = authUser.value?.id ?? null;
    const names = presenceMembers.value.map((member) => {
        if (currentUserId !== null && member.id === currentUserId) {
            return 'You';
        }

        return member.nickname ?? 'Community member';
    });

    if (names.length <= 3) {
        return names.join(', ');
    }

    return `${names.slice(0, 3).join(', ')} and ${names.length - 3} more`;
});
const liveReplyNotice = ref<LivePostEvent | null>(null);
const liveReplyAuthorName = computed(() => liveReplyNotice.value?.post.author?.nickname ?? 'Someone');
const liveReplyExcerpt = computed(() => liveReplyNotice.value?.post.excerpt ?? null);
const liveReplyUrl = computed(() => liveReplyNotice.value?.post.url ?? null);

let presenceChannel: PresenceChannel | null = null;

const threadPresenceChannelName = computed(() => `forum.threads.${props.thread.id}`);

const normaliseMembers = (members: ThreadPresenceMember[]): ThreadPresenceMember[] => {
    const mapped = members
        .map((member) => ({
            id: Number(member.id),
            nickname: member.nickname ?? null,
            avatar_url: member.avatar_url ?? null,
        }))
        .filter((member) => Number.isFinite(member.id));

    const unique = new Map<number, ThreadPresenceMember>();

    mapped.forEach((member) => {
        unique.set(member.id, member);
    });

    return Array.from(unique.values()).sort((a, b) => {
        const nameA = (a.nickname ?? '').toLowerCase();
        const nameB = (b.nickname ?? '').toLowerCase();

        return nameA.localeCompare(nameB);
    });
};

const updatePresenceMembers = (members: ThreadPresenceMember[]) => {
    presenceMembers.value = normaliseMembers(members);
};

const leaveThreadPresence = () => {
    leaveEchoChannel(threadPresenceChannelName.value);
    presenceChannel = null;
    presenceMembers.value = [];
};

const joinThreadPresence = () => {
    const echo = getEcho();

    if (!echo || !authUser.value) {
        leaveThreadPresence();
        return;
    }

    if (presenceChannel) {
        return;
    }

    presenceChannel = echo.join(threadPresenceChannelName.value)
        .here((members: ThreadPresenceMember[]) => {
            updatePresenceMembers(members);
        })
        .joining((member: ThreadPresenceMember) => {
            updatePresenceMembers([...presenceMembers.value, member]);
        })
        .leaving((member: ThreadPresenceMember) => {
            updatePresenceMembers(presenceMembers.value.filter((existing) => existing.id !== Number(member.id)));
        })
        .listen('.ForumPostCreated', (event: LivePostEvent) => {
            if (event.thread_id !== props.thread.id) {
                return;
            }

            if (event.post.author?.id && authUser.value?.id === event.post.author.id) {
                return;
            }

            liveReplyNotice.value = event;
        });
};

const dismissLiveReplyNotice = () => {
    liveReplyNotice.value = null;
};

const viewLiveReply = () => {
    const url = liveReplyUrl.value;

    if (!url) {
        return;
    }

    let relativeUrl = url;

    if (typeof window !== 'undefined') {
        try {
            const parsed = new URL(url, window.location.origin);

            if (parsed.origin === window.location.origin) {
                relativeUrl = parsed.pathname + parsed.search + parsed.hash;
            }
        } catch {
            // Ignore malformed URLs and fall back to the provided value.
        }
    }

    router.visit(relativeUrl, {
        preserveScroll: true,
        onFinish: () => {
            dismissLiveReplyNotice();
        },
    });
};

watch(
    () => authUser.value?.id,
    () => {
        leaveThreadPresence();
        joinThreadPresence();
    },
    { immediate: true },
);

watch(
    () => props.posts.data,
    () => {
        liveReplyNotice.value = null;
    },
);

watch(
    () => props.thread.id,
    () => {
        leaveThreadPresence();
        joinThreadPresence();
    },
);

onMounted(() => {
    joinThreadPresence();
});

onBeforeUnmount(() => {
    leaveThreadPresence();
});

const forumProfileDialogOpen = ref(false);

const highlightMentionsInHtml = (body: string, mentions: PostMention[]): string => {
    if (!body || mentions.length === 0) {
        return body;
    }

    if (body.includes('data-type="mention"')) {
        return body;
    }

    if (typeof window === 'undefined' || typeof window.DOMParser === 'undefined') {
        return body;
    }

    const parser = new window.DOMParser();
    const doc = parser.parseFromString(body, 'text/html');
    const root = doc.body ?? doc.documentElement;

    if (!root) {
        return body;
    }

    const mentionMap = new Map(mentions.map((mention) => [mention.nickname.toLowerCase(), mention]));
    const mentionPattern = /(?<![\w@])@([A-Za-z0-9_.-]{2,50})/g;
    const walker = doc.createTreeWalker(root, window.NodeFilter.SHOW_TEXT);
    const textNodes: Text[] = [];

    let node = walker.nextNode();
    while (node) {
        textNodes.push(node as Text);
        node = walker.nextNode();
    }

    textNodes.forEach((textNode) => {
        const text = textNode.nodeValue ?? '';

        if (text === '') {
            return;
        }

        const parent = textNode.parentNode;

        if (!parent) {
            return;
        }

        if (parent instanceof Element) {
            const mentionAncestor = parent.closest('[data-type="mention"]');

            if (mentionAncestor) {
                return;
            }

            if (parent.classList.contains('mention')) {
                return;
            }
        }

        const fragments: (string | Node)[] = [];
        let lastIndex = 0;
        let match: RegExpExecArray | null;

        mentionPattern.lastIndex = 0;

        while ((match = mentionPattern.exec(text)) !== null) {
            const nickname = match[1];
            const mention = mentionMap.get(nickname.toLowerCase());

            if (!mention) {
                continue;
            }

            const startIndex = match.index ?? 0;

            if (startIndex > lastIndex) {
                fragments.push(text.slice(lastIndex, startIndex));
            }

            const element = doc.createElement(mention.profile_url ? 'a' : 'span');
            element.className = 'mention text-primary font-medium hover:underline';
            element.textContent = `@${nickname}`;
            element.setAttribute('data-type', 'mention');
            element.setAttribute('data-nickname', mention.nickname);
            element.setAttribute('data-id', String(mention.id));

            if (mention.profile_url) {
                element.setAttribute('href', mention.profile_url);
                element.setAttribute('data-profile-url', mention.profile_url);
            }

            fragments.push(element);
            lastIndex = startIndex + match[0].length;
        }

        if (fragments.length === 0) {
            return;
        }

        if (lastIndex < text.length) {
            fragments.push(text.slice(lastIndex));
        }

        fragments.forEach((fragment) => {
            if (typeof fragment === 'string') {
                parent.insertBefore(doc.createTextNode(fragment), textNode);
            } else {
                parent.insertBefore(fragment, textNode);
            }
        });

        parent.removeChild(textNode);
    });

    return root.innerHTML ?? body;
};

const renderPostBody = (post: ThreadPost): string => {
    return highlightMentionsInHtml(post.body, post.mentions ?? []);
};

const resolveForumProfileDefaults = (user: User | null) => ({
    nickname: user?.nickname ?? '',
    email: user?.email ?? '',
    avatar_url: user?.avatar_url ?? '',
    forum_signature: user?.forum_signature ?? '',
});

const forumProfileForm = useForm(resolveForumProfileDefaults(authUser.value));

watch(
    authUser,
    (user) => {
        const defaults = resolveForumProfileDefaults(user);
        forumProfileForm.defaults(defaults);

        if (!forumProfileDialogOpen.value) {
            forumProfileForm.reset();
        }
    },
    { immediate: true },
);

watch(forumProfileDialogOpen, (open) => {
    if (!open) {
        forumProfileForm.reset();
        forumProfileForm.clearErrors();
    }
});

watch(
    () => forumProfileForm.avatar_url,
    () => {
        if (forumProfileForm.errors.avatar_url) {
            forumProfileForm.clearErrors('avatar_url');
        }
    },
);

watch(
    () => forumProfileForm.forum_signature,
    () => {
        if (forumProfileForm.errors.forum_signature) {
            forumProfileForm.clearErrors('forum_signature');
        }
    },
);

const submitForumProfile = () => {
    if (!authUser.value || forumProfileForm.processing) {
        return;
    }

    forumProfileForm.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            forumProfileDialogOpen.value = false;
        },
    });
};

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const trail: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];
    if (props.board.category?.title) {
        trail.push({ title: props.board.category.title, href: '/forum' });
    }
    trail.push({ title: props.board.title, href: `/forum/${props.board.slug}` });
    trail.push({ title: props.thread.title, href: route('forum.threads.show', { board: props.board.slug, thread: props.thread.slug }) });
    return trail;
});

const threadPermissions = computed(() => props.thread.permissions);
const reportReasons = computed(() => props.reportReasons ?? []);
const defaultReportReason = computed(() => reportReasons.value[0]?.value ?? '');
const hasReportReasons = computed(() => reportReasons.value.length > 0);

const {
    meta: postsMeta,
    page: paginationPage,
    rangeLabel: postsRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.posts.meta ?? null),
    itemsLength: computed(() => props.posts.data.length),
    defaultPerPage: 10,
    itemLabel: 'post',
    itemLabelPlural: 'posts',
    emptyLabel: 'No posts to display',
    onNavigate: (page) => {
        router.get(
            route('forum.threads.show', { board: props.board.slug, thread: props.thread.slug }),
            {
                page,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});
const threadActionLoading = ref(false);
const activePostActionId = ref<number | null>(null);
const threadReportDialogOpen = ref(false);
const postReportDialogOpen = ref(false);
const postReportTarget = ref<ThreadPost | null>(null);
const deleteThreadDialogOpen = ref(false);
const deletePostDialogOpen = ref(false);
const pendingDeletePost = ref<ThreadPost | null>(null);
const deleteThreadDialogTitle = computed(
    () => `Delete "${props.thread.title}"?`,
);
const deletePostDialogTitle = computed(() => {
    const target = pendingDeletePost.value;

    if (!target) {
        return 'Delete post?';
    }

    const author = target.author?.nickname ?? 'Unknown user';

    return `Delete post #${target.number} by ${author}?`;
});

const threadReportForm = useForm({
    reason_category: '',
    reason: '',
    evidence_url: '',
    page: postsMeta.value.current_page,
});

const postReportForm = useForm({
    reason_category: '',
    reason: '',
    evidence_url: '',
    page: postsMeta.value.current_page,
});

const selectedThreadReason = computed(() =>
    reportReasons.value.find((option) => option.value === threadReportForm.reason_category) ?? null,
);

const selectedPostReason = computed(() =>
    reportReasons.value.find((option) => option.value === postReportForm.reason_category) ?? null,
);

watch(
    () => postsMeta.value.current_page,
    (page) => {
        threadReportForm.page = page;
        postReportForm.page = page;
    },
);

watch(paginationPage, (page) => {
    threadReportForm.page = page;
    postReportForm.page = page;
});

watch(threadReportDialogOpen, (open) => {
    if (open) {
        if (!threadReportForm.reason_category && defaultReportReason.value) {
            threadReportForm.reason_category = defaultReportReason.value;
        }
    } else {
        threadReportForm.reset('reason_category', 'reason', 'evidence_url');
        threadReportForm.clearErrors();
    }
});

watch(postReportDialogOpen, (open) => {
    if (open) {
        if (!postReportForm.reason_category && defaultReportReason.value) {
            postReportForm.reason_category = defaultReportReason.value;
        }
    } else {
        postReportTarget.value = null;
        postReportForm.reset('reason_category', 'reason', 'evidence_url');
        postReportForm.clearErrors();
    }
});

watch(deletePostDialogOpen, (open) => {
    if (!open) {
        pendingDeletePost.value = null;
    }
});

const performThreadAction = (
    method: 'put' | 'post',
    routeName: string,
    payload: Record<string, unknown> = {},
) => {
    threadActionLoading.value = true;

    const url = route(routeName, { board: props.board.slug, thread: props.thread.slug });
    const data = {
        ...payload,
        redirect_to_thread: true,
        page: postsMeta.value.current_page,
    };

    const options = {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => {
            threadActionLoading.value = false;
        },
    } as const;

    if (method === 'post') {
        router.post(url, data, options);
    } else {
        router.put(url, data, options);
    }
};
const openThreadReportDialog = () => {
    if (!threadPermissions.value?.canReport || !hasReportReasons.value) {
        return;
    }

    threadReportDialogOpen.value = true;
};

const submitThreadReport = () => {
    if (!threadPermissions.value?.canReport || threadReportForm.processing || !hasReportReasons.value) {
        return;
    }

    threadReportForm.page = postsMeta.value.current_page;

    threadReportForm.post(route('forum.threads.report', { board: props.board.slug, thread: props.thread.slug }), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onSuccess: () => {
            threadReportDialogOpen.value = false;
        },
    });
};

const subscribeToThread = () => {
    if (!authUser.value || threadActionLoading.value) {
        return;
    }

    threadActionLoading.value = true;

    router.post(
        route('forum.threads.subscribe', { board: props.board.slug, thread: props.thread.slug }),
        {
            page: postsMeta.value.current_page,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
            onFinish: () => {
                threadActionLoading.value = false;
            },
        },
    );
};

const unsubscribeFromThread = () => {
    if (!authUser.value || threadActionLoading.value) {
        return;
    }

    threadActionLoading.value = true;

    router.delete(
        route('forum.threads.unsubscribe', { board: props.board.slug, thread: props.thread.slug }),
        {
            page: postsMeta.value.current_page,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
            onFinish: () => {
                threadActionLoading.value = false;
            },
        },
    );
};

const publishThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.publish');
};

const unpublishThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unpublish');
};

const lockThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.lock');
};

const unlockThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unlock');
};

const pinThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.pin');
};

const unpinThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unpin');
};

const threadEditDialogOpen = ref(false);
const threadEditForm = useForm({
    title: props.thread.title,
});

watch(
    () => props.thread.title,
    (title) => {
        threadEditForm.defaults('title', title);

        if (!threadEditDialogOpen.value && threadEditForm.title !== title) {
            threadEditForm.title = title;
        }
    },
    { immediate: true },
);

watch(threadEditDialogOpen, (open) => {
    if (!open) {
        threadEditForm.reset('title');
        threadEditForm.clearErrors('title');
        threadEditForm.title = props.thread.title;
    }
});

watch(
    () => threadEditForm.title,
    () => {
        if (threadEditForm.errors.title) {
            threadEditForm.clearErrors('title');
        }
    },
);

const renameThread = () => {
    if (!threadPermissions.value?.canEdit || threadActionLoading.value) {
        return;
    }

    threadEditForm.title = props.thread.title;
    threadEditForm.clearErrors('title');
    threadEditDialogOpen.value = true;
};

const submitThreadEdit = () => {
    if (!threadPermissions.value?.canEdit || threadEditForm.processing) {
        return;
    }

    const trimmed = threadEditForm.title.trim();

    if (trimmed === '') {
        threadEditForm.setError('title', 'Please provide a thread title.');
        return;
    }

    if (trimmed === props.thread.title) {
        threadEditDialogOpen.value = false;
        return;
    }

    threadEditForm.title = trimmed;
    threadActionLoading.value = true;

    threadEditForm
        .transform(() => ({
            title: trimmed,
            redirect_to_thread: true,
            page: postsMeta.value.current_page,
        }))
        .put(route('forum.threads.update', { board: props.board.slug, thread: props.thread.slug }), {
            preserveScroll: true,
            preserveState: false,
            replace: true,
            onSuccess: () => {
                threadEditDialogOpen.value = false;
            },
            onFinish: () => {
                threadActionLoading.value = false;
            },
        });
};

const deleteThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    deleteThreadDialogOpen.value = true;
};

const confirmDeleteThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        deleteThreadDialogOpen.value = false;
        return;
    }

    threadActionLoading.value = true;
    deleteThreadDialogOpen.value = false;

    const url = route('forum.threads.destroy', { board: props.board.slug, thread: props.thread.slug });

    router.delete(url, {}, {
        preserveScroll: false,
        preserveState: false,
        onFinish: () => {
            threadActionLoading.value = false;
        },
    });
};

const cancelDeleteThread = () => {
    deleteThreadDialogOpen.value = false;
};

const performPostAction = (
    post: ThreadPost,
    method: 'put' | 'delete' | 'post',
    routeName: string,
    payload: Record<string, unknown> = {},
) => {
    activePostActionId.value = post.id;

    const url = route(routeName, { board: props.board.slug, thread: props.thread.slug, post: post.id });
    const data = {
        ...payload,
        page: postsMeta.value.current_page,
    };

    const options = {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => {
            activePostActionId.value = null;
        },
    } as const;

    if (method === 'delete') {
        router.delete(url, data, options);
    } else if (method === 'put') {
        router.put(url, data, options);
    } else {
        router.post(url, data, options);
    }
};

const openPostReportDialog = (post: ThreadPost) => {
    if (!post.permissions.canReport || !hasReportReasons.value) {
        return;
    }

    postReportTarget.value = post;
    postReportDialogOpen.value = true;
};

const submitPostReport = () => {
    const target = postReportTarget.value;

    if (!target || !target.permissions.canReport || postReportForm.processing || !hasReportReasons.value) {
        return;
    }

    postReportForm.page = postsMeta.value.current_page;

    postReportForm.post(route('forum.posts.report', { board: props.board.slug, thread: props.thread.slug, post: target.id }), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onSuccess: () => {
            postReportDialogOpen.value = false;
        },
    });
};

const postEditDialogOpen = ref(false);
const postEditTarget = ref<ThreadPost | null>(null);
const postEditForm = useForm({
    body: '',
});

const richTextToPlainText = (html: string) =>
    html
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const hasRichTextContent = (html: string) => richTextToPlainText(html) !== '';

watch(postEditDialogOpen, (open) => {
    if (!open) {
        postEditTarget.value = null;
        postEditForm.reset('body');
        postEditForm.clearErrors('body');
    }
});

watch(
    () => postEditForm.body,
    () => {
        if (postEditForm.errors.body) {
            postEditForm.clearErrors('body');
        }
    },
);

const editPost = (post: ThreadPost) => {
    if (!post.permissions.canEdit) {
        return;
    }

    postEditTarget.value = post;
    postEditForm.body = post.body_raw;
    postEditForm.clearErrors('body');
    postEditDialogOpen.value = true;
};

const submitPostEdit = () => {
    const target = postEditTarget.value;

    if (!target || !target.permissions.canEdit || postEditForm.processing) {
        return;
    }

    const currentBody = postEditForm.body ?? '';

    if (!hasRichTextContent(currentBody)) {
        postEditForm.setError('body', 'Please enter some content before saving.');
        return;
    }

    const normalisedBody = currentBody.trim();

    if (normalisedBody === target.body_raw?.trim()) {
        postEditDialogOpen.value = false;
        return;
    }

    postEditForm.body = normalisedBody;
    activePostActionId.value = target.id;

    postEditForm
        .transform(() => ({
            body: normalisedBody,
            page: postsMeta.value.current_page,
        }))
        .put(route('forum.posts.update', { board: props.board.slug, thread: props.thread.slug, post: target.id }), {
            preserveScroll: true,
            preserveState: false,
            replace: true,
            onSuccess: () => {
                postEditDialogOpen.value = false;
            },
            onFinish: () => {
                activePostActionId.value = null;
            },
        });
};

const deletePost = (post: ThreadPost) => {
    if (!post.permissions.canDelete || activePostActionId.value === post.id) {
        return;
    }

    pendingDeletePost.value = post;
    deletePostDialogOpen.value = true;
};

const viewPostHistory = (post: ThreadPost) => {
    if (!post.permissions.canModerate) {
        return;
    }

    router.get(
        route('forum.posts.history', {
            board: props.board.slug,
            thread: props.thread.slug,
            post: post.id,
        }),
        {},
        {
            preserveState: false,
        },
    );
};

const confirmDeletePost = () => {
    const target = pendingDeletePost.value;

    if (!target) {
        deletePostDialogOpen.value = false;
        return;
    }

    if (!target.permissions.canDelete || activePostActionId.value === target.id) {
        deletePostDialogOpen.value = false;
        return;
    }

    deletePostDialogOpen.value = false;
    performPostAction(target, 'delete', 'forum.posts.destroy');
};

const cancelDeletePost = () => {
    deletePostDialogOpen.value = false;
};

const quotePost = (post: ThreadPost) => {
    if (!threadPermissions.value?.canReply) {
        return;
    }

    const quoteHtml = post.quote_html?.trim();

    if (!quoteHtml) {
        return;
    }

    const currentBody = replyForm.body ?? '';
    const hasExistingContent = hasRichTextContent(currentBody);

    if (!hasExistingContent) {
        replyForm.body = quoteHtml;
    } else {
        const trimmed = currentBody.trim();
        const withSpacer = trimmed.endsWith('<p></p>') ? trimmed : `${trimmed}<p></p>`;
        replyForm.body = `${withSpacer}${quoteHtml}`;
    }

    nextTick(() => {
        document.getElementById('thread_reply_body')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

const replyForm = useForm({
    body: '',
});

const replyDraftStorageKey = computed(() => `forum:thread:${props.thread.id}:reply`);

watch(
    () => replyForm.body,
    () => {
        if (replyForm.errors.body) {
            replyForm.clearErrors('body');
        }
    },
);

const showReplyForm = computed(() => threadPermissions.value?.canReply ?? false);

const replySubmitDisabled = computed(() => {
    if (!threadPermissions.value?.canReply) {
        return true;
    }

    if (replyForm.processing) {
        return true;
    }

    return !hasRichTextContent(replyForm.body ?? '');
});

const submitReply = () => {
    if (!threadPermissions.value?.canReply || replyForm.processing) {
        return;
    }

    const currentBody = replyForm.body ?? '';

    if (!hasRichTextContent(currentBody)) {
        replyForm.setError('body', 'Please enter a reply before submitting.');
        return;
    }

    replyForm.clearErrors('body');
    const normalisedBody = currentBody.trim();
    replyForm.body = normalisedBody;

    replyForm.post(route('forum.posts.store', { board: props.board.slug, thread: props.thread.slug }), {
        preserveScroll: false,
        onSuccess: () => {
            replyForm.reset('body');
            replyForm.clearErrors();

            if (replyDraftStorageKey.value && typeof window !== 'undefined') {
                window.localStorage.removeItem(replyDraftStorageKey.value);
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Forum • ${props.thread.title}`" />
        <Dialog v-model:open="threadEditDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit thread title</DialogTitle>
                    <DialogDescription>
                        Update the discussion title to better reflect the current topic.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitThreadEdit">
                    <div class="space-y-2">
                        <Label for="thread_edit_title">Title</Label>
                        <Input
                            id="thread_edit_title"
                            v-model="threadEditForm.title"
                            type="text"
                            placeholder="Thread title"
                            :disabled="threadEditForm.processing"
                        />
                        <p v-if="threadEditForm.errors.title" class="text-sm text-destructive">
                            {{ threadEditForm.errors.title }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="threadEditForm.processing"
                            @click="threadEditDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="threadEditForm.processing">
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="forumProfileDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Forum profile</DialogTitle>
                    <DialogDescription>
                        Update your avatar and signature. These details appear next to every post you make.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitForumProfile">
                    <div class="flex items-center gap-4">
                        <Avatar class="h-16 w-16 overflow-hidden rounded-full">
                            <AvatarImage
                                v-if="forumProfileForm.avatar_url"
                                :src="forumProfileForm.avatar_url"
                                alt="Avatar preview"
                            />
                            <AvatarFallback
                                class="flex h-full w-full items-center justify-center bg-muted text-lg font-semibold uppercase"
                            >
                                {{ getInitials(authUser?.nickname ?? 'User') }}
                            </AvatarFallback>
                        </Avatar>
                        <p class="text-sm text-muted-foreground">
                            Paste a direct link to an image to use as your avatar. Square images look best.
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="forum_profile_avatar">Avatar URL</Label>
                        <Input
                            id="forum_profile_avatar"
                            v-model="forumProfileForm.avatar_url"
                            type="url"
                            placeholder="https://example.com/avatar.png"
                        />
                        <p v-if="forumProfileForm.errors.avatar_url" class="text-sm text-destructive">
                            {{ forumProfileForm.errors.avatar_url }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="forum_profile_signature">Forum signature</Label>
                        <Textarea
                            id="forum_profile_signature"
                            v-model="forumProfileForm.forum_signature"
                            rows="4"
                            maxlength="500"
                            placeholder="Share a short sign-off, pronouns, or helpful links."
                        />
                        <p class="text-xs text-muted-foreground">
                            Plain text only. Your signature is shown below each of your posts.
                        </p>
                        <p v-if="forumProfileForm.errors.forum_signature" class="text-sm text-destructive">
                            {{ forumProfileForm.errors.forum_signature }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="forumProfileForm.processing"
                            @click="forumProfileDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="forumProfileForm.processing">
                            Save profile
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="postEditDialogOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Edit post</DialogTitle>
                    <DialogDescription>
                        Make adjustments to your reply before saving the update.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitPostEdit">
                    <div class="space-y-2">
                        <Label for="post_edit_body">Post content</Label>
                        <RichTextEditor
                            id="post_edit_body"
                            v-model="postEditForm.body"
                            :placeholder="'Share your updated thoughts...'"
                            :autofocus="true"
                        />
                        <p v-if="postEditForm.errors.body" class="text-sm text-destructive">
                            {{ postEditForm.errors.body }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="postEditForm.processing"
                            @click="postEditDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="postEditForm.processing">
                            Save post
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="threadReportDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Report thread</DialogTitle>
                    <DialogDescription>
                        Let the moderation team know why this discussion needs attention. Provide as much
                        context as you can so we can review it quickly.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitThreadReport">
                    <div class="space-y-2">
                        <Label for="thread_report_reason">Reason</Label>
                        <select
                            id="thread_report_reason"
                            v-model="threadReportForm.reason_category"
                            class="w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus:outline-none focus:ring-2"
                            :class="threadReportForm.errors.reason_category
                                ? 'border-destructive focus:ring-destructive/40'
                                : 'focus:ring-primary/40'"
                            :disabled="!hasReportReasons"
                            required
                        >
                            <option disabled value="">Select a reason…</option>
                            <option v-for="option in reportReasons" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="selectedThreadReason?.description" class="text-xs text-muted-foreground">
                            {{ selectedThreadReason.description }}
                        </p>
                        <p v-if="!hasReportReasons" class="text-xs text-destructive">
                            Reporting options are temporarily unavailable. Please reach out to the support team.
                        </p>
                        <p v-if="threadReportForm.errors.reason_category" class="text-sm text-destructive">
                            {{ threadReportForm.errors.reason_category }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="thread_report_details">Additional details</Label>
                        <Textarea
                            id="thread_report_details"
                            v-model="threadReportForm.reason"
                            placeholder="Share specific quotes, timeline, or any other details that explain the problem."
                            class="min-h-[120px]"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but detailed reports help moderators resolve issues faster.
                        </p>
                        <p v-if="threadReportForm.errors.reason" class="text-sm text-destructive">
                            {{ threadReportForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="thread_report_evidence">Supporting link (optional)</Label>
                        <Input
                            id="thread_report_evidence"
                            v-model="threadReportForm.evidence_url"
                            type="url"
                            placeholder="https://example.com/screenshot-or-proof"
                        />
                        <p class="text-xs text-muted-foreground">
                            Share a link to screenshots, logs, or other evidence that supports your report.
                        </p>
                        <p v-if="threadReportForm.errors.evidence_url" class="text-sm text-destructive">
                            {{ threadReportForm.errors.evidence_url }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="threadReportForm.processing"
                            @click="threadReportDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-orange-500 hover:bg-orange-600"
                            :disabled="threadReportForm.processing || !hasReportReasons"
                        >
                            Submit report
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="postReportDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Report post
                        <template v-if="postReportTarget">
                            #{{ postReportTarget.number }} by {{ postReportTarget.author?.nickname ?? 'Unknown user' }}
                        </template>
                    </DialogTitle>
                    <DialogDescription>
                        Flag this reply for moderator review. We will notify you once a decision has been made.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="postReportTarget" class="rounded-md border border-muted bg-muted/20 p-3 text-sm">
                    <p class="text-xs uppercase text-muted-foreground">Post preview</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-foreground">
                        {{ postReportTarget.body_raw }}
                    </p>
                </div>
                <form class="mt-5 space-y-5" @submit.prevent="submitPostReport">
                    <div class="space-y-2">
                        <Label for="post_report_reason">Reason</Label>
                        <select
                            id="post_report_reason"
                            v-model="postReportForm.reason_category"
                            class="w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus:outline-none focus:ring-2"
                            :class="postReportForm.errors.reason_category
                                ? 'border-destructive focus:ring-destructive/40'
                                : 'focus:ring-primary/40'"
                            :disabled="!hasReportReasons"
                            required
                        >
                            <option disabled value="">Select a reason…</option>
                            <option v-for="option in reportReasons" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="selectedPostReason?.description" class="text-xs text-muted-foreground">
                            {{ selectedPostReason.description }}
                        </p>
                        <p v-if="!hasReportReasons" class="text-xs text-destructive">
                            Reporting options are temporarily unavailable. Please reach out to the support team.
                        </p>
                        <p v-if="postReportForm.errors.reason_category" class="text-sm text-destructive">
                            {{ postReportForm.errors.reason_category }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="post_report_details">Additional details</Label>
                        <Textarea
                            id="post_report_details"
                            v-model="postReportForm.reason"
                            placeholder="Explain what is wrong with this reply and why it breaks the rules."
                            class="min-h-[120px]"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but context helps moderators resolve issues faster.
                        </p>
                        <p v-if="postReportForm.errors.reason" class="text-sm text-destructive">
                            {{ postReportForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="post_report_evidence">Supporting link (optional)</Label>
                        <Input
                            id="post_report_evidence"
                            v-model="postReportForm.evidence_url"
                            type="url"
                            placeholder="https://example.com/screenshot-or-proof"
                        />
                        <p class="text-xs text-muted-foreground">
                            Share a link to screenshots, logs, or other evidence that supports your report.
                        </p>
                        <p v-if="postReportForm.errors.evidence_url" class="text-sm text-destructive">
                            {{ postReportForm.errors.evidence_url }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="postReportForm.processing"
                            @click="postReportDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-orange-500 hover:bg-orange-600"
                            :disabled="postReportForm.processing || !hasReportReasons"
                        >
                            Submit report
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <ConfirmDialog
            v-model:open="deleteThreadDialogOpen"
            :title="deleteThreadDialogTitle"
            description="Deleting this thread will remove all replies. This action cannot be undone."
            confirm-label="Delete thread"
            cancel-label="Cancel"
            :confirm-disabled="threadActionLoading"
            @confirm="confirmDeleteThread"
            @cancel="cancelDeleteThread"
        />
        <ConfirmDialog
            v-model:open="deletePostDialogOpen"
            :title="deletePostDialogTitle"
            description="Deleting this post cannot be undone."
            confirm-label="Delete post"
            cancel-label="Cancel"
            :confirm-disabled="activePostActionId === pendingDeletePost?.id"
            @confirm="confirmDeletePost"
            @cancel="cancelDeletePost"
        />
        <div class="p-4 space-y-8">
            <!-- Forum Header -->
            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
                <h1 id="thread_title" class="text-2xl font-bold text-green-500">
                    <Pin v-if="props.thread.is_pinned" class="h-8 w-8 inline-block mr-2" />
                    {{ props.thread.title }}
                    <Lock
                        v-if="props.thread.is_locked"
                        class="h-8 w-8 inline-block ml-2 text-muted-foreground"
                    />
                </h1>
                <div class="flex flex-wrap justify-end gap-2 md:flex-nowrap">
                    <div class="flex flex-col items-end justify-center rounded-md border border-border px-3 py-1">
                        <span class="text-xs font-medium uppercase text-muted-foreground">Followers</span>
                        <span class="text-base font-semibold text-foreground">{{ props.thread.subscribers_count }}</span>
                    </div>
                    <div
                        v-if="authUser && presenceCount > 0"
                        class="flex items-center gap-3 rounded-md border border-border px-3 py-1"
                    >
                        <div class="flex -space-x-2">
                            <Avatar
                                v-for="member in presencePreviewMembers"
                                :key="member.id"
                                class="border-2 border-background"
                                :title="member.nickname ?? 'Online member'"
                            >
                                <AvatarImage
                                    v-if="member.avatar_url"
                                    :src="member.avatar_url"
                                    :alt="member.nickname ?? 'Online member'"
                                />
                                <AvatarFallback>
                                    {{ getInitials(member.nickname ?? 'Online member') }}
                                </AvatarFallback>
                            </Avatar>
                        </div>
                        <div class="flex flex-col text-right">
                            <span class="text-xs font-medium uppercase text-muted-foreground">Online now</span>
                            <span class="text-base font-semibold text-foreground">{{ presenceCount }}</span>
                            <span v-if="presenceNamesSummary" class="text-xs text-muted-foreground">
                                {{ presenceNamesSummary }}
                            </span>
                        </div>
                    </div>
                    <Button v-if="props.thread.is_locked" variant="secondary" class="cursor-pointer text-yellow-500" disabled>
                        <Lock class="h-8 w-8" />
                        Locked
                    </Button>
                    <a href="#post_reply">
                        <Button variant="secondary" class="cursor-pointer" :disabled="!props.thread.permissions.canReply">
                            Post Reply
                        </Button>
                    </a>
                    <Button
                        v-if="authUser"
                        :variant="props.thread.is_subscribed ? 'default' : 'outline'"
                        class="cursor-pointer"
                        :disabled="threadActionLoading"
                        @click="props.thread.is_subscribed ? unsubscribeFromThread() : subscribeToThread()"
                    >
                        <component :is="props.thread.is_subscribed ? BellOff : Bell" class="mr-2 h-4 w-4" />
                        {{ props.thread.is_subscribed ? 'Following' : 'Follow thread' }}
                    </Button>
                    <Button
                        v-if="authUser"
                        variant="outline"
                        class="cursor-pointer"
                        @click="forumProfileDialogOpen = true"
                    >
                        Edit forum profile
                    </Button>
                    <DropdownMenu
                        v-if="
                            threadPermissions.canReport ||
                            threadPermissions.canModerate ||
                            threadPermissions.canEdit
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="icon">
                                <Ellipsis class="h-8 w-8" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup v-if="threadPermissions.canReport">
                                <DropdownMenuItem
                                    class="text-orange-500"
                                    :disabled="threadActionLoading || threadReportForm.processing || !hasReportReasons"
                                    @select="openThreadReportDialog"
                                >
                                    <Flag class="h-8 w-8" />
                                    <span>Report</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuGroup v-if="threadPermissions.canEdit">
                                <DropdownMenuItem
                                    class="text-blue-500"
                                    :disabled="threadActionLoading"
                                    @select="renameThread"
                                >
                                    <Pencil class="h-8 w-8" />
                                    <span>Edit Title</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <template v-if="threadPermissions.canModerate">
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>Mod Actions</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_published"
                                        :disabled="threadActionLoading"
                                        @select="publishThread"
                                    >
                                        <Eye class="h-8 w-8" />
                                        <span>Publish</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_published"
                                        :disabled="threadActionLoading"
                                        @select="unpublishThread"
                                    >
                                        <EyeOff class="h-8 w-8" />
                                        <span>Unpublish</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_locked"
                                        :disabled="threadActionLoading"
                                        @select="lockThread"
                                    >
                                        <Lock class="h-8 w-8" />
                                        <span>Lock</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_locked"
                                        :disabled="threadActionLoading"
                                        @select="unlockThread"
                                    >
                                        <LockOpen class="h-8 w-8" />
                                        <span>Unlock</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_pinned"
                                        :disabled="threadActionLoading"
                                        @select="pinThread"
                                    >
                                        <Pin class="h-8 w-8" />
                                        <span>Pin</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_pinned"
                                        :disabled="threadActionLoading"
                                        @select="unpinThread"
                                    >
                                        <PinOff class="h-8 w-8" />
                                        <span>Unpin</span>
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                            </template>
                            <DropdownMenuSeparator v-if="threadPermissions.canModerate" />
                            <DropdownMenuItem
                                v-if="threadPermissions.canModerate"
                                class="text-red-500"
                                :disabled="threadActionLoading"
                                @select="deleteThread"
                            >
                                <Trash2 class="h-8 w-8" />
                                <span>Delete</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>
            <Alert
                v-if="liveReplyNotice"
                class="flex flex-col gap-2 border border-primary/40 bg-primary/10 text-foreground"
            >
                <AlertTitle>New reply from {{ liveReplyAuthorName }}</AlertTitle>
                <AlertDescription class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p v-if="liveReplyExcerpt" class="text-sm text-muted-foreground md:flex-1">
                        {{ liveReplyExcerpt }}
                    </p>
                    <div class="flex items-center gap-2 md:justify-end">
                        <Button size="sm" :disabled="!liveReplyUrl" @click="viewLiveReply">
                            View reply
                        </Button>
                        <Button size="sm" variant="outline" @click="dismissLiveReplyNotice">
                            Dismiss
                        </Button>
                    </div>
                </AlertDescription>
            </Alert>
            <!-- Top Pagination and Search -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ postsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(postsMeta.per_page, 1)"
                    :total="postsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items">
                                <PaginationListItem v-if="item.type === 'page'" :key="index" :value="item.value" as-child>
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :key="item.type" :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </div>
                </Pagination>
            </div>

            <!-- Posts List -->
            <div class="space-y-6">
                <div
                    v-for="post in props.posts.data"
                    :key="post.id"
                    :id="`post-${post.id}`"
                    class="flex flex-col md:flex-row gap-4 rounded-xl border p-4 shadow-sm"
                >
                    <!-- Left Side: User Info -->
                    <div class="flex-shrink-0 w-full md:w-1/5 border-r pr-4">
                        <Avatar class="mb-2 h-24 w-24 overflow-hidden rounded-full">
                            <AvatarImage
                                v-if="post.author.avatar_url"
                                :src="post.author.avatar_url"
                                :alt="post.author.nickname ?? 'Forum user'"
                            />
                            <AvatarFallback
                                class="flex h-full w-full items-center justify-center bg-muted text-xl font-semibold uppercase text-muted-foreground"
                            >
                                {{ getInitials(post.author.nickname ?? 'Member') }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="font-bold text-lg">{{ post.author.nickname ?? 'Unknown' }}</div>
                        <div class="text-sm text-gray-500">{{ post.author.primary_role ?? 'Member' }}</div>
                        <div class="mt-2 text-xs text-gray-600">
                            Joined: <span class="font-medium">{{ post.author.joined_at ?? '—' }}</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-600">
                            Posts: <span class="font-medium">{{ post.author.forum_posts_count }}</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-600">
                            Reputation: <span class="font-medium">{{ post.author.reputation_points }}</span>
                        </div>
                        <div
                            v-if="post.author.badges.length > 0"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <span
                                v-for="badge in post.author.badges"
                                :key="badge.id"
                                class="inline-flex items-center rounded-full border border-muted-foreground/20 bg-muted px-2 py-1 text-xs text-muted-foreground"
                                :title="badge.description ?? undefined"
                            >
                                {{ badge.name }}
                            </span>
                        </div>
                    </div>

                    <!-- Right Side: Post Content -->
                    <div class="flex-1">
                        <div class="flex justify-between items-center border-b pb-2 mb-4">
                            <div class="text-sm text-gray-500">{{ post.created_at }}</div>
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-medium text-gray-500">#{{ post.number }}</div>
                                <DropdownMenu
                                    v-if="
                                        threadPermissions.canReply ||
                                        post.permissions.canReport ||
                                        post.permissions.canEdit ||
                                        post.permissions.canDelete
                                    "
                                >
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <Ellipsis class="h-5 w-5" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent>
                                        <DropdownMenuLabel>Post Actions</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuGroup v-if="threadPermissions.canReply">
                                            <DropdownMenuItem
                                                class="text-green-500"
                                                :disabled="!threadPermissions.canReply"
                                                @select="quotePost(post)"
                                            >
                                                <Quote class="h-4 w-4" />
                                                <span>Quote</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator
                                            v-if="
                                                threadPermissions.canReply &&
                                                (post.permissions.canReport ||
                                                    post.permissions.canEdit ||
                                                    post.permissions.canDelete)
                                            "
                                        />
                                        <DropdownMenuGroup v-if="post.permissions.canReport">
                                            <DropdownMenuItem
                                                class="text-orange-500"
                                                :disabled="activePostActionId === post.id || postReportForm.processing || !hasReportReasons"
                                                @select="openPostReportDialog(post)"
                                            >
                                                <Flag class="h-4 w-4" />
                                                <span>Report</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuGroup v-if="post.permissions.canEdit">
                                            <DropdownMenuItem
                                                class="text-blue-500"
                                                :disabled="activePostActionId === post.id"
                                                @select="editPost(post)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                <span>Edit</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator
                                            v-if="
                                                post.permissions.canDelete &&
                                                (post.permissions.canReport || post.permissions.canEdit)
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="post.permissions.canDelete"
                                            class="text-red-500"
                                            :disabled="activePostActionId === post.id"
                                            @select="deletePost(post)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            <span>Delete</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                post.permissions.canModerate &&
                                                (threadPermissions.canReply ||
                                                    post.permissions.canReport ||
                                                    post.permissions.canEdit ||
                                                    post.permissions.canDelete)
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="post.permissions.canModerate"
                                            class="text-purple-500"
                                            @select="viewPostHistory(post)"
                                        >
                                            <RotateCcw class="h-4 w-4" />
                                            <span>View Post History</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                        <!-- Post Body -->
                        <div class="tiptap ProseMirror prose prose-sm dark:prose-invert max-w-none" v-html="renderPostBody(post)"></div>
                        <!-- Forum Signature -->
                        <div
                            v-if="post.author.forum_signature"
                            class="mt-4 border-t pt-2 text-xs text-gray-500 whitespace-pre-line"
                        >
                            {{ post.author.forum_signature }}
                        </div>
                    </div>
                </div>
            </div>

            <header class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ postsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(postsMeta.per_page, 1)"
                    :total="postsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items">
                                <PaginationListItem v-if="item.type === 'page'" :key="index" :value="item.value" as-child>
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :key="item.type" :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </div>
                </Pagination>
                <div class="flex w-full max-w-md justify-end">
                    <a href="#thread_title">
                        <Button variant="secondary" class="cursor-pointer">
                            Go To Top
                        </Button>
                    </a>
                </div>
            </header>

            <Alert v-if="props.thread.is_locked" variant="warning">
                <MessageSquareLock class="w-6 h-6" />
                <AlertTitle>Thread Locked</AlertTitle>
                <AlertDescription>
                    This thread has been locked by a moderator.
                </AlertDescription>
            </Alert>

            <Alert v-if="!props.thread.is_published" variant="default">
                <AlertTitle>Thread Not Published</AlertTitle>
                <AlertDescription>
                    Replies are disabled until this discussion has been published.
                </AlertDescription>
            </Alert>

            <!-- Reply Input Section -->
            <div v-if="showReplyForm" class="mt-8 rounded-xl border p-6 shadow">
                <h2 id="post_reply" class="mb-4 text-xl font-bold">Leave a Reply</h2>
                <form class="flex flex-col gap-4" @submit.prevent="submitReply">
                    <RichTextEditor
                        id="thread_reply_body"
                        v-model="replyForm.body"
                        :placeholder="'Write your reply here...'"
                        :storage-key="replyDraftStorageKey"
                    />
                    <p v-if="replyForm.errors.body" class="text-sm text-destructive">
                        {{ replyForm.errors.body }}
                    </p>

                    <Button
                        type="submit"
                        variant="secondary"
                        class="cursor-pointer bg-green-500 hover:bg-green-600"
                        :disabled="replySubmitDisabled"
                    >
                        Submit Reply
                    </Button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
