import { execFileSync, spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = process.env.PHP_BIN ?? 'php';
const database = process.env.DB_DATABASE ?? '';

// The audit server is http://127.0.0.1. A session cookie pinned to the
// site's own domain (SESSION_DOMAIN in .env) is never stored for that
// host, so a cart added in one request is missing from the next.
process.env.APP_URL ??= 'http://127.0.0.1:8000';
process.env.SESSION_DOMAIN = 'null';
process.env.SESSION_SECURE_COOKIE = 'false';

if (process.env.A11Y_PREPARE === '1') {
    const normalised = database.replaceAll('\\', '/');
    const allowed = normalised.endsWith('/database/a11y.sqlite') || normalised === 'database/a11y.sqlite';

    if (process.env.DB_CONNECTION !== 'sqlite' || !allowed) {
        console.error('Refusing to rebuild a database that is not database/a11y.sqlite.');
        process.exit(1);
    }

    execFileSync(php, ['artisan', 'migrate:fresh', '--force', '--seeder=AccessibilityFlowSeeder'], {
        cwd: root,
        stdio: 'inherit',
        env: process.env,
    });
}

// artisan serve keeps the database settings from reaching the pages, so the
// built-in server is started directly and inherits this process.
const server = path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
const child = spawn(php, ['-S', '127.0.0.1:8000', server], {
    cwd: path.join(root, 'public'),
    stdio: 'inherit',
    env: process.env,
});

child.on('exit', (code) => {
    process.exit(code ?? 1);
});
