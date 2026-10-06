<?php
require __DIR__ . '/_layout.php';

$hasAdmin = (bool) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if (!$hasAdmin) {
        // Premier lancement : création du compte administrateur.
        if ($user === '' || strlen($pass) < 10) {
            $error = 'Identifiant requis et mot de passe de 10 caractères minimum.';
        } elseif ($pass !== ($_POST['password2'] ?? '')) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) db()->lastInsertId();
            flash('Compte administrateur créé.');
            redirect('admin/index.php');
        }
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $st->execute([$user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $row['id'];
            redirect('admin/index.php');
        }
        usleep(500000); // ralentit les tentatives par force brute
        $error = 'Identifiants incorrects.';
    }
}

admin_header('Connexion');
?>
<section class="card narrow">
  <h1><?= $hasAdmin ? 'Connexion' : 'Créer le compte administrateur' ?></h1>
  <?php if (!$hasAdmin): ?>
    <p class="muted">Premier lancement : choisissez l'identifiant et le mot de passe du backoffice.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Identifiant <input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Mot de passe <input type="password" name="password" required
        autocomplete="<?= $hasAdmin ? 'current-password' : 'new-password' ?>"></label>
    <?php if (!$hasAdmin): ?>
      <label>Confirmer le mot de passe <input type="password" name="password2" required autocomplete="new-password"></label>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= $hasAdmin ? 'Se connecter' : 'Créer le compte' ?></button>
  </form>
</section>
<?php admin_footer();
