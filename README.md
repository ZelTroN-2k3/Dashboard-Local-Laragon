# 🚀 Dashboard Local Laragon

Un tableau de bord local moderne, dynamique et entièrement sur-mesure pour [Laragon](https://laragon.org/) (ou tout autre serveur web local PHP). Conçu pour améliorer le flux de travail des développeurs en offrant une navigation fluide, une détection intelligente des environnements de développement et une interface hautement personnalisable.

## ✨ Fonctionnalités Principales

*   **🕵️‍♂️ Détection Intelligente de la Stack :** Analyse automatiquement le contenu de vos dossiers pour afficher des badges visuels selon les technologies détectées (Laravel, WordPress, PrestaShop, Node.js, Vite, PHP, C++, Arduino, VB6, Python, HTML5, etc.).
*   **📁 Navigation Dynamique sans `.htaccess` :** Explorez vos sous-dossiers directement depuis l'interface du tableau de bord sans perdre le design ni utiliser la vue par défaut d'Apache.
*   **⭐ Système de Favoris :** Épinglez vos projets les plus actifs en haut de la page grâce à un système de cœurs interactifs (sauvegardés via les cookies du navigateur).
*   **🌓 Thème Sombre & Clair :** Basculez facilement entre un mode sombre et un mode clair. Votre préférence est sauvegardée (Local Storage).
*   **🔲 Vues Grille & Liste :** Adaptez l'affichage de vos projets selon vos préférences d'un simple clic.
*   **🔍 Recherche Rapide :** Filtrez vos projets instantanément grâce à la barre de recherche en temps réel.
*   **⚡ Actions Rapides intégrées :** Ouvrez n'importe quel projet directement dans l'Explorateur Windows ou dans votre Terminal (support de Cmder intégré) depuis le menu latéral.
*   **🗜️ Poids des dossiers optimisé :** Calcule la taille des dossiers en ignorant intelligemment les répertoires lourds (comme `node_modules`, `vendor`, `.git`, `.vs`) pour garantir un affichage instantané.
*   **🎨 Icônes SVG par type de fichier :** Les fichiers à la racine s'affichent avec des icônes spécifiques selon leur extension (`.apk`, `.conf`, `.exe`, archives, code, médias).

## 🛠️ Installation

1. Téléchargez ou clonez ce dépôt.
2. Prenez le fichier `index.php` et placez-le à la racine de votre répertoire web local (par défaut, le dossier `www` de Laragon).
3. **Important :** Assurez-vous de supprimer tout fichier `.htaccess` existant à la racine de votre dossier `www` pour éviter les conflits avec la navigation dynamique du script.
4. Ouvrez votre navigateur et allez sur `http://localhost`. C'est tout !

## ⚙️ Personnalisation

Ce tableau de bord est conçu pour être facilement extensible. 

**Ajouter une nouvelle "Stack" (technologie) :**
Ouvrez le fichier `index.php` et trouvez la fonction `detectStack($folderPath)`. Vous pouvez y ajouter vos propres règles avec un simple `if`. 
Exemple pour détecter un projet React :
```php
if (file_exists($folderPath . '/public/manifest.json') && file_exists($folderPath . '/src/App.js')) {$stack[] = ['name' => 'React', 'bg' => '#61dafb', 'color' => '#000'];
}