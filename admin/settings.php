<?php
require __DIR__ . '/_layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ($_POST['action'] ?? '') {
        case 'threshold':
            $t = (float) str_replace(',', '.', (string) ($_POST['threshold'] ?? ''));
            if ($t < 0.3 || $t > 0.99) {
                flash('Le seuil doit être compris entre 0,30 et 0,99.', 'error');
            } else {
                set_setting('threshold', (string) $t);
                flash('Seuil enregistré.');
            }
            break;

        case 'embedding':
            // Appelé en AJAX par admin.js lors du recalcul des empreintes.
            $emb = decode_embedding($_POST['embedding'] ?? null);
            if (!$emb) {
                json_out(['error' => 'Empreinte invalide.'], 400);
            }
            run('UPDATE photos SET embedding = ? WHERE id = ?', [$emb, (int) ($_POST['photo_id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'password':
            $row = run('SELECT password_hash FROM admins WHERE id = ?', [$_SESSION['admin_id']])->fetch();
            $new = (string) ($_POST['new'] ?? '');
            if (!$row || !password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
                flash('Mot de passe actuel incorrect.', 'error');
            } elseif (strlen($new) < 10 || $new !== ($_POST['new2'] ?? '')) {
                flash('Nouveau mot de passe : 10 caractères minimum, confirmation identique.', 'error');
            } else {
                run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
                flash('Mot de passe modifié.');
            }
            break;

        case 'add_admin':
            $user = trim((string) ($_POST['username'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            if ($user === '' || strlen($pass) < 10) {
                flash('Identifiant requis et mot de passe de 10 caractères minimum.', 'error');
            } elseif (run('SELECT 1 FROM admins WHERE username = ?', [$user])->fetchColumn()) {
                flash('Cet identifiant existe déjà.', 'error');
            } else {
                run('INSERT INTO admins (username, password_hash) VALUES (?, ?)', [$user, password_hash($pass, PASSWORD_DEFAULT)]);
                flash('Administrateur ajouté.');
            }
            break;
    }
    redirect('admin/settings.php');
}

$photoIds = array_map('intval', db()->query('SELECT id FROM photos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$missing = (int) db()->query('SELECT COUNT(*) FROM photos WHERE embedding IS NULL')->fetchColumn();

admin_header('Réglages', true);
?>
<h1>Réglages</h1>

<section class="card">
  <h2>Tester la reconnaissance</h2>
  <p class="muted">Analysez une photo pour voir les scores de similarité des modèles les plus proches
    et ajuster le seuil ci-dessous.</p>
  <label class="btn">Choisir une photo <input type="file" accept="image/*" id="test-input" hidden></label>
  <p class="status" id="test-status" aria-live="polite"></p>
  <div id="test-result"></div>
</section>

<section class="card">
  <h2>Seuil de reconnaissance</h2>
  <p class="muted">Similarité minimale (0 à 1) pour qu'un produit soit considéré comme reconnu.
    Plus il est élevé, moins il y a d'erreurs mais plus il y a de « non reconnu ».
    Valeur par défaut : <?= DEFAULT_THRESHOLD ?>.</p>
  <form method="post" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="threshold">
    <input type="number" name="threshold" step="0.01" min="0.3" max="0.99" value="<?= e((string) threshold()) ?>">
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </form>
</section>

<section class="card">
  <h2>Empreintes des photos</h2>
  <p class="muted"><?= count($photoIds) ?> photo(s) de référence, dont <?= $missing ?> sans empreinte.
    Le recalcul est utile après une mise à jour du moteur de reconnaissance.</p>
  <button class="btn" id="recompute" data-ids="<?= e(json_encode($photoIds)) ?>" <?= $photoIds ? '' : 'disabled' ?>>Recalculer toutes les empreintes</button>
  <p class="status" id="recompute-status" aria-live="polite"></p>
</section>

<section class="card">
  <h2>Mon mot de passe</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <label>Mot de passe actuel <input type="password" name="current" required autocomplete="current-password"></label>
    <label>Nouveau mot de passe <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    <label>Confirmation <input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>
    <div class="full"><button class="btn btn-primary" type="submit">Modifier</button></div>
  </form>
</section>

<section class="card">
  <h2>Ajouter un administrateur</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_admin">
    <label>Identifiant <input name="username" required autocomplete="off"></label>
    <label>Mot de passe <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
    <div class="full"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</section>
<?php admin_footer(true);
