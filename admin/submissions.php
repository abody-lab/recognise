<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sub = run("SELECT * FROM submissions WHERE id = ? AND status = 'pending'", [(int) ($_POST['id'] ?? 0)])->fetch();
    if (!$sub) {
        flash('Proposition introuvable ou déjà traitée.', 'error');
        redirect('admin/submissions.php');
    }
    $keepPhoto = $sub['photo_file'] && !empty($_POST['keep_photo']);
    $keepDoc = ($sub['doc_file'] || $sub['doc_url']) && !empty($_POST['keep_doc']);

    if (($_POST['decision'] ?? '') === 'accept') {
        try {
            db()->beginTransaction();
            if (($_POST['target'] ?? '') === 'new') {
                $name = trim((string) ($_POST['new_name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Saisissez le nom du nouveau modèle.');
                }
                run('INSERT INTO models (name, brand, reference) VALUES (?, ?, ?)',
                    [$name, trim($_POST['new_brand'] ?? ''), trim($_POST['new_reference'] ?? '')]);
                $modelId = (int) db()->lastInsertId();
            } else {
                $modelId = (int) ($_POST['model_id'] ?? 0);
                if (!run('SELECT 1 FROM models WHERE id = ?', [$modelId])->fetchColumn()) {
                    throw new RuntimeException('Choisissez le modèle à associer.');
                }
            }
            if ($keepPhoto) {
                run('INSERT INTO photos (model_id, file, mime, embedding) VALUES (?, ?, ?, ?)',
                    [$modelId, $sub['photo_file'], $sub['photo_mime'], $sub['embedding']]);
            }
            if ($keepDoc) {
                $title = trim((string) ($_POST['doc_title'] ?? '')) ?: ($sub['doc_original_name'] ?: 'Documentation technique');
                run('INSERT INTO documents (model_id, title, file, original_name, url) VALUES (?, ?, ?, ?, ?)',
                    [$modelId, $title, $sub['doc_file'], $sub['doc_original_name'], $sub['doc_file'] ? null : $sub['doc_url']]);
            }
            // Les fichiers repris appartiennent désormais aux tables photos/documents.
            run("UPDATE submissions SET status = 'accepted', model_id = ?,
                   photo_file = CASE WHEN ? THEN NULL ELSE photo_file END,
                   doc_file = CASE WHEN ? THEN NULL ELSE doc_file END
                 WHERE id = ?", [$modelId, (int) $keepPhoto, (int) $keepDoc, (int) $sub['id']]);
            db()->commit();
        } catch (RuntimeException $ex) {
            db()->rollBack();
            flash($ex->getMessage(), 'error');
            redirect('admin/submissions.php');
        }
        if (!$keepPhoto) {
            delete_file('photos', $sub['photo_file']);
        }
        if (!$keepDoc) {
            delete_file('docs', $sub['doc_file']);
        }
        flash('Proposition validée.');
    } else {
        delete_file('photos', $sub['photo_file']);
        delete_file('docs', $sub['doc_file']);
        run("UPDATE submissions SET status = 'rejected', photo_file = NULL, doc_file = NULL WHERE id = ?", [(int) $sub['id']]);
        flash('Proposition refusée.');
    }
    redirect('admin/submissions.php');
}

$subs = db()->query("SELECT s.*, m.name, m.brand, m.reference FROM submissions s
                     LEFT JOIN models m ON m.id = s.model_id
                     WHERE s.status = 'pending' ORDER BY s.created_at")->fetchAll();

admin_header('À valider');
?>
<h1>Propositions à valider</h1>
<p class="muted">Photos identifiées et documentations envoyées par les utilisateurs. Une fois validées,
  les photos enrichissent la reconnaissance et les documentations deviennent publiques.</p>

<?php if (!$subs): ?>
  <p class="card">Rien à valider pour le moment. 🎉</p>
<?php endif; ?>

<?php foreach ($subs as $s):
    $isNew = !$s['model_id']; ?>
<section class="card submission">
  <div class="submission-grid">
    <div>
      <?php if ($s['photo_file']): ?>
        <a href="../file.php?t=sub-photo&id=<?= (int) $s['id'] ?>" target="_blank">
          <img class="preview" src="../file.php?t=sub-photo&id=<?= (int) $s['id'] ?>" alt="Photo envoyée"></a>
      <?php else: ?>
        <div class="preview placeholder">Pas de photo</div>
      <?php endif; ?>
    </div>
    <div>
      <p class="muted">Reçue le <?= e($s['created_at']) ?> UTC ·
        <?= $s['kind'] === 'identification' ? 'Identification' : 'Documentation' ?></p>
      <p>Modèle indiqué :
        <strong><?= $isNew ? e($s['proposed_model']) . ' <span class="pill">nouveau</span>' : e(model_label($s)) ?></strong></p>
      <?php if ($s['doc_file'] || $s['doc_url']): ?>
        <p>Documentation :
          <?php if ($s['doc_file']): ?>
            <a href="../file.php?t=sub-doc&id=<?= (int) $s['id'] ?>" target="_blank">📄 <?= e($s['doc_title'] ?: $s['doc_original_name']) ?></a>
          <?php else: ?>
            <a href="<?= e($s['doc_url']) ?>" target="_blank" rel="noopener noreferrer">🔗 <?= e($s['doc_title'] ?: $s['doc_url']) ?></a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if ($s['comment']): ?><blockquote><?= nl2br(e($s['comment'])) ?></blockquote><?php endif; ?>

      <form method="post" class="decision">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <fieldset>
          <legend>Associer à</legend>
          <label class="radio"><input type="radio" name="target" value="existing" <?= $isNew ? '' : 'checked' ?>>
            un modèle existant
            <select name="model_id"><option value="">—</option><?= model_options($s['model_id'] ? (int) $s['model_id'] : null) ?></select>
          </label>
          <label class="radio"><input type="radio" name="target" value="new" <?= $isNew ? 'checked' : '' ?>> un nouveau modèle</label>
          <div class="grid-form">
            <label>Marque <input name="new_brand" maxlength="100"></label>
            <label>Nom <input name="new_name" value="<?= e($s['proposed_model']) ?>" maxlength="200"></label>
            <label>Référence <input name="new_reference" maxlength="100"></label>
          </div>
        </fieldset>
        <?php if ($s['photo_file']): ?>
          <label class="radio"><input type="checkbox" name="keep_photo" value="1" checked> Ajouter la photo aux références<?= $s['embedding'] ? '' : ' <span class="warn-text">(sans empreinte)</span>' ?></label>
        <?php endif; ?>
        <?php if ($s['doc_file'] || $s['doc_url']): ?>
          <label class="radio"><input type="checkbox" name="keep_doc" value="1" checked> Publier la documentation</label>
          <label>Titre de la documentation <input name="doc_title" value="<?= e($s['doc_title']) ?>" maxlength="200"></label>
        <?php endif; ?>
        <div class="actions">
          <button class="btn btn-primary" name="decision" value="accept">Valider</button>
          <button class="btn btn-danger" name="decision" value="reject" onclick="return confirm('Refuser et supprimer cette proposition ?')">Refuser</button>
        </div>
      </form>
    </div>
  </div>
</section>
<?php endforeach;
admin_footer();
