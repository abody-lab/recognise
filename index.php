<?php
require __DIR__ . '/lib/bootstrap.php';
db();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="./"><?= e(APP_NAME) ?></a>
  <a class="topbar-link" href="admin/">Backoffice</a>
</header>

<main class="container">
  <section class="card intro">
    <h1>Identifier un produit</h1>
    <p>Prenez le produit en photo : l'application retrouve le modèle et sa documentation technique.</p>
    <div class="actions">
      <label class="btn btn-primary">
        📷 Prendre une photo
        <input type="file" accept="image/*" capture="environment" class="photo-input" hidden>
      </label>
      <label class="btn">
        🖼️ Choisir une image
        <input type="file" accept="image/*" class="photo-input" hidden>
      </label>
    </div>
    <p id="status" class="status" aria-live="polite"></p>
  </section>

  <section id="result" class="card" hidden>
    <div class="result-grid">
      <img id="preview" class="preview" alt="Photo analysée">
      <div id="result-body"></div>
    </div>
  </section>

  <!-- Correction / identification manuelle -->
  <section id="identify" class="card" hidden>
    <h2>Quel est ce modèle ?</h2>
    <p class="muted">Indiquez le modèle : votre photo aidera l'application à le reconnaître la prochaine fois
      (après validation par un administrateur).</p>
    <form id="identify-form">
      <label>Modèle existant
        <input type="search" id="model-filter" placeholder="Filtrer la liste…" autocomplete="off">
        <select name="model_id" id="model-select">
          <option value="">— Nouveau modèle (non listé) —</option>
        </select>
      </label>
      <div id="existing-docs"></div>
      <label id="new-model-field">Nom du nouveau modèle
        <input type="text" name="proposed_model" maxlength="200" placeholder="Marque, modèle, référence…">
      </label>
      <details id="doc-details">
        <summary>📎 Téléverser une documentation technique (facultatif)</summary>
        <div class="doc-fields">
          <label>Titre <input type="text" name="doc_title" maxlength="200" placeholder="Notice d'utilisation, schéma…"></label>
          <label>Fichier PDF <input type="file" name="doc" accept="application/pdf"></label>
          <label>… ou lien vers la documentation <input type="url" name="doc_url" placeholder="https://"></label>
        </div>
      </details>
      <label>Commentaire <textarea name="comment" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Envoyer</button>
        <button class="btn" type="button" data-close="identify">Annuler</button>
      </div>
    </form>
  </section>

  <!-- Ajout de documentation pour un modèle reconnu -->
  <section id="add-doc" class="card" hidden>
    <h2>Téléverser une documentation</h2>
    <p class="muted">Pour <strong id="add-doc-model"></strong>. Elle sera publiée après validation.</p>
    <form id="add-doc-form">
      <input type="hidden" name="model_id">
      <label>Titre <input type="text" name="doc_title" maxlength="200" placeholder="Notice d'utilisation, schéma…"></label>
      <label>Fichier PDF <input type="file" name="doc" accept="application/pdf"></label>
      <label>… ou lien vers la documentation <input type="url" name="doc_url" placeholder="https://"></label>
      <label>Commentaire <textarea name="comment" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Envoyer</button>
        <button class="btn" type="button" data-close="add-doc">Annuler</button>
      </div>
    </form>
  </section>
</main>

<script src="assets/vendor/tf.min.js"></script>
<script src="assets/js/embed.js" data-base="./"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
