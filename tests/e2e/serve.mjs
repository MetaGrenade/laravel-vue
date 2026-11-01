import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Starts the application for a browser suite (docs/e2e-tests.md): a database of its own, built from a seeder, and the built-in PHP server.
// The Playwright configs start this through tests/e2e/server.ts and say which database, seeder and port to use.

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = process.env.PHP_BIN ?? 'php';
const port = process.env.E2E_PORT ?? '8000';
const databaseName = process.env.E2E_DATABASE ?? '';
const seeder = process.env.E2E_SEEDER ?? '';

function refuse(message) {
    console.error(message);
    process.exit(1);
}

// The database is rebuilt from nothing on every start, so it can only be a file made for this: never the one in .env.
if (!/^[a-z0-9-]+\.sqlite$/.test(databaseName) || !/^[A-Za-z]+Seeder$/.test(seeder)) {
    refuse('E2E_DATABASE must be the name of a .sqlite file in database/ and E2E_SEEDER the name of a seeder.');
}

// While Vite's dev server runs, the pages ask it for their scripts, which a test browser cannot use.
if (fs.existsSync(path.join(root, 'public/hot'))) {
    refuse('public/hot exists, so the pages would load from a Vite dev server. Stop it, or remove public/hot, and run again.');
}

if (!fs.existsSync(path.join(root, 'public/build/manifest.json'))) {
    refuse('There is no build in public/build. Run `npm run build` first.');
}

const database = path.join(root, 'database', databaseName);

// Everything the application would otherwise take from .env that reaches outside this machine, or this database, is set here.
const env = {
    ...process.env,
    APP_URL: `http://127.0.0.1:${port}`,
    APP_FORCE_HTTPS: 'false',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    DB_URL: '',
    // The server is http://127.0.0.1: a session cookie pinned to the site's own domain (SESSION_DOMAIN in .env) is never stored for that
    // host, so a cart added in one request would be missing from the next.
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'database',
    MAIL_MAILER: 'array',
    BROADCAST_CONNECTION: 'log',
    BROADCAST_FORCE_LOG: 'true',
    PUSHER_APP_ID: '',
    PUSHER_APP_KEY: '',
    PUSHER_APP_SECRET: '',
    STRIPE_KEY: '',
    STRIPE_SECRET: '',
    STRIPE_WEBHOOK_SECRET: '',
    SEARCH_DRIVER: 'database',
};

for (const suffix of ['', '-wal', '-shm']) {
    fs.rmSync(database + suffix, { force: true });
}

fs.closeSync(fs.openSync(database, 'a'));

execFileSync(php, ['artisan', 'migrate:fresh', '--force', `--seeder=${seeder}`], { cwd: root, stdio: 'inherit', env });

// artisan serve keeps the database settings from reaching the pages, so the built-in server is started directly and inherits this process.
// It writes a line to the error output for every request, which is a file here, with whatever the application reports.
const server = path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
fs.mkdirSync(path.join(root, 'test-results'), { recursive: true });
const log = fs.openSync(path.join(root, 'test-results', `server-${databaseName.replace(/\.sqlite$/, '')}.log`), 'w');
const child = spawn(php, ['-S', `127.0.0.1:${port}`, server], { cwd: path.join(root, 'public'), stdio: ['ignore', log, log], env });

child.on('exit', (code) => {
    process.exit(code ?? 1);
});
