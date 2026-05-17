<?php
session_start();
// =====================================================
// YSC Enterprise Cloud — Backend API
// =====================================================

// CORS & Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =====================================================
// Constants
// =====================================================
define('DATA_DIR', __DIR__ . '/ysc_data/');
define('FILES_DIR', DATA_DIR . 'files/');
define('STATE_FILE', DATA_DIR . 'state.json');
define('LOCK_FILE', DATA_DIR . 'state.lock');

// =====================================================
// Init directories
// =====================================================
foreach ([DATA_DIR, FILES_DIR, FILES_DIR . 'shared/'] as $d) {
    if (!is_dir($d))
        mkdir($d, 0755, true);
}

// =====================================================
// Default application state
// =====================================================
$defaultState = [
    'users' => [
        [
            'id' => 'u1',
            'username' => 'Yavuz',
            'password' => 'y.y.1453',
            'role' => 'admin',
            'quota' => -1,
            'used' => 0,
            'premium' => true,
            'avatar' => 'Y',
            'lang' => 'en',
        ],
        [
            'id' => 'u2',
            'username' => 'user',
            'password' => 'user',
            'role' => 'user',
            'quota' => 2048,
            'used' => 0,
            'premium' => false,
            'avatar' => 'U',
            'lang' => 'en',
        ],
    ],
    'files' => [],
    'folders' => [
        ['id' => 'shared', 'name' => 'Shared Folder', 'parentId' => null, 'userId' => null, 'shared' => true, 'icon' => '🤝', 'createdAt' => time()],
    ],
    'tasks' => [],
    'messages' => [],
    'announcements' => [],
    'activities' => [],
    'premiumRequests' => [],
    'hubApps' => [],
    'hubCardOverrides' => [],
    'hubCardFiles' => [],
    'settings' => [
        'siteTitle' => 'YSC Enterprise Cloud',
        'loginTitle' => 'YSC Enterprise',
        'loginSubtitle' => 'Secure File Management Platform',
        'maxFileSize' => 100,
        'defaultQuota' => 5120,
        'maintenance' => false,
        'apiEnabled' => true,
        'language' => 'en',
        'adInterval' => 30,
        'openCloudOnLogin' => false,
    ],
    'adSettings' => [],
    'apiKeys' => [],
];

// =====================================================
// Helpers
// =====================================================
function loadState(): array
{
    global $defaultState;
    if (!file_exists(STATE_FILE)) {
        file_put_contents(STATE_FILE, json_encode($defaultState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $defaultState;
    }
    $raw = file_get_contents(STATE_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data))
        $data = $defaultState;

    if (!isset($data['apiKeys'])) {
        $data['apiKeys'] = [
            [
                'id' => 'ak_master',
                'key' => 'YSC-MASTER-' . strtoupper(bin2hex(random_bytes(4))),
                'label' => 'System Master Key',
                'permissions' => ['read' => true, 'upload' => true, 'delete' => true],
                'createdAt' => time()
            ]
        ];
        file_put_contents(STATE_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $data;
}

function saveState(array $state): void
{
    $fp = fopen(LOCK_FILE, 'w');
    if (flock($fp, LOCK_EX)) {
        file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

define('VIRTUAL_FS_FILE', DATA_DIR . 'pass.yscec');
define('VIRTUAL_FS_LOCK', DATA_DIR . 'pass.yscec.lock');

function loadVirtualFS(): array
{
    if (!file_exists(VIRTUAL_FS_FILE)) {
        return [];
    }
    $raw = file_get_contents(VIRTUAL_FS_FILE);
    if (empty($raw)) {
        return [];
    }
    $decoded = unserialize($raw);
    return is_array($decoded) ? $decoded : [];
}

function saveVirtualFS(array $fs): void
{
    $fp = fopen(VIRTUAL_FS_LOCK, 'w');
    if (flock($fp, LOCK_EX)) {
        file_put_contents(VIRTUAL_FS_FILE, serialize($fs));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function respond(mixed $data, int $code = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function addActivity(array &$state, string $userId, string $action, string $target): void
{
    array_unshift($state['activities'], [
        'id' => 'a_' . uniqid(),
        'userId' => $userId,
        'action' => $action,
        'target' => $target,
        'createdAt' => time(),
    ]);
    if (count($state['activities']) > 200) {
        $state['activities'] = array_slice($state['activities'], 0, 200);
    }
}

function safeState(array $state): array
{
    $s = $state;
    foreach ($s['users'] as &$u) {
        unset($u['password']);
    }
    // PHP boş array [] ile JSON object {} karışıklığını önle
    // hubCardFiles ve hubCardOverrides her zaman JSON object {} olarak gönderilmeli
    if (empty($s['hubCardFiles']) || !is_array($s['hubCardFiles']) || array_is_list($s['hubCardFiles'] ?? [])) {
        $s['hubCardFiles'] = (object) [];
    }
    if (empty($s['hubCardOverrides']) || !is_array($s['hubCardOverrides']) || array_is_list($s['hubCardOverrides'] ?? [])) {
        $s['hubCardOverrides'] = (object) [];
    }
    unset($s['apiKeys']);
    return $s;
}

// PHP 8.1+ array_is_list yoksa fallback
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool
    {
        if ($arr === [])
            return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

// =====================================================
// Router
// =====================================================
$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// API Key Authentication check
$providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$currentApiKey = null;
if ($providedApiKey) {
    $apiState = loadState();
    if (empty($apiState['settings']['apiEnabled'])) {
        respond(['error' => 'API System is currently offline!'], 403);
    }
    $validKeys = $apiState['apiKeys'] ?? [];
    foreach ($validKeys as $k) {
        if (($k['key'] ?? '') === $providedApiKey) {
            $currentApiKey = $k;
            break;
        }
    }
    if (!$currentApiKey) {
        respond(['error' => 'Invalid API Key!'], 403);
    }
}

// Strict Lockdown Enforcement (API Key OR Session required)
$isLoggedIn = isset($_SESSION['userId']);
$publicActions = ['ping', 'login', 'get_public_state'];

if (!$currentApiKey && !$isLoggedIn && !in_array($action, $publicActions)) {
    respond(['error' => 'Unauthorized Access! A valid API Key or User Session is required.'], 403);
}

switch ($action) {

    // ─── Ping ──────────────────────────────────────
    case 'ping':
        respond(['ok' => true, 'ts' => time(), 'version' => '2.0']);

    // ─── Login ─────────────────────────────────────
    case 'login':
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $state = loadState();

        // Bakım modu: sadece admin kullanıcılar giriş yapabilir
        $isMaintenance = !empty($state['settings']['maintenance']) && $state['settings']['maintenance'] !== false && $state['settings']['maintenance'] !== 'false' && $state['settings']['maintenance'] !== 0;
        if ($isMaintenance) {
            $isAdmin = false;
            foreach ($state['users'] as $u) {
                if (strtolower($u['username']) === strtolower($username) && $u['password'] === $password) {
                    if (($u['role'] ?? '') === 'admin')
                        $isAdmin = true;
                    break;
                }
            }
            if (!$isAdmin) {
                respond(['success' => false, 'error' => 'Das System befindet sich im Wartungsmodus. Nur Administratoren können sich anmelden.'], 503);
            }
        }

        foreach ($state['users'] as $user) {
            if (strtolower($user['username']) === strtolower($username) && $user['password'] === $password) {
                $_SESSION['userId'] = $user['id'];
                addActivity($state, $user['id'], 'login', '');
                saveState($state);
                $safe = $user;
                unset($safe['password']);
                respond(['success' => true, 'user' => $safe, 'state' => safeState($state)]);
            }
        }
        respond(['success' => false, 'error' => 'Invalid username or password'], 401);

    // ─── Logout ────────────────────────────────────
    case 'logout':
        session_destroy();
        respond(['success' => true]);

    // ─── Get Public State ──────────────────────────
    case 'get_public_state':
        $st = loadState();
        respond([
            'settings' => [
                'siteTitle' => $st['settings']['siteTitle'] ?? 'YSC Enterprise Cloud',
                'loginTitle' => $st['settings']['loginTitle'] ?? 'YSC Enterprise',
                'loginSubtitle' => $st['settings']['loginSubtitle'] ?? 'Güvenli Dosya Yönetim Platformu',
                'language' => $st['settings']['language'] ?? 'tr'
            ]
        ]);

    // ─── Get State ─────────────────────────────────
    case 'get_state':
        if ($currentApiKey && empty($currentApiKey['permissions']['read'])) {
            respond(['error' => 'This API key does not have read permissions!'], 403);
        }
        respond(safeState(loadState()));

    // ─── Save State ────────────────────────────────
    // Passwords are never updated via this endpoint; use change_password.
    case 'save_state':
        if (empty($body))
            respond(['success' => false, 'error' => 'Empty body'], 400);
        $stored = loadState();

        // Preserve passwords for existing users; accept for new users
        if (!empty($body['users'])) {
            $pwMap = [];
            foreach ($stored['users'] as $su)
                $pwMap[$su['id']] = $su['password'];
            foreach ($body['users'] as &$nu) {
                if (isset($pwMap[$nu['id']])) {
                    $nu['password'] = $pwMap[$nu['id']]; // existing → keep stored
                } elseif (empty($nu['password'])) {
                    $nu['password'] = 'changeme'; // new user fallback
                }
            }
        }
        saveState($body);
        respond(['success' => true]);

    // ─── Change Password ────────────────────────────
    case 'change_password':
        $userId = $body['userId'] ?? '';
        $oldPw = $body['oldPw'] ?? '';
        $newPw = $body['newPw'] ?? '';
        $byAdmin = $body['byAdmin'] ?? false;
        $state = loadState();
        $found = false;
        foreach ($state['users'] as &$u) {
            if ($u['id'] === $userId) {
                if (!$byAdmin && $u['password'] !== $oldPw) {
                    respond(['success' => false, 'error' => 'Current password is incorrect'], 400);
                }
                $u['password'] = $newPw;
                $found = true;
                break;
            }
        }
        if (!$found)
            respond(['success' => false, 'error' => 'User not found'], 404);
        saveState($state);
        respond(['success' => true]);

    // ─── Upload File ────────────────────────────────
    case 'upload_file':
        if ($currentApiKey && empty($currentApiKey['permissions']['upload'])) {
            respond(['error' => 'This API key does not have upload permissions!'], 403);
        }
        if (!isset($_FILES['file']))
            respond(['success' => false, 'error' => 'File not found'], 400);
        $state = loadState();
        $userId = $_POST['userId'] ?? '';
        $folderId = $_POST['folderId'] ?? '';
        $shared = ($_POST['shared'] ?? '0') === '1';
        $file = $_FILES['file'];
        $maxBytes = ($state['settings']['maxFileSize'] ?? 100) * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK)
            respond(['success' => false, 'error' => 'Upload error: ' . $file['error']], 400);
        if ($file['size'] > $maxBytes)
            respond(['success' => false, 'error' => 'File too large! Max: ' . ($state['settings']['maxFileSize'] ?? 100) . ' MB'], 413);

        // Quota check
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['quota'] ?? -1) > 0) {
                $usedMB = (float) ($u['used'] ?? 0);
                $newMB = $file['size'] / (1024 * 1024);
                if ($usedMB + $newMB > $u['quota'])
                    respond(['success' => false, 'error' => 'Storage quota exceeded!'], 413);
            }
        }

        $fileId = 'f_' . uniqid();
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $storedName = $fileId . ($ext ? '.' . $ext : '');

        $fileData = file_get_contents($file['tmp_name']);
        $fs = loadVirtualFS();
        $fs[$storedName] = base64_encode($fileData);
        saveVirtualFS($fs);

        $sizeMB = round($file['size'] / (1024 * 1024), 4);
        $mime = $file['type'] ?: 'application/octet-stream';
        $record = [
            'id' => $fileId,
            'name' => $file['name'],
            'size' => $file['size'],
            'sizeMB' => $sizeMB,
            'type' => $mime,
            'storedName' => $storedName,
            'ownerKey' => 'pass.yscec',
            'userId' => $userId,
            'folderId' => $folderId,
            'shared' => $shared,
            'createdAt' => time(),
        ];

        $hubCard = $_POST['hubCard'] ?? '';
        if ($hubCard)
            $record['hubOnly'] = true;

        $state['files'][] = $record;
        foreach ($state['users'] as &$u) {
            if ($u['id'] === $userId) {
                $u['used'] = round((float) ($u['used'] ?? 0) + $sizeMB, 4);
            }
        }

        // hubCard dosyası ise state'e kaydet
        if ($hubCard) {
            if (!isset($state['hubCardFiles']) || !is_array($state['hubCardFiles']) || array_is_list($state['hubCardFiles'])) {
                $state['hubCardFiles'] = [];
            }
            if (!isset($state['hubCardFiles'][$hubCard]))
                $state['hubCardFiles'][$hubCard] = [];
            $state['hubCardFiles'][$hubCard][] = $fileId;
        }

        addActivity($state, $userId, 'upload', $file['name']);
        saveState($state);
        respond(['success' => true, 'file' => $record]);

    // ─── Serve / Download File ──────────────────────
    case 'serve_file':
    case 'download_file':
        $fileId = $_GET['fileId'] ?? '';
        $state = loadState();
        $fileRec = null;
        foreach ($state['files'] as $f) {
            if ($f['id'] === $fileId) {
                $fileRec = $f;
                break;
            }
        }
        if (!$fileRec) {
            http_response_code(404);
            exit('Not found');
        }

        $fs = loadVirtualFS();
        if (isset($fs[$fileRec['storedName']])) {
            $fileData = base64_decode($fs[$fileRec['storedName']]);
            $size = strlen($fileData);
        } else {
            // Fallback to legacy disk file
            $ownerKey = $fileRec['ownerKey'] ?? ($fileRec['shared'] ? 'shared' : $fileRec['userId']);
            $path = FILES_DIR . 'pass.yscec/' . $fileRec['storedName'];
            if (!file_exists($path)) {
                $path = FILES_DIR . $ownerKey . '/' . $fileRec['storedName'];
            }
            if (file_exists($path)) {
                $fileData = file_get_contents($path);
                $size = filesize($path);
            }
        }

        if (!isset($fileData)) {
            http_response_code(404);
            exit('File missing');
        }

        $mime = $fileRec['type'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        if ($action === 'download_file') {
            header('Content-Disposition: attachment; filename="' . rawurlencode($fileRec['name']) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . rawurlencode($fileRec['name']) . '"');
        }
        header('Content-Length: ' . $size);
        header('Cache-Control: private, max-age=86400');
        header('Accept-Ranges: bytes');
        echo $fileData;
        exit;

    // ─── Delete File ────────────────────────────────
    case 'delete_file':
        if ($currentApiKey && empty($currentApiKey['permissions']['delete'])) {
            respond(['error' => 'This API key does not have delete permissions!'], 403);
        }
        $fileId = $body['fileId'] ?? '';
        $state = loadState();
        foreach ($state['files'] as $i => $f) {
            if ($f['id'] === $fileId) {
                $fs = loadVirtualFS();
                if (isset($fs[$f['storedName']])) {
                    unset($fs[$f['storedName']]);
                    saveVirtualFS($fs);
                } else {
                    $ownerKey = $f['ownerKey'] ?? ($f['shared'] ? 'shared' : $f['userId']);
                    $path = FILES_DIR . 'pass.yscec/' . $f['storedName'];
                    if (!file_exists($path)) {
                        $path = FILES_DIR . $ownerKey . '/' . $f['storedName'];
                    }
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
                foreach ($state['users'] as &$u) {
                    if ($u['id'] === $f['userId']) {
                        $u['used'] = max(0, round((float) ($u['used'] ?? 0) - (float) ($f['sizeMB'] ?? 0), 4));
                    }
                }
                addActivity($state, $f['userId'], 'delete', $f['name']);
                array_splice($state['files'], $i, 1);
                break;
            }
        }
        saveState($state);
        respond(['success' => true, 'state' => safeState($state)]);

    // ─── Backup Export ──────────────────────────────
    case 'export_backup':
        $state = loadState();
        $safe = $state;
        // Strip passwords for safety? No — admin needs full backup. Keep as is.
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ysc_backup_' . date('Ymd_His') . '.json"');
        echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;

    // ─── Reset App (Admin Only) ──────────────────────
    case 'reset_app':
        $userId = $body['userId'] ?? $_GET['userId'] ?? '';
        $state = loadState();
        $isAdmin = false;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['role'] ?? '') === 'admin')
                $isAdmin = true;
        }
        if (!$isAdmin)
            respond(['success' => false, 'error' => 'Unauthorized access'], 403);

        // Delete all uploaded files
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FILES_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
        // Recreate base dirs
        foreach ([FILES_DIR, FILES_DIR . 'shared/'] as $d) {
            if (!is_dir($d))
                mkdir($d, 0755, true);
        }
        // Delete VFS pass.yscec file
        @unlink(VIRTUAL_FS_FILE);
        @unlink(VIRTUAL_FS_LOCK);

        // Write fresh default state
        file_put_contents(STATE_FILE, json_encode($defaultState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        respond(['success' => true]);

    // ─── Backup Import (Admin Only) ──────────────────
    case 'import_backup':
        $userId = $body['userId'] ?? $_GET['userId'] ?? '';
        $state = loadState();
        $isAdmin = false;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['role'] ?? '') === 'admin')
                $isAdmin = true;
        }
        if (!$isAdmin)
            respond(['success' => false, 'error' => 'Unauthorized access'], 403);

        if (empty($body))
            respond(['success' => false, 'error' => 'Invalid backup file'], 400);
        // Validate structure
        if (!isset($body['users']) || !isset($body['settings'])) {
            respond(['success' => false, 'error' => 'Backup file has an invalid format'], 400);
        }
        saveState($body);
        respond(['success' => true]);

    // ─── Get Stats ──────────────────────────────────
    case 'get_stats':
        $state = loadState();
        $totalStorage = 0;
        foreach ($state['files'] as $f)
            $totalStorage += (float) ($f['sizeMB'] ?? 0);
        respond([
            'users' => count($state['users']),
            'files' => count($state['files']),
            'tasks' => count($state['tasks']),
            'messages' => count($state['messages']),
            'announcements' => count($state['announcements']),
            'storageMB' => round($totalStorage, 2),
        ]);

    // ─── API Keys (Admin Only) ──────────────────────
    case 'get_api_keys':
        $userId = $body['userId'] ?? $_GET['userId'] ?? '';
        $state = loadState();
        $isAdmin = false;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['role'] ?? '') === 'admin')
                $isAdmin = true;
        }
        if (!$isAdmin)
            respond(['success' => false, 'error' => 'Unauthorized access'], 403);

        respond(['success' => true, 'apiKeys' => $state['apiKeys'] ?? []]);

    case 'generate_api_key':
        $userId = $body['userId'] ?? '';
        $label = $body['label'] ?? 'New API Key';
        $perms = $body['permissions'] ?? ['read' => true, 'upload' => false, 'delete' => false];
        $state = loadState();
        $isAdmin = false;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['role'] ?? '') === 'admin')
                $isAdmin = true;
        }
        if (!$isAdmin)
            respond(['success' => false, 'error' => 'Unauthorized access'], 403);

        $newKey = 'YSC-API-' . strtoupper(bin2hex(random_bytes(8)));
        if (!isset($state['apiKeys'])) {
            $state['apiKeys'] = [];
        }
        $state['apiKeys'][] = [
            'id' => 'ak_' . uniqid(),
            'key' => $newKey,
            'label' => $label,
            'permissions' => [
                'read' => (bool) ($perms['read'] ?? false),
                'upload' => (bool) ($perms['upload'] ?? false),
                'delete' => (bool) ($perms['delete'] ?? false)
            ],
            'createdAt' => time()
        ];
        saveState($state);
        respond(['success' => true, 'apiKey' => $newKey, 'apiKeys' => $state['apiKeys']]);

    case 'delete_api_key':
        $userId = $body['userId'] ?? '';
        $keyId = $body['keyId'] ?? '';
        $state = loadState();
        $isAdmin = false;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $userId && ($u['role'] ?? '') === 'admin')
                $isAdmin = true;
        }
        if (!$isAdmin)
            respond(['success' => false, 'error' => 'Unauthorized access'], 403);

        if (isset($state['apiKeys'])) {
            $state['apiKeys'] = array_filter($state['apiKeys'], function ($k) use ($keyId) {
                return $k['id'] !== $keyId;
            });
            $state['apiKeys'] = array_values($state['apiKeys']);
        }
        saveState($state);
        respond(['success' => true, 'apiKeys' => $state['apiKeys']]);

    default:
        respond(['error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}