<?php
/**
 * Point d'entrée commun : configuration, base SQLite, session, helpers.
 */
declare(strict_types=1);

const APP_NAME = 'Reconnaissance produit';
const EMBEDDING_DIM = 1280;           // Sortie MobileNet v2 (AvgPool)
const MAX_PHOTO_BYTES = 8 * 1024 * 1024;
const MAX_DOC_BYTES = 30 * 1024 * 1024;
const DEFAULT_THRESHOLD = 0.80;       // Similarité cosinus minimale pour accepter une reconnaissance

define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('FILES_DIR', DATA_DIR . '/files');

// Surcharges locales éventuelles (non versionnées).
if (is_file(DATA_DIR . '/config.local.php')) {
    require DATA_DIR . '/config.local.php';
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_name('prsess');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    foreach ([DATA_DIR, FILES_DIR, FILES_DIR . '/photos', FILES_DIR . '/docs'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            reference TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS photos (
            id INTEGER PRIMARY KEY,
            model_id INTEGER NOT NULL REFERENCES models(id) ON DELETE CASCADE,
            file TEXT NOT NULL,
            mime TEXT NOT NULL,
            embedding BLOB,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS photos_model ON photos(model_id);
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY,
            model_id INTEGER NOT NULL REFERENCES models(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            file TEXT,
            original_name TEXT,
            url TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS documents_model ON documents(model_id);
        CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY,
            kind TEXT NOT NULL,                 -- 'identification' ou 'documentation'
            model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
            proposed_model TEXT NOT NULL DEFAULT '',
            photo_file TEXT,
            photo_mime TEXT,
            embedding BLOB,
            doc_file TEXT,
            doc_original_name TEXT,
            doc_title TEXT NOT NULL DEFAULT '',
            doc_url TEXT,
            comment TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending', -- pending | accepted | rejected
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    SQL);
}

/** Exécute une requête en typant les paramètres (les binaires, ex. empreintes, en BLOB). */
function run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        $type = match (true) {
            $v === null => PDO::PARAM_NULL,
            is_int($v) => PDO::PARAM_INT,
            is_string($v) && str_contains($v, "\0") => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
        $st->bindValue($i + 1, $v, $type);
    }
    $st->execute();
    return $st;
}

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function threshold(): float
{
    return (float) setting('threshold', (string) DEFAULT_THRESHOLD);
}

/* ---------- Helpers HTML / HTTP ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL de base de l'application (gère l'installation dans un sous-dossier). */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = rtrim(dirname($script), '/');
        if (basename($dir) === 'admin') {
            $dir = rtrim(dirname($dir), '/');
        }
        $base = $dir . '/';
    }
    return $base;
}

function redirect(string $path): never
{
    header('Location: ' . base_url() . ltrim($path, '/'));
    exit;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(403);
        exit('Jeton CSRF invalide. Rechargez la page.');
    }
}

/* ---------- Authentification ---------- */

function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('admin/login.php');
    }
}

/* ---------- Empreintes (embeddings) ---------- */

/** Décode une empreinte envoyée en base64 (Float32 little-endian) et la normalise. */
function decode_embedding(?string $b64): ?string
{
    if (!$b64) {
        return null;
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) !== EMBEDDING_DIM * 4) {
        return null;
    }
    $v = array_values(unpack('g*', $bin));
    $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
    if (!is_finite($norm) || $norm <= 0) {
        return null;
    }
    return pack('g*', ...array_map(fn($x) => $x / $norm, $v));
}

function embedding_to_array(string $bin): array
{
    return array_values(unpack('g*', $bin));
}

/**
 * Compare une empreinte à toutes les photos de référence.
 * Retourne les modèles triés par meilleure similarité (plus proche voisin).
 */
function rank_models(string $query): array
{
    $q = embedding_to_array($query);
    $best = [];
    $rows = db()->query('SELECT model_id, embedding FROM photos WHERE embedding IS NOT NULL');
    foreach ($rows as $row) {
        $v = unpack('g*', $row['embedding']);
        $dot = 0.0;
        $i = 0;
        foreach ($v as $x) {
            $dot += $x * $q[$i++];
        }
        $mid = (int) $row['model_id'];
        if (!isset($best[$mid]) || $dot > $best[$mid]) {
            $best[$mid] = $dot;
        }
    }
    arsort($best);
    return $best;
}

/* ---------- Fichiers ---------- */

const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const DOC_TYPES = ['application/pdf' => 'pdf'];

/**
 * Enregistre un fichier téléversé dans data/files/<sub>/ après vérification
 * du type réel. Retourne [nom de fichier, mime] ou lance une exception.
 */
function store_upload(array $file, string $sub, array $types, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Échec du téléversement (code ' . ($file['error'] ?? '?') . ').');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Fichier trop volumineux (max ' . round($maxBytes / 1048576) . ' Mo).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$mime])) {
        throw new RuntimeException('Type de fichier non accepté (' . $mime . ').');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    db(); // garantit l'existence des dossiers
    if (!move_uploaded_file($file['tmp_name'], FILES_DIR . "/$sub/$name")) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    return [$name, $mime];
}

function delete_file(string $sub, ?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $name)) {
        @unlink(FILES_DIR . "/$sub/$name");
    }
}

function valid_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Lien de documentation invalide (http:// ou https:// attendu).');
    }
    return $url;
}

function photo_url(int $id): string
{
    return base_url() . 'file.php?t=photo&id=' . $id;
}

function doc_href(array $doc): string
{
    return $doc['url'] ?: base_url() . 'file.php?t=doc&id=' . $doc['id'];
}

function model_label(array $m): string
{
    return trim(($m['brand'] ? $m['brand'] . ' ' : '') . $m['name'] . ($m['reference'] ? ' (' . $m['reference'] . ')' : ''));
}
