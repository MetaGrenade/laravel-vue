// Builds the image and starts compose.production.yaml the way docs/docker.md says, on a database made for the run, then checks that the site
// works and that the things the Dockerfile and the Caddyfile promise are true:
//
//     node scripts/docker-smoke.mjs              # build, run, check, take it all down again
//     node scripts/docker-smoke.mjs --no-build   # use the image that is already built
//     node scripts/docker-smoke.mjs --keep       # leave the containers running to look at (the address is printed)
//
// It needs Docker with Compose, Node 22 or newer, and the ports DOCKER_SMOKE_HTTP_PORT (8080) and DOCKER_SMOKE_HTTPS_PORT (8443). It never
// reads your .env: its settings are .env.example plus passwords it makes up, in a folder of its own. It is what the `docker` job in CI runs.

import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { appendFileSync, copyFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const flags = new Set(process.argv.slice(2));
const httpPort = process.env.DOCKER_SMOKE_HTTP_PORT ?? '8080';
const httpsPort = process.env.DOCKER_SMOKE_HTTPS_PORT ?? '8443';
const project = process.env.DOCKER_SMOKE_PROJECT ?? 'metaforger-smoke';
const site = `http://127.0.0.1:${httpPort}`;

const adminEmail = 'owner@example.test';
const adminPassword = `Ci-${randomBytes(16).toString('hex')}-Aa1`;

const folder = mkdtempSync(join(tmpdir(), 'metaforger-smoke-'));
const envFile = join(folder, '.env');

/** A step that did not give the answer it should: the run ends with the state of the containers. */
class Failure extends Error {}

function run(command, commandArgs, { capture = false, allowFailure = false } = {}) {
    const result = spawnSync(command, commandArgs, {
        cwd: root,
        encoding: 'utf8',
        stdio: capture ? ['ignore', 'pipe', 'pipe'] : ['ignore', 'inherit', 'inherit'],
        // The compose file reads its settings from the file this variable names.
        env: { ...process.env, ENV_FILE: envFile },
    });

    if (result.error) throw new Failure(`could not run ${command}: ${result.error.message}`);
    if (result.status !== 0 && !allowFailure) throw new Failure(`${command} ${commandArgs.slice(0, 6).join(' ')} ended with ${result.status}`);

    return result;
}

function compose(composeArgs, options) {
    return run('docker', ['compose', '-p', project, '-f', 'compose.production.yaml', '--env-file', envFile, ...composeArgs], options);
}

function ok(label, detail = '') {
    console.log(`ok   ${label}${detail ? `: ${detail}` : ''}`);
}

function expect(label, condition, detail = '') {
    if (!condition) throw new Failure(`${label}${detail ? ` (${detail})` : ''}`);

    ok(label, detail);
}

async function get(path, headers = {}) {
    const response = await fetch(site + path, { redirect: 'manual', headers });
    const body = await response.text();

    return { status: response.status, headers: response.headers, body };
}

const sleep = (seconds) => new Promise((resolve) => setTimeout(resolve, seconds * 1000));

/** The settings of a site: the example file, then what this run needs, which wins because it comes last. */
function writeSettings() {
    copyFileSync(join(root, '.env.example'), envFile);
    appendFileSync(
        envFile,
        [
            '',
            'APP_NAME="Docker Smoke"',
            `APP_URL=${site}`,
            `APP_KEY=base64:${randomBytes(32).toString('base64')}`,
            `DB_PASSWORD=${randomBytes(16).toString('hex')}`,
            `DB_ROOT_PASSWORD=${randomBytes(16).toString('hex')}`,
            'MAIL_MAILER=log',
            `APP_HTTP_PORT=${httpPort}`,
            `APP_HTTPS_PORT=${httpsPort}`,
            '',
        ].join('\n'),
    );
}

/**
 * Start everything and wait until the site answers. `up --wait` cannot be given the worker or the scheduler: it stops with "has no
 * healthcheck configured" for a service that has none, so it is given the web server, which has one and waits for MySQL and Redis too.
 */
function startSite() {
    compose(['up', '-d']);
    compose(['up', '-d', '--wait', '--wait-timeout', '180', 'app']);
}

function signIn() {
    run('node', ['scripts/check-install.mjs', site, adminEmail, adminPassword]);
}

async function main() {
    expect('Docker with Compose is there', run('docker', ['compose', 'version'], { capture: true, allowFailure: true }).status === 0);

    writeSettings();

    if (!flags.has('--no-build')) compose(['build']);

    // The order docs/docker.md gives: the database and Redis, the installer on its own, then everything.
    compose(['up', '-d', '--wait', '--wait-timeout', '180', 'mysql', 'redis']);
    compose([
        'run',
        '--rm',
        'app',
        'php',
        'artisan',
        'metaforger:install',
        '--force',
        '--no-interaction',
        '--app-name=Docker Smoke',
        `--app-url=${site}`,
        '--admin-nickname=owner',
        `--admin-email=${adminEmail}`,
        `--admin-password=${adminPassword}`,
    ]);
    startSite();

    // The site, as the installer check sees it: the pages open, the administrator signs in, the admin area and Settings → Profile open.
    signIn();

    const running = compose(['ps', '--status', 'running', '--services'], { capture: true }).stdout.split(/\s+/).filter(Boolean).sort();
    expect(
        'the web server, the worker, the scheduler, MySQL and Redis are all running',
        running.join() === 'app,mysql,redis,scheduler,worker',
        running.join(' '),
    );

    // What the Caddyfile promises.
    expect('a file that starts with a dot is not served', (await get('/.env')).status === 404);

    compose(['exec', '-T', 'app', 'sh', '-c', 'printf "<?php echo 6*7;" > /app/storage/app/public/probe.php']);
    const probe = await get('/storage/probe.php');
    expect('a PHP file in the public storage folder is not run', probe.status === 404 && !probe.body.includes('42'), `answered ${probe.status}`);

    const asset = (await get('/login')).body.match(/\/build\/assets\/[\w.-]+\.js/)?.[0];
    expect('the login page points at a built script', asset !== undefined);
    const script = await get(asset);
    expect(
        'a built file is kept for a year',
        script.status === 200 && (script.headers.get('cache-control') ?? '').includes('immutable'),
        script.headers.get('cache-control') ?? 'no Cache-Control',
    );

    // What the queue promises: the web container puts the email on the queue, the worker container sends it.
    compose(
        [
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            `App\\Models\\User::where('email', '${adminEmail}')->firstOrFail()->notify(new App\\Notifications\\ApiKeyCreated('smoke key', 'settings', [], false, null, null));`,
        ],
        { capture: true },
    );

    let sent = false;
    for (let waited = 0; waited < 40 && !sent; waited += 2) {
        sent = /ApiKeyCreated\s.*DONE/.test(compose(['logs', '--no-color', 'worker'], { capture: true }).stdout);
        if (!sent) await sleep(2);
    }
    expect('the worker sends an email that the web container queued', sent);

    const failed = compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:failed'], { capture: true }).stdout;
    expect('no job has failed', /No failed jobs/i.test(failed), failed.trim().split('\n').pop());

    // What the compose file promises about stopping and starting: a stop is quick (it is not left to the kill timeout), and nothing is lost.
    const stopped = Date.now();
    compose(['stop', 'app', 'worker', 'scheduler']);
    const seconds = Math.round((Date.now() - stopped) / 1000);
    expect('the web server, the worker and the scheduler stop on the first signal', seconds < 45, `${seconds} s`);

    startSite();
    signIn();
    ok('after a stop and a start the administrator can still sign in');
}

let failure = null;

try {
    await main();
    console.log('The image builds, the compose file runs it, and the site works.');
} catch (error) {
    failure = error;
    console.error(`FAIL ${error.message}`);

    // What the containers say, which is where the reason is.
    compose(['ps', '--all'], { allowFailure: true });
    compose(['logs', '--no-color', '--tail', '60'], { allowFailure: true });
} finally {
    if (flags.has('--keep') && failure === null) {
        console.log(`Left running at ${site}. Sign in as ${adminEmail} with ${adminPassword}; the settings are in ${envFile}.`);
        console.log(
            `Take it down with: ENV_FILE="${envFile}" docker compose -p ${project} -f compose.production.yaml --env-file "${envFile}" down -v`,
        );
    } else {
        compose(['down', '--volumes', '--remove-orphans'], { allowFailure: true, capture: true });
        rmSync(folder, { recursive: true, force: true });
    }
}

process.exit(failure === null ? 0 : 1);
