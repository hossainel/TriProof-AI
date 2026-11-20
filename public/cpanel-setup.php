<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
$envPath = $root.'/.env';
$envExamplePath = $root.'/.env.example';

$steps = [];
$warnings = [];
$migrateOutput = '';
$seedOutput = '';
$publicKey = '';
$adminEmail = '';
$generatedAdminPassword = '';
$selfDeleted = false;
$errorMessage = '';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function envSet(string $contents, string $key, string $value): string
{
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $contents) === 1) {
        return (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
    }

    return rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
}

function envGet(string $contents, string $key): string
{
    if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
        return '';
    }

    return trim(trim($matches[1]), '"\'');
}

function randomPassword(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

try {
    if (PHP_VERSION_ID < 80200) {
        throw new RuntimeException('PHP 8.2 or newer is required. Select PHP 8.4/8.5 in cPanel MultiPHP Manager.');
    }

    foreach (['pdo_mysql', 'gd', 'mbstring', 'openssl'] as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException("Required PHP extension missing: {$extension}. Enable it in cPanel Select PHP Extensions.");
        }
    }

    if (! is_file($envPath)) {
        if (! is_file($envExamplePath)) {
            throw new RuntimeException('.env is missing. Upload .env or copy .env.example to .env first.');
        }

        if (! @copy($envExamplePath, $envPath)) {
            throw new RuntimeException('Could not create .env from .env.example. Check file permissions.');
        }

        @chmod($envPath, 0600);
    }

    if (! is_writable($envPath)) {
        throw new RuntimeException('.env is not writable. Set permissions to 0600 (or 0644) for your cPanel user.');
    }

    $requiredDirs = [
        'storage',
        'storage/app',
        'storage/app/public',
        'storage/framework',
        'storage/framework/cache',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];

    foreach ($requiredDirs as $directory) {
        $path = $root.'/'.$directory;
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create directory: {$directory}. Check ownership/permissions.");
        }

        if (! is_writable($path)) {
            throw new RuntimeException("Directory is not writable: {$directory}. Set permissions to 0775 for your cPanel user.");
        }
    }

    $autoload = $root.'/vendor/autoload.php';
    if (! is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php is missing. Upload vendor/ or run composer install.');
    }

    require $autoload;

    if (! function_exists('sodium_crypto_sign_keypair') || ! function_exists('sodium_crypto_sign_detached')) {
        throw new RuntimeException('Ed25519 functions unavailable. Ensure paragonie/sodium_compat is installed in vendor/.');
    }

    $steps[] = 'PHP runtime, extensions, permissions, and Ed25519 support verified.';

    foreach (glob($root.'/bootstrap/cache/*.php') ?: [] as $cacheFile) {
        if (! @unlink($cacheFile)) {
            throw new RuntimeException('Could not delete stale cache file: bootstrap/cache/'.basename($cacheFile));
        }
    }

    $steps[] = 'Stale bootstrap/cache PHP files removed.';

    $appKey = 'base64:'.base64_encode(random_bytes(32));
    $jwtSecret = bin2hex(random_bytes(32));

    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $rawPublicKey = sodium_crypto_sign_publickey($keypair);
    $publicKey = base64_encode($rawPublicKey);

    if (! hash_equals($rawPublicKey, sodium_crypto_sign_publickey_from_secretkey($secretKey))) {
        throw new RuntimeException('Generated Ed25519 keypair failed self-verification.');
    }

    $privateKey = base64_encode($secretKey);
    $steps[] = 'New APP_KEY, JWT_SECRET, and Ed25519 keypair generated.';

    $contents = (string) file_get_contents($envPath);

    $contents = envSet($contents, 'APP_ENV', 'production');
    $contents = envSet($contents, 'APP_DEBUG', 'false');
    $contents = envSet($contents, 'APP_URL', 'https://t.focusautomobiles.com');
    $contents = envSet($contents, 'APP_KEY', $appKey);
    $contents = envSet($contents, 'SESSION_DOMAIN', 't.focusautomobiles.com');
    $contents = envSet($contents, 'SESSION_SECURE_COOKIE', 'true');
    $contents = envSet($contents, 'CORS_ALLOWED_ORIGINS', 'https://t.focusautomobiles.com');
    $contents = envSet($contents, 'TRIPROOF_DEMO_SEED', 'false');
    $contents = envSet($contents, 'TRIPROOF_SHOW_DEMO_HINTS', 'false');
    $contents = envSet($contents, 'HQ_PRIVATE_SIGNING_KEY', $privateKey);
    $contents = envSet($contents, 'UNIVERSAL_PUBLIC_VERIFICATION_KEY', $publicKey);
    $contents = envSet($contents, 'JWT_SECRET', $jwtSecret);

    if (envGet($contents, 'ADMIN_EMAIL') === '') {
        $contents = envSet($contents, 'ADMIN_EMAIL', 'admin@t.focusautomobiles.com');
    }

    if (strlen(envGet($contents, 'ADMIN_PASSWORD')) < 12) {
        $generatedAdminPassword = randomPassword();
        $contents = envSet($contents, 'ADMIN_PASSWORD', $generatedAdminPassword);
    }

    $adminEmail = envGet($contents, 'ADMIN_EMAIL');

    $temporaryEnv = $envPath.'.tmp';
    if (file_put_contents($temporaryEnv, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write .env. Check disk space and permissions.');
    }

    @chmod($temporaryEnv, 0600);

    if (! @rename($temporaryEnv, $envPath)) {
        @unlink($temporaryEnv);

        throw new RuntimeException('Could not replace .env atomically. Check file permissions.');
    }

    @chmod($envPath, 0600);
    $steps[] = 'Production values written to .env (permissions 0600).';

    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(ConsoleKernel::class);
    $kernel->bootstrap();

    $migrateCode = Artisan::call('migrate', ['--force' => true]);
    $migrateOutput = trim((string) Artisan::output());

    if ($migrateCode !== 0) {
        throw new RuntimeException('Database migrations failed. Review the output below and check DB_* credentials.');
    }

    $steps[] = 'Database migrations completed.';

    $seedCode = Artisan::call('db:seed', ['--force' => true]);
    $seedOutput = trim((string) Artisan::output());

    if ($seedCode !== 0) {
        throw new RuntimeException('Database seeding failed. Review the output below.');
    }

    $steps[] = 'Database seeders completed.';

    $selfDeleted = @unlink(__FILE__);

    if (! $selfDeleted) {
        $warnings[] = 'Setup succeeded, but this file could not delete itself. Delete public/cpanel-setup.php manually immediately.';
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>TriProof AI — cPanel Setup</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; padding: 2rem 1rem; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif; background: #0f172a; color: #e2e8f0; }
  .card { max-width: 860px; margin: 0 auto; background: #111c33; border: 1px solid #24344f; border-radius: 14px; padding: 2rem; }
  h1 { margin-top: 0; font-size: 1.5rem; }
  h2 { font-size: 1.05rem; margin-top: 2rem; }
  .ok { color: #4ade80; }
  .bad { color: #f87171; }
  .warn { color: #fbbf24; }
  ul { line-height: 1.7; }
  code, pre, textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  code { background: #0b1424; padding: .15rem .35rem; border-radius: 6px; word-break: break-all; }
  textarea { width: 100%; box-sizing: border-box; min-height: 4.5rem; background: #0b1424; color: #e2e8f0; border: 1px solid #2e4262; border-radius: 10px; padding: .75rem; resize: vertical; }
  pre { background: #0b1424; border: 1px solid #2e4262; border-radius: 10px; padding: .85rem; overflow: auto; white-space: pre-wrap; word-break: break-word; }
  .callout { border-radius: 10px; padding: 1rem 1.25rem; margin: 1.25rem 0; }
  .callout.error { background: #3b1219; border: 1px solid #7f1d1d; }
  .callout.success { background: #052e1c; border: 1px solid #166534; }
  a { color: #7dd3fc; }
  .muted { color: #94a3b8; }
</style>
</head>
<body>
<div class="card">
  <h1>TriProof AI — cPanel Production Setup</h1>

<?php if ($errorMessage !== '') { ?>
  <div class="callout error">
    <strong class="bad">Setup failed.</strong>
    <p><?= h($errorMessage) ?></p>
    <p class="muted">Fix the issue, then reload this page. The setup script did not delete itself.</p>
  </div>

  <?php if ($steps !== []) { ?>
    <h2>Completed before the failure</h2>
    <ul><?php foreach ($steps as $step) { ?><li class="ok"><?= h($step) ?></li><?php } ?></ul>
  <?php } ?>

  <?php if ($migrateOutput !== '') { ?>
    <h2>Migration output</h2>
    <pre><?= h($migrateOutput) ?></pre>
  <?php } ?>

  <?php if ($seedOutput !== '') { ?>
    <h2>Seeder output</h2>
    <pre><?= h($seedOutput) ?></pre>
  <?php } ?>

  <h2>Checklist</h2>
  <ul>
    <li>PHP version is 8.2+ with <code>pdo_mysql</code>, <code>gd</code>, <code>mbstring</code>, <code>openssl</code> enabled.</li>
    <li><code>.env</code> contains valid <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_DATABASE</code>, <code>DB_USERNAME</code>, <code>DB_PASSWORD</code>.</li>
    <li>The cPanel user owns <code>storage/</code>, <code>bootstrap/cache/</code>, and <code>.env</code> (no root-owned files).</li>
    <li>The database exists and the cPanel user has ALL PRIVILEGES on it.</li>
  </ul>
<?php } else { ?>
  <div class="callout success">
    <strong class="ok">Setup completed successfully.</strong>
    <p>
      <?= $selfDeleted
          ? 'The setup script deleted itself. No further web setup is possible.'
          : 'Setup finished, but the script could not delete itself. Delete <code>public/cpanel-setup.php</code> now.' ?>
    </p>
  </div>

  <h2>Universal public verification key (embed in the mobile app)</h2>
  <textarea readonly onclick="this.select()"><?= h($publicKey) ?></textarea>
  <p class="muted">Build with: <code>--dart-define=UNIVERSAL_PUBLIC_KEY=&lt;key&gt;</code>. The private key stays only in <code>.env</code>.</p>

  <h2>Administrator</h2>
  <p>Email: <code><?= h($adminEmail) ?></code></p>
  <?php if ($generatedAdminPassword !== '') { ?>
    <p>Generated password: <code><?= h($generatedAdminPassword) ?></code></p>
    <p class="muted">Store this password now. It is shown only on this page (also written to <code>.env</code> as <code>ADMIN_PASSWORD</code>).</p>
  <?php } else { ?>
    <p class="muted">Existing ADMIN_PASSWORD from <code>.env</code> was kept.</p>
  <?php } ?>

  <h2>Completed steps</h2>
  <ul><?php foreach ($steps as $step) { ?><li class="ok"><?= h($step) ?></li><?php } ?></ul>

  <?php if ($warnings !== []) { ?>
    <h2>Warnings</h2>
    <ul><?php foreach ($warnings as $warning) { ?><li class="warn"><?= h($warning) ?></li><?php } ?></ul>
  <?php } ?>

  <h2>Open the application</h2>
  <p><a href="https://t.focusautomobiles.com">https://t.focusautomobiles.com</a></p>
<?php } ?>
</div>
</body>
</html>
