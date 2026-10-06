<?php
require __DIR__ . '/_layout.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM models WHERE id = ?');
$st->execute([$id]);
$model = $st->fetch();
if (!$model) {
    flash('Modèle introuvable.', 'error');
    redirect('admin/index.php');
}
$back = 'admin/model.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        switch ($_POST['action'] ?? '') {
            case 'update':
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Le nom du modèle est obligatoire.');
                }
                db()->prepare('UPDATE models SET name = ?, brand = ?, reference = ?, description = ? WHERE id = ?')
                    ->execute([$name, trim($_POST['brand'] ?? ''), trim($_POST['reference'] ?? ''), trim($_POST['description'] ?? ''), $id]);
                flash('Modèle enregistré.');
                break;

            case 'delete':
                foreach (db()->query('SELECT file FROM photos WHERE model_id = ' . $id) as $p) {
                    delete_file('photos', $p['file']);
                }
                foreach (db()->query('SELECT file FROM documents WHERE model_id = ' . $id) as $d) {
                    delete_file('docs', $d['file']);
                }
                db()->prepare('DELETE FROM models WHERE id = ?')->execute([$id]);
                flash('Modèle supprimé.');
                redirect('admin/index.php');

            case 'add_photos':
                // Envoi en AJAX par admin.js : photos[] + embeddings[] (calculés dans le navigateur).
                $files = $_FILES['photos'] ?? null;
                $embs = $_POST['embeddings'] ?? [];
                $n = 0;
                if ($files && is_array($files['name'])) {
                    foreach (array_keys($files['name']) as $i) {
                        $file = array_combine(array_keys($files), array_column($files, $i));
                        [$name, $mime] = store_upload($file, 'photos', PHOTO_TYPES, MAX_PHOTO_BYTES);
                        run('INSERT INTO photos (model_id, file, mime, embedding) VALUES (?, ?, ?, ?)',
                            [$id, $name, $mime, decode_embedding($embs[$i] ?? null)]);
                        $n++;
                    }
                }
                flash("$n photo(s) ajoutée(s).");
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    json_out(['ok' => true, 'count' => $n]);
                }
                break;

            case 'delete_photo':
                $p = db()->prepare('SELECT file FROM photos WHERE id = ? AND model_id = ?');
                $p->execute([(int) $_POST['photo_id'], $id]);
                if ($f = $p->fetchColumn()) {
                    delete_file('photos', $f);
                    db()->prepare('DELETE FROM photos WHERE id = ?')->execute([(int) $_POST['photo_id']]);
                }
                flash('Photo supprimée.');
                break;

            case 'add_doc':
                $title = trim((string) ($_POST['title'] ?? ''));
                $url = valid_url($_POST['url'] ?? null);
                $hasFile = !empty($_FILES['doc']) && $_FILES['doc']['error'] !== UPLOAD_ERR_NO_FILE;
                if (!$hasFile && !$url) {
                    throw new RuntimeException('Ajoutez un fichier PDF ou un lien.');
                }
                $file = $orig = null;
                if ($hasFile) {
                    [$file] = store_upload($_FILES['doc'], 'docs', DOC_TYPES, MAX_DOC_BYTES);
                    $orig = basename((string) $_FILES['doc']['name']);
                }
                db()->prepare('INSERT INTO documents (model_id, title, file, original_name, url) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $title ?: ($orig ?: 'Documentation technique'), $file, $orig, $file ? null : $url]);
                flash('Documentation ajoutée.');
                break;

            case 'delete_doc':
                $d = db()->prepare('SELECT file FROM documents WHERE id = ? AND model_id = ?');
                $d->execute([(int) $_POST['doc_id'], $id]);
                $row = $d->fetch();
                if ($row) {
                    delete_file('docs', $row['file']);
                    db()->prepare('DELETE FROM documents WHERE id = ?')->execute([(int) $_POST['doc_id']]);
                }
                flash('Documentation supprimée.');
                break;
        }
    } catch (RuntimeException $ex) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_out(['error' => $ex->getMessage()], 400);
        }
        flash($ex->getMessage(), 'error');
    }
    redirect($back);
}

$photos = db()->prepare('SELECT id, embedding IS NOT NULL AS has_emb FROM photos WHERE model_id = ? ORDER BY id');
$photos->execute([$id]);
$photos = $photos->fetchAll();
$docs = db()->prepare('SELECT * FROM documents WHERE model_id = ? ORDER BY id');
$docs->execute([$id]);
$docs = $docs->fetchAll();

admin_header(model_label($model), true);
?>
<p><a href="index.php">← Tous les modèles</a></p>
<h1><?= e(model_label($model)) ?></h1>

<section class="card">
  <h2>Informations</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <label>Marque <input name="brand" value="<?= e($model['brand']) ?>" maxlength="100"></label>
    <label>Nom du modèle * <input name="name" value="<?= e($model['name']) ?>" required maxlength="200"></label>
    <label>Référence <input name="reference" value="<?= e($model['reference']) ?>" maxlength="100"></label>
    <label class="full">Description <textarea name="description" rows="3"><?= e($model['description']) ?></textarea></label>
    <div class="full"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</section>

<section class="card">
  <h2>Photos de référence <small class="muted">(<?= count($photos) ?>)</small></h2>
  <p class="muted">Ajoutez plusieurs photos du produit (angles, éclairages, arrière-plans différents) :
    plus il y en a, meilleure est la reconnaissance.</p>
  <div class="gallery">
    <?php foreach ($photos as $p): ?>
      <figure>
        <a href="<?= e(photo_url((int) $p['id'])) ?>" target="_blank"><img src="<?= e(photo_url((int) $p['id'])) ?>" alt="" loading="lazy"></a>
        <?php if (!$p['has_emb']): ?><figcaption class="warn-text">Sans empreinte</figcaption><?php endif; ?>
        <form method="post" onsubmit="return confirm('Supprimer cette photo ?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_photo">
          <input type="hidden" name="photo_id" value="<?= (int) $p['id'] ?>">
          <button class="icon-btn" title="Supprimer">✕</button>
        </form>
      </figure>
    <?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="photo-upload-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_photos">
    <label>Ajouter des photos <input type="file" name="photos[]" accept="image/*" multiple required></label>
    <button class="btn btn-primary" type="submit">Téléverser</button>
    <p class="status" aria-live="polite"></p>
  </form>
</section>

<section class="card">
  <h2>Documentation technique</h2>
  <?php if ($docs): ?>
    <ul class="docs">
      <?php foreach ($docs as $d): ?>
        <li>
          <a href="<?= e(doc_href($d)) ?>" target="_blank" rel="noopener">📄 <?= e($d['title']) ?></a>
          <small class="muted"><?= $d['url'] ? 'lien externe' : e($d['original_name']) ?></small>
          <form method="post" class="inline" onsubmit="return confirm('Supprimer cette documentation ?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_doc">
            <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-small btn-danger">Supprimer</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="warn-text">Aucune documentation pour ce modèle.</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="grid-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_doc">
    <label>Titre <input name="title" maxlength="200" placeholder="Notice, manuel de maintenance, schéma…"></label>
    <label>Fichier PDF <input type="file" name="doc" accept="application/pdf"></label>
    <label class="full">… ou lien externe <input type="url" name="url" placeholder="https://"></label>
    <div class="full"><button class="btn btn-primary" type="submit">Ajouter la documentation</button></div>
  </form>
</section>

<section class="card danger-zone">
  <form method="post" onsubmit="return confirm('Supprimer définitivement ce modèle, ses photos et ses documentations ?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn btn-danger" type="submit">Supprimer le modèle</button>
  </form>
</section>
<?php admin_footer(true);
