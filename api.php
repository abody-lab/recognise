<?php
/**
 * API publique (JSON).
 *   POST ?action=recognize  embedding=<base64>            → modèle reconnu + documentations
 *   GET  ?action=models                                    → liste des modèles (pour la correction)
 *   POST ?action=submit     (multipart)                    → proposition utilisateur (à valider)
 */
require __DIR__ . '/lib/bootstrap.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'recognize':
            require_post();
            $emb = decode_embedding($_POST['embedding'] ?? null);
            if (!$emb) {
                json_out(['error' => 'Empreinte invalide.'], 400);
            }
            $ranked = rank_models($emb);
            $threshold = threshold();
            $candidates = [];
            foreach (array_slice($ranked, 0, 3, true) as $mid => $score) {
                $candidates[] = model_payload($mid) + ['score' => round($score, 4)];
            }
            $match = ($candidates && $candidates[0]['score'] >= $threshold) ? $candidates[0] : null;
            json_out([
                'match' => $match,
                'candidates' => $candidates,
                'threshold' => $threshold,
                'empty' => !$ranked,
            ]);

        case 'models':
            $rows = db()->query('SELECT id, name, brand, reference FROM models ORDER BY brand, name')->fetchAll();
            json_out(['models' => array_map(fn($m) => ['id' => (int) $m['id'], 'label' => model_label($m)], $rows)]);

        case 'model':
            json_out(['model' => model_payload((int) ($_GET['id'] ?? 0))]);

        case 'submit':
            require_post();
            json_out(['ok' => true, 'id' => create_submission()]);

        default:
            json_out(['error' => 'Action inconnue.'], 404);
    }
} catch (RuntimeException $ex) {
    json_out(['error' => $ex->getMessage()], 400);
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'POST attendu.'], 405);
    }
}

function model_payload(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM models WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) {
        return null;
    }
    $docs = db()->prepare('SELECT id, title, file, url FROM documents WHERE model_id = ? ORDER BY id');
    $docs->execute([$id]);
    $photo = db()->prepare('SELECT id FROM photos WHERE model_id = ? ORDER BY id LIMIT 1');
    $photo->execute([$id]);
    $pid = $photo->fetchColumn();
    return [
        'id' => (int) $m['id'],
        'label' => model_label($m),
        'name' => $m['name'],
        'brand' => $m['brand'],
        'reference' => $m['reference'],
        'description' => $m['description'],
        'photo' => $pid ? photo_url((int) $pid) : null,
        'documents' => array_map(fn($d) => ['title' => $d['title'], 'href' => doc_href($d)], $docs->fetchAll()),
    ];
}

/**
 * Enregistre une proposition utilisateur : identification d'un modèle (existant ou nouveau)
 * à partir d'une photo, et/ou ajout d'une documentation. Tout est mis en attente de validation.
 */
function create_submission(): int
{
    $modelId = (int) ($_POST['model_id'] ?? 0) ?: null;
    $proposed = trim(mb_substr((string) ($_POST['proposed_model'] ?? ''), 0, 200));
    if ($modelId) {
        $st = db()->prepare('SELECT 1 FROM models WHERE id = ?');
        $st->execute([$modelId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Modèle inconnu.');
        }
    }
    if (!$modelId && $proposed === '') {
        throw new RuntimeException('Choisissez un modèle existant ou saisissez le nom du modèle.');
    }

    $hasPhoto = !empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE;
    $hasDoc = !empty($_FILES['doc']) && $_FILES['doc']['error'] !== UPLOAD_ERR_NO_FILE;
    $docUrl = valid_url($_POST['doc_url'] ?? null);
    if (!$hasPhoto && !$hasDoc && !$docUrl) {
        throw new RuntimeException('Rien à envoyer : ajoutez une photo ou une documentation.');
    }

    $photo = $mime = $emb = $doc = $docName = null;
    if ($hasPhoto) {
        $emb = decode_embedding($_POST['embedding'] ?? null);
        [$photo, $mime] = store_upload($_FILES['photo'], 'photos', PHOTO_TYPES, MAX_PHOTO_BYTES);
    }
    if ($hasDoc) {
        [$doc] = store_upload($_FILES['doc'], 'docs', DOC_TYPES, MAX_DOC_BYTES);
        $docName = mb_substr(basename((string) $_FILES['doc']['name']), 0, 200);
    }

    run('INSERT INTO submissions (kind, model_id, proposed_model, photo_file, photo_mime, embedding,
         doc_file, doc_original_name, doc_title, doc_url, comment)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $hasPhoto ? 'identification' : 'documentation',
            $modelId,
            $modelId ? '' : $proposed,
            $photo,
            $mime,
            $emb,
            $doc,
            $docName,
            trim(mb_substr((string) ($_POST['doc_title'] ?? ''), 0, 200)),
            $docUrl,
            trim(mb_substr((string) ($_POST['comment'] ?? ''), 0, 2000)),
    ]);
    return (int) db()->lastInsertId();
}
