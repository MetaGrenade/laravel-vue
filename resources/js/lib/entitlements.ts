import type { PlanGiven } from '@/types/entitlements';

/** What a plan gives, in a few words: a feature by its name, a limit with how much ("Projects: 25"). */
export const describeGiven = (given: Pick<PlanGiven, 'label' | 'type' | 'value'>): string =>
    given.type === 'limit' && given.value !== null ? `${given.label}: ${given.value.toLocaleString()}` : given.label;
