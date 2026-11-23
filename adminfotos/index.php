<?php
/*
 * Admin de fotos de BA on my way (/adminfotos/).
 * Entra con usuario y contraseña, sube y borra fotos.
 * Las fotos quedan en /fotos (WebP) y la lista en /photos.json. No hay base de datos.
 */
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');
@ini_set('memory_limit', '512M');
@set_time_limit(120);

const MAX_SIDE = 1600;     // lado más largo de cada foto, en píxeles
const WEBP_QUALITY = 80;
const MAX_FILES = 20;      // fotos por envío
const MAX_FAILS = 5;       // intentos de login antes de bloquear
const LOCK_SECONDS = 300;

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Falta adminfotos/config.php. Copiá config.sample.php y completalo.');
}
$cfg = require $cfgFile;

$root = dirname(__DIR__);
$photosDir = $root . '/fotos';
$listFile = $root . '/photos.json';
$lockFile = __DIR__ . '/.lock';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('bomw_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/adminfotos/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

function flash($type, $msg) {
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash() {
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

function authed() {
    return !empty($_SESSION['ok']);
}

function back() {
    header('Location: /adminfotos/', true, 303);
    exit;
}

function read_list($file) {
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return (isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : [];
}

function write_list($file, array $photos) {
    $json = json_encode(['photos' => array_values($photos)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }
    return rename($tmp, $file);
}

// Todo cambio de la lista pasa por acá, de a uno por vez
function with_lock($lockFile, $fn) {
    $fp = fopen($lockFile, 'c');
    flock($fp, LOCK_EX);
    try {
        $res = $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $res;
}

// Convierte la foto a WebP, la achica si hace falta y la gira según la orientación EXIF
function make_webp($src, $dst) {
    $info = @getimagesize($src);
    if (!$info) {
        return false;
    }
    $img = false;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src); break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif($src); break;
        case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($src); break;
    }
    if (!$img) {
        return false;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        $angle = 0;
        if ($o === 3) { $angle = 180; }
        if ($o === 6) { $angle = 270; }
        if ($o === 8) { $angle = 90; }
        if ($angle) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot) {
                imagedestroy($img);
                $img = $rot;
            }
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $ok = imagewebp($out, $dst, WEBP_QUALITY);
    imagedestroy($out);
    return $ok ? ['w' => $nw, 'h' => $nh] : false;
}

function upload_error_text($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'es más pesada que el límite del servidor (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_PARTIAL:
            return 'se subió incompleta, probá otra vez';
        default:
            return 'no se pudo subir';
    }
}

function handle_upload($photosDir, $listFile, $lockFile) {
    $f = isset($_FILES['fotos']) ? $_FILES['fotos'] : null;
    if (!$f || !is_array($f['name'])) {
        flash('err', 'No elegiste ninguna foto.');
        return;
    }
    $total = 0;
    foreach ($f['error'] as $e) {
        if ($e !== UPLOAD_ERR_NO_FILE) {
            $total++;
        }
    }
    if ($total === 0) {
        flash('err', 'No elegiste ninguna foto.');
        return;
    }
    if ($total > MAX_FILES) {
        flash('err', 'Subí hasta ' . MAX_FILES . ' fotos por vez.');
        return;
    }
    if (!is_dir($photosDir) && !mkdir($photosDir, 0755, true)) {
        flash('err', 'No se pudo crear la carpeta fotos/.');
        return;
    }
    if (!is_file($photosDir . '/index.html')) {
        file_put_contents($photosDir . '/index.html', '');
    }

    $new = [];
    $written = [];
    foreach ($f['name'] as $i => $name) {
        $err = $f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            flash('err', $name . ': ' . upload_error_text($err));
            continue;
        }
        $id = bin2hex(random_bytes(8));
        $dst = $photosDir . '/' . $id . '.webp';
        $size = make_webp($f['tmp_name'][$i], $dst);
        if ($size === false) {
            flash('err', $name . ': formato no compatible. Si es HEIC (iPhone), exportala como JPG.');
            continue;
        }
        $written[] = $dst;
        $new[] = ['id' => $id, 'file' => 'fotos/' . $id . '.webp', 'w' => $size['w'], 'h' => $size['h'], 'added' => date('c')];
    }
    if (!$new) {
        return;
    }
    $saved = with_lock($lockFile, function () use ($listFile, $new) {
        $list = read_list($listFile);
        return write_list($listFile, array_merge($new, $list));
    });
    if ($saved) {
        flash('ok', 'Se subieron ' . count($new) . ' foto(s). Se ven en el sitio al recargarlo.');
    } else {
        foreach ($written as $file) {
            @unlink($file);
        }
        flash('err', 'No se pudo guardar la lista de fotos. Probá de nuevo.');
    }
}

function handle_delete($photosDir, $listFile, $lockFile) {
    $id = preg_replace('/[^a-f0-9]/', '', (string)(isset($_POST['id']) ? $_POST['id'] : ''));
    if ($id === '') {
        flash('err', 'No se encontró esa foto.');
        return;
    }
    $found = with_lock($lockFile, function () use ($photosDir, $listFile, $id) {
        $list = read_list($listFile);
        $keep = [];
        $hit = false;
        foreach ($list as $p) {
            if (isset($p['id']) && $p['id'] === $id) {
                $hit = true;
            } else {
                $keep[] = $p;
            }
        }
        if ($hit) {
            write_list($listFile, $keep);
            @unlink($photosDir . '/' . $id . '.webp');
        }
        return $hit;
    });
    if ($found) {
        flash('ok', 'Foto borrada. El sitio la deja de mostrar al recargarse.');
    } else {
        flash('err', 'No se encontró esa foto.');
    }
}

function try_login($cfg) {
    if (!empty($_SESSION['lock_until']) && $_SESSION['lock_until'] > time()) {
        flash('err', 'Demasiados intentos. Esperá unos minutos.');
        return;
    }
    $okUser = strcasecmp((string)(isset($_POST['user']) ? $_POST['user'] : ''), $cfg['user']) === 0;
    $okPass = password_verify((string)(isset($_POST['pass']) ? $_POST['pass'] : ''), $cfg['hash']);
    if ($okUser && $okPass) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['fails'] = 0;
        unset($_SESSION['lock_until']);
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return;
    }
    usleep(500000);
    $_SESSION['fails'] = (isset($_SESSION['fails']) ? $_SESSION['fails'] : 0) + 1;
    if ($_SESSION['fails'] >= MAX_FAILS) {
        $_SESSION['lock_until'] = time() + LOCK_SECONDS;
        $_SESSION['fails'] = 0;
    }
    flash('err', 'Usuario o contraseña incorrectos.');
}

// ---- Acciones (todas terminan en un redirect) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if (empty($_POST) && empty($_FILES) && (int)(isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0) > 0) {
        flash('err', 'Las fotos son demasiado pesadas para el servidor (límite ' . ini_get('post_max_size') . ').');
        back();
    }
    if ($action === 'login') {
        if (!csrf_ok()) {
            flash('err', 'La página venció. Probá de nuevo.');
        } else {
            try_login($cfg);
        }
        back();
    }
    if (!authed()) {
        back();
    }
    if (!csrf_ok()) {
        flash('err', 'La sesión venció. Entrá de nuevo.');
        back();
    }
    switch ($action) {
        case 'upload':
            handle_upload($photosDir, $listFile, $lockFile);
            break;
        case 'delete':
            handle_delete($photosDir, $listFile, $lockFile);
            break;
        case 'logout':
            $_SESSION = [];
            session_destroy();
            break;
    }
    back();
}

// ---- Página ----
$flashes = take_flash();
$token = csrf_token();
$logged = authed();
$photos = $logged ? read_list($listFile) : [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Fotos del sitio · Admin</title>
<style>
:root { --bg:#f6f3ee; --fg:#1e1433; --card:#ffffff; --line:#e3dccd; --accent:#7a3cff; --danger:#b3261e; --muted:#6b6470; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg:#1a1426; --fg:#f2eefa; --card:#241c33; --line:#3a2f4d; --accent:#9b6bff; --muted:#b3a9c2; color-scheme: dark; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--fg); font:15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding:16px; }
main { max-width:980px; margin:0 auto; }
h1 { font-size:22px; margin:8px 0 12px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:16px; }
.msg { padding:10px 12px; border-radius:8px; margin:0 0 12px; }
.msg.ok { background:#e7f6ea; color:#1d5c2a; }
.msg.err { background:#fdecea; color:#8a1c14; }
label { display:block; font-weight:600; margin:10px 0 4px; }
input[type=text], input[type=password] { width:100%; padding:10px; border:1px solid var(--line); border-radius:8px; font:inherit; background:var(--card); color:var(--fg); }
button { font:inherit; border:0; border-radius:999px; padding:10px 18px; background:var(--accent); color:#fff; cursor:pointer; }
button.ghost { background:transparent; color:var(--fg); border:1px solid var(--line); }
button.danger { background:var(--danger); width:100%; }
.row { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
.meta { color:var(--muted); font-size:13px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; }
.thumb { background:var(--card); border:1px solid var(--line); border-radius:10px; overflow:hidden; min-width:0; }
.thumb img { display:block; width:100%; aspect-ratio:4/3; object-fit:cover; background:var(--line); }
.thumb form { margin:0; padding:8px; }
</style>
</head>
<body>
<main>
<?php foreach ($flashes as $fl): ?>
  <p class="msg <?= $fl[0] === 'ok' ? 'ok' : 'err' ?>"><?= h($fl[1]) ?></p>
<?php endforeach; ?>

<?php if (!$logged): ?>
  <h1>Entrar</h1>
  <section class="card">
    <form method="post" action="/adminfotos/">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="login">
      <label for="user">Usuario</label>
      <input id="user" name="user" type="text" autocomplete="username" required>
      <label for="pass">Contraseña</label>
      <input id="pass" name="pass" type="password" autocomplete="current-password" required>
      <p><button type="submit">Entrar</button></p>
    </form>
  </section>
<?php else: ?>
  <div class="row">
    <h1>Fotos del sitio</h1>
    <form method="post" action="/adminfotos/">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <button class="ghost" type="submit" name="action" value="logout">Salir</button>
    </form>
  </div>
  <p class="meta"><?= count($photos) ?> fotos publicadas. Los cambios se ven en el sitio al recargarlo.</p>

  <section class="card">
    <form method="post" action="/adminfotos/" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="upload">
      <label for="fotos">Subir fotos</label>
      <input id="fotos" name="fotos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
      <p class="meta">Hasta <?= MAX_FILES ?> por vez. JPG, PNG, WebP o GIF. Límite del servidor: <?= h(ini_get('upload_max_filesize')) ?>. WebP disponible: <?= function_exists('imagewebp') ? 'sí' : 'no' ?>.</p>
      <p><button type="submit">Subir fotos</button></p>
    </form>
  </section>

  <?php if (!$photos): ?>
    <p class="meta">Todavía no hay fotos. Mientras tanto el sitio muestra las fotos de respaldo.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($photos as $p): ?>
        <div class="thumb">
          <img src="/<?= h($p['file']) ?>" alt="" loading="lazy">
          <form method="post" action="/adminfotos/" onsubmit="return confirm('¿Borrar esta foto del sitio?');">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= h($p['id']) ?>">
            <button class="danger" type="submit">Borrar</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
