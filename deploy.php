<?php
/**
 * GitHub push webhook → automatic deploy.
 *
 * Put this file at the repository root (it already deploys there with the rest of the site).
 * On every push to `main`, GitHub calls this URL and it updates the server's working tree to
 * match GitHub — so the live site auto-deploys without touching cPanel.
 *
 * It runs, inside its own folder (the repo / document root):
 *     git fetch origin main
 *     git reset --hard origin/main   (match the pushed commit; only TRACKED files)
 *     git clean -fd                  (remove untracked leftovers; KEEPS git-ignored files
 *                                     such as config.php, uploads/, backups/)
 *
 * SECURITY: the shared secret is read from `.deploy-secret`, placed ONE LEVEL ABOVE this repo
 * folder (so it is never in git). Every request must carry a matching GitHub HMAC signature.
 *
 * ── One-time setup ─────────────────────────────────────────────────────────
 * 1. cPanel File Manager: create a file ONE folder above this repo (e.g. in public_html)
 *    named  .deploy-secret  containing a long random string (your webhook secret).
 * 2. GitHub repo → Settings → Webhooks → Add webhook:
 *       Payload URL : https://YOUR-SITE/deploy.php   (the URL that maps to this repo folder)
 *       Content type: application/json
 *       Secret      : the same string you put in .deploy-secret
 *       Events      : Just the push event
 *    Save. GitHub sends a "ping" — this returns "pong". After that every push deploys.
 * 3. (Optional) Visit https://YOUR-SITE/deploy.php?log=1 with ?token=<secret> to read deploy.log.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$repoDir   = __DIR__;
$secretFile = $repoDir . '/../.deploy-secret';
$logFile    = $repoDir . '/../deploy.log';
$secret = is_file($secretFile) ? trim((string)file_get_contents($secretFile)) : '';

if ($secret === '') {
    http_response_code(500);
    exit("Deploy secret is not configured. Create a .deploy-secret file one folder above the repo.\n");
}

// Optional: read the deploy log in a browser with ?log=1&token=<secret>
if (isset($_GET['log'])) {
    if (!hash_equals($secret, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden.\n");
    }
    exit(is_file($logFile) ? (string)file_get_contents($logFile) : "No deployments yet.\n");
}

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!$sig || !hash_equals($expected, $sig)) {
    http_response_code(403);
    exit("Signature check failed.\n");
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    exit("pong\n");
}
if ($event !== 'push') {
    exit("Ignored event: " . $event . "\n");
}

$data = json_decode($payload, true);
$branch = isset($data['ref']) ? preg_replace('#^refs/heads/#', '', (string)$data['ref']) : '';
if ($branch !== 'main') {
    exit("Ignored branch: " . $branch . "\n");
}

if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
    http_response_code(500);
    exit("shell_exec() is disabled on this host, so git cannot be run from PHP.\n");
}
if (!is_dir($repoDir . '/.git')) {
    http_response_code(500);
    exit("No .git folder found here; deploy.php must sit at the repository root.\n");
}

$git = 'git -C ' . escapeshellarg($repoDir) . ' ';
$out = "=== " . date('c') . " deploy push → " . $branch . " ===\n";
$out .= '$ git fetch origin main' . "\n" . shell_exec($git . 'fetch origin main 2>&1') . "\n";
$out .= '$ git reset --hard origin/main' . "\n" . shell_exec($git . 'reset --hard origin/main 2>&1') . "\n";
$out .= '$ git clean -fd' . "\n" . shell_exec($git . 'clean -fd 2>&1') . "\n";
@file_put_contents($logFile, $out . "\n", FILE_APPEND);

echo "Deployed.\n\n" . $out;
