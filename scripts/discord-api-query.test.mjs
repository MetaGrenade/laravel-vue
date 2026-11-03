import assert from 'node:assert/strict';
import test from 'node:test';

/**
 * Mirrors listSyncFailures query building in discord-api.ts (companion API uses unresolved=1, not resolved=0|1).
 */
function syncFailuresQueryString(options = {}) {
    const params = new URLSearchParams();
    params.set('per_page', String(options.perPage ?? 15));

    if (options.page !== undefined && options.page > 0) {
        params.set('page', String(options.page));
    }

    if (options.unresolved === true) {
        params.set('unresolved', '1');
    }

    return params.toString();
}

test('sync failures unresolved filter uses unresolved=1', () => {
    assert.equal(syncFailuresQueryString({ unresolved: true }), 'per_page=15&unresolved=1');
});

test('sync failures all view omits unresolved filter', () => {
    const query = syncFailuresQueryString({ page: 2, perPage: 20 });
    assert.equal(query, 'per_page=20&page=2');
    assert.doesNotMatch(query, /resolved/);
});
