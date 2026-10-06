# Reconnaissance produit → documentation technique

Application web qui reconnaît un produit à partir d'une photo et affiche sa documentation technique,
avec un backoffice pour gérer les modèles, leurs photos de référence et leurs documentations.

## Fonctionnement

1. **Photo** : depuis un téléphone ou un ordinateur, l'utilisateur prend ou choisit une photo.
2. **Empreinte visuelle** : le navigateur calcule une empreinte de l'image avec MobileNet v2
   (TensorFlow.js). Le modèle est hébergé dans `assets/model/`, donc aucun service externe n'est appelé.
3. **Comparaison** : le serveur compare cette empreinte à celles des photos de référence
   (similarité cosinus, plus proche voisin) et renvoie le modèle le plus proche si le score dépasse
   le seuil réglé dans le backoffice.
4. **Résultat** :
   - *Produit reconnu* : affichage du modèle et de ses documentations (PDF ou liens). L'utilisateur peut
     aussi téléverser une documentation ou signaler que ce n'est pas le bon modèle.
   - *Produit non reconnu* : l'utilisateur indique le modèle (existant ou nouveau). Il peut joindre une
     documentation (PDF ou lien) via « Téléverser une documentation technique ».
5. **Validation** : les propositions des utilisateurs arrivent dans *Backoffice → À valider*.
   Quand l'administrateur les valide, la photo rejoint les références du modèle, ce qui améliore
   la reconnaissance, et la documentation devient publique. Avant validation, rien n'est visible
   publiquement.

## Backoffice (`/admin/`)

- **Modèles** : créer, modifier ou supprimer un modèle (marque, nom, référence, description).
  - Photos de référence : en ajouter plusieurs par modèle (angles, éclairages et fonds différents).
  - Documentation technique : fichier PDF ou lien externe, plusieurs documents par modèle possibles.
- **À valider** : propositions des utilisateurs. Chacune peut être associée à un modèle existant ou
  à un nouveau modèle, puis validée ou refusée.
- **Réglages** :
  - outil de test qui affiche les scores de similarité ;
  - seuil de reconnaissance (0,80 par défaut) ;
  - recalcul des empreintes ;
  - mot de passe et ajout d'administrateurs.

Au **premier accès** à `/admin/`, l'application demande de créer le compte administrateur.
Faites-le juste après la mise en ligne.

## Installation

Il faut un hébergement **PHP 8.1+** avec les extensions `pdo_sqlite` et `fileinfo`
(c'est le cas de la plupart des hébergements mutualisés). Aucune base MySQL ni aucune compilation n'est
nécessaire.

1. Copier les fichiers sur le serveur. Le workflow GitHub Actions `maj.yml` les déploie par FTP à chaque push.
2. Vérifier que le dossier `data/` est accessible en écriture par PHP. Il contient la base SQLite et les
   fichiers téléversés, et il est protégé par `.htaccess`.
3. Ouvrir `/admin/` pour créer le compte administrateur.
4. Créer les modèles, ajouter des photos et des documentations.
5. Dans *Réglages*, tester quelques photos et ajuster le seuil si besoin.

Sur **nginx** (sans `.htaccess`), interdire l'accès direct aux dossiers `data/` et `lib/` :

```nginx
location ~ ^/(data|lib)/ { deny all; }
```

Le fichier `.user.ini` augmente les limites de téléversement (32 Mo par fichier). Selon l'hébergeur,
ces valeurs peuvent devoir être réglées dans son panneau d'administration.

### En local

```bash
php -S localhost:8080
```

Ouvrir ensuite http://localhost:8080 (application) et http://localhost:8080/admin/ (backoffice).

## Structure

| Chemin | Rôle |
| --- | --- |
| `index.php`, `assets/js/app.js` | Application publique (photo → résultat, propositions) |
| `api.php` | API JSON : `recognize`, `models`, `model`, `submit` |
| `file.php` | Sert les photos et PDF stockés dans `data/files/` (fichiers en attente réservés aux admins) |
| `admin/` | Backoffice |
| `lib/bootstrap.php` | Configuration, schéma SQLite, sécurité (CSRF, sessions), comparaison des empreintes |
| `assets/js/embed.js` | Calcul de l'empreinte dans le navigateur |
| `assets/model/`, `assets/vendor/tf.min.js` | MobileNet v2 et TensorFlow.js, auto-hébergés |
| `data/` | Base `app.sqlite` et fichiers téléversés (non versionné) |

## Conseils pour une bonne reconnaissance

- Mettez au moins 3 à 5 photos par modèle, prises dans des conditions variées, idéalement proches
  de celles du terrain.
- Les modèles qui se ressemblent beaucoup (même gamme, couleurs proches) ont besoin de photos qui
  montrent ce qui les distingue : plaque signalétique, façade, etc.
- Validez les photos envoyées par les utilisateurs : ce sont des exemples réels qui améliorent
  la reconnaissance.

## Sécurité

- Le backoffice est protégé par mot de passe (hachage `password_hash`), avec des jetons CSRF sur
  tous les formulaires.
- Les fichiers téléversés sont vérifiés par leur type réel (JPEG/PNG/WebP pour les photos, PDF pour les
  documentations), renommés aléatoirement et stockés hors de la partie publique. Ils sont servis
  uniquement par `file.php`.
- Les propositions des utilisateurs restent privées tant qu'elles ne sont pas validées.
