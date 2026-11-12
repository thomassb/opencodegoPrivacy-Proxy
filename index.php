<?php
declare(strict_types=1);

/**
 * PMDiscos OpenCode Go proxy + lightweight dashboard.
 * PHP 8+ with cURL. No Composer dependencies.
 *
 * Put a .env file beside this script (or set environment variables).
 * Never commit .env or expose it via your web server.
 */

const UPSTREAM_URL = 'https://opencode.ai/inference/go/openai/v1/responses';
const DEFAULT_MODEL = 'glm-5.3-flash';
const LOG_FILE = __DIR__ . '/proxy-activity.jsonl';
const DEBUG_LOG_FILE = __DIR__ . '/proxy-debug.jsonl';
const MAX_LOG_LINES = 1000;

// Dev-only capture limits (override with PROXY_DEBUG_MAX_BYTES / PROXY_DEBUG_LOG_BYTES).
const DEFAULT_DEBUG_BODY_BYTES = 262144;   // 256 KiB per captured body
const DEFAULT_DEBUG_LOG_BYTES = 10485760;  // 10 MiB total debug log

/* ---------------------------- .env loader ---------------------------- */

function loadDotEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Accept optional "export KEY=value".
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        // Remove matching single/double quotes. Double-quoted values support
        // basic newline, carriage-return, tab and escaped quote sequences.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                if ($first === '"') {
                    $value = str_replace(
                        ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                        ["\n", "\r", "\t", '"', '\\'],
                        $value
                    );
                }
            } else {
                // Unquoted inline comments are allowed when preceded by whitespace.
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }
        }

        // Environment variables already set by the hosting environment win.
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

loadDotEnv(__DIR__ . '/.env');

/* ------------------------------ Helpers ------------------------------ */

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) ? trim($m[1]) : '';
}

function dashboardPassword(): string
{
    $explicit = envValue('DASHBOARD_PASSWORD');
    return $explicit !== '' ? $explicit : envValue('PROXY_CLIENT_KEY');
}

function dashboardUsername(): string
{
    return envValue('DASHBOARD_USERNAME', 'admin');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function verifyCsrf(): bool
{
    $provided = (string)($_POST['csrf'] ?? '');
    return $provided !== '' && isset($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $provided);
}

function ensureLogFile(): void
{
    if (!is_file(LOG_FILE)) {
        @touch(LOG_FILE);
        @chmod(LOG_FILE, 0600);
    }
}

function appendLog(array $entry): void
{
    ensureLogFile();
    $entry['time'] = gmdate('c');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod(LOG_FILE, 0600);
    }
}

function readLogs(): array
{
    if (!is_file(LOG_FILE) || !is_readable(LOG_FILE)) {
        return [];
    }
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_slice($lines, -MAX_LOG_LINES);
    $entries = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
        }
    }
    return $entries;
}

/* --------------------------- Debug logging --------------------------- */

function debugBool(string $key, bool $default = false): bool
{
    $raw = strtolower(trim(envValue($key)));
    if ($raw === '') {
        return $default;
    }
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function debugEnabled(): bool
{
    static $enabled = null;
    if ($enabled === null) {
        // PROXY_DEBUG is the switch; PROXY_DEBUG_LOG_BODIES also works for
        // backwards compatibility with older .env files.
        $enabled = debugBool('PROXY_DEBUG') || debugBool('PROXY_DEBUG_LOG_BODIES');
    }
    return $enabled;
}

function maskSecret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $length = strlen($value);
    if ($length <= 8) {
        return '*** (' . $length . ' chars)';
    }
    return substr($value, 0, 4) . '...' . substr($value, -4) . ' (' . $length . ' chars)';
}

function requestHeadersForLog(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string)$key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr((string)$key, 5)));
            $headers[$name] = (string)$value;
        }
    }
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $name) {
        if (isset($_SERVER[$serverKey])) {
            $headers[$name] = (string)$_SERVER[$serverKey];
        }
    }
    return $headers;
}

function headersForLog(array $headers): array
{
    $sensitive = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'api-key'];
    $masked = [];
    foreach ($headers as $name => $value) {
        $lower = strtolower((string)$name);
        $masked[$name] = in_array($lower, $sensitive, true)
            ? maskSecret((string)$value)
            : $value;
    }
    return $masked;
}

function debugBodyLimit(): int
{
    static $limit = null;
    if ($limit === null) {
        $limit = (int)envValue('PROXY_DEBUG_MAX_BYTES', (string)DEFAULT_DEBUG_BODY_BYTES);
        if ($limit < 1024) {
            $limit = DEFAULT_DEBUG_BODY_BYTES;
        }
    }
    return $limit;
}

function truncateForLog(string $data): array
{
    $limit = debugBodyLimit();
    $bytes = strlen($data);
    if ($bytes <= $limit) {
        return ['bytes' => $bytes, 'truncated' => false, 'data' => $data];
    }
    return ['bytes' => $bytes, 'truncated' => true, 'data' => substr($data, 0, $limit)];
}

function trimDebugLog(): void
{
    $max = (int)envValue('PROXY_DEBUG_LOG_BYTES', (string)DEFAULT_DEBUG_LOG_BYTES);
    if ($max < 65536) {
        $max = DEFAULT_DEBUG_LOG_BYTES;
    }

    $size = @filesize(DEBUG_LOG_FILE);
    if ($size === false || $size <= $max) {
        return;
    }

    $handle = @fopen(DEBUG_LOG_FILE, 'r');
    if ($handle === false) {
        return;
    }

    // Keep roughly the newest half, starting at a line boundary.
    @fseek($handle, max(0, $size - (int)($max / 2)));
    @fgets($handle);
    $tail = @stream_get_contents($handle);
    @fclose($handle);

    if (is_string($tail) && $tail !== '') {
        @file_put_contents(DEBUG_LOG_FILE, $tail, LOCK_EX);
        @chmod(DEBUG_LOG_FILE, 0600);
    }
}

function debugLog(array $entry): void
{
    if (!debugEnabled()) {
        return;
    }

    if (!is_file(DEBUG_LOG_FILE)) {
        @touch(DEBUG_LOG_FILE);
        @chmod(DEBUG_LOG_FILE, 0600);
    }

    $entry = ['time' => gmdate('c')] + $entry;
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }

    @file_put_contents(DEBUG_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    @chmod(DEBUG_LOG_FILE, 0600);
    trimDebugLog();
}

function readDebugLogs(int $limit = 25): array
{
    if (!debugEnabled() || !is_file(DEBUG_LOG_FILE) || !is_readable(DEBUG_LOG_FILE)) {
        return [];
    }

    $lines = @file(DEBUG_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $entries = [];
    foreach (array_reverse(array_slice($lines, -200)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
            if (count($entries) >= $limit) {
                break;
            }
        }
    }
    return $entries;
}

/**
 * Recursively drop parameters the upstream rejects.
 *
 * OpenCode Go can echo a trace_id on response items. Clients replay those
 * items verbatim on the next turn, and the upstream then rejects the whole
 * request with "invalid or unsupported parameter value trace_id".
 */
function scrubUnsupportedParams(array $node, array $denied, array &$removed): array
{
    $clean = [];
    foreach ($node as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), $denied, true)) {
            $removed[] = $key;
            continue;
        }
        $clean[$key] = is_array($value) ? scrubUnsupportedParams($value, $denied, $removed) : $value;
    }
    // Keep JSON lists encoded as arrays even if an element/key was removed.
    return array_is_list($node) ? array_values($clean) : $clean;
}

function deniedParams(): array
{
    static $denied = null;
    if ($denied === null) {
        $denied = [];
        foreach (explode(',', envValue('PROXY_STRIP_PARAMS', 'trace_id')) as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $denied[] = $name;
            }
        }
    }
    return $denied;
}

function dashboardStats(array $logs): array
{
    $total = count($logs);
    $success = 0;
    $errors = 0;
    $durations = [];

    foreach ($logs as $row) {
        $status = (int)($row['status'] ?? 0);
        if ($status >= 200 && $status < 400) {
            $success++;
        } elseif ($status >= 400 || $status === 0) {
            $errors++;
        }
        if (isset($row['duration_ms'])) {
            $durations[] = (int)$row['duration_ms'];
        }
    }

    return [
        'total' => $total,
        'success' => $success,
        'errors' => $errors,
        'avg_ms' => $durations ? (int)round(array_sum($durations) / count($durations)) : 0,
    ];
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('pmdiscos_proxy_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function renderPage(string $title, string $content): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title><style>
    :root{color-scheme:dark;--bg:#0b1020;--panel:#131b2e;--line:#27334b;--muted:#9baac4;--text:#eaf0fa;--accent:#7dd3fc}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
    .wrap{max-width:1120px;margin:0 auto;padding:28px 18px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}
    h1{font-size:24px;margin:0}h2{font-size:17px;margin:0 0 14px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.stat{font-size:28px;font-weight:700}.label{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    input{width:100%;padding:11px 12px;border-radius:8px;border:1px solid var(--line);background:#0b1222;color:var(--text);margin:6px 0 14px}
    button,.btn{display:inline-block;border:0;border-radius:8px;background:#2563eb;color:white;padding:10px 14px;font-weight:600;cursor:pointer;text-decoration:none}
    .secondary{background:#26334a}.danger{background:#9f1239}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:600}
    .table-wrap{overflow-x:auto}.pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#203047;color:#c9e7ff;font-size:12px}.ok{color:#86efac}.bad{color:#fda4af}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.login{max-width:420px;margin:9vh auto}.small{font-size:12px}
    @media(max-width:600px){.wrap{padding:18px 12px}.panel{padding:14px}th,td{padding:8px}}
    </style></head><body><main class="wrap">' . $content . '</main></body></html>';
    exit;
}

/* -------------------------- Dashboard routes -------------------------- */

startSecureSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Logout.
if ($method === 'POST' && isset($_POST['logout'])) {
    if (!verifyCsrf()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    $_SESSION = [];
    session_regenerate_id(true);
    renderPage('Logged out', '<section class="panel login"><h1>Signed out</h1><p class="muted">Your dashboard session has ended.</p><a class="btn" href="' . h($path) . '">Sign in</a></section>');
}

// Clear the dev debug log.
if ($method === 'POST' && isset($_POST['clear_debug'])) {
    if (empty($_SESSION['authenticated']) || !verifyCsrf()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    @unlink(DEBUG_LOG_FILE);
    header('Location: ' . $path, true, 303);
    exit;
}

// Login form submission.
if ($method === 'POST' && isset($_POST['login'])) {
    if (!verifyCsrf()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $expectedUser = dashboardUsername();
    $expectedPass = dashboardPassword();

    if ($expectedPass === '') {
        renderPage('Configuration required', '<section class="panel login"><h1>Dashboard not configured</h1><p>Set <code>DASHBOARD_PASSWORD</code> or <code>PROXY_CLIENT_KEY</code> in your .env file.</p></section>');
    }

    if (hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        unset($_SESSION['csrf']);
        header('Location: ' . $path, true, 303);
        exit;
    }

    appendLog(['type' => 'dashboard_login_failed', 'status' => 401]);
    renderPage('Login', '<section class="panel login"><h1>PMDiscos Proxy</h1><p class="bad">Invalid username or password.</p>' .
        loginForm() . '</section>');
}

function loginForm(): string
{
    return '<form method="post"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '">' .
        '<label>Username</label><input name="username" autocomplete="username" required>' .
        '<label>Password</label><input type="password" name="password" autocomplete="current-password" required>' .
        '<button name="login" value="1">Sign in</button></form>';
}

// GET dashboard: browser requests only. API requests are POST.
if ($method === 'GET' || $method === 'HEAD') {
    if (empty($_SESSION['authenticated'])) {
        renderPage('PMDiscos Proxy Login', '<section class="panel login"><h1>PMDiscos Proxy</h1><p class="muted">Sign in to view proxy status and activity.</p>' . loginForm() . '</section>');
    }

    $logs = readLogs();
    $stats = dashboardStats($logs);
    $model = envValue('OPENCODE_GO_MODEL', DEFAULT_MODEL);
    $clientConfigured = envValue('PROXY_CLIENT_KEY') !== '';
    $upstreamConfigured = envValue('OPENCODE_GO_API_KEY') !== '';
    $statusText = ($clientConfigured && $upstreamConfigured) ? 'Configured' : 'Needs configuration';
    $statusClass = ($clientConfigured && $upstreamConfigured) ? 'ok' : 'bad';

    $content = '<header class="top"><div><h1>PMDiscos Proxy</h1><div class="muted">OpenCode Go · Responses API</div></div>' .
        '<form method="post"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '">' .
        '<button class="secondary" name="logout" value="1">Log out</button></form></header>';

    $content .= '<section class="panel"><div class="grid">' .
        '<div><div class="label">Configuration</div><div class="' . $statusClass . '">' . h($statusText) . '</div></div>' .
        '<div><div class="label">Model</div><div>' . h($model) . '</div></div>' .
        '<div><div class="label">Logged requests</div><div class="stat">' . $stats['total'] . '</div></div>' .
        '<div><div class="label">Successful</div><div class="stat ok">' . $stats['success'] . '</div></div>' .
        '<div><div class="label">Errors</div><div class="stat bad">' . $stats['errors'] . '</div></div>' .
        '<div><div class="label">Avg. duration</div><div class="stat">' . $stats['avg_ms'] . '<span class="muted" style="font-size:13px"> ms</span></div></div>' .
        '</div></section>';

    $content .= '<section class="panel"><h2>Endpoint</h2><p><span class="pill">POST</span> <code>' . h(UPSTREAM_URL) . '</code></p>' .
        '<p class="muted small">The browser dashboard and VS Code proxy share this PHP URL. VS Code authenticates with a Bearer token; dashboard login uses a session cookie.</p></section>';

    $content .= '<section class="panel"><h2>Recent activity</h2><div class="table-wrap"><table><thead><tr><th>Time (UTC)</th><th>Type</th><th>Status</th><th>Model</th><th>Duration</th></th></tr></thead><tbody>';
    if (!$logs) {
        $content .= '<tr><td colspan="5" class="muted">No proxy requests logged yet.</td></tr>';
    } else {
        foreach (array_slice($logs, 0, 100) as $row) {
            $time = (string)($row['time'] ?? '');
            $type = (string)($row['type'] ?? 'request');
            $status = (int)($row['status'] ?? 0);
            $modelName = (string)($row['model'] ?? '—');
            $duration = isset($row['duration_ms']) ? (string)$row['duration_ms'] . ' ms' : '—';
            $class = ($status >= 200 && $status < 400) ? 'ok' : 'bad';
            $content .= '<tr><td>' . h($time) . '</td><td>' . h($type) . '</td><td class="' . $class . '">' . h((string)$status) . '</td><td>' . h($modelName) . '</td><td>' . h($duration) . '</td></tr>';
        }
    }
    $content .= '</tbody></table></div><p class="muted small">Only metadata is logged. Request prompts, response bodies, and API keys are not recorded.</p></section>';

    if (debugEnabled()) {
        $debugLogs = readDebugLogs(20);
        $content .= '<section class="panel"><div class="row" style="justify-content:space-between">' .
            '<h2>Debug log</h2>' .
            '<form method="post" onsubmit="return confirm(\'Delete the debug log?\')">' .
            '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">' .
            '<button class="danger" name="clear_debug" value="1">Clear log</button></form></div>' .
            '<p class="muted small"><code>PROXY_DEBUG</code> is enabled. Full client request headers/bodies and upstream request/response bodies are captured in <code>' .
            h(basename(DEBUG_LOG_FILE)) . '</code>. Authorization headers are masked. Bodies may contain user content, so disable this in production and never commit the file.</p>';

        if (!$debugLogs) {
            $content .= '<p class="muted">No debug entries yet. Send a request through the proxy.</p>';
        } else {
            foreach ($debugLogs as $row) {
                $time = (string)($row['time'] ?? '');
                $kind = (string)($row['kind'] ?? 'entry');
                $status = (int)($row['status'] ?? 0);
                $summary = $time . ' · ' . $kind . ($status > 0 ? ' · HTTP ' . $status : '');
                $pretty = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                if (strlen($pretty) > 12000) {
                    $pretty = substr($pretty, 0, 12000) . "\n... truncated for display ...";
                }
                $content .= '<details style="margin:8px 0"><summary>' . h($summary) . '</summary>' .
                    '<pre style="overflow:auto;max-height:420px;background:#0b1222;padding:12px;border-radius:8px;font-size:12px">' .
                    h($pretty) . '</pre></details>';
            }
        }
        $content .= '</section>';
    }

    $content .= '<p class="muted small">Log file: ' . h(basename(LOG_FILE)) . ' · up to ' . MAX_LOG_LINES . ' lines retained in the dashboard view.</p>';

    renderPage('PMDiscos Proxy Dashboard', $content);
}

/* ---------------------------- Proxy route ---------------------------- */

if ($method !== 'POST') {
    header('Allow: GET, POST, HEAD');
    jsonResponse(405, ['error' => ['message' => 'Only POST requests are supported for the API.']]);
}

$clientKey = envValue('PROXY_CLIENT_KEY');
$upstreamKey = envValue('OPENCODE_GO_API_KEY').'u';
$model = envValue('OPENCODE_GO_MODEL', DEFAULT_MODEL);


if ($clientKey === '' || $upstreamKey === '') {
    jsonResponse(500, ['error' => ['message' => 'Proxy not configured. Set PROXY_CLIENT_KEY and OPENCODE_GO_API_KEY in .env.']]);
}

$providedKey = bearerToken();
if ($providedKey === '' || !hash_equals($clientKey, $providedKey)) {
    appendLog(['type' => 'unauthorized', 'status' => 401]);
    jsonResponse(401, ['error' => ['message' => 'Invalid proxy API key.']]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    jsonResponse(400, ['error' => ['message' => 'Request body is empty.']]);
}
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    jsonResponse(400, ['error' => ['message' => 'Request body must be valid JSON.']]);
}

$clientHeaders = requestHeadersForLog();
$clientRequestId = '';
foreach (['x-client-request-id', 'x-request-id', 'request-id', 'x-correlation-id'] as $name) {
    if (!empty($clientHeaders[$name])) {
        $clientRequestId = (string)$clientHeaders[$name];
        break;
    }
}

// Capture exactly what the client sent, before the proxy rewrites anything.
debugLog([
    'kind' => 'client_request',
    'client_request_id' => $clientRequestId,
    'path' => $path,
    'headers' => headersForLog($clientHeaders),
    'body' => truncateForLog($rawBody),
]);

$body['model'] = $model;
// OpenCode Go requires stateless Responses API requests.
$body['store'] = false;

// Do not use server-side response chaining.
unset($body['previous_response_id']);

// Strip parameters the upstream rejects. Gateways sometimes echo fields such
// as trace_id on response items; clients replay those items on the next turn
// and the upstream fails with "invalid or unsupported parameter value".
$removedParams = [];
$deniedParams = deniedParams();
if ($deniedParams) {
    $body = scrubUnsupportedParams($body, $deniedParams, $removedParams);
}
if ($removedParams) {
    debugLog([
        'kind' => 'stripped_params',
        'client_request_id' => $clientRequestId,
        'params' => array_values(array_unique($removedParams)),
        'count' => count($removedParams),
    ]);
}
$sessionSeed = '';
foreach ([
    $body['metadata']['conversation_id'] ?? null,
    $body['conversation_id'] ?? null,
    $body['session_id'] ?? null,
    $body['prompt_cache_key'] ?? null,
] as $candidate) {
    if (is_string($candidate) && $candidate !== '') {
        $sessionSeed = $candidate;
        break;
    }
}
if ($sessionSeed === '') {
    $input = $body['input'] ?? null;
    if (is_string($input)) {
        $sessionSeed = $input;
    } elseif (is_array($input)) {
        foreach ($input as $item) {
            if (is_array($item) && ($item['role'] ?? '') === 'user') {
                $sessionSeed = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'user';
                break;
            }
        }
    }
}
if ($sessionSeed === '') {
    $sessionSeed = 'default-session';
}
$sessionId = 'ses_' . substr(hash('sha256', $clientKey . ':' . $sessionSeed), 0, 32);

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

$started = microtime(true);
$upstreamStatus = 502;
$headersSent = false;
$debugOn = debugEnabled();
$responseCapture = '';
$responseTruncated = false;
$responseCaptureLimit = debugBodyLimit();
$upstreamResponseHeaders = [];

$outgoingBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($outgoingBody === false) {
    jsonResponse(500, ['error' => ['message' => 'Could not encode request body.']]);
}

$upstreamHeaders = [
    'Authorization' => 'Bearer ' . $upstreamKey,
    'Content-Type' => 'application/json',
    'Accept' => 'text/event-stream, application/json',
    'User-Agent' => 'pmdiscos-vscode-proxy/1.1',
    'x-opencode-session' => $sessionId,
];
$curlHeaders = [];
foreach ($upstreamHeaders as $name => $value) {
    $curlHeaders[] = $name . ': ' . $value;
}

debugLog([
    'kind' => 'upstream_request',
    'client_request_id' => $clientRequestId,
    'session_id' => $sessionId,
    'url' => UPSTREAM_URL,
    'headers' => headersForLog($upstreamHeaders),
    'body' => truncateForLog($outgoingBody),
]);

$curl = curl_init(UPSTREAM_URL);
if ($curl === false) {
    jsonResponse(500, ['error' => ['message' => 'Could not initialize cURL.']]);
}

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $outgoingBody,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER => $curlHeaders,
    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$upstreamStatus, &$headersSent, &$upstreamResponseHeaders): int {
        $length = strlen($line);
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
            $upstreamStatus = (int)$m[1];
            http_response_code($upstreamStatus);
            $upstreamResponseHeaders[] = trim($line);
            return $length;
        }

        $trimmed = trim($line);
        if ($trimmed === '') {
            return $length;
        }
        $upstreamResponseHeaders[] = $trimmed;
        $name = strtolower(trim(strtok($trimmed, ':') ?: ''));
        $blocked = [
            'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
            'te', 'trailers', 'transfer-encoding', 'upgrade', 'content-length',
            'content-encoding', 'set-cookie',
        ];
        if ($name !== '' && !in_array($name, $blocked, true)) {
            header($trimmed, false);
            $headersSent = true;
        }
        return $length;
    },
    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$headersSent, &$responseCapture, &$responseTruncated, $debugOn, $responseCaptureLimit): int {
        if ($debugOn && strlen($responseCapture) < $responseCaptureLimit) {
            $responseCapture .= substr($chunk, 0, $responseCaptureLimit - strlen($responseCapture));
            if (strlen($responseCapture) >= $responseCaptureLimit) {
                $responseTruncated = true;
            }
        }
        echo $chunk;
        $headersSent = true;
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
        return strlen($chunk);
    },
]);

$execResult = curl_exec($curl);
$curlErrno = curl_errno($curl);
$curlError = curl_error($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$durationMs = (int)round((microtime(true) - $started) * 1000);
$finalStatus = $httpCode > 0 ? $httpCode : $upstreamStatus;

debugLog([
    'kind' => 'upstream_response',
    'client_request_id' => $clientRequestId,
    'session_id' => $sessionId,
    'status' => $finalStatus,
    'duration_ms' => $durationMs,
    'curl_errno' => $curlErrno,
    'curl_error' => $curlError !== '' ? $curlError : null,
    'headers' => $upstreamResponseHeaders,
    'body' => [
        'bytes' => strlen($responseCapture),
        'truncated' => $responseTruncated,
        'data' => $responseCapture,
    ],
]);

appendLog([
    'type' => 'request',
    'status' => $finalStatus,
    'model' => $model,
    'duration_ms' => $durationMs,
    'stream' => !empty($body['stream']),
]);

if ($execResult === false || $curlErrno !== 0) {
    error_log('OpenCode Go proxy cURL error (' . $curlErrno . '): ' . $curlError);
    if (!$headersSent && !headers_sent()) {
        jsonResponse(502, ['error' => ['message' => 'Upstream connection failed. Check the PHP error log.']]);
    }
}