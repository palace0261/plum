<?php
$pdsCats = ['manual' => 'BOLIN 매뉴얼', 'data' => '제품 자료'];
$pdsAll = [];

// 허용 목록에 있는 파일만 PDF 다운로드로 전달
if (isset($_GET['dl'], $pdsAll[$_GET['dl']]) && $pdsAll[$_GET['dl']]['url']) {
    $it = $pdsAll[$_GET['dl']];
    $ch = curl_init($it['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok) {
        header('Location: ' . $it['url']);
        exit;
    }
    // 파일명은 게시글 제목, 확장자는 원본 URL 그대로 유지
    $ext = strtolower(pathinfo((string)parse_url($it['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'pdf';
    $base = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $it['title']), ' .');
    $dlName = ($base !== '' ? $base : 'download') . '.' . $ext;
    header('Content-Type: ' . ($it['mime'] ?? 'application/pdf'));
    header('Content-Disposition: attachment; filename="download.' . $ext . "\"; filename*=UTF-8''" . rawurlencode($dlName));
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$cat = $_GET['cat'] ?? '';
if (!isset($pdsCats[$cat])) $cat = '';
$q = trim((string)($_GET['q'] ?? ''));
$pdsList = array_filter($pdsAll, fn($it) => ($cat === '' || $it['cat'] === $cat)
    && ($q === '' || mb_stripos($it['title'], $q) !== false));

$pages = [
    'product' => 'Product',
    'support' => '고객지원',
    'about'   => '회사소개',
    'demo'    => '데모요청',
];
$p = $_GET['p'] ?? 'main';
if (!isset($pages[$p]) && !in_array($p, ['detail', 'pds', 'way', 'inquiry'], true)) $p = 'main';
$view = $pdsAll[$_GET['id'] ?? ''] ?? null;
if ($p === 'pds' && !$view) $p = 'support';

$products = json_decode((string)@file_get_contents(__DIR__ . '/data/products.json'), true) ?: [];
$products = array_column($products, null, 'slug');
$products = array_filter($products, fn($product) => ($product['enabled'] ?? true) !== false);
$pcats = [
    'INDOOR PTZ CAMERAS' => 'INDOOR',
    'OUTDOOR PTZ CAMERAS' => 'OUTDOOR',
    'RANGE PTZ CAMERA' => 'RANGE',
    'ENCODER & DECODERS' => 'ENCODER & DECODERS',
    'CONTROLLERS' => 'CONTROLLERS',
    'NDI' => 'NDI',
    'DANTE AV' => 'Dante AV',
];
$pcatNotes = [
    'INDOOR PTZ CAMERAS' => 'Standard IP',
    'OUTDOOR PTZ CAMERAS' => 'Standard IP',
    'RANGE PTZ CAMERA' => '',
    'ENCODER & DECODERS' => '',
    'CONTROLLERS' => '',
    'NDI' => '',
    'DANTE AV' => '',
];

$pcat = $_GET['cat'] ?? '';
if (!isset($pcats[$pcat])) $pcat = '';
$prd = $products[$_GET['id'] ?? ''] ?? null;
if ($p === 'detail' && !$prd) $p = 'product';
$filteredProducts = array_filter($products, fn($x) => ($pcat === '' || $x['cat'] === $pcat
        || in_array($pcat, $x['also_cats'] ?? [], true))
    && ($q === '' || stripos($x['name'] . ' ' . ($x['summary'] ?? ''), $q) !== false));
$img = fn($f) => preg_match('~^https?://~i', (string)$f) ? (string)$f : '../b-pl-img/' . $f;
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

function canonBoardIpInCidr(string $ip, string $cidr): bool
{
    $parts = explode('/', trim($cidr), 2);
    $address = @inet_pton($ip);
    $network = @inet_pton($parts[0]);
    if ($address === false || $network === false || strlen($address) !== strlen($network)) return false;

    $bits = strlen($address) * 8;
    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : $bits;
    if ($prefix < 0 || $prefix > $bits) return false;

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) return false;
    if ($remainingBits === 0) return true;

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
}

function canonBoardConfig(string $key)
{
    static $file = null;
    if ($file === null) {
        define('CANON_BOARD', true);
        $file = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
    }
    $envKey = strtoupper($key);
    $envPrefixes = str_starts_with($key, 'db_')
        ? ['PLUM_']
        : ['PLUM_', 'CANON_'];
    foreach ($envPrefixes as $prefix) {
        $env = getenv($prefix . $envKey);
        if ($env !== false && $env !== '') return $env;
    }
    return $file[$key] ?? null;
}

function canonBoardTrustedProxy(): bool
{
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ranges = canonBoardConfig('cloudflare_cidrs');
    if (is_string($ranges)) $ranges = array_filter(array_map('trim', explode(',', $ranges)));
    foreach ((array)$ranges as $range) {
        if (canonBoardIpInCidr($remoteAddress, (string)$range)) return true;
    }
    return false;
}

function canonBoardIsKorea(): bool
{
    if (canonBoardConfig('geo_mode') === 'off') return true;

    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (canonBoardConfig('allow_local') !== false
        && filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    $allowed = canonBoardConfig('allow_ips');
    if (is_string($allowed)) $allowed = array_filter(array_map('trim', explode(',', $allowed)));
    if (in_array(canonBoardClientIp(), (array)$allowed, true)) return true;

    return canonBoardTrustedProxy()
        && strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')) === 'KR'
        && filter_var((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''), FILTER_VALIDATE_IP) !== false;
}

function canonBoardEnsureTable(mysqli $db, string $tableName, string $createSql): void
{
    $check = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $check->bind_param('s', $tableName);
    $check->execute();
    $check->bind_result($exists);
    $check->fetch();
    $check->close();
    if ((int)$exists === 0) $db->query($createSql);
}

function canonBoardClientIp(): string
{
    if (canonBoardTrustedProxy()) {
        $forwardedAddress = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if (filter_var($forwardedAddress, FILTER_VALIDATE_IP) !== false) return $forwardedAddress;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function canonBoardConnect(): mysqli
{
    $host = canonBoardConfig('db_host');
    $port = canonBoardConfig('db_port');
    $name = canonBoardConfig('db_name');
    $user = canonBoardConfig('db_user');
    $password = canonBoardConfig('db_password');
    if (!$host || !$name || !$user || $password === null || $password === '') {
        throw new RuntimeException('Canon board database environment is incomplete.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $password, $name, $port ? (int)$port : 3306);
    $db->set_charset('utf8mb4');
    canonBoardEnsureTable($db, 'canon_inquiries',
        "CREATE TABLE IF NOT EXISTS canon_inquiries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category VARCHAR(32) NOT NULL,
            author VARCHAR(80) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            product VARCHAR(255) NOT NULL,
            phone VARCHAR(24) NOT NULL,
            email VARCHAR(254) NOT NULL DEFAULT '',
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            answer TEXT NULL,
            ip_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_canon_inquiries_created (created_at),
            KEY idx_canon_inquiries_ip_created (ip_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    canonBoardEnsureTable($db, 'canon_inquiry_admin_attempts',
        "CREATE TABLE IF NOT EXISTS canon_inquiry_admin_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash CHAR(64) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_canon_admin_attempts_ip_time (ip_hash, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    canonBoardEnsureTable($db, 'canon_inquiry_password_attempts',
        "CREATE TABLE IF NOT EXISTS canon_inquiry_password_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_canon_password_attempts (ip_hash, post_id, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columnCheck = $db->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'canon_inquiries' AND column_name = 'deleted_at'"
    );
    $hasDeletedAt = (int)$columnCheck->fetch_row()[0] > 0;
    $columnCheck->free();
    if (!$hasDeletedAt) {
        $db->query('ALTER TABLE canon_inquiries ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');
    }
    return $db;
}

function canonBoardMaskName(string $name): string
{
    $length = mb_strlen($name, 'UTF-8');
    if ($length <= 1) return '*';
    if ($length === 2) return mb_substr($name, 0, 1, 'UTF-8') . '*';
    return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', $length - 2) . mb_substr($name, -1, 1, 'UTF-8');
}

function canonBoardCsrfValid(): bool
{
    return isset($_POST['csrf'], $_SESSION['canon_board_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['canon_board_csrf'], $_POST['csrf']);
}

function canonBoardPasswordAttemptCount(mysqli $db, string $ipHash, int $postId): int
{
    $query = $db->prepare(
        'SELECT COUNT(*) FROM canon_inquiry_password_attempts WHERE ip_hash = ? AND post_id = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $query->bind_param('si', $ipHash, $postId);
    $query->execute();
    $query->bind_result($attempts);
    $query->fetch();
    $query->close();
    return (int)$attempts;
}

function canonBoardRecordPasswordFailure(mysqli $db, string $ipHash, int $postId): void
{
    $query = $db->prepare('INSERT INTO canon_inquiry_password_attempts (ip_hash, post_id) VALUES (?, ?)');
    $query->bind_param('si', $ipHash, $postId);
    $query->execute();
    $query->close();
}

function canonBoardClearPasswordFailures(mysqli $db, string $ipHash, int $postId): void
{
    $query = $db->prepare('DELETE FROM canon_inquiry_password_attempts WHERE ip_hash = ? AND post_id = ?');
    $query->bind_param('si', $ipHash, $postId);
    $query->execute();
    $query->close();
}

function canonFlowSend(string $title, string $contents): bool
{
    $key = getenv('flowapistage');
    if ($key === false || $key === '') $key = $_SERVER['flowapistage'] ?? $_ENV['flowapistage'] ?? (function_exists('apache_getenv') ? apache_getenv('flowapistage') : false);
    $project = (string)canonBoardConfig('flow_project_id');
    if ($key === false || $key === '') {
        error_log('flowapistage 환경 변수가 설정되지 않았습니다.');
        $GLOBALS['canonFlowError'] = '환경변수 flowapistage 없음';
        return false;
    }
    if ($project === '') { $GLOBALS['canonFlowError'] = '프로젝트 ID 없음'; return false; }
    $payload = json_encode([
        'title' => $title,
        'contents' => $contents,
        'status' => 'request',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.flow.team/user/posts/projects/' . rawurlencode($project) . '/tasks');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-flow-api-key: ' . $key],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        error_log('Canon flow send failed: HTTP ' . $code . ' ' . $curlErr . ' ' . substr((string)$res, 0, 300));
        $GLOBALS['canonFlowError'] = 'HTTP ' . $code . ' ' . ($curlErr !== '' ? $curlErr : substr(strip_tags((string)$res), 0, 150));
        return false;
    }
    return true;
}

function canonBoardFormData(array $products, string &$error): ?array
{
    $categories = ['종교시설', '교육기관', '학원강의', '회의병원', '스튜디오', '야외경기'];
    $productNames = array_column($products, 'name');
    $data = [
        'category' => trim((string)($_POST['category'] ?? '')),
        'author' => trim((string)($_POST['author'] ?? '')),
        'product' => trim((string)($_POST['product'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'title' => trim((string)($_POST['title'] ?? '')),
        'body' => trim((string)($_POST['body'] ?? '')),
    ];

    if (class_exists('Normalizer')) {
        foreach (['author', 'title', 'body'] as $k) {
            $n = Normalizer::normalize($data[$k], Normalizer::FORM_C);
            if ($n !== false) $data[$k] = $n;
        }
    }
    $data['phone'] = preg_replace('/[^0-9+\-()\s]/', '', str_replace(['.', '·'], '-', $data['phone']));

    $error = '';
    if (!in_array($data['category'], $categories, true)) {
        $error = '말머리를 선택해 주세요.';
    } elseif (!preg_match('/^[가-힣]{2,20}$/u', $data['author'])) {
        $error = '작성자는 한글 2~20자만 입력할 수 있습니다.';
    } elseif (!in_array($data['product'], $productNames, true)) {
        $error = '상품을 선택해 주세요.';
    } elseif (!preg_match('/^[0-9+()\-\s]{8,24}$/', $data['phone'])) {
        $error = '휴대폰번호를 숫자 8자리 이상으로 입력해 주세요.';
    } elseif ($data['email'] !== '' && (strlen($data['email']) > 254 || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false)) {
        $error = '이메일 형식을 확인해 주세요.';
    } elseif (mb_strlen($data['title'], 'UTF-8') < 1 || mb_strlen($data['title'], 'UTF-8') > 180) {
        $error = '제목은 1~180자로 입력해 주세요.';
    } elseif (mb_strlen($data['body'], 'UTF-8') < 1 || mb_strlen($data['body'], 'UTF-8') > 10000) {
        $error = '본문은 1~10000자로 입력해 주세요.';
    }
    if ($error !== '') {
        return null;
    }
    return $data;
}

$boardError = '';
$boardNotice = '';
$boardRows = [];
$boardPost = null;
$boardPostId = filter_var($_GET['post'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$boardAdminLoggedIn = false;
$boardAdminMode = $p === 'inquiry';
$boardCanPost = canonBoardIsKorea();
$boardRateLimitReady = false;
$boardDb = null;

if (in_array($p, ['support', 'inquiry'], true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || canonBoardTrustedProxy(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['canon_board_csrf'])) {
        $_SESSION['canon_board_csrf'] = bin2hex(random_bytes(32));
    }
    if ($p === 'support' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_SESSION['canon_board_form_time'] = time();
    }

    try {
        $boardDb = canonBoardConnect();
        $rateKey = (string)canonBoardConfig('rate_limit_key');
        $boardRateLimitReady = $rateKey !== '';
        $clientIpHash = $rateKey !== ''
            ? hash_hmac('sha256', canonBoardClientIp(), $rateKey)
            : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['board_action'])) {
            $action = (string)$_POST['board_action'];
            if (!canonBoardCsrfValid()) {
                $boardNotice = '요청이 만료되었습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.';
            } elseif ($action === 'create' || $action === 'edit') {
                if (!$boardCanPost) {
                    $boardNotice = 'Cloudflare를 통한 국내 접속만 게시글을 등록하거나 수정할 수 있습니다.';
                } elseif ($rateKey === '') {
                    $boardNotice = '게시판 보안 설정이 완료되지 않아 등록할 수 없습니다.';
                } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
                    $boardNotice = '요청을 처리할 수 없습니다.';
                } elseif ($action === 'create' && !(ctype_digit($captchaAnswer = trim((string)($_POST['captcha'] ?? '')))
                    && isset($_SESSION['canon_captcha']['inquiry'])
                    && (int)$captchaAnswer === (int)$_SESSION['canon_captcha']['inquiry'])) {
                    $boardNotice = '자동등록방지 답이 올바르지 않습니다.';
                } elseif ($action === 'create'
                    && (time() - (int)($_SESSION['canon_board_form_time'] ?? 0) < 3
                        || time() - (int)($_SESSION['canon_board_form_time'] ?? 0) > 1800)) {
                    $boardNotice = '페이지를 새로고침한 뒤 다시 작성해 주세요.';
                } else {
                    $dataError = '';
                    $data = canonBoardFormData($products, $dataError);
                    $passwordInput = (string)($_POST['password'] ?? '');
                    if ($data === null) {
                        $boardNotice = $dataError;
                    } elseif (strlen($passwordInput) < 8 || strlen($passwordInput) > 72) {
                        $boardNotice = '비밀번호는 8~72바이트로 입력해 주세요.';
                    } elseif ($action === 'create') {
                        $rateQuery = $boardDb->prepare(
                            'SELECT COUNT(*) FROM canon_inquiries WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)'
                        );
                        $rateQuery->bind_param('s', $clientIpHash);
                        $rateQuery->execute();
                        $rateQuery->bind_result($recentCount);
                        $rateQuery->fetch();
                        $rateQuery->close();
                        if ($recentCount > 0) {
                            $boardNotice = '잠시 후 다시 등록해 주세요.';
                        } else {
                            $passwordHash = password_hash($passwordInput, PASSWORD_DEFAULT);
                            $insert = $boardDb->prepare(
                                'INSERT INTO canon_inquiries (category, author, password_hash, product, phone, email, title, body, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                            );
                            $insert->bind_param(
                                'sssssssss',
                                $data['category'],
                                $data['author'],
                                $passwordHash,
                                $data['product'],
                                $data['phone'],
                                $data['email'],
                                $data['title'],
                                $data['body'],
                                $clientIpHash
                            );
                            $insert->execute();
                            $insert->close();
                            canonFlowSend(
                                $data['category'] . ' / ' . $data['title'],
                                $data['author'] . ' / ' . $data['phone'] . ' / ' . $data['email']
                                    . "\n제품: " . $data['product'] . "\n" . $data['body']
                            );
                            unset($_SESSION['canon_board_form_time']);
                            header('Location: ?p=support&inquiry=created#inquiry');
                            exit;
                        }
                    } else {
                        $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if (!$postId) {
                            $boardNotice = '게시글 또는 비밀번호를 확인해 주세요.';
                        } elseif (canonBoardPasswordAttemptCount($boardDb, $clientIpHash, (int)$postId) >= 10) {
                            $boardNotice = '비밀번호 확인 횟수를 초과했습니다. 15분 후 다시 시도해 주세요.';
                        } else {
                            $lookup = $boardDb->prepare('SELECT password_hash FROM canon_inquiries WHERE id = ? AND deleted_at IS NULL');
                            $lookup->bind_param('i', $postId);
                            $lookup->execute();
                            $lookup->bind_result($storedPasswordHash);
                            $found = $lookup->fetch();
                            $lookup->close();
                            if (!$found || !password_verify($passwordInput, $storedPasswordHash)) {
                                canonBoardRecordPasswordFailure($boardDb, $clientIpHash, (int)$postId);
                                $boardNotice = '게시글 또는 비밀번호를 확인해 주세요.';
                            } else {
                                canonBoardClearPasswordFailures($boardDb, $clientIpHash, (int)$postId);
                                $newPassword = (string)($_POST['new_password'] ?? '');
                                if ($newPassword !== '' && (strlen($newPassword) < 8 || strlen($newPassword) > 72)) {
                                    $boardNotice = '새 비밀번호는 8~72바이트로 입력해 주세요.';
                                } else {
                                    $updatedPasswordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $storedPasswordHash;
                                    $update = $boardDb->prepare(
                                        'UPDATE canon_inquiries SET category = ?, author = ?, password_hash = ?, product = ?, phone = ?, email = ?, title = ?, body = ? WHERE id = ?'
                                    );
                                    $update->bind_param(
                                        'ssssssssi',
                                        $data['category'],
                                        $data['author'],
                                        $updatedPasswordHash,
                                        $data['product'],
                                        $data['phone'],
                                        $data['email'],
                                        $data['title'],
                                        $data['body'],
                                        $postId
                                    );
                                    $update->execute();
                                    $update->close();
                                    header('Location: ?p=support&inquiry=updated#inquiry');
                                    exit;
                                }
                            }
                        }
                    }
                }
            } elseif ($action === 'delete') {
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $grantedAt = $postId ? (int)($_SESSION['canon_board_view_grant'][$postId] ?? 0) : 0;
                if (!$postId || $grantedAt === 0 || time() - $grantedAt > 1800) {
                    $boardNotice = '비밀번호 확인이 만료되었습니다. 게시글을 다시 열어 주세요.';
                } else {
                    $remove = $boardDb->prepare('UPDATE canon_inquiries SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
                    $remove->bind_param('i', $postId);
                    $remove->execute();
                    $remove->close();
                    unset($_SESSION['canon_board_view_grant'][$postId]);
                    header('Location: ?p=support&inquiry=deleted#inquiry');
                    exit;
                }            } elseif ($action === 'view') {
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($rateKey === '') {
                    $boardNotice = '게시판 보안 설정이 완료되지 않아 비밀글을 확인할 수 없습니다.';
                } elseif (!$postId) {
                    $boardNotice = '게시글 또는 비밀번호를 확인해 주세요.';
                } elseif (canonBoardPasswordAttemptCount($boardDb, $clientIpHash, (int)$postId) >= 10) {
                    $boardNotice = '비밀번호 확인 횟수를 초과했습니다. 15분 후 다시 시도해 주세요.';
                } else {
                    $lookup = $boardDb->prepare(
                        'SELECT id, category, author, password_hash, product, phone, email, title, body, answer, created_at FROM canon_inquiries WHERE id = ? AND deleted_at IS NULL'
                    );
                    $lookup->bind_param('i', $postId);
                    $lookup->execute();
                    $lookup->bind_result(
                        $candidateId,
                        $candidateCategory,
                        $candidateAuthor,
                        $candidatePasswordHash,
                        $candidateProduct,
                        $candidatePhone,
                        $candidateEmail,
                        $candidateTitle,
                        $candidateBody,
                        $candidateAnswer,
                        $candidateCreatedAt
                    );
                    $found = $lookup->fetch();
                    $lookup->close();
                    if ($found && password_verify((string)($_POST['password'] ?? ''), $candidatePasswordHash)) {
                        canonBoardClearPasswordFailures($boardDb, $clientIpHash, (int)$postId);
                        $_SESSION['canon_board_view_grant'][(int)$postId] = time();
                        $boardPost = [
                            'id' => $candidateId,
                            'category' => $candidateCategory,
                            'author' => $candidateAuthor,
                            'product' => $candidateProduct,
                            'phone' => $candidatePhone,
                            'email' => $candidateEmail,
                            'title' => $candidateTitle,
                            'body' => $candidateBody,
                            'answer' => $candidateAnswer,
                            'created_at' => $candidateCreatedAt,
                        ];
                    } else {
                        canonBoardRecordPasswordFailure($boardDb, $clientIpHash, (int)$postId);
                        $boardNotice = '게시글 또는 비밀번호를 확인해 주세요.';
                    }
                }
            } elseif ($p === 'inquiry' && $action === 'admin_login') {
                $adminHash = (string)canonBoardConfig('admin_password_hash');
                $attempts = 0;
                if ($clientIpHash !== '') {
                    $count = $boardDb->prepare(
                        'SELECT COUNT(*) FROM canon_inquiry_admin_attempts WHERE ip_hash = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
                    );
                    $count->bind_param('s', $clientIpHash);
                    $count->execute();
                    $count->bind_result($attempts);
                    $count->fetch();
                    $count->close();
                }
                if ($rateKey === '' || $adminHash === '' || $attempts >= 5) {
                    $boardNotice = '로그인할 수 없습니다. 설정을 확인하거나 잠시 후 다시 시도해 주세요.';
                } elseif (password_verify((string)($_POST['admin_password'] ?? ''), $adminHash)) {
                    session_regenerate_id(true);
                    $_SESSION['canon_board_admin_auth_at'] = time();
                    if ($clientIpHash !== '') {
                        $clear = $boardDb->prepare('DELETE FROM canon_inquiry_admin_attempts WHERE ip_hash = ?');
                        $clear->bind_param('s', $clientIpHash);
                        $clear->execute();
                        $clear->close();
                    }
                    header('Location: ?p=inquiry');
                    exit;
                } else {
                    if ($clientIpHash !== '') {
                        $attempt = $boardDb->prepare('INSERT INTO canon_inquiry_admin_attempts (ip_hash) VALUES (?)');
                        $attempt->bind_param('s', $clientIpHash);
                        $attempt->execute();
                        $attempt->close();
                    }
                    $boardNotice = '관리자 비밀번호가 올바르지 않습니다.';
                }
            } elseif ($p === 'inquiry' && $action === 'admin_logout') {
                unset($_SESSION['canon_board_admin_auth_at']);
                session_regenerate_id(true);
                header('Location: ?p=inquiry');
                exit;
            } elseif ($p === 'inquiry' && $action === 'admin_reply') {
                $adminLoggedIn = isset($_SESSION['canon_board_admin_auth_at'])
                    && time() - (int)$_SESSION['canon_board_admin_auth_at'] <= 1800;
                $answer = trim((string)($_POST['answer'] ?? ''));
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$adminLoggedIn) {
                    $boardNotice = '관리자 로그인이 필요합니다.';
                } elseif ($answer === '' || mb_strlen($answer, 'UTF-8') > 10000 || !$postId) {
                    $boardNotice = '답변 내용을 확인해 주세요.';
                } else {
                    $reply = $boardDb->prepare('UPDATE canon_inquiries SET answer = ? WHERE id = ?');
                    $reply->bind_param('si', $answer, $postId);
                    $reply->execute();
                    $reply->close();
                    header('Location: ?p=inquiry&replied=1');
                    exit;
                }
            } elseif ($p === 'inquiry' && $action === 'admin_delete') {
                $adminLoggedIn = isset($_SESSION['canon_board_admin_auth_at'])
                    && time() - (int)$_SESSION['canon_board_admin_auth_at'] <= 1800;
                $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$adminLoggedIn) {
                    $boardNotice = '관리자 로그인이 필요합니다.';
                } elseif (!$postId) {
                    $boardNotice = '삭제할 게시글을 확인해 주세요.';
                } else {
                    $remove = $boardDb->prepare(
                        'UPDATE canon_inquiries SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
                    );
                    $remove->bind_param('i', $postId);
                    $remove->execute();
                    $remove->close();
                    header('Location: ?p=inquiry&deleted=1');
                    exit;
                }
            }
        }

        $boardAdminLoggedIn = isset($_SESSION['canon_board_admin_auth_at'])
            && time() - (int)$_SESSION['canon_board_admin_auth_at'] <= 1800;
        if ($boardAdminMode && $boardAdminLoggedIn) {
            $_SESSION['canon_board_admin_auth_at'] = time();
            $result = $boardDb->query(
                'SELECT id, category, author, product, phone, email, title, body, answer, created_at, deleted_at FROM canon_inquiries ORDER BY id DESC LIMIT 200'
            );
            $boardRows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            if (isset($_GET['deleted']) && $_GET['deleted'] === '1') $boardNotice = '게시글을 삭제했습니다.';
            if (isset($_GET['replied']) && $_GET['replied'] === '1') $boardNotice = '답변을 저장했습니다.';
        } elseif ($p === 'support') {
            $result = $boardDb->query('SELECT id, category, author, title FROM canon_inquiries WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 100');
            $boardRows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            if (!$boardPost && $boardPostId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $boardNotice = '';
            }
            if (isset($_GET['inquiry']) && $_GET['inquiry'] === 'created') $boardNotice = '비밀글이 등록되었습니다.';
            if (isset($_GET['inquiry']) && $_GET['inquiry'] === 'updated') $boardNotice = '게시글을 수정했습니다.';
            if (isset($_GET['inquiry']) && $_GET['inquiry'] === 'deleted') $boardNotice = '게시글을 삭제했습니다.';
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Canon inquiry board database error: ' . $exception->getMessage());
        $boardError = '문의 게시판을 일시적으로 사용할 수 없습니다. 잠시 후 다시 시도해 주세요.';
    } catch (RuntimeException $exception) {
        error_log('Canon inquiry board configuration error: ' . $exception->getMessage());
        $boardError = '문의 게시판 설정이 완료되지 않았습니다.';
    }
}

$mainInquiries = [];
if ($p === 'main') {
    try {
        $mainDb = canonBoardConnect();
        $mainResult = $mainDb->query('SELECT id, category, title FROM canon_inquiries WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5');
        $mainInquiries = $mainResult->fetch_all(MYSQLI_ASSOC);
        $mainDb->close();
    } catch (Throwable $exception) {
        error_log('Canon main inquiry list error: ' . $exception->getMessage());
    }
}

$consultError = '';
$captchaText = [];
$sent = $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['board_action'])
    && in_array($p, ['demo', 'support'], true);
if (in_array($p, ['demo', 'support'], true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || canonBoardTrustedProxy(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['canon_board_csrf'])) {
        $_SESSION['canon_board_csrf'] = bin2hex(random_bytes(32));
    }
    if ($sent) {
        $formKey = $p === 'demo' ? 'demo' : 'consult';
        $expected = $_SESSION['canon_captcha'][$formKey] ?? null;
        $answer = trim((string)($_POST['captcha'] ?? ''));
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            $consultError = '요청을 처리할 수 없습니다.';
        } elseif ($expected === null || !ctype_digit($answer) || (int)$answer !== (int)$expected) {
            $consultError = '자동등록방지 답이 올바르지 않습니다.';
        } elseif (!hash_equals((string)($_SESSION['canon_board_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            $consultError = '요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.';
        } elseif (time() - (int)($_SESSION['canon_consult_time'] ?? 0) < 3) {
            $consultError = '잠시 후 다시 시도해 주세요.';
        }
        if ($consultError !== '') {
            $sent = false;
        } else {
            $flowOk = $p === 'demo'
                ? canonFlowSend(
                    trim((string)($_POST['model'] ?? '')) . ' / ' . trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['tel'] ?? '')) . "\n" . trim((string)($_POST['msg'] ?? ''))
                )
                : canonFlowSend(
                    trim((string)($_POST['name'] ?? '')) . ' / ' . trim((string)($_POST['tel'] ?? '')),
                    trim((string)($_POST['msg'] ?? ''))
                );
            if (!$flowOk) {
                $consultError = 'Flow 전송에 실패했습니다. (' . ($GLOBALS['canonFlowError'] ?? '알 수 없음') . ')';
                $sent = false;
            }
        }
    }
    foreach ($p === 'demo' ? ['demo'] : ['consult', 'inquiry'] as $formKey) {
        $capA = random_int(1, 9);
        $capB = random_int(1, 9);
        $_SESSION['canon_captcha'][$formKey] = $capA + $capB;
        $captchaText[$formKey] = "$capA + $capB";
    }
    $_SESSION['canon_consult_time'] = time();
}
$consultCaptcha = $captchaText['consult'] ?? '';
$captchaLabel = fn(string $text) => '<label style="display:block;margin:10px 0 4px;font-weight:bold">자동등록방지: <span style="color:#e60012;font-size:1.2em">'
    . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . ' = ?</span> (정답 숫자를 입력해 주세요)</label>'
    . '<input name="captcha" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" autocomplete="off" placeholder="정답 입력" required>';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$siteOrigin = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'plum.sodamedia.kr');
$sitePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/site/plum/index.php')), '/') . '/';
$assetBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/site/plum/index.php'))), '/');
$canonicalUrl = $siteOrigin . $sitePath;
if ($p !== 'main') {
    $canonicalParams = ['p' => $p];
    if ($p === 'detail' && $prd) $canonicalParams['id'] = $prd['slug'];
    $canonicalUrl .= '?' . http_build_query($canonicalParams);
}
$seoTitle = $p === 'detail' && $prd
    ? $prd['name'] . ' | BOLIN PTZ 카메라 - 플럼 코리아'
    : ($p === 'main'
        ? 'BOLIN 한국 총판 플럼 코리아 | BOLIN PTZ 카메라'
        : ($pages[$p] ?? 'BOLIN 한국 총판') . ' | BOLIN 한국 총판 플럼 코리아');
$seoDescription = $p === 'detail' && $prd
    ? $prd['summary']
    : 'BOLIN 한국 총판 플럼 코리아. 방송, 영상 제작 환경에 최적화된 BOLIN PTZ 카메라 제품 안내와 상담, 기술 지원을 제공합니다.';
$seoImage = $siteOrigin . $assetBase . '/b-pl-img/' . ($p === 'detail' && $prd
    ? $prd['gallery'][0]
    : 'logo.png');
?>
<title><?= $e($seoTitle) ?></title>

<!-- SEO Meta Tags -->
<meta name="description" content="<?= $e($seoDescription) ?>">
<meta name="keywords" content="BOLIN 한국 총판, BOLIN PTZ 카메라, R9-230H, R9 Series, Full HD PTZ 카메라, 플럼 코리아">
<meta name="author" content="플럼 코리아">
<meta name="robots" content="index, follow">
<meta name="googlebot" content="index, follow">
<meta name="bingbot" content="index, follow">
<meta name="naver-site-verification" content="">
<meta name="google-site-verification" content="">

<!-- Open Graph Meta Tags for Social Media -->
<meta property="og:type" content="<?= $p === 'detail' ? 'product' : 'website' ?>">
<meta property="og:title" content="<?= $e($seoTitle) ?>">
<meta property="og:description" content="<?= $e($seoDescription) ?>">
<meta property="og:url" content="<?= $e($canonicalUrl) ?>">
<meta property="og:image" content="<?= $e($seoImage) ?>">
<meta property="og:site_name" content="플럼 코리아 | BOLIN 한국 총판">
<meta property="og:locale" content="ko_KR">

<!-- Twitter Card Meta Tags -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($seoTitle) ?>">
<meta name="twitter:description" content="<?= $e($seoDescription) ?>">
<meta name="twitter:image" content="<?= $e($seoImage) ?>">

<!-- Canonical URL -->
<link rel="canonical" href="<?= $e($canonicalUrl) ?>">

<!-- Link Tags for Alternate Languages (if needed) -->
<link rel="alternate" hreflang="ko" href="<?= $e($canonicalUrl) ?>">

<?php if ($p === 'detail' && $prd): ?>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $prd['name'],
    'description' => $prd['summary'],
    'image' => [$seoImage],
    'sku' => $prd['slug'],
    'brand' => ['@type' => 'Brand', 'name' => 'BOLIN'],
    'manufacturer' => ['@type' => 'Organization', 'name' => 'BOLIN'],
    'url' => $canonicalUrl,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<link rel="icon" href="../b-pl-img/favicon.ico" type="image/x-icon">
<link rel="apple-touch-icon" href="../b-pl-img/favicon.ico">
<link rel="mask-icon" href="../b-pl-img/favicon.ico" color="#000000">
<meta name="msapplication-TileColor" content="#000000">
<meta name="theme-color" content="#000000">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chiron+GoRound+TC:wght@200..900&family=Roboto:wght@400..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../b-pl-css/index.css?v=<?= filemtime(__DIR__ . '/../b-pl-css/index.css') ?>">
</head>
<body class="<?= $p === 'detail' ? 'product-page' : '' ?>">
<?php
$menu = [
    ['Product', '?p=product', [
        ['INDOOR', '?p=product&cat=INDOOR%20PTZ%20CAMERAS#product-categories', []],
        ['OUTDOOR', '?p=product&cat=OUTDOOR%20PTZ%20CAMERAS#product-categories', []],
        ['RANGE', '?p=product&cat=RANGE%20PTZ%20CAMERA#product-categories', []],
        ['ENCODER', '?p=product&cat=ENCODER%20%26%20DECODERS#product-categories', []],
        ['CONTROLLERS', '?p=product&cat=CONTROLLERS#product-categories', []],
        ['NDI', '?p=product&cat=NDI#product-categories', []],
        ['Dante AV', '?p=product&cat=DANTE+AV#product-categories', []],
    ]],
    ['견적문의', '?p=support#consult', [
    ]],
    ['기술문의', '?p=support#inquiry', [
    ]],
    ['데모요청', '?p=demo', [
    ]],
    ['회사소개', '?p=about', [
    ]],
    ['오시는길', '?p=way', [
    ]]
];
?>
<header id="hd"><div class="header-shell">
  <div class="utility-row in">
    <span class="utility-note">PLUM KOREA</span>
    <div class="utility-links">
      <a href="?p=support#consult">견적문의</a>
      <a href="?p=support#inquiry">기술문의</a>
      <a href="?p=way">오시는길</a>
      <a href="?p=demo">데모신청</a>
    </div>
  </div>
  <div class="header-top in">
    <a class="logo" href="?p=main">
      <img class="lg" src="../b-pl-img/logo.png" alt="PLUM_LOGO">
      <img class="lg-on" src="../b-pl-img/logo_on.png" alt="PLUM_LOGO">
      <span class="logo-label">
        <strong>
          <span style="
  font-weight: 200;
">ㅣ</span> BOLIN 한국 총판</strong></span></a>
    <div class="header-actions">
      <form class="site-search" method="get" role="search">
        <input type="hidden" name="p" value="product">
        <label class="sr-only" for="siteSearch">제품 검색</label>
        <input id="siteSearch" type="search" name="q" value="<?= $p === 'product' ? $e($q) : '' ?>" placeholder="제품명 검색">
        <button type="submit" aria-label="검색"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg></button>
      </form>
      <button type="button" class="mega-toggle" id="megaToggle" aria-label="전체 메뉴 열기" aria-expanded="false" aria-controls="megaMenu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <nav class="site-nav" aria-label="주 메뉴"><div class="nav-inner in">
    <?php foreach ($menu as $m): ?>
      <?php if ($m[0] === 'Product'): ?>
        <button type="button" class="nav-trigger" id="productToggle" aria-expanded="false" aria-controls="megaMenu">
          <span><?= $e($m[0]) ?></span><span class="nav-caret" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 14 6-6 6 6"/></svg></span>
        </button>
      <?php else: ?>
        <a class="nav-trigger" href="<?= $e($m[1]) ?>"><?= $e($m[0]) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div></nav>
  <div class="mega-menu" id="megaMenu" aria-hidden="true">
    <div class="mega-inner in">
      <div class="mega-columns">
        <?php foreach ($menu as $m): ?>
          <section class="mega-column<?= $m[0] === 'Product' ? ' product-mega-column' : '' ?>">
            <div class="mega-links">
              <?php foreach ($m[2] as $item): ?>
                <div class="mega-link-group">
                  <a class="mega-category" href="<?= $e($item[1]) ?>"><?= $e($item[0]) ?></a>
                  <?php if ($item[2]): ?>
                    <div class="mega-sub-links">
                      <?php foreach ($item[2] as $subitem): ?>
                        <a href="<?= $e($subitem[1]) ?>"><?= $e($subitem[0]) ?></a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div></header>
<script>
(function () {
  var shell = document.querySelector('.header-shell');
  var menu = document.getElementById('megaMenu');
  var menuToggle = document.getElementById('megaToggle');
  var productToggle = document.getElementById('productToggle');
  var navInner = document.querySelector('.nav-inner');
  var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var closeTimer, pinnedOpen = false;
  function positionMenu() {
    var productRect = productToggle.getBoundingClientRect();
    var shellRect = shell.getBoundingClientRect();
    menu.style.setProperty('--mega-left', (productRect.left - shellRect.left) + 'px');
    menu.style.setProperty('--mega-width', productRect.width + 'px');
  }
  function setOpen(open) {
    window.clearTimeout(closeTimer);
    if (open) {
      navInner.scrollLeft = 0;
      positionMenu();
    }
    shell.classList.toggle('mega-open', open);
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuToggle.setAttribute('aria-label', open ? '전체 메뉴 닫기' : '전체 메뉴 열기');
    productToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function togglePinned() {
    pinnedOpen = !pinnedOpen;
    setOpen(pinnedOpen);
  }
  window.addEventListener('resize', positionMenu);
  navInner.addEventListener('scroll', positionMenu);
  menuToggle.addEventListener('click', togglePinned);
  productToggle.addEventListener('click', togglePinned);
  if (canHover) {
    function scheduleClose() {
      window.clearTimeout(closeTimer);
      closeTimer = window.setTimeout(function () {
        if (!pinnedOpen) setOpen(false);
      }, 180);
    }
    productToggle.addEventListener('pointerenter', function () { setOpen(true); });
    productToggle.addEventListener('pointerleave', scheduleClose);
    shell.addEventListener('pointerleave', scheduleClose);
    menu.addEventListener('pointerenter', function () { window.clearTimeout(closeTimer); });
    menu.addEventListener('pointerleave', scheduleClose);
  }
  document.addEventListener('click', function (event) {
    if (event.target.closest('#footerMegaToggle')) {
      event.preventDefault();
      menuToggle.focus({ preventScroll: true });
      window.scrollTo({ top: 0, behavior: 'instant' });
      menuToggle.click();
      return;
    }
    if (!shell.contains(event.target) && !pinnedOpen) {
      setOpen(false);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      pinnedOpen = false;
      setOpen(false);
    }
  });
  shell.addEventListener('focusout', function (event) {
    if (!shell.contains(event.relatedTarget) && !pinnedOpen) setOpen(false);
  });
})();
</script>

<?php if ($p === 'main'): ?>
<div class="hero is-initial" id="hero">
  <?php
  $slides = [
    ['image' => 'b-main-a04-a.png', 'title' => 'All Weather 4K60 PTZR Camera', 'subtitle' => "Range", 'href' => '?p=detail&id=RANGE'],
    ['image' => 'b-main-a05-a.png', 'title' => '1 Inch 4K60 PTZ Camera', 'subtitle' => "R9-L420N", 'href' => '?p=detail&id=R9-L420N'],
    ['image' => 'b-main-a06-a.png', 'title' => '4K60 Outdoor PTZ Camera', 'subtitle' => "EXU420F ", 'href' => '?p=detail&id=EXU420F'],
    ['image' => 'b-main-a07-a.png', 'title' => 'Advanced PTZ Production Controller', 'subtitle' => "KBD Plus", 'href' => '?p=detail&id=KBD-PLUS'],
    //['image' => 'b-main-a01-a.png', 'title' => 'NDI Embedded Bridge', 'subtitle' => "NDI6ㅣHX3", 'href' => '?p=product&cat=NDI'],
  ];
  foreach ($slides as $i => $s): ?>
  <div class="slide<?= $i === 0 ? ' on' : '' ?>" style="background-image:url('../b-pl-img/<?= $e($s['image']) ?>')">
    <div class="in txt">
      <span class="s-t"><?= nl2br($e($s['title']), false) ?></span>
      <?php if ($i === 0): ?><h1 class="s-p"><?= nl2br($e($s['subtitle']), false) ?></h1><?php else: ?><p class="s-p"><?= nl2br($e($s['subtitle']), false) ?></p><?php endif; ?>
      <a class="slide-cta" href="<?= $e($s['href']) ?>">자세히 보기 <span aria-hidden="true">&gt;</span></a>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="dots"><?php foreach ($slides as $i => $s): ?><button type="button" class="<?= $i === 0 ? 'on' : '' ?>" aria-label="slide <?= $i + 1 ?>"></button><?php endforeach; ?></div>

</div>
<script>
(function () {
  var hd = document.getElementById('hd');
  function onScroll() { hd.classList.toggle('scrolled', window.scrollY > 10); }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  var slides = document.querySelectorAll('#hero .slide'),
      dots = document.querySelectorAll('#hero .dots button'),
      hero = document.getElementById('hero'), cur = 0, timer;
  function go(n) {
    hero.classList.remove('is-initial');
    slides[cur].classList.remove('on'); dots[cur].classList.remove('on');
    cur = (n + slides.length) % slides.length;
    slides[cur].classList.add('on'); dots[cur].classList.add('on');
  }
  function start() {
    clearInterval(timer);
    timer = setInterval(function () { go(cur + 1); }, 5000);
  }
  dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); start(); }); });
  start();
})();
</script>
<?php else: ?>
<script>
(function () {
  var hd = document.getElementById('hd');
  function onScroll() { hd.classList.toggle('scrolled', window.scrollY > 10); }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>
<?php endif; ?>
<?php if ($p === 'main'): ?>
<section style="background:#111" class="ycl"><div class="in">
  <h2 class="h">제품소개</h2>
  <p class="lead">BOLIN Technology 한국 총판</p>
  <div class="mobile-carousel-wrap">
  <div class="grid mobile-carousel" id="wszq">
    <a class="yard" href="?p=detail&id=RANGE"><div class="thumb">
      <img src="../b-pl-img/ro/b-01a.png" ></div><div class="b"><h3>Range</h3></div></a>
    <a class="yard" href="?p=product&cat=INDOOR%20PTZ%20CAMERAS#product-categories"><div class="thumb">
      <img src="../b-pl-img/ro/b-02.png" ></div><div class="b"><h3>실내용</h3></div></a>
    <a class="yard" href="?p=product&cat=CONTROLLERS#product-categories"><div class="thumb">
      <img src="../b-pl-img/ro/b-03.png" ></div><div class="b"><h3>컨트롤러</h3></div></a>
    <a class="yard" href="?p=product&cat=OUTDOOR%20PTZ%20CAMERAS#product-categories"><div class="thumb">
      <img src="../b-pl-img/ro/b-04.png" ></div><div class="b"><h3>야외용</h3></div></a>
    <a class="yard" href="?p=product&cat=ENCODER+%26+DECODERS#product-categories"><div class="thumb">
      <img src="../b-pl-img/ro/b-05.png" ></div><div class="b"><h3>엔코더</h3></div></a>
  </div>
  <button type="button" class="carousel-next" aria-label="다음 카드"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
  <button type="button" class="carousel-prev" aria-label="이전 카드" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>
  </div>
</div></section>
<section style="background:#000"><div class="in">
  <h2 class="h">NEW PTZ Range</h2>
  <p class="lead">All Weather 4K60</p>
  <p class="lead"><a href="?p=detail&id=RANGE" style="color:var(--fs)">자세히 보기 ></a></p>
  <div>
    <video id="product-video" class="product-video" src="../b-pl-img/bo-rg-a.webm" controls muted playsinline preload="auto"></video>
  </div>
</div></section>
<script>
(function () {
  var video = document.getElementById('product-video');
  if (!video || !('IntersectionObserver' in window)) return;

  var loops = 0, maxLoops = 2, completed = false, inViewport = false;
  video.addEventListener('ended', function () {
    if (loops >= maxLoops) {
      completed = true;
      return;
    }
    loops++;
    video.currentTime = 0;
    if (inViewport) {
      video.play().catch(function (error) {
        if (error.name !== 'AbortError') console.error('Product video replay failed:', error);
      });
    }
  });

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.target !== video) return;
      if (entry.isIntersecting) {
        inViewport = true;
        if (!completed) {
          video.play().catch(function (error) {
            if (error.name !== 'AbortError') console.error('Product video autoplay failed:', error);
          });
        }
      } else {
        inViewport = false;
        video.pause();
      }
    });
  });
  observer.observe(video);
})();
</script>


<section style="background:#111"><div class="in">
  <h2 class="h" id="reference">Reference</h2>
  <p class="lead">설치 사례</p>
  <div class="mobile-carousel-wrap">
  <div class="grid mobile-carousel reference-grid">
        <div class="thumb">
            <img src="../b-pl-img/rf/001.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/002.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/003.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/004.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/005.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/006.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/007.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/008.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/009.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/010.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/011.jpg" alt="제품소개">
        </div>
        <div class="thumb">
            <img src="../b-pl-img/rf/012.jpg" alt="제품소개">
        </div>
  </div>
  <button type="button" class="carousel-next" aria-label="다음 설치 사례"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
  </div>
</div></section>

<section style="background:#000"><div class="in">
  <h2 class="h">플럼 코리아</h2>
  <p class="lead">최선의 고객 지원 서비스를 제공하기 위해 끊임없이 노력하며, 고객이 믿고 맡길 수 있는 든든한 파트너가 되겠습니다.</p>
  <div class="mobile-carousel-wrap">
  <div class="grid mobile-carousel">
    <a class="card" href="?p=product"><div class="thumb">
      <img src="../b-pl-img/ms1_li1.jpg" alt="Product"></div><div class="b"><h3>Product</h3></div></a>
    <a class="card" href="?p=support"><div class="thumb">
      <img src="../b-pl-img/ms1_li2.jpg" alt="고객지원"></div><div class="b"><h3>고객지원</h3></div></a>
    <a class="card" href="?p=demo"><div class="thumb">
      <img src="../b-pl-img/ms1_li3.jpg" alt="데모요청"></div><div class="b"><h3>데모요청</h3></div></a>
  </div>
  <button type="button" class="carousel-next" aria-label="다음 카드"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></button>
  </div>
</div></section>

<script>
(function () {
  document.querySelectorAll('.mobile-carousel-wrap').forEach(function (wrap) {
    var carousel = wrap.querySelector('.mobile-carousel'),
        next = wrap.querySelector('.carousel-next'),
        prev = wrap.querySelector('.carousel-prev');
    function moveNext() {
      var gap = parseFloat(window.getComputedStyle(carousel).columnGap) || 0;
      carousel.scrollBy({
        left: carousel.firstElementChild.getBoundingClientRect().width + gap,
        behavior: 'smooth'
      });
    }
    if (prev) {
      function updateButtons() {
        var maxScroll = carousel.scrollWidth - carousel.clientWidth;
        var atStart = carousel.scrollLeft <= 1;
        var atEnd = carousel.scrollLeft >= maxScroll - 1;
        if (!atStart) prev.hidden = false;
        if (!atEnd) next.hidden = false;
        if (atEnd && document.activeElement === next && !atStart) prev.focus({ preventScroll: true });
        if (atStart && document.activeElement === prev && !atEnd) next.focus({ preventScroll: true });
        prev.hidden = atStart;
        next.hidden = atEnd;
      }
      next.addEventListener('click', moveNext);
      prev.addEventListener('click', function () {
        carousel.scrollTo({ left: 0, behavior: 'smooth' });
      });
      carousel.addEventListener('scroll', updateButtons);
      window.addEventListener('resize', updateButtons);
      updateButtons();
      return;
    }
    next.addEventListener('click', function () {
      var maxScroll = carousel.scrollWidth - carousel.clientWidth;
      if (carousel.scrollLeft >= maxScroll - 1) {
        carousel.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }
      moveNext();
    });
  });
})();
</script>
<section class="gray" style="background:#111"><div class="in">
  <h2 class="h">CONTACT US</h2>
  <p class="lead">문의사항이 있으시면 아래 연락처로 연락주시기 바랍니다.</p>
  <div class="contact">
    <div><b>구매 문의</b>070-8866-1171</div>
    <div><b>영업시간</b>오전 10시 ~ 오후 7시 (토/일/공휴일 휴무)</div>
    <div><b>점심식사</b>오후 1시 ~ 2시</div>
    <div><b>이메일</b>plumkorea1171@gmail.com</div>
  </div>
</div></section>
<section class="bar-banner">
  <div class="grid">
        <div class="thumb" id="pl-bar-banner-0a1">
        <img src="../b-pl-img/bar-bn-a1.jpg" alt="BOLIN">
        </div>
  </div></section>

<?php elseif ($p === 'product'): ?>
<div class="sub"><div class="title"><h1>Product</h1></div></div>
<section><div class="in">
  <div class="tabs anchor" id="product-categories">
    <?php foreach (['' => '전체'] + $pcats as $k => $v): ?>
      <a href="?p=product&cat=<?= urlencode($k) ?>#product-categories" class="<?= $k === $pcat ? 'on' : '' ?>"><?= $e($v) ?></a>
    <?php endforeach; ?>
  </div>
  <?php foreach ($pcats as $ck => $cv):
    if ($pcat !== '' && $pcat !== $ck) continue;
    $categoryProducts = array_filter($filteredProducts, fn($x) => $x['cat'] === $ck
      || in_array($ck, $x['also_cats'] ?? [], true));
    if (!$categoryProducts) continue; ?>
    <h3 class="grp"><?= $e($cv) ?><?php if ($pcatNotes[$ck] !== ''): ?> <small class="category-note"><?= $e($pcatNotes[$ck]) ?></small><?php endif; ?></h3>
    <div class="grid product-grid">
    <?php foreach ($categoryProducts as $x): ?>
      <a class="card product-card" href="?p=detail&id=<?= $e($x['slug']) ?>">
        <div class="product-card-head">
          <span class="product-brand"><?= $e($x['brand'] ?? 'BOLIN') ?></span>
          <span class="product-model"><?= $e($x['model'] ?? $x['slug']) ?></span>
        </div>
        <div class="thumb pimg product-card-image"><img src="<?= $e($img($x['gallery'][0] ?? '')) ?>" alt="<?= $e($x['name']) ?>" loading="lazy"></div>
        <div class="product-card-specs">
          <?php foreach (($x['card_specs'] ?? []) as $specLine): ?>
            <span><?= $e($specLine) ?></span>
          <?php endforeach; ?>
        </div>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($q !== '' && !$filteredProducts): ?><p class="search-empty">검색 결과가 없습니다. 다른 검색어를 입력해 주세요.</p><?php endif; ?>
</div></section>

<?php elseif ($p === 'detail'): ?>
<div class="sub"><div class="title"><h1><?= $e($prd['name']) ?></h1></div></div>
<section><div class="in" style="max-width:900px">
  <p class="crumb"><a href="?p=product">Product</a> &gt; <a href="?p=product&cat=<?= urlencode($prd['cat']) ?>#product-categories"><?= $e($pcats[$prd['cat']]) ?></a> &gt; <?= $e($prd['name']) ?></p>
  <?php if ($prd['gallery']): ?>
  <div class="gal">
    <img id="main-img" src="<?= $e($img($prd['gallery'][0])) ?>" alt="<?= $e($prd['name']) ?>">
    <?php if (count($prd['gallery']) > 1): ?>
    <div class="thumbs">
      <?php foreach ($prd['gallery'] as $g): ?>
        <img src="<?= $e($img($g)) ?>" alt="" onclick="document.getElementById('main-img').src=this.src">
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($prd['info'])): ?>
    <table class="info"><?php foreach ($prd['info'] as $r): ?><tr><th><?= $e($r[0]) ?></th><td><?= $e($r[1]) ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
  <?php if (!empty($prd['summary'])): ?>
    <div class="product-intro">
      <p class="product-series"><?= $e($prd['series'] ?? 'BOLIN') ?></p>
      <p><?= $e($prd['summary']) ?></p>
      <?php if (!empty($prd['features'])): ?>
        <ul class="product-features"><?php foreach ($prd['features'] as $feature): ?><li><?= $e($feature) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($prd['detail_sections'])): ?>
    <div class="full-product-details">
      <h2 class="h">상세 제품 정보</h2>
      <?php foreach ($prd['detail_sections'] as $detailSection): ?>
        <article class="detail-section">
          <h3><?= $e($detailSection['title']) ?></h3>
          <?php if (!empty($detailSection['text'])): ?><p><?= $e($detailSection['text']) ?></p><?php endif; ?>
          <?php if (!empty($detailSection['items'])): ?>
            <ul><?php foreach ($detailSection['items'] as $item): ?><li><?= $e($item) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if (!empty($detailSection['images'])): ?>
            <div class="detail-media">
              <?php foreach ($detailSection['images'] as $image): ?>
                <figure>
                  <img src="<?= $e($img($image['src'])) ?>" alt="<?= $e($image['caption']) ?>" loading="lazy">
                  <figcaption><?= $e($image['caption']) ?></figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($prd['spec']): ?>
    <div class="full-specifications">
      <h2 class="h">상세 사양</h2>
      <?php foreach ($prd['spec'] as $group): ?>
        <div class="spec-table-wrap">
          <table class="spec-table">
            <caption><?= $e($group['title']) ?></caption>
            <tbody>
              <?php foreach ($group['rows'] as $row): ?>
                <tr><th><?= $e($row[0]) ?></th><td><?= nl2br($e($row[1]), false) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
      <?php if (!empty($prd['spec_note'])): ?><p class="spec-note"><?= $e($prd['spec_note']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>
  <p style="text-align:center;margin-top:40px"><a href="?p=demo"><button type="button" style="padding:14px 40px">데모 요청</button></a></p>
  <?php
  $showSource = !empty($prd['source']) && ($prd['source_label'] ?? 'BOLIN 한국 총판 플럼 코리아') !== 'BOLIN 한국 총판 플럼 코리아';
  $showSpecSource = !empty($prd['spec_source']) && (!$showSource || $prd['spec_source'] !== $prd['source']);
  ?>
  <?php if ($showSource || $showSpecSource): ?>
    <p class="note">제품 정보 출처: <?php if ($showSource): ?><a href="<?= $e($prd['source']) ?>" target="_blank" rel="noopener"><?= $e($prd['source_label']) ?></a><?php endif; ?><?php if ($showSource && $showSpecSource): ?> · <?php endif; ?><?php if ($showSpecSource): ?><a href="<?= $e($prd['spec_source']) ?>" target="_blank" rel="noopener">BOLIN 공식 사양</a><?php endif; ?></p>
  <?php endif; ?>
</div></section>

<?php elseif ($p === 'pds'): ?>
<div class="sub"><div class="title"><h1>자료실</h1></div></div>
<section><div class="in" style="max-width:900px">
  <div style="border-top:2px solid #222;border-bottom:1px solid #ddd;padding:18px 10px">
    <small style="color:#e60012"><?= $e($pdsCats[$view['cat']]) ?></small>
    <h3 class="post-title"><?= $e($view['title']) ?></h3>
  </div>
  <div style="padding:40px 10px;min-height:160px;border-bottom:1px solid #ddd">
    <?php if ($view['url']): ?>
      <p><?= $e($view['title']) ?> 파일을 다운로드할 수 있습니다.</p>
      <p style="margin-top:20px"><a href="?dl=<?= $e($_GET['id']) ?>" style="display:inline-block;padding:12px 30px;background:#e60012;color:#fff;border-radius:4px"><?= $e($view['label'] ?? 'PDF 다운로드') ?></a></p>
    <?php else: ?>
      <p>등록된 파일이 없습니다.</p>
    <?php endif; ?>
  </div>
  <p style="text-align:center;margin-top:30px"><a href="?p=support&cat=<?= $e($view['cat']) ?>" style="display:inline-block;padding:12px 30px;border:1px solid #888;border-radius:4px">목록</a></p>
</div></section>

<?php elseif ($p === 'support'): ?>
<div class="sub"><div class="title"><h1>고객지원</h1></div></div>
<section style="display:none;"><div class="in">
  <h2 class="h">자료실</h2><p class="lead">매뉴얼 및 설치 도구</p>
  <div style="display:flex;gap:8px;justify-content:center;margin-bottom:20px;flex-wrap:wrap">
    <?php foreach (['' => '전체'] + $pdsCats as $k => $v): ?>
      <a href="?p=support&cat=<?= urlencode($k) ?>&q=<?= urlencode($q) ?>" style="padding:8px 18px;border:1px solid <?= $k === $cat ? '#e60012' : '#ccc' ?>;border-radius:20px;<?= $k === $cat ? 'background:#e60012;color:#fff' : '' ?>"><?= $e($v) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="" style="display:flex;gap:8px;margin:0 auto 20px;max-width:560px">
    <input type="hidden" name="p" value="support"><input type="hidden" name="cat" value="<?= $e($cat) ?>">
    <input name="q" value="<?= $e($q) ?>" placeholder="제목 검색">
    <button style="white-space:nowrap;padding:0 24px">검색</button>
  </form>
  <table>
    <tr><th style="width:110px">분류</th><th>제목</th></tr>
    <?php if (!$pdsList): ?><tr><td colspan="2" style="text-align:center;color:#888">BOLIN 제품 자료를 준비하고 있습니다.</td></tr><?php endif; ?>
    <?php foreach ($pdsList as $id => $it): ?>
      <tr><td><?= $e($pdsCats[$it['cat']]) ?></td><td><?php if ($it['url']): ?><a href="?p=pds&id=<?= $e($id) ?>"><?= $e($it['title']) ?></a><?php else: ?><?= $e($it['title']) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pdsList): ?><tr><td colspan="2" style="text-align:center;color:#888">검색 결과가 없습니다.</td></tr><?php endif; ?>
  </table>
</div></section>
<section class="gray"><div class="in">
  <h2 class="h" id="consult">견적문의</h2><p class="lead">&nbsp;</p>
  <?php if ($sent): ?><div class="ok">문의가 접수되었습니다.</div><?php endif; ?>
  <?php if ($consultError !== ''): ?><div role="alert" style="padding:12px;margin:12px 0;color:#9b1c1c;background:#fff2f2;border:1px solid #e6b8b8"><?= $e($consultError) ?></div><?php endif; ?>
  <form method="post" action="?p=support#consult">
    <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
    <div aria-hidden="true" style="position:absolute;left:-10000px"><label>웹사이트<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <input name="name" placeholder="이름" value="<?= $e($consultError !== '' ? (string)($_POST['name'] ?? '') : '') ?>" required>
    <input name="tel" placeholder="연락처" value="<?= $e($consultError !== '' ? (string)($_POST['tel'] ?? '') : '') ?>" required>
    <textarea name="msg" rows="5" placeholder="문의 내용" required><?= $e($consultError !== '' ? (string)($_POST['msg'] ?? '') : '') ?></textarea>
    <?php if ($consultCaptcha !== '') echo $captchaLabel($consultCaptcha); ?>
    <button>신청 하기</button>
  </form>
</div></section>
<section><div class="in" style="max-width:900px">
  <h2 class="h" id="inquiry">기술문의</h2>
  <p class="lead">모든 게시글은 비밀글로 등록되며 작성자와 관리자만 내용을 확인할 수 있습니다.</p>
  <?php if ($boardError !== ''): ?><div role="alert" style="padding:12px;margin:12px 0;color:#9b1c1c;background:#fff2f2;border:1px solid #e6b8b8"><?= $e($boardError) ?></div><?php endif; ?>
  <?php if ($boardNotice !== ''): ?><div role="status" style="padding:12px;margin:12px 0;border:1px solid #ddd"><?= $e($boardNotice) ?></div><?php endif; ?>
  <?php if (!$boardError && !$boardRateLimitReady && $boardPostId > 0): ?>
    <p role="alert" class="note">게시판 보안 설정이 완료되지 않아 비밀글을 확인할 수 없습니다.</p>
  <?php elseif (!$boardError && !$boardPost && $boardPostId > 0): ?>
    <form method="post" action="?p=support&amp;post=<?= $boardPostId ?>#inquiry" style="max-width:620px;margin:0 auto 30px">
      <input type="hidden" name="board_action" value="view">
      <input type="hidden" name="post_id" value="<?= $boardPostId ?>">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <label>비밀번호<input type="password" name="password" autocomplete="current-password" required></label>
      <button>비밀글 확인</button>
    </form>
  <?php endif; ?>
  <?php if ($boardPost): ?>
    <div style="border-top:2px solid #222;border-bottom:1px solid #ddd;padding:18px 10px">
      <small><?= $e($boardPost['category']) ?> · <?= $e($boardPost['created_at']) ?></small>
      <h3 class="post-title"><?= $e($boardPost['title']) ?></h3>
      <p>작성자: <?= $e($boardPost['author']) ?> · 상품: <?= $e($boardPost['product']) ?></p>
      <p>휴대폰: <?= $e($boardPost['phone']) ?> · 이메일: <?= $e($boardPost['email']) ?></p>
    </div>
    <div style="padding:24px 10px;white-space:pre-wrap;border-bottom:1px solid #ddd"><?= $e($boardPost['body']) ?></div>
    <?php if (!empty($boardPost['answer'])): ?>
      <div style="padding:24px 10px;background:#f7f7f7;white-space:pre-wrap"><strong>관리자 답변</strong><br><br><?= $e($boardPost['answer']) ?></div>
    <?php else: ?>
      <p style="padding:16px 10px;color:#777">아직 등록된 답변이 없습니다.</p>
    <?php endif; ?>
    <form method="post" action="?p=support#inquiry" id="delForm" style="margin:30px 0 0;text-align:right">
      <input type="hidden" name="board_action" value="delete">
      <input type="hidden" name="post_id" value="<?= (int)$boardPost['id'] ?>">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <button type="button" id="btnDel" aria-label="게시글 삭제"><span aria-hidden="true">🗑</span> 삭제하기</button>
      <span id="delAsk" hidden>
        <strong>삭제하시겠습니까?</strong>
        <button type="submit">네</button>
        <button type="button" id="btnDelNo">아니요</button>
      </span>
    </form>
    <script>
    (function () {
      var b = document.getElementById('btnDel'), a = document.getElementById('delAsk');
      b.addEventListener('click', function () { b.hidden = true; a.hidden = false; });
      document.getElementById('btnDelNo').addEventListener('click', function () { a.hidden = true; b.hidden = false; });
    })();
    </script>    <h3 style="margin:30px 0 15px">게시글 수정</h3>
    <form method="post" action="?p=support#inquiry" style="max-width:700px;margin:0 auto">
      <input type="hidden" name="board_action" value="edit">
      <input type="hidden" name="post_id" value="<?= (int)$boardPost['id'] ?>">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <select name="category" required>
        <?php foreach (['종교시설', '교육기관', '학원강의', '회의병원', '스튜디오', '야외경기'] as $category): ?>
          <option value="<?= $e($category) ?>" <?= $category === $boardPost['category'] ? 'selected' : '' ?>><?= $e($category) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="author" value="<?= $e($boardPost['author']) ?>" minlength="2" maxlength="20" pattern="[가-힣]{2,20}" title="한글 2~20자로 입력해 주세요." placeholder="작성자 (한글 2~20자)" aria-describedby="edit-author-error" required>
      <span id="edit-author-error" role="status" style="color:#ff8585;font-size:.9rem" hidden>한글 2~20자로 입력해 주세요.</span>
      <input name="password" type="password" autocomplete="current-password" placeholder="현재 비밀번호" required>
      <input name="new_password" type="password" autocomplete="new-password" placeholder="새 비밀번호 (변경 시에만 입력)">
      <select name="product" required>
        <?php foreach ($products as $product): ?>
          <option value="<?= $e($product['name']) ?>" <?= $product['name'] === $boardPost['product'] ? 'selected' : '' ?>><?= $e($product['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="phone" value="<?= $e($boardPost['phone']) ?>" maxlength="24" placeholder="휴대폰번호" required>
      <input name="email" type="email" value="<?= $e($boardPost['email']) ?>" maxlength="254" placeholder="이메일">
      <input name="title" value="<?= $e($boardPost['title']) ?>" maxlength="180" placeholder="제목" required>
      <textarea name="body" rows="8" maxlength="10000" placeholder="본문" required><?= $e($boardPost['body']) ?></textarea>
      <button>게시글 수정</button>
    </form>
  <?php endif; ?>
  <?php $boardFormOpen = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['board_action'] ?? '') === 'create' && $boardNotice !== ''; ?>
  <p style="text-align:right;margin:30px 0 15px"><button type="button" id="btnWrite" aria-expanded="<?= $boardFormOpen ? 'true' : 'false' ?>" aria-controls="writeBox" style="padding:12px 30px">문의글 작성</button></p>
  <div id="writeBox" <?= $boardFormOpen ? '' : 'hidden' ?>>
  <?php if (!$boardCanPost): ?>
    <p class="note">국내 접속 확인이 되지 않아 작성 및 수정이 제한됩니다. (해외 IP이거나 Cloudflare를 경유하지 않은 접속)</p>
  <?php elseif (!$boardError && !$boardRateLimitReady): ?>
    <p role="alert" class="note">게시판 보안 설정이 완료되지 않아 작성할 수 없습니다.</p>
  <?php elseif (!$boardError): ?>
    <form method="post" action="?p=support#inquiry" style="max-width:700px;margin:0 auto">
      <input type="hidden" name="board_action" value="create">
      <?php $keep = fn(string $k) => $boardFormOpen ? $e((string)($_POST[$k] ?? '')) : ''; ?>
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <div aria-hidden="true" style="position:absolute;left:-10000px"><label>웹사이트<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <select name="category" required>
        <option value="">말머리 선택</option>
        <?php foreach (['종교시설', '교육기관', '학원강의', '회의병원', '스튜디오', '야외경기'] as $category): ?>
          <option value="<?= $e($category) ?>" <?= $keep('category') === $e($category) ? 'selected' : '' ?>><?= $e($category) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="author" minlength="2" maxlength="20" pattern="[가-힣]{2,20}" title="한글 2~20자로 입력해 주세요." placeholder="작성자 (한글 2~20자)" value="<?= $keep('author') ?>" aria-describedby="create-author-error" required>
      <span id="create-author-error" role="status" style="color:#ff8585;font-size:.9rem" hidden>한글 2~20자로 입력해 주세요.</span>
      <input name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="비밀번호 (8자 이상)" required>
      <select name="product" required>
        <option value="">상품선택</option>
        <?php foreach ($products as $product): ?>
          <option value="<?= $e($product['name']) ?>" <?= $keep('product') === $e($product['name']) ? 'selected' : '' ?>><?= $e($product['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="phone" maxlength="24" placeholder="휴대폰번호" value="<?= $keep('phone') ?>" required>
      <input name="email" type="email" maxlength="254" placeholder="이메일" value="<?= $keep('email') ?>">
      <input name="title" maxlength="180" placeholder="제목" value="<?= $keep('title') ?>" required>
      <textarea name="body" rows="8" maxlength="10000" placeholder="본문" required><?= $keep('body') ?></textarea>
      <?= $captchaLabel($captchaText['inquiry'] ?? '') ?>
      <button>비밀글 등록</button>
    </form>
  <?php endif; ?>
  </div>
  <script>
  (function () {
    var b = document.getElementById('btnWrite'), box = document.getElementById('writeBox');
    b.addEventListener('click', function () {
      var open = box.hasAttribute('hidden');
      box.toggleAttribute('hidden', !open);
      b.setAttribute('aria-expanded', open);
    });

    document.querySelectorAll('input[name="author"][pattern]').forEach(function (input) {
      var error = document.getElementById(input.getAttribute('aria-describedby'));
      var validate = function (showEmpty) {
        var invalid = (input.value !== '' || showEmpty) && !/^[가-힣]{2,20}$/u.test(input.value);
        input.setCustomValidity(invalid ? error.textContent : '');
        input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
        error.hidden = !invalid;
      };
      input.addEventListener('input', function (event) {
        if (!event.isComposing) validate(false);
      });
      input.addEventListener('compositionend', function () { validate(false); });
      input.addEventListener('invalid', function () { validate(true); });
      validate(false);
    });
  })();
  </script>
  <h3 style="margin:40px 0 15px">문의글 목록</h3>
  <table>
    <tr><th style="width:110px">말머리</th><th>제목</th><th style="width:110px">작성자</th></tr>
    <?php foreach ($boardRows as $row): ?>
      <tr>
        <td><?= $e($row['category']) ?></td>
        <td><a href="?p=support&amp;post=<?= (int)$row['id'] ?>#inquiry" style="text-decoration:underline"><span aria-hidden="true">🔒</span> <span class="sr-only" style="position:absolute;left:-10000px">비밀글</span><?= $e($row['title']) ?></a></td>
        <td><?= $e(canonBoardMaskName((string)$row['author'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$boardError && !$boardRows): ?><tr><td colspan="3" style="text-align:center;color:#888">등록된 문의글이 없습니다.</td></tr><?php endif; ?>
  </table>
</div></section>

<?php elseif ($p === 'about'): ?>
<div class="sub"><div class="title"><h1>회사소개</h1></div></div>
<section><div class="in" style="max-width:1200px">


<div class="wero">
  

<section id="about">
  <div class="split">
    <div>
      
    <img width="100%" src="../b-pl-img/fw.png">
    </div>
    <div>
      <h2>미디어 장비 한 분야에 집중합니다</h2>
      <p class="wead"><br>
플럼코리아는 미국 BOLIN Technology의 한국 공식 총판으로, 전문 영상·방송 장비 분야에 집중하며 고객에게 최적의 솔루션을 제공하고 있습니다.<br>
단순히 제품을 공급하는 데 그치지 않고, 고객의 환경과 목적에 맞는 장비 제안부터 설치, 운영, 사후관리까지 함께하며 실질적인 도움이 되는 파트너가 되고자 합니다.<br>
빠르게 변화하는 미디어 환경 속에서도 끊임없는 기술 연구와 솔루션 개발을 통해 한발 앞선 서비스를 제공하고, 고객이 보다 안정적이고 효율적인 시스템을 구축할 수 있도록 전문적인 기술 지원과 맞춤형 컨설팅을 이어가겠습니다.<br>
무엇보다 제품을 판매하는 순간보다 판매 이후의 과정까지 중요하게 생각하며, 고객이 장비를 사용하는 모든 순간에 믿고 의지할 수 있는 기업이 되기 위해 노력하겠습니다.<br>
말뿐인 약속이 아닌 꾸준한 실천으로 신뢰를 쌓아가며 언제나 고객의 목소리에 귀 기울이며, 한 분 한 분과의 소중한 인연을 오래도록 이어가겠습니다.<br>
<br>
감사합니다.
      
    </div>
  </div>
</section>



</div>

</div></section>

<?php elseif ($p === 'way'): ?>
<div class="sub"><div class="title"><h1>오시는길</h1></div></div>
<section><div class="in">
  <p class="eyebrow">LOCATION</p>
  <h2 class="way-h">오시는길</h2>
  <iframe class="map" title="오시는길" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
    src="https://maps.google.com/maps?q=<?= urlencode('서울특별시 강서구 양천로 400-12') ?>&z=16&output=embed"></iframe>
  <table class="way-tb">
    <tr><th>주소</th><td>서울특별시 강서구 양천로 400-12 (더리브골드타워) 1114호</td></tr>
    <tr><th>대표번호</th><td>070-8866-1171</td></tr>
    <tr><th>메일</th><td>plumkorea1171@gmail.com</td></tr>
  </table>
</div></section>

<?php elseif ($p === 'demo'): ?>
<div class="sub"><div class="title"><h1>데모요청</h1></div></div>
<section><div class="in">
  <?php if ($sent): ?><div class="ok">데모 요청이 접수되었습니다.</div><?php endif; ?>
  <?php if ($consultError !== ''): ?><div role="alert" style="padding:12px;margin:12px 0;color:#9b1c1c;background:#fff2f2;border:1px solid #e6b8b8"><?= $e($consultError) ?></div><?php endif; ?>
  <?php $dk = fn(string $k) => $consultError !== '' ? $e((string)($_POST[$k] ?? '')) : ''; ?>
  <form method="post" action="?p=demo">
    <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
    <div aria-hidden="true" style="position:absolute;left:-10000px"><label>웹사이트<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <input name="name" placeholder="이름 / 회사명" value="<?= $dk('name') ?>" required>
    <input name="tel" placeholder="연락처" value="<?= $dk('tel') ?>" required>
    <select name="model"><?php foreach ($products as $x): ?><option <?= $dk('model') === $e($x['name']) ? 'selected' : '' ?>><?= $e($x['name']) ?></option><?php endforeach; ?></select>
    <textarea name="msg" rows="5" placeholder="요청 사항"><?= $dk('msg') ?></textarea>
    <?= $captchaLabel($captchaText['demo'] ?? '') ?>
    <button>데모 요청</button>
  </form>
</div></section>
<?php elseif ($p === 'inquiry'): ?>
<div class="sub"><div class="title"><h1>문의 관리자</h1></div></div>
<section><div class="in" style="max-width:1000px">
  <?php if ($boardError !== ''): ?><div role="alert" style="padding:12px;margin:12px 0;color:#9b1c1c;background:#fff2f2;border:1px solid #e6b8b8"><?= $e($boardError) ?></div><?php endif; ?>
  <?php if ($boardNotice !== ''): ?><div role="status" style="padding:12px;margin:12px 0;border:1px solid #ddd"><?= $e($boardNotice) ?></div><?php endif; ?>
  <?php if (!$boardAdminLoggedIn && !$boardRateLimitReady && !$boardError): ?>
    <p role="alert" class="note">로그인 보안 설정이 완료되지 않았습니다.</p>
  <?php elseif (!$boardAdminLoggedIn): ?>
    <form method="post" action="?p=inquiry" style="max-width:480px;margin:0 auto">
      <input type="hidden" name="board_action" value="admin_login">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <input type="password" name="admin_password" autocomplete="current-password" placeholder="관리자 비밀번호" required>
      <button>관리자 로그인</button>
    </form>
  <?php else: ?>
    <form method="post" action="?p=inquiry" style="text-align:right">
      <input type="hidden" name="board_action" value="admin_logout">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
      <button>로그아웃</button>
    </form>
    <?php if (!$boardError && !$boardRows): ?><p>등록된 문의글이 없습니다.</p><?php endif; ?>
    <?php foreach ($boardRows as $row): ?>
      <article style="border:1px solid #ddd;padding:20px;margin:18px 0">
        <p><strong>#<?= (int)$row['id'] ?> · <?= $e($row['category']) ?> · <?= $e($row['created_at']) ?></strong><?php if (!empty($row['deleted_at'])): ?> <span style="color:#e60012">· 작성자 삭제됨 (<?= $e($row['deleted_at']) ?>)</span><?php endif; ?></p>
        <h3><?= $e($row['title']) ?></h3>
        <p>작성자: <?= $e($row['author']) ?> · 상품: <?= $e($row['product']) ?></p>
        <p>휴대폰: <?= $e($row['phone']) ?> · 이메일: <?= $e($row['email']) ?></p>
        <div style="white-space:pre-wrap;padding:15px 0"><?= $e($row['body']) ?></div>
        <form method="post" action="?p=inquiry">
          <input type="hidden" name="board_action" value="admin_reply">
          <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
          <input type="hidden" name="post_id" value="<?= (int)$row['id'] ?>">
          <textarea name="answer" rows="5" maxlength="10000" placeholder="관리자 답변" required><?= $e($row['answer'] ?? '') ?></textarea>
          <button>답변 저장</button>
        </form>
        <?php if (empty($row['deleted_at'])): ?>
          <form method="post" action="?p=inquiry" class="admin-delete-form" style="margin-top:10px;text-align:right">
            <input type="hidden" name="board_action" value="admin_delete">
            <input type="hidden" name="csrf" value="<?= $e($_SESSION['canon_board_csrf'] ?? '') ?>">
            <input type="hidden" name="post_id" value="<?= (int)$row['id'] ?>">
            <button type="submit" style="background:#b42318">문의 삭제</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <script>
      document.querySelectorAll('.admin-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
          if (!window.confirm('이 문의글을 삭제하시겠습니까?')) event.preventDefault();
        });
      });
    </script>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<footer>
  <div class="footer-main">
    <div class="footer-links">
      <ul>
        <a href="?p=about">회사소개</a>
        <a href="?p=way">오시는길</a>
        <a href="?p=demo">데모요청</a>
        <a href="?p=support#inquiry">기술문의</a>
        <a href="?p=support#consult">견적문의</a>
        <a href="?p=support">고객지원</a>
      </ul>
    </div>
    <div class="footer-grid in">
      <div class="footer-logo">
        <img class="f-logo" src="../b-pl-img/logo_on.png" alt="PLUM 플럼 코리아">
      </div>
      <div class="footer-column footer-company">
        <ul class="f-info">
          <li><span>플럼 코리아</span></li>
          <li><span><b>주소</b> : 서울특별시 강서구 양천로 400-12 (더리브골드타워) 1114호</span></li>
        </ul>
      </div>
      <div class="footer-column footer-business">
        <ul class="f-info">
          <li><span><b>사업자등록번호</b> : 181-07-00410</span></li>
          <li><span><b>통신판매업신고</b> : 2020-서울강서-2732</span></li>
        </ul>
      </div>
      <div class="footer-column footer-contact">
        <ul class="f-info">
          <li><span><b>대표번호</b> : 070-8866-1171</span></li>
          <li><span><b>개인정보관리자</b> : 서정우</span></li>
          <li><span><b>메일</b> : plumkorea1171@gmail.com</span></li>
        </ul>
      </div>
      <div class="footer-column footer-hours">
        <ul class="f-info">
          <li><span><b>영업시간</b> : 오전 10시 ~ 오후 7시 (토/일/공휴일 휴무)</span></li>
          <li><span><b>점심식사</b> : 오후 1시 ~ 2시</span></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="in">
      <p class="copy">COPYRIGHT(C) 플럼 코리아 ALL RIGHT RESERVED.</p>
    </div>
  </div>
</footer>
</body>
</html>