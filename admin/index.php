<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('Le nom du modèle est obligatoire.', 'error');
        redirect('admin/index.php');
    }
    db()->prepare('INSERT INTO models (name, brand, reference, description) VALUES (?, ?, ?, ?)')
        ->execute([$name, trim($_POST['brand'] ?? ''), trim($_POST['reference'] ?? ''), trim($_POST['description'] ?? '')]);
    flash('Modèle créé. Ajoutez maintenant des photos et la documentation.');
    redirect('admin/model.php?id=' . db()->lastInsertId());
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT m.*,
          (SELECT COUNT(*) FROM photos p WHERE p.model_id = m.id) AS nb_photos,
          (SELECT COUNT(*) FROM documents d WHERE d.model_id = m.id) AS nb_docs,
          (SELECT MIN(id) FROM photos p WHERE p.model_id = m.id) AS photo_id
        FROM models m';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE m.name LIKE ? OR m.brand LIKE ? OR m.reference LIKE ?';
    $params = array_fill(0, 3, '%' . $q . '%');
}
$st = db()->prepare($sql . ' ORDER BY m.brand, m.name');
$st->execute($params);
$models = $st->fetchAll();

admin_header('Modèles');
?>
<div class="page-head">
  <h1>Modèles</h1>
  <form method="get" class="inline">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher…">
  </form>
</div>

<?php if (!$models): ?>
  <p class="muted"><?= $q ? 'Aucun résultat.' : 'Aucun modèle pour le moment : créez le premier ci-dessous.' ?></p>
<?php else: ?>
<table class="table">
  <thead><tr><th></th><th>Modèle</th><th>Photos</th><th>Docs</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($models as $m): ?>
    <tr>
      <td class="thumb-cell"><?php if ($m['photo_id']): ?><img class="thumb" src="<?= e(photo_url((int) $m['photo_id'])) ?>" alt="" loading="lazy"><?php endif; ?></td>
      <td><a href="model.php?id=<?= (int) $m['id'] ?>"><strong><?= e(model_label($m)) ?></strong></a></td>
      <td class="<?= $m['nb_photos'] ? '' : 'warn-text' ?>"><?= (int) $m['nb_photos'] ?></td>
      <td class="<?= $m['nb_docs'] ? '' : 'warn-text' ?>"><?= (int) $m['nb_docs'] ?></td>
      <td><a class="btn btn-small" href="model.php?id=<?= (int) $m['id'] ?>">Modifier</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<section class="card">
  <h2>Nouveau modèle</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?>
    <label>Marque <input name="brand" maxlength="100"></label>
    <label>Nom du modèle * <input name="name" required maxlength="200"></label>
    <label>Référence <input name="reference" maxlength="100"></label>
    <label class="full">Description <textarea name="description" rows="2"></textarea></label>
    <div class="full"><button class="btn btn-primary" type="submit">Créer le modèle</button></div>
  </form>
</section>
<?php admin_footer();
