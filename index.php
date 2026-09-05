<?php
// --- DEFINITION DU DOSSIER COURANT (Navigation sans .htaccess) ---
$baseDir = str_replace('\\', '/', __DIR__);
$subPath = isset($_GET['path']) ? trim($_GET['path'], '/') : '';
$subPath = str_replace(['../', '..\\'], '', $subPath); // Sécurité anti-remontée

$currentDir = $baseDir;
if ($subPath !== '') {
    $target = realpath($baseDir . '/' . $subPath);
    // Vérifie que le dossier existe et reste dans le dossier racine www/
    if ($target && strpos(str_replace('\\', '/', $target), $baseDir) === 0 && is_dir($target)) {
        $currentDir = str_replace('\\', '/', $target);
    } else {
        $subPath = ''; 
    }
}
$isSubFolder = ($subPath !== '');
$displayTitle = $isSubFolder ? '/' . htmlspecialchars($subPath) : 'Dossiers';

// --- GESTION DES FAVORIS (Cookies) ---
$favoris = isset($_COOKIE['laragon_favs']) ? json_decode($_COOKIE['laragon_favs'], true) : [];
if (!is_array($favoris)) $favoris = [];

// --- RESTAURATION DE LA FONCTION PHPINFO ---
if (isset($_GET['q']) && $_GET['q'] === 'info') {
    phpinfo();
    exit;
}

// --- ACTIONS LOCALES (Terminal et Explorateur) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'terminal') {
        $cmderExe = 'D:\laragon\bin\cmder\Cmder.exe';
        if (file_exists($cmderExe)) {
            pclose(popen('start "" "' . $cmderExe . '" /START "' . $currentDir . '"', "r"));
        } else {
            pclose(popen("start cmd.exe /K cd " . escapeshellarg($currentDir), "r"));
        }
        header("Location: /" . ($isSubFolder ? "?path=" . urlencode($subPath) : ""));
        exit;
    } elseif ($_GET['action'] === 'root') {
        pclose(popen("start explorer.exe " . escapeshellarg($currentDir), "r"));
        header("Location: /" . ($isSubFolder ? "?path=" . urlencode($subPath) : ""));
        exit;
    }
}

// --- DETECTION DES SERVICES ---
$services = [];
$services['Web'] = explode(' ', $_SERVER['SERVER_SOFTWARE'])[0];

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli('127.0.0.1', 'root', '');
    if (!$mysqli->connect_error) {
        $services['MySQL'] = $mysqli->server_info;
        $mysqli->close();
    }
} catch (Throwable $e) {}

if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        if (@$redis->connect('127.0.0.1', 6379)) {
            $info = @$redis->info();
            $services['Redis'] = (is_array($info) && isset($info['redis_version'])) ? $info['redis_version'] : 'Actif';
            $redis->close();
        }
    } catch (Throwable $e) {}
}

if (class_exists('Memcache')) {
    try {
        $memcache = new Memcache();
        if (@$memcache->connect('127.0.0.1', 11211)) {
            $services['Memcached'] = @$memcache->getVersion() ?: 'Actif';
            $memcache->close();
        }
    } catch (Throwable $e) {}
} elseif (class_exists('Memcached')) {
    try {
        $m = new Memcached();
        $m->addServer('127.0.0.1', 11211);
        $versions = @$m->getVersion();
        if (is_array($versions) && !empty($versions)) {
            $services['Memcached'] = current($versions);
        }
    } catch (Throwable $e) {}
}

// --- LECTURE DU DOSSIER ---
$items = scandir($currentDir);
$pinnedFolders = [];
$normalFolders = [];
$files = [];

// Ajout du bouton retour si on est dans un sous-dossier
if ($isSubFolder) {
    $parentParts = explode('/', $subPath);
    array_pop($parentParts);
    $parentPath = implode('/', $parentParts);
    
    $normalFolders[] = [
        'name' => '⬅️ Retour au dossier parent',
        'is_back' => true,
        'target_path' => $parentPath,
        'mtime' => '-',
        'stack' => []
    ];
}

function formatBytes($size, $precision = 2) {
    if ($size === 0) return '0 o';
    $base = log($size, 1024);
    $suffixes = ['o', 'ko', 'Mo', 'Go', 'To'];
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $types = [
        'code' => ['php', 'html', 'css', 'js', 'json', 'xml', 'md', 'sql', 'py', 'cpp', 'ino', 'vbp'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
        'media' => ['mp3', 'mp4', 'avi', 'wav', 'mkv', 'ogg'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'],
        'config' => ['conf', 'ini', 'env', 'htaccess', 'yaml', 'yml', 'cfg'],
        'android' => ['apk', 'aab'],
        'exe' => ['exe', 'msi', 'bat', 'cmd']
    ];
    foreach ($types as $type => $exts) {
        if (in_array($ext, $exts)) return $type;
    }
    return 'default';
}

function getFileSvg($type) {
    switch($type) {
        case 'code': return '<path d="M4.72 3.22a.75.75 0 0 1 1.06 1.06L2.06 8l3.72 3.72a.75.75 0 1 1-1.06 1.06L.47 8.53a.75.75 0 0 1 0-1.06Z"></path><path d="M11.28 3.22a.75.75 0 0 0-1.06 1.06L13.94 8l-3.72 3.72a.75.75 0 1 0 1.06 1.06l4.25-4.25a.75.75 0 0 0 0-1.06Z"></path>';
        case 'image': return '<path d="M1.75 2.5a.25.25 0 0 0-.25.25v10.5c0 .138.112.25.25.25h12.5a.25.25 0 0 0 .25-.25v-10.5a.25.25 0 0 0-.25-.25ZM0 2.75C0 1.784.784 1 1.75 1h12.5c.966 0 1.75.784 1.75 1.75v10.5A1.75 1.75 0 0 1 14.25 15H1.75A1.75 1.75 0 0 1 0 13.25ZM5.75 7.5a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5ZM6 11.5l3-3 4.5 4.5H2.5l3.5-3.5Z"></path>';
        case 'archive': return '<path d="M7.527 1.545a1.75 1.75 0 0 1 1.636-.007l5.25 2.825A1.75 1.75 0 0 1 15.5 5.892v4.887a1.75 1.75 0 0 1-.89 1.529l-5.75 3.284a1.75 1.75 0 0 1-1.72 0l-5.75-3.284a1.75 1.75 0 0 1-.89-1.529V5.892a1.75 1.75 0 0 1 .89-1.529ZM1.5 5.892v4.887a.25.25 0 0 0 .127.218l5.75 3.284a.25.25 0 0 0 .246 0l5.75-3.284a.25.25 0 0 0 .127-.218V5.892a.25.25 0 0 0-.127-.218l-5.25-2.825a.25.25 0 0 0-.234.001l-6.25 3.493V7.25a.75.75 0 0 1 1.5 0v-.248l1.642-.917.001-.001.002-.001 2.92-1.632a.75.75 0 0 1 .738 1.304l-2.924 1.635-1.996 1.116-.003.001v.001l-1.85 1.034.004.006 5.875 3.282a.25.25 0 0 0 .246 0l5.87-3.279L2.875 3.738a.25.25 0 0 0-.234-.002Z"></path>';
        case 'media': return '<path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm4.879-2.773 4.264 2.559a.25.25 0 0 1 0 .428l-4.264 2.559A.25.25 0 0 1 6 10.559V5.442a.25.25 0 0 1 .379-.215Z"></path>';
        case 'document': return '<path d="M0 3.75C0 2.784.784 2 1.75 2h7.5c.966 0 1.75.784 1.75 1.75v8.5A1.75 1.75 0 0 1 9.25 14h-7.5A1.75 1.75 0 0 1 0 12.25Zm1.75-.25a.25.25 0 0 0-.25.25v8.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-8.5a.25.25 0 0 0-.25-.25ZM3.5 6.25a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75Zm.75 2.25h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1 0-1.5Z"></path>';
        
        case 'config': return '<path d="M7.429 1.525a6.593 6.593 0 0 1 1.142 0c.036.003.108.036.137.146l.289 1.105c.147.56.55.967.997 1.189.174.086.341.183.501.29.417.278.97.423 1.53.27l1.102-.303c.11-.03.175.016.195.046.219.31.41.641.573.989.014.031.022.11-.059.19l-.915.902c-.432.426-.606 1.056-.47 1.557a7.253 7.253 0 0 1 0 1.187c-.136.501.038 1.131.47 1.557l.915.902c.081.08.073.159.059.19a6.52 6.52 0 0 1-.573.99c-.02.029-.086.074-.195.045l-1.103-.303c-.559-.153-1.112-.008-1.529.27-.16.107-.327.204-.501.29-.447.222-.85.629-.997 1.189l-.289 1.105c-.029.11-.101.143-.137.146a6.613 6.613 0 0 1-1.142 0c-.036-.003-.108-.036-.137-.146l-.289-1.105c-.147-.56-.55-.967-.997-1.189a6.357 6.357 0 0 1-.501-.29c-.417-.278-.97-.423-1.53-.27l-1.102.303c-.11.03-.175-.016-.195-.046a6.516 6.516 0 0 1-.573-.989c-.014-.031-.022-.11.059-.19l.915-.902c.432-.426.606-1.056.47-1.557a7.253 7.253 0 0 1 0-1.187c.136-.501-.038-1.131-.47-1.557l-.915-.902c-.081-.08-.073-.159-.059-.19a6.52 6.52 0 0 1 .573-.99c.02-.029.086-.074.195-.045l1.103.303c.559.153 1.112.008 1.529-.27.16-.107.327-.204.501-.29.447-.222.85-.629.997-1.189l.289-1.105c.029-.11.101-.143.137-.146ZM8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5ZM6.5 8a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"></path>';
        case 'android': return '<path d="M4.75 0h6.5A1.75 1.75 0 0 1 13 1.75v12.5A1.75 1.75 0 0 1 11.25 16h-6.5A1.75 1.75 0 0 1 3 14.25V1.75C3 .784 3.784 0 4.75 0ZM4.5 1.75v12.5c0 .138.112.25.25.25h6.5a.25.25 0 0 0 .25-.25V1.75a.25.25 0 0 0-.25-.25h-6.5a.25.25 0 0 0-.25.25ZM8 13a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"></path>';
        case 'exe': return '<path d="M1.75 1h12.5c.966 0 1.75.784 1.75 1.75v10.5A1.75 1.75 0 0 1 14.25 15H1.75A1.75 1.75 0 0 1 0 13.25V2.75C0 1.784.784 1 1.75 1ZM1.5 4.5v8.75c0 .138.112.25.25.25h12.5a.25.25 0 0 0 .25-.25V4.5H1.5Zm13-1.5H1.5v-.25a.25.25 0 0 1 .25-.25h12.5a.25.25 0 0 1 .25.25v.25Z"></path>';
        
        default: return '<path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"></path>';
    }
}

function detectStack($folderPath) {
    $stack = [];

    if (file_exists($folderPath . '/artisan')) {
        $stack[] = ['name' => 'Laravel', 'bg' => '#ff2d20', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/wp-config.php') || file_exists($folderPath . '/wp-config-sample.php')) {
        $stack[] = ['name' => 'WordPress', 'bg' => '#21759b', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/config/settings.inc.php') || file_exists($folderPath . '/config/parameters.php')) {
        $stack[] = ['name' => 'PrestaShop', 'bg' => '#df0067', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/vite.config.js') || file_exists($folderPath . '/vite.config.ts')) {
        $stack[] = ['name' => 'Vite', 'bg' => '#646cff', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/package.json')) {
        $stack[] = ['name' => 'Node', 'bg' => '#339933', 'color' => '#fff'];
    }

    $isAdvancedPHP = !empty(array_filter($stack, fn($s) => in_array($s['name'], ['Laravel', 'WordPress', 'PrestaShop'])));
    if (!$isAdvancedPHP && (file_exists($folderPath . '/composer.json') || file_exists($folderPath . '/index.php'))) {
        $stack[] = ['name' => 'PHP', 'bg' => '#777BB4', 'color' => '#fff'];
    }

    if (file_exists($folderPath . '/CMakeLists.txt') || !empty(glob($folderPath . '/*.sln'))) {
        $stack[] = ['name' => 'C++', 'bg' => '#00599C', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/platformio.ini') || !empty(glob($folderPath . '/*.ino'))) {
        $stack[] = ['name' => 'Arduino', 'bg' => '#00979d', 'color' => '#fff'];
    }
    if (!empty(glob($folderPath . '/*.vbp'))) {
        $stack[] = ['name' => 'VB6', 'bg' => '#512bd4', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/requirements.txt') || file_exists($folderPath . '/main.py')) {
        $stack[] = ['name' => 'Python', 'bg' => '#3776AB', 'color' => '#fff'];
    }
    if (file_exists($folderPath . '/index.html') && empty($stack)) {
        $stack[] = ['name' => 'HTML5', 'bg' => '#e34f26', 'color' => '#fff'];
    }
    if (empty($stack)) {
        $stack[] = ['name' => 'Divers', 'bg' => '#6e7681', 'color' => '#ffffff'];
    }

    return $stack;
}

foreach ($items as $item) {
    if (in_array($item, ['.', '..'])) continue;
    if (substr($item, 0, 1) === '.') continue; 
    if (!$isSubFolder && $item === basename(__FILE__)) continue; 
    
    $path = $currentDir . DIRECTORY_SEPARATOR . $item;
    $stat = @stat($path);
    $modTime = $stat ? date('d/m/Y H:i', $stat['mtime']) : '-';
    
    if (is_dir($path)) {
        $stackInfo = detectStack($path); 
        $folderData = [
            'name' => $item, 
            'mtime' => $modTime,
            'stack' => $stackInfo,
            // Vérifie intelligemment s'il y a un index pour déterminer si on navigue ou si on exécute
            'has_index' => (file_exists($path . '/index.php') || file_exists($path . '/index.html'))
        ];
        
        if (in_array($item, $favoris) && !$isSubFolder) {
            $pinnedFolders[] = $folderData;
        } else {
            $normalFolders[] = $folderData;
        }
    } else {
        $fileType = getFileType($item);
        $files[] = [
            'name' => $item, 
            'mtime' => $modTime, 
            'size' => $stat ? formatBytes($stat['size'], 0) : '0 o',
            'type' => $fileType
        ];
    }
}

$pinnedCount = count($pinnedFolders);
$normalCount = count($normalFolders);
$fileCount = count($files);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Local</title>
    
    <!-- ANTI-FOUC (Évite le clignotement blanc) -->
    <script>
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
    
    <style>
        /* VARIABLES THÈMES */
        :root {
            --bg-color: #0d1117;
            --panel-bg: #161b22;
            --border-color: #30363d;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent: #58a6ff;
            --accent-hover: #1f6feb;
            --badge-bg: #21262d;
            
            --folder-icon: #e3b341;
            --folder-pinned: #f85149;
            --icon-code: #58a6ff;
            --icon-image: #3fb950;
            --icon-archive: #d29922;
            --icon-media: #bc8cff;
            --icon-document: #f0883e;
            --icon-default: #8b949e;
        }

        html[data-theme="light"] {
            --bg-color: #f6f8fa;
            --panel-bg: #ffffff;
            --border-color: #d0d7de;
            --text-main: #24292f;
            --text-muted: #57606a;
            --accent: #0969da;
            --accent-hover: #0349b4;
            --badge-bg: #f3f4f6;
        }

        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; padding: 0; line-height: 1.5; display: flex; flex-direction: column; min-height: 100vh; transition: background-color 0.3s, color 0.3s; }
        
        /* HEADER - Z-INDEX 100 POUR RESTER AU-DESSUS DES COEURS */
        .header { background-color: var(--panel-bg); border-bottom: 1px solid var(--border-color); padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; gap: 20px; transition: background-color 0.3s, border-color 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .header-left { display: flex; align-items: center; flex: 1; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        
        .server-info { display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.85em; font-family: Consolas, monospace; align-items: center; }
        .badge { background: var(--badge-bg); border: 1px solid var(--border-color); padding: 4px 10px; border-radius: 2em; display: inline-flex; align-items: center; color: var(--text-muted); transition: 0.2s; cursor: default; }
        .badge:hover { border-color: var(--text-muted); }
        .badge span { color: var(--accent); font-weight: bold; margin-right: 6px; }
        
        .search-container { position: relative; }
        .search-input { background-color: var(--bg-color); border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 15px 8px 35px; border-radius: 6px; font-size: 0.9em; width: 220px; transition: 0.2s; }
        .search-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.3); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; fill: var(--text-muted); }
        
		.icon-btn { cursor: pointer; background: none; border: none; fill: var(--text-main); color: var(--text-main); width: 28px; height: 28px; padding: 0; transition: fill 0.2s, color 0.2s; display: flex; align-items: center; justify-content: center; }
        .icon-btn:hover { fill: var(--accent); color: var(--accent); }
        
        /* Couleurs spécifiques pour le soleil et la lune */
        .theme-icon-sun { color: var(--folder-icon); } 
        .theme-icon-moon { color: var(--text-main); }
        .hamburger { margin-right: 20px; }

        /* GESTION VISIBILITÉ DU BOUTON THÈME */
        .theme-icon-sun, .theme-icon-moon { display: none; }
        html[data-theme="dark"] .theme-icon-sun { display: block; }
        html[data-theme="light"] .theme-icon-moon { display: block; }

        /* SIDEBAR */
        .sidebar { position: fixed; top: 0; left: -280px; width: 280px; height: 100%; background-color: var(--panel-bg); border-right: 1px solid var(--border-color); transition: left 0.3s ease, background-color 0.3s, border-color 0.3s; z-index: 1000; display: flex; flex-direction: column; }
        .sidebar.open { left: 0; }
        .sidebar-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); font-size: 1.2em; font-weight: bold; color: var(--accent); display: flex; align-items: center; gap: 12px; }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999; display: none; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: flex; align-items: center; padding: 15px 25px; color: var(--text-main); text-decoration: none; border-bottom: 1px solid var(--border-color); transition: 0.2s; }
        .sidebar-menu li a:hover { background-color: var(--bg-color); color: var(--accent); padding-left: 30px; }
        .sidebar-icon { width: 20px; height: 20px; margin-right: 12px; flex-shrink: 0; }

        /* CONTENU PRINCIPAL */
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }
        h2 { font-size: 1.2em; font-weight: 600; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; transition: border-color 0.3s; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 40px; }
        
        .card { position: relative; background-color: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; text-decoration: none; color: var(--text-main); display: flex; align-items: center; transition: 0.15s; }
        .card:hover { border-color: var(--accent-hover); transform: translateY(-2px); }
        .card.pinned:hover { border-color: var(--folder-pinned); }
        .card.parent-dir { background-color: var(--bg-color); border-style: dashed; }
        .card-icon { margin-right: 15px; display: flex; align-items: center; }
        
        .card-details { display: flex; flex-direction: column; overflow: hidden; width: 100%; padding-right: 25px; }
        .card-name { font-weight: 600; font-size: 0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .card-meta { font-size: 0.75em; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; justify-content: space-between; }
        
        /* BOUTON COEUR (Z-index réduit à 5) */
        .fav-btn { position: absolute; top: 12px; right: 12px; z-index: 5; color: var(--text-muted); cursor: pointer; transition: transform 0.2s, color 0.2s; display: flex; align-items: center; justify-content: center; }
        .fav-btn:hover { color: var(--folder-pinned); transform: scale(1.15); }
        .fav-btn.is-fav { color: var(--folder-pinned); }
        .heart-icon { fill: none; stroke: currentColor; stroke-width: 2; transition: fill 0.2s; }
        .fav-btn.is-fav .heart-icon { fill: currentColor; }
        
        .stack-badges { display: flex; gap: 6px; }
        .stack-badge { font-size: 0.8em; padding: 2px 6px; border-radius: 4px; font-weight: bold; line-height: 1; }

        .grid.list-view { display: flex; flex-direction: column; gap: 10px; }
        .grid.list-view .card { padding: 10px 15px; }
        .grid.list-view .card-details { flex-direction: row; justify-content: space-between; align-items: center; padding-right: 35px; }
        .grid.list-view .card-meta { margin-top: 0; margin-left: 15px; white-space: nowrap; justify-content: flex-end; gap: 15px; }
		
		/* BOUTON VERSION FOOTER */
        .footer { background-color: var(--panel-bg); border-top: 1px solid var(--border-color); padding: 20px; text-align: center; font-size: 0.85em; color: var(--text-muted); margin-top: auto; position: sticky; bottom: 0; z-index: 5; transition: background-color 0.3s, border-color 0.3s; }
		.version-btn { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); background: none; border: 1px solid var(--border-color); color: var(--text-muted); padding: 4px 10px; border-radius: 4px; cursor: pointer; font-family: Consolas, monospace; font-size: 0.9em; transition: 0.2s; }
        .version-btn:hover { color: var(--accent); border-color: var(--accent); background-color: var(--badge-bg); }

        /* POPUP INFO (Modal) */
        .info-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; backdrop-filter: blur(3px); }
        .info-modal-overlay.active { display: flex; opacity: 1; }
        .info-modal-content { background-color: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 25px; max-width: 350px; width: 90%; color: var(--text-main); position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.5); transform: translateY(20px); transition: transform 0.3s ease; text-align: left; }
        .info-modal-overlay.active .info-modal-content { transform: translateY(0); }
        .info-modal-content h3 { margin-top: 0; margin-bottom: 20px; color: var(--accent); border-bottom: 1px solid var(--border-color); padding-bottom: 15px; display: flex; align-items: center; font-size: 1.3em; }
        .info-modal-content p { margin: 12px 0; font-size: 0.95em; color: var(--text-muted); }
        .info-modal-content strong { color: var(--text-main); font-weight: 600; display: inline-block; width: 90px; }
        .close-modal { position: absolute; top: 15px; right: 15px; background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; transition: color 0.2s, transform 0.2s; display: flex; align-items: center; justify-content: center; }
        .close-modal:hover { color: var(--folder-pinned); transform: scale(1.1); }
                
        .back-to-top { position: fixed; bottom: 70px; right: 30px; background-color: var(--accent); color: #fff; border: none; border-radius: 50%; width: 45px; height: 45px; cursor: pointer; display: none; align-items: center; justify-content: center; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.4); transition: 0.2s; }
        .back-to-top:hover { background-color: var(--accent-hover); transform: translateY(-3px); }
        .back-to-top svg { fill: currentColor; width: 24px; height: 24px; }

        .svg-icon { width: 24px; height: 24px; }
        .fill-folder { fill: var(--folder-icon); }
        .fill-pinned { fill: var(--folder-pinned); }
        .fill-code { fill: var(--icon-code); }
        .fill-image { fill: var(--icon-image); }
        .fill-archive { fill: var(--icon-archive); }
        .fill-media { fill: var(--icon-media); }
        .fill-document { fill: var(--icon-document); }
        .fill-default { fill: var(--icon-default); }
        
		.fill-config { fill: #89929b; }     /* Gris métal pour les configs */
        .fill-android { fill: #3ddc84; }    /* Vert officiel Android pour les APK */
        .fill-exe { fill: var(--accent); }  /* Bleu dynamique pour les exécutables */
        
		/* GESTION VISIBILITÉ BOUTON GRILLE/LISTE */
        .icon-grid-view { display: block; }
        .icon-list-view { display: none; }
        #viewToggleBtn.active-list .icon-grid-view { display: none; }
        #viewToggleBtn.active-list .icon-list-view { display: block; }
                
        #no-results { display: none; color: var(--text-muted); text-align: center; margin-top: 50px; }
    </style>
</head>
<body>

    <!-- OVERLAY ET MENU LATÉRAL -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <svg viewBox="0 0 24 24" style="width: 26px; height: 26px; flex-shrink: 0;">
                <path d="M12 0 C12 0 10.5 2 10.5 3.5 C10.5 4.3 11.2 5 12 5 C12.8 5 13.5 4.3 13.5 3.5 C13.5 2 12 0 12 0 Z" fill="#3498db"/>
                <path d="M15.5 2.5 C15.5 2.5 14.5 4 14.5 5 C14.5 5.5 14.9 6 15.5 6 C16.1 6 16.5 5.5 16.5 5 C16.5 4 15.5 2.5 15.5 2.5 Z" fill="#58a6ff"/>
                <path d="M8.5 2.5 C8.5 2.5 7.5 4 7.5 5 C7.5 5.5 7.9 6 8.5 6 C9.1 6 9.5 5.5 9.5 5 C9.5 4 8.5 2.5 8.5 2.5 Z" fill="#58a6ff"/>
                <path d="M21 13.5 C21 11.5 19.5 9 16.5 8.5 C13 8 9.5 8.5 6 10.5 C5 11 3 11.5 3 14.5 L3 19 L6 19 L6 23 L9 23 L9 19 L13 19 L13 23 L16 23 L16 18.5 C18 18.5 20.5 17.5 21 15.5 Z" fill="#777BB4"/>
                <path d="M16 8.5 C17 6 19.5 5 21 6.5 C21.5 7 21 8.5 19.5 9.5" fill="none" stroke="#777BB4" stroke-width="2.5" stroke-linecap="round"/>
                <circle cx="15.5" cy="11.5" r="1.2" fill="#0d1117"/>
            </svg>
            Laragon Tools
        </div>
        <ul class="sidebar-menu">
            <li><a href="/">
                <svg class="sidebar-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg> Vue d'ensemble (Racine)
            </a></li>
            <li><a href="?action=root<?php echo $isSubFolder ? '&path=' . urlencode($subPath) : ''; ?>">
                <svg class="sidebar-icon" viewBox="0 0 24 24" fill="#e3b341"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg> Explorer ce dossier (Windows)
            </a></li>
            <li><a href="?action=terminal<?php echo $isSubFolder ? '&path=' . urlencode($subPath) : ''; ?>">
                <svg class="sidebar-icon" viewBox="0 0 24 24">
                    <rect x="2" y="3" width="20" height="18" rx="2" fill="#1e1e1e" stroke="#444" stroke-width="1"/>
                    <text x="7" y="17.5" fill="#4af626" font-family="Arial, sans-serif" font-size="16" font-weight="bold">λ</text>
                </svg> Terminal dans ce dossier
            </a></li>
            <li><a href="/?q=info" target="_blank">
                <svg class="sidebar-icon" viewBox="0 0 24 24" fill="#8892BF"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg> Info PHP
            </a></li>
            <li><a href="http://localhost/phpmyadmin" target="_blank">
                <svg class="sidebar-icon" viewBox="0 0 24 24">
                    <path d="M13,3 L13,15 L21,15 Z" fill="#f89d1b"/>
                    <path d="M11,5 L11,15 L3,15 Z" fill="#e97b00"/>
                    <path d="M2,17 L22,17 L19,21 L5,21 Z" fill="#999"/>
                </svg> phpMyAdmin
            </a></li>
        </ul>
    </div>

    <!-- HEADER PRINCIPAL -->
    <header class="header">
        <div class="header-left">
            <button class="icon-btn hamburger" id="hamburgerBtn" title="Menu">
                <svg viewBox="0 0 16 16"><path d="M1 2.75A.75.75 0 0 1 1.75 2h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 2.75Zm0 5A.75.75 0 0 1 1.75 7h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 7.75ZM1.75 12h12.5a.75.75 0 0 1 0 1.5H1.75a.75.75 0 0 1 0-1.5Z"></path></svg>
            </button>
            <div class="server-info">
                <div class="badge"><span>⚡ PHP</span> <?php echo phpversion(); ?></div>
                <div class="badge"><span>🌐 Web</span> <?php echo $services['Web']; ?></div>
                
                <?php if (isset($services['MySQL'])): ?>
                    <div class="badge" title="Connecté"><span>🐬 MySQL</span> <?php echo $services['MySQL']; ?></div>
                <?php endif; ?>
                
                <?php if (isset($services['Redis'])): ?>
                    <div class="badge"><span>🔴 Redis</span> <?php echo $services['Redis']; ?></div>
                <?php endif; ?>

                <?php if (isset($services['Memcached'])): ?>
                    <div class="badge"><span>📦 Memcached</span> <?php echo $services['Memcached']; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-right">
            <div class="search-container">
                <svg class="search-icon" viewBox="0 0 16 16"><path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"></path></svg>
                <input type="text" id="searchInput" class="search-input" placeholder="Filtrer...">
            </div>
            
            <!-- BOUTON THEME SOMBRE/CLAIR -->
            <button class="icon-btn" id="themeToggleBtn" title="Basculer le thème">
                <svg class="theme-icon-sun" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0l1.06-1.06zM7.05 18.36c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41.39.39 1.03.39 1.41 0l1.06-1.06z"/>
                </svg>
                <svg class="theme-icon-moon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-2.98 0-5.4-2.42-5.4-5.4 0-1.81.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/>
                </svg>
            </button>

			<button class="icon-btn" id="viewToggleBtn" title="Basculer Grille/Liste">
                <svg class="icon-grid-view" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z"/>
                </svg>
                <svg class="icon-list-view" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M8 5h12v2H8V5zm0 6h12v2H8v-2zm0 6h12v2H8v-2zM4 5h2v2H4V5zm0 6h2v2H4v-2zm0 6h2v2H4v-2z"/>
                </svg>
            </button>
        </div>
    </header>

    <div class="container">
        
        <!-- SECTION ÉPINGLÉS (UNIQUEMENT À LA RACINE) -->
        <?php if (!$isSubFolder): ?>
        <div id="section-pinned" style="display: <?php echo $pinnedCount > 0 ? 'block' : 'none'; ?>;">
            <h2 id="title-pinned" style="color: var(--folder-pinned);">
                <svg viewBox="0 0 24 24" style="width: 22px; height: 22px; flex-shrink: 0;" fill="currentColor">
                    <path d="M16 12l2 2v2h-5v6l-1 1l-1-1v-6H6v-2l2-2V5H7V3h10v2h-1v7z"/>
                </svg>
                Projets Épinglés (<span id="count-pinned"><?php echo $pinnedCount; ?></span>)
            </h2>
            <div class="grid" id="grid-pinned">
                <?php foreach ($pinnedFolders as $folder): ?>
                    <?php 
                        // Lien intelligent : va sur l'index s'il y en a un, ou dans l'explorateur du dashboard sinon
                        $link = $folder['has_index'] ? '/' . rawurlencode($folder['name']) . '/' : '?path=' . urlencode($folder['name']); 
                    ?>
                    <a href="<?php echo $link; ?>" class="card project-item pinned">
                        <!-- Bouton Cœur -->
                        <div class="fav-btn is-fav" data-folder="<?php echo htmlspecialchars($folder['name']); ?>" title="Retirer des favoris">
                            <svg class="heart-icon" viewBox="0 0 24 24" width="18" height="18" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                            </svg>
                        </div>
                        <div class="card-icon">
                            <svg class="svg-icon fill-pinned" viewBox="0 0 16 16"><path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"></path></svg>
                        </div>
                        <div class="card-details">
                            <span class="card-name"><?php echo htmlspecialchars($folder['name']); ?></span>
                            <div class="card-meta">
                                <span>Modifié le <?php echo $folder['mtime']; ?></span>
                                <?php if (!empty($folder['stack'])): ?>
                                    <div class="stack-badges">
                                        <?php foreach ($folder['stack'] as $badge): ?>
                                            <span class="stack-badge" style="background-color: <?php echo $badge['bg']; ?>; color: <?php echo $badge['color']; ?>;"><?php echo $badge['name']; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <h2 id="title-folders">
            <svg viewBox="0 0 16 16" width="20" height="20" fill="var(--folder-icon)"><path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"></path></svg>
            <?php echo $displayTitle; ?> (<span id="count-folders"><?php echo $normalCount; ?></span>)
        </h2>
        <div class="grid" id="grid-folders">
            <?php foreach ($normalFolders as $folder): ?>
                <?php 
                if (isset($folder['is_back'])) {
                    $link = $folder['target_path'] === '' ? '/' : '?path=' . urlencode($folder['target_path']);
                } else {
                    $itemPath = $isSubFolder ? $subPath . '/' . $folder['name'] : $folder['name'];
                    if ($folder['has_index']) {
                        $link = '/' . str_replace('%2F', '/', rawurlencode($itemPath)) . '/';
                    } else {
                        $link = '?path=' . urlencode($itemPath);
                    }
                }
                ?>
                <a href="<?php echo $link; ?>" class="card project-item <?php echo isset($folder['is_back']) ? 'parent-dir' : ''; ?>">
                    
                    <?php if (!isset($folder['is_back'])): ?>
                    <div class="fav-btn" data-folder="<?php echo htmlspecialchars($folder['name']); ?>" title="Ajouter aux favoris">
                        <svg class="heart-icon" viewBox="0 0 24 24" width="18" height="18" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                    
                    <div class="card-icon">
                        <?php if (isset($folder['is_back'])): ?>
                            <svg class="svg-icon fill-folder" viewBox="0 0 24 24" fill="var(--accent)"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                        <?php else: ?>
                            <svg class="svg-icon fill-folder" viewBox="0 0 16 16"><path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"></path></svg>
                        <?php endif; ?>
                    </div>
                    <div class="card-details">
                        <span class="card-name"><?php echo htmlspecialchars($folder['name']); ?></span>
                        <div class="card-meta">
                            <?php if (isset($folder['is_back'])): ?>
                                <span>Remonter d'un niveau</span>
                            <?php else: ?>
                                <span>Modifié le <?php echo $folder['mtime']; ?></span>
                            <?php endif; ?>
                            
                            <?php if (!empty($folder['stack'])): ?>
                                <div class="stack-badges">
                                    <?php foreach ($folder['stack'] as $badge): ?>
                                        <span class="stack-badge" style="background-color: <?php echo $badge['bg']; ?>; color: <?php echo $badge['color']; ?>;"><?php echo $badge['name']; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($files)): ?>
        <h2 id="title-files">
            <svg viewBox="0 0 16 16" width="20" height="20" fill="var(--icon-default)"><path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"></path></svg>
            Fichiers (<?php echo $fileCount; ?>)
        </h2>
        <div class="grid" id="grid-files">
            <?php foreach ($files as $file): ?>
                <?php 
                $itemPath = $isSubFolder ? $subPath . '/' . $file['name'] : $file['name'];
                $link = '/' . str_replace('%2F', '/', rawurlencode($itemPath));
                ?>
                <a href="<?php echo $link; ?>" class="card project-item" target="_blank">
                    <div class="card-icon">
                        <svg class="svg-icon fill-<?php echo $file['type']; ?>" viewBox="0 0 16 16">
                            <?php echo getFileSvg($file['type']); ?>
                        </svg>
                    </div>
                    <div class="card-details">
                        <span class="card-name"><?php echo htmlspecialchars($file['name']); ?></span>
                        <span class="card-meta"><?php echo $file['size']; ?> • <?php echo $file['mtime']; ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="no-results"><h3>Aucun projet trouvé.</h3></div>
    </div>

    <button class="back-to-top" id="backToTopBtn" title="Retour en haut">
        <svg viewBox="0 0 16 16"><path d="M3.22 9.78a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1-1.06 1.06L8 6.06 4.28 9.78a.75.75 0 0 1-1.06 0Z"></path></svg>
    </button>

	<footer class="footer">
        &copy; <?php echo date('Y'); ?> ZelTroN2k3 - Dashboard Local Laragon
        <!-- Bouton Version -->
        <button class="version-btn" id="infoPopupBtn" title="À propos">v1.1.0</button>
    </footer>

    <!-- POPUP INFO (Modal) -->
    <div class="info-modal-overlay" id="infoModal">
        <div class="info-modal-content">
            <button class="close-modal" id="closeModalBtn" title="Fermer">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <h3>
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" style="margin-right: 8px;">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
                Informations
            </h3>
            <p><strong>Auteur :</strong> ZelTroN2k3</p>
            <p><strong>Création :</strong> Septembre 2026</p>
            <p><strong>Version :</strong> 1.1.0 (Nav. Dynamique)</p>
        </div>
    </div>

    <script>
        // GESTION DU THÈME SOMBRE/CLAIR
        const themeToggleBtn = document.getElementById('themeToggleBtn');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                document.documentElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
            });
        }

        // GESTION DES FAVORIS (Cœurs)
        document.querySelectorAll('.fav-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const folderName = this.getAttribute('data-folder');
                const card = this.closest('.card');
                let favs = getFavsFromCookie();

                const isFav = this.classList.contains('is-fav');

                if (isFav) {
                    favs = favs.filter(f => f !== folderName);
                    this.classList.remove('is-fav');
                    card.classList.remove('pinned');
                    this.setAttribute('title', 'Ajouter aux favoris');
                    document.getElementById('grid-folders').appendChild(card);
                } else {
                    favs.push(folderName);
                    this.classList.add('is-fav');
                    card.classList.add('pinned');
                    this.setAttribute('title', 'Retirer des favoris');
                    document.getElementById('grid-pinned').appendChild(card);
                }

                saveFavsToCookie(favs);
                updateSectionCounts();
            });
        });

        function getFavsFromCookie() {
            const match = document.cookie.match(/(^| )laragon_favs=([^;]+)/);
            if (match) {
                try { return JSON.parse(decodeURIComponent(match[2])); } catch(e) {}
            }
            return [];
        }

        function saveFavsToCookie(favs) {
            document.cookie = "laragon_favs=" + encodeURIComponent(JSON.stringify(favs)) + ";path=/;max-age=31536000"; 
        }

        function updateSectionCounts() {
            const pinnedCount = document.getElementById('grid-pinned').children.length;
            const foldersCount = document.getElementById('grid-folders').children.length;

            const countPinnedEl = document.getElementById('count-pinned');
            if (countPinnedEl) countPinnedEl.textContent = pinnedCount;
            
            document.getElementById('count-folders').textContent = foldersCount;

            const sectionPinned = document.getElementById('section-pinned');
            if (sectionPinned) {
                sectionPinned.style.display = pinnedCount > 0 ? 'block' : 'none';
            }
        }

        // RECHERCHE
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const items = document.querySelectorAll('.project-item');
            let visibleCount = 0;
            items.forEach(item => {
                const name = item.querySelector('.card-name').textContent.toLowerCase();
                if (name.includes(term)) { item.style.display = 'flex'; visibleCount++; } 
                else { item.style.display = 'none'; }
            });
            document.getElementById('no-results').style.display = visibleCount === 0 ? 'block' : 'none';
        });

        // MENU HAMBURGER
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        function toggleSidebar() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }
        document.getElementById('hamburgerBtn').addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);

		// VUE GRILLE/LISTE
        document.getElementById('viewToggleBtn').addEventListener('click', function() {
            // Inverse l'icône du bouton
            this.classList.toggle('active-list');
            // Change l'affichage des dossiers
            document.querySelectorAll('.grid').forEach(grid => grid.classList.toggle('list-view'));
        });

        // RETOUR EN HAUT
        const backToTopBtn = document.getElementById('backToTopBtn');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 250) { backToTopBtn.style.display = 'flex'; } 
            else { backToTopBtn.style.display = 'none'; }
        });
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
		// POPUP INFORMATIONS
        const infoModal = document.getElementById('infoModal');
        const infoPopupBtn = document.getElementById('infoPopupBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');

        function openModal() {
            infoModal.style.display = 'flex';
            // Petit délai pour permettre à la transition CSS de se déclencher
            setTimeout(() => infoModal.classList.add('active'), 10);
        }

        function closeModal() {
            infoModal.classList.remove('active');
            // Attend la fin de l'animation CSS avant de cacher l'élément
            setTimeout(() => infoModal.style.display = 'none', 300);
        }

        infoPopupBtn.addEventListener('click', openModal);
        closeModalBtn.addEventListener('click', closeModal);
        
        // Ferme le popup si on clique en dehors de la boîte
        infoModal.addEventListener('click', (e) => {
            if (e.target === infoModal) closeModal();
        });
        
		// FORCE LE RECHARGEMENT LORS D'UN RETOUR EN ARRIÈRE (Évite le bug bfcache des extensions)
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });       
    </script>
</body>
</html>