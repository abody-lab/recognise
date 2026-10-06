<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';

function pending_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending'")->fetchColumn();
}

function admin_header(string $title, bool $withEmbed = false): void
{
    $pending = is_admin() ? pending_count() : 0;
    $self = basename($_SERVER['SCRIPT_NAME']);
    $nav = [
        'index.php' => 'Modèles',
        'submissions.php' => 'À valider' . ($pending ? " <span class=\"pill\">$pending</span>" : ''),
        'settings.php' => 'Réglages',
    ];
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> · Backoffice</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin">
<header class="topbar">
  <a class="brand" href="index.php">Backoffice</a>
  <?php if (is_admin()): ?>
  <nav>
    <?php foreach ($nav as $href => $label): ?>
      <a href="<?= $href ?>" class="<?= $self === $href ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <a href="../" target="_blank">Application ↗</a>
    <a href="logout.php">Déconnexion</a>
  </nav>
  <?php endif; ?>
</header>
<main class="container wide">
    <?php if ($f = flash()): ?>
  <p class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></p>
    <?php endif;
    if ($withEmbed) {
        echo '<script src="../assets/vendor/tf.min.js"></script>' . "\n";
        echo '<script src="../assets/js/embed.js" data-base="../"></script>' . "\n";
    }
}

function admin_footer(bool $withAdminJs = false): void
{
    ?>
</main>
<?php if ($withAdminJs): ?><script src="../assets/js/admin.js"></script><?php endif; ?>
</body>
</html>
    <?php
}

/** Liste <option> des modèles. */
function model_options(?int $selected = null): string
{
    $out = '';
    foreach (db()->query('SELECT id, name, brand, reference FROM models ORDER BY brand, name') as $m) {
        $sel = (int) $m['id'] === $selected ? ' selected' : '';
        $out .= '<option value="' . (int) $m['id'] . '"' . $sel . '>' . e(model_label($m)) . '</option>';
    }
    return $out;
}
