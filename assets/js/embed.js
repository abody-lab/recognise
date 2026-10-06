/*
 * Calcul de l'empreinte visuelle d'une image dans le navigateur (MobileNet v2, TensorFlow.js).
 * Le modèle est auto-hébergé dans assets/model : aucune donnée n'est envoyée à un service tiers.
 */
(function () {
  'use strict';

  const BASE = (document.currentScript && document.currentScript.dataset.base) || './';
  const MODEL_URL = BASE + 'assets/model/model.json';
  const FEATURE_NODE = 'module_apply_default/MobilenetV2/Logits/AvgPool';
  const SIZE = 224;
  const MAX_UPLOAD_SIDE = 1600;

  let modelPromise = null;

  function loadModel() {
    if (!modelPromise) {
      modelPromise = tf.ready().then(() => tf.loadGraphModel(MODEL_URL)).then((m) => {
        // Préchauffage pour que la première vraie analyse soit rapide.
        tf.tidy(() => m.execute(tf.zeros([1, SIZE, SIZE, 3]), FEATURE_NODE));
        return m;
      });
      modelPromise.catch(() => { modelPromise = null; });
    }
    return modelPromise;
  }

  function loadImage(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => resolve({ img, url });
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Image illisible.')); };
      img.src = url;
    });
  }

  /** Recadrage carré centré, redimensionné en 224×224. */
  function toCanvas(img, cropRatio) {
    const w = img.naturalWidth, h = img.naturalHeight;
    const side = Math.min(w, h) * cropRatio;
    const c = document.createElement('canvas');
    c.width = c.height = SIZE;
    const ctx = c.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, (w - side) / 2, (h - side) / 2, side, side, 0, 0, SIZE, SIZE);
    return c;
  }

  /**
   * Empreinte = moyenne normalisée de deux vues (image complète recadrée + zoom central)
   * et de leur miroir horizontal : plus robuste au cadrage approximatif d'une photo mobile.
   */
  async function embedImage(img) {
    const model = await loadModel();
    const views = [toCanvas(img, 1), toCanvas(img, 0.8)];
    const data = tf.tidy(() => {
      const batch = tf.stack(views.map((c) => tf.browser.fromPixels(c)))
        .toFloat().div(255);
      const all = tf.concat([batch, tf.reverse(batch, 2)]);
      const feats = model.execute(all, FEATURE_NODE).reshape([all.shape[0], -1]);
      const normed = feats.div(feats.norm('euclidean', 1, true));
      const mean = normed.mean(0);
      return mean.div(mean.norm());
    }).dataSync();
    return new Float32Array(data);
  }

  function toBase64(f32) {
    const bytes = new Uint8Array(f32.buffer, f32.byteOffset, f32.byteLength);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(bin);
  }

  /** Réduit la photo (JPEG, côté max 1600 px) pour un envoi léger depuis un mobile. */
  function shrink(img) {
    const scale = Math.min(1, MAX_UPLOAD_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
    const c = document.createElement('canvas');
    c.width = Math.round(img.naturalWidth * scale);
    c.height = Math.round(img.naturalHeight * scale);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.88));
  }

  /** Analyse complète d'un fichier image : empreinte (base64) + version réduite à téléverser. */
  async function analyze(file) {
    const { img, url } = await loadImage(file);
    try {
      const embedding = await embedImage(img);
      const blob = await shrink(img);
      return { embedding: toBase64(embedding), blob, previewUrl: url };
    } catch (err) {
      URL.revokeObjectURL(url);
      throw err;
    }
  }

  window.ProductEmbed = { loadModel, analyze };
})();
