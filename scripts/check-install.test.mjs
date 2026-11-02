// The install job in CI runs `check-install.mjs` against a real site. These run it against a stand-in, so a change to the script itself is
// found in a minute and not in that job: `npm run bundle:test`.

import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import net from 'node:net';
import { after, describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./check-install.mjs', import.meta.url));
const servers = [];

/**
 * A site whose pages are `size` bytes long and end by closing the connection, as PHP's built-in server ends them. `signIn` is what
 * answers the sign-in form: a redirect to the dashboard, or the form again.
 */
async function standIn({ size, signIn = 'redirect' }) {
    const server = net.createServer((socket) => {
        socket.on('error', () => {});
        socket.on('data', (data) => {
            const line = data.toString().split('\r\n')[0];
            let head = 'HTTP/1.1 200 OK\r\n';

            if (line.startsWith('GET /login')) {
                head += 'Set-Cookie: XSRF-TOKEN=token; path=/\r\n';
            } else if (line.startsWith('POST /login') && signIn === 'redirect') {
                head = `HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:${server.address().port}/dashboard\r\n`;
            }

            socket.write(`${head}Content-Type: text/html\r\nConnection: close\r\n\r\n`);
            socket.end('x'.repeat(size));
        });
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    servers.push(server);

    return server.address().port;
}

/** Run the script against the stand-in. Not `spawnSync`: the stand-in lives in this process and has to keep answering. */
function run(port) {
    return new Promise((resolve) => {
        execFile(
            process.execPath,
            [script, `http://127.0.0.1:${port}`, 'owner@example.test', 'a password'],
            { timeout: 30000 },
            (error, stdout, stderr) => {
                resolve({ status: error ? (error.code ?? 1) : 0, stdout, stderr });
            },
        );
    });
}

after(() => servers.forEach((server) => server.close()));

describe('check-install', () => {
    // On Node 24 a body that is never read stops the HTTP client with `assert(!this.paused)` when the server then closes the connection.
    // It stopped the install job in CI once. Pages of this size did it every time; 64 kB and over did not.
    for (const size of [300, 16384, 40000]) {
        test(`signs in to a site whose pages of ${size} bytes end by closing the connection`, async () => {
            const result = await run(await standIn({ size }));

            assert.equal(result.status, 0, result.stdout + result.stderr);
            assert.match(result.stdout, /The site is up and the administrator can sign in\./);
        });
    }

    test('says which check failed, and stops, when signing in does not lead to the dashboard', async () => {
        const result = await run(await standIn({ size: 300, signIn: 'form again' }));

        assert.equal(result.status, 1);
        assert.match(result.stdout, /FAIL the administrator signs in: 200/);
        assert.doesNotMatch(result.stdout, /the member area opens/);
    });
});
