<?php
/**
 * Sert les photos et documentations stockées hors de la racine publique (data/files).
 * Les fichiers des propositions en attente ne sont visibles que par un administrateur.
 */
require __DIR__ . '/lib/bootstrap.php';

$type = $_GET['t'] ?? '';
$id = (int) ($_GET['id'] ?? 0);

$map = [
    'photo' => ['SELECT file, mime FROM photos WHERE id = ?', 'photos', false],
    'doc' => ['SELECT file, original_name, title FROM documents WHERE id = ?', 'docs', false],
    'sub-photo' => ['SELECT photo_file AS file, photo_mime AS mime FROM submissions WHERE id = ?', 'photos', true],
    'sub-doc' => ['SELECT doc_file AS file, doc_original_name AS original_name, doc_title AS title FROM submissions WHERE id = ?', 'docs', true],
];
if (!isset($map[$type])) {
    http_response_code(404);
    exit;
}
[$sql, $sub, $adminOnly] = $map[$type];
if ($adminOnly && !is_admin()) {
    http_response_code(403);
    exit;
}
$st = db()->prepare($sql);
$st->execute([$id]);
$row = $st->fetch();
$path = $row && $row['file'] ? FILES_DIR . "/$sub/" . basename($row['file']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
if ($sub === 'docs') {
    $name = $row['original_name'] ?: (($row['title'] ?: 'documentation') . '.pdf');
    $name = preg_replace('/[^\w.\- ]+/u', '_', $name);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
} else {
    header('Content-Type: ' . (array_key_exists($row['mime'], PHOTO_TYPES) ? $row['mime'] : 'application/octet-stream'));
}
header('Content-Length: ' . filesize($path));
readfile($path);
