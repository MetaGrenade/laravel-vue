// Signs in to a site that `php artisan metaforger:install` just set up, over HTTP, the way a browser would (docs/install.md):
//
//     node scripts/check-install.mjs http://127.0.0.1:8000 owner@example.test "the password"
//
// A list of tables says little about whether the site works. This shows that it answers, that the administrator the installer made can sign
// in, and that the administrator role came with the account: the admin area opens. It is what the `install` job in CI runs after the installer.

const [base, email, password] = process.argv.slice(2);

if (!base || !email || !password) {
    console.error('Usage: node scripts/check-install.mjs <address of the site> <administrator email> <administrator password>');
    process.exit(2);
}

const origin = base.replace(/\/+$/, '');
const jar = new Map();

/** Remember the cookies a response sets, so the next request carries them as a browser's would. */
function keepCookies(response) {
    for (const line of response.headers.getSetCookie()) {
        const [pair] = line.split(';');
        const at = pair.indexOf('=');

        jar.set(pair.slice(0, at).trim(), pair.slice(at + 1).trim());
    }
}

async function request(path, { method = 'GET', form } = {}) {
    const headers = { Cookie: [...jar].map(([name, value]) => `${name}=${value}`).join('; ') };

    if (form) {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        // Laravel hands the page a copy of the token in this cookie, URL-encoded, and expects it back in this header.
        headers['X-XSRF-TOKEN'] = decodeURIComponent(jar.get('XSRF-TOKEN') ?? '');
    }

    const response = await fetch(origin + path, { method, headers, body: form ? new URLSearchParams(form) : undefined, redirect: 'manual' });
    keepCookies(response);

    return response;
}

function expect(label, response, status, location) {
    const where = response.headers.get('location') ?? '';
    const ok = response.status === status && (location === undefined || where.endsWith(location));

    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}: ${response.status}${where ? ` -> ${where}` : ''}`);

    if (!ok) {
        console.error(`     expected ${status}${location ? ` -> ...${location}` : ''}`);
        process.exit(1);
    }
}

/** The server is started a moment before this runs, so it is given time to answer. */
async function waitForSite(seconds) {
    const until = Date.now() + seconds * 1000;
    let reason = 'no answer';

    while (Date.now() < until) {
        try {
            const response = await fetch(`${origin}/login`, { redirect: 'manual' });

            if (response.status === 200) return;

            reason = `/login answered ${response.status}`;
        } catch (error) {
            reason = error.cause?.code ?? error.message;
        }

        await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    console.error(`FAIL the site at ${origin} did not answer within ${seconds} seconds (${reason}).`);
    process.exit(1);
}

await waitForSite(60);

expect('the sign-in page opens', await request('/login'), 200);
expect('the administrator signs in', await request('/login', { method: 'POST', form: { email, password } }), 302, '/dashboard');
expect('the member area opens', await request('/dashboard'), 200);
expect('the admin area opens', await request('/acp/users'), 200);

console.log('The site is up and the administrator can sign in.');
