/* Page publique : reconnaissance d'un produit à partir d'une photo. */
(function () {
  'use strict';

  const $ = (sel) => document.querySelector(sel);
  const statusEl = $('#status');
  const resultEl = $('#result');
  const resultBody = $('#result-body');
  const identifyEl = $('#identify');
  const addDocEl = $('#add-doc');
  const modelSelect = $('#model-select');

  let current = null;   // { embedding, blob, previewUrl }
  let models = [];

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function setStatus(msg, type) {
    statusEl.textContent = msg || '';
    statusEl.className = 'status' + (type ? ' ' + type : '');
  }

  async function api(action, body, params) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const res = await fetch('api.php?' + qs, body ? { method: 'POST', body } : {});
    const data = await res.json().catch(() => ({ error: 'Réponse invalide du serveur.' }));
    if (!res.ok || data.error) throw new Error(data.error || 'Erreur ' + res.status);
    return data;
  }

  function docsHtml(model) {
    if (!model.documents.length) {
      return '<p class="muted">Aucune documentation technique n\'est encore disponible pour ce modèle.</p>';
    }
    return '<ul class="docs">' + model.documents.map((d) =>
      `<li><a href="${esc(d.href)}" target="_blank" rel="noopener">📄 ${esc(d.title)}</a></li>`).join('') + '</ul>';
  }

  function show(el) {
    el.hidden = false;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Préchargement du modèle dès l'ouverture de la page.
  setStatus('Chargement du moteur de reconnaissance…');
  ProductEmbed.loadModel()
    .then(() => setStatus(''))
    .catch(() => setStatus('Impossible de charger le moteur de reconnaissance.', 'error'));

  document.querySelectorAll('.photo-input').forEach((input) => {
    input.addEventListener('change', async () => {
      const file = input.files[0];
      input.value = '';
      if (file) await handlePhoto(file);
    });
  });

  async function handlePhoto(file) {
    identifyEl.hidden = addDocEl.hidden = resultEl.hidden = true;
    if (current) URL.revokeObjectURL(current.previewUrl);
    current = null;
    try {
      setStatus('Analyse de la photo…');
      current = await ProductEmbed.analyze(file);
      const fd = new FormData();
      fd.append('embedding', current.embedding);
      const res = await api('recognize', fd);
      setStatus('');
      renderResult(res);
    } catch (err) {
      setStatus(err.message, 'error');
    }
  }

  function renderResult(res) {
    $('#preview').src = current.previewUrl;
    const m = res.match;
    if (m) {
      resultBody.innerHTML = `
        <p class="badge ok">Modèle reconnu · ${Math.round(m.score * 100)} %</p>
        <h2>${esc(m.label)}</h2>
        ${m.description ? `<p>${esc(m.description)}</p>` : ''}
        <h3>Documentation technique</h3>
        ${docsHtml(m)}
        <div class="actions">
          <button class="btn" data-action="add-doc" data-id="${m.id}">📎 Téléverser une documentation</button>
          <button class="btn btn-link" data-action="identify">Ce n'est pas ce modèle ?</button>
        </div>`;
    } else {
      const others = res.candidates.filter((c) => c.score >= res.threshold - 0.15);
      resultBody.innerHTML = `
        <p class="badge warn">Produit non reconnu</p>
        <h2>Nous n'avons pas pu identifier ce produit.</h2>
        ${others.length ? `<p>S'agit-il de l'un de ces modèles ?</p><div class="candidates">${others.map((c) => `
            <button class="candidate" data-action="pick" data-id="${c.id}">
              ${c.photo ? `<img src="${esc(c.photo)}" alt="">` : ''}
              <span>${esc(c.label)}</span><small>${Math.round(c.score * 100)} %</small>
            </button>`).join('')}</div>` : ''}
        <div class="actions">
          <button class="btn btn-primary" data-action="identify">Indiquer le modèle</button>
        </div>`;
    }
    show(resultEl);
  }

  resultBody.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;
    if (btn.dataset.action === 'identify') openIdentify();
    if (btn.dataset.action === 'pick') openIdentify(id);
    if (btn.dataset.action === 'add-doc') openAddDoc(id);
  });

  document.querySelectorAll('[data-close]').forEach((b) =>
    b.addEventListener('click', () => { $('#' + b.dataset.close).hidden = true; }));

  /* ---------- Identification manuelle ---------- */

  async function loadModels() {
    if (models.length) return;
    models = (await api('models')).models;
    fillSelect('');
  }

  function fillSelect(filter) {
    const f = filter.trim().toLowerCase();
    const selected = modelSelect.value;
    modelSelect.length = 1;
    models.filter((m) => !f || m.label.toLowerCase().includes(f)).forEach((m) => {
      modelSelect.add(new Option(m.label, m.id, false, String(m.id) === selected));
    });
  }

  $('#model-filter').addEventListener('input', (e) => {
    fillSelect(e.target.value);
    if (e.target.value && modelSelect.length === 2) modelSelect.selectedIndex = 1;
    onModelChange();
  });
  modelSelect.addEventListener('change', onModelChange);

  async function onModelChange() {
    const id = modelSelect.value;
    $('#new-model-field').hidden = !!id;
    const box = $('#existing-docs');
    box.innerHTML = '';
    if (!id) return;
    try {
      const { model } = await api('model', null, { id });
      if (modelSelect.value !== id || !model) return;
      box.innerHTML = `<div class="notice"><strong>Documentation de ${esc(model.label)}</strong>${docsHtml(model)}</div>`;
      $('#doc-details').open = !model.documents.length;
    } catch (_) { /* affichage facultatif */ }
  }

  async function openIdentify(preselect) {
    addDocEl.hidden = true;
    $('#identify-form').reset();
    try { await loadModels(); } catch (err) { setStatus(err.message, 'error'); }
    $('#model-filter').value = '';
    fillSelect('');
    modelSelect.value = preselect || '';
    onModelChange();
    show(identifyEl);
  }

  $('#identify-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    const fd = new FormData(form);
    if (!fd.get('model_id') && !String(fd.get('proposed_model')).trim()) {
      setStatus('Choisissez un modèle ou saisissez le nom du nouveau modèle.', 'error');
      return;
    }
    if (current) {
      fd.append('photo', current.blob, 'photo.jpg');
      fd.append('embedding', current.embedding);
    }
    await send(fd, form, identifyEl,
      'Merci ! Votre proposition a été transmise et sera validée par un administrateur.');
  });

  /* ---------- Ajout de documentation ---------- */

  function openAddDoc(id) {
    identifyEl.hidden = true;
    const form = $('#add-doc-form');
    form.reset();
    form.model_id.value = id;
    $('#add-doc-model').textContent = resultBody.querySelector('h2').textContent;
    show(addDocEl);
  }

  $('#add-doc-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const form = ev.target;
    const fd = new FormData(form);
    if (!fd.get('doc').size && !String(fd.get('doc_url')).trim()) {
      setStatus('Ajoutez un fichier PDF ou un lien.', 'error');
      return;
    }
    await send(fd, form, addDocEl, 'Merci ! La documentation sera publiée après validation.');
  });

  async function send(fd, form, section, okMsg) {
    // Les champs fichier vides sont retirés pour ne pas être vus comme des envois.
    for (const [k, v] of [...fd.entries()]) {
      if (v instanceof File && !v.size) fd.delete(k);
    }
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    try {
      await api('submit', fd);
      section.hidden = true;
      setStatus(okMsg, 'ok');
      statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (err) {
      setStatus(err.message, 'error');
      statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } finally {
      btn.disabled = false;
    }
  }
})();
