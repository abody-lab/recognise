/* Backoffice : calcul des empreintes dans le navigateur avant envoi. */
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name=csrf]').content;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function post(url, fd) {
    const res = await fetch(url, {
      method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf },
    });
    const data = await res.json().catch(() => ({ error: 'Réponse invalide du serveur.' }));
    if (!res.ok || data.error) throw new Error(data.error || 'Erreur ' + res.status);
    return data;
  }

  /* Ajout de photos de référence (fiche modèle). */
  document.querySelectorAll('.photo-upload-form').forEach((form) => {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const status = form.querySelector('.status');
      const btn = form.querySelector('[type=submit]');
      const files = [...form.querySelector('input[type=file]').files];
      if (!files.length) return;
      btn.disabled = true;
      try {
        status.textContent = 'Chargement du moteur de reconnaissance…';
        await ProductEmbed.loadModel();
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'add_photos');
        for (let i = 0; i < files.length; i++) {
          status.textContent = `Analyse de la photo ${i + 1}/${files.length}…`;
          const r = await ProductEmbed.analyze(files[i]);
          URL.revokeObjectURL(r.previewUrl);
          fd.append('photos[]', r.blob, files[i].name.replace(/\.\w+$/, '') + '.jpg');
          fd.append('embeddings[]', r.embedding);
        }
        status.textContent = 'Envoi…';
        await post(location.href, fd);
        location.reload();
      } catch (err) {
        status.textContent = err.message;
        status.className = 'status error';
        btn.disabled = false;
      }
    });
  });

  /* Recalcul de toutes les empreintes (réglages). */
  const recompute = document.getElementById('recompute');
  if (recompute) {
    recompute.addEventListener('click', async () => {
      const ids = JSON.parse(recompute.dataset.ids);
      const status = document.getElementById('recompute-status');
      recompute.disabled = true;
      let errors = 0;
      try {
        await ProductEmbed.loadModel();
        for (let i = 0; i < ids.length; i++) {
          status.textContent = `Photo ${i + 1}/${ids.length}…`;
          try {
            const blob = await (await fetch(`../file.php?t=photo&id=${ids[i]}`)).blob();
            const r = await ProductEmbed.analyze(blob);
            URL.revokeObjectURL(r.previewUrl);
            const fd = new FormData();
            fd.append('action', 'embedding');
            fd.append('photo_id', ids[i]);
            fd.append('embedding', r.embedding);
            fd.append('csrf', csrf);
            await post('settings.php', fd);
          } catch (_) {
            errors++;
          }
        }
        status.textContent = `Terminé : ${ids.length - errors} empreinte(s) recalculée(s)` + (errors ? `, ${errors} erreur(s).` : '.');
      } catch (err) {
        status.textContent = err.message;
      }
      recompute.disabled = false;
    });
  }

  /* Test de reconnaissance avec scores détaillés (réglages). */
  const testInput = document.getElementById('test-input');
  if (testInput) {
    testInput.addEventListener('change', async () => {
      const file = testInput.files[0];
      testInput.value = '';
      if (!file) return;
      const status = document.getElementById('test-status');
      const out = document.getElementById('test-result');
      out.innerHTML = '';
      try {
        status.textContent = 'Analyse…';
        const r = await ProductEmbed.analyze(file);
        const fd = new FormData();
        fd.append('embedding', r.embedding);
        const res = await (await fetch('../api.php?action=recognize', { method: 'POST', body: fd })).json();
        if (res.error) throw new Error(res.error);
        status.textContent = res.match ? `Reconnu : ${res.match.label}` : 'Non reconnu (aucun score au-dessus du seuil).';
        out.innerHTML = `<div class="result-grid"><img class="preview" src="${r.previewUrl}" alt="">
          <table class="table"><thead><tr><th>Modèle</th><th>Similarité</th></tr></thead><tbody>
          ${res.candidates.map((c) => `<tr><td><a href="model.php?id=${c.id}">${esc(c.label)}</a></td>
            <td class="${c.score >= res.threshold ? 'ok-text' : ''}">${c.score.toFixed(3)}</td></tr>`).join('')
            || '<tr><td colspan="2">Aucune photo de référence.</td></tr>'}
          </tbody></table></div><p class="muted">Seuil actuel : ${res.threshold}</p>`;
      } catch (err) {
        status.textContent = err.message;
      }
    });
  }
})();
