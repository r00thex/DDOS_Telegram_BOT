<?php
/**
 * Safe Web File Manager - FINAL v6.0
 * ------------------------------------------------------------
 * Tek dosya PHP dosya yöneticisi.
 * Amaç: KENDİ sunucunda / yetkili olduğun hosting hesabında dosya listeleme,
 * düzenleme, yükleme, indirme, izin değiştirme ve altyapı tespiti.
 *
 * Varsayılan giriş şifresi: admin
 *
 * Güvenlik notları:
 * - Terminal / shell komutu / process manager YOKTUR.
 * - SMTP oluşturma, credential tarama, self-delete/backdoor YOKTUR.
 * - PHP 7.x uyumludur; str_starts_with(), match, typed property, ?? gibi
 *   PHP 8'e özel kullanımlar özellikle kullanılmamıştır.
 */

/* ===================== AYARLAR ===================== */
$FM_APP_NAME = 'Safe Web File Manager';
$FM_VERSION  = 'FINAL-v6.0-php7-compatible';
$FM_PASSWORD = 'admin'; // Kullanıcının istediği sabit şifre

$FM_MAX_UPLOAD_BYTES = 100 * 1024 * 1024; // 100 MB / dosya
$FM_MAX_EDIT_BYTES   = 10 * 1024 * 1024;  // 10 MB edit limiti
$FM_BACKUP_ON_SAVE   = true;              // Kaydetmeden önce .bak yedeği alır
$FM_ALLOW_DELETE     = true;              // Silme aktif; boş olmayan klasörü silmez
$FM_SHOW_HIDDEN      = true;              // .env, .htaccess, .git gibi dosyaları göster
$FM_FILES_FIRST      = true;              // index.php / php dosyaları klasörlerden önce gelsin
$FM_SEARCH_LIMIT     = 3000;              // Recursive aramada maksimum sonuç

// Boş kalırsa şifreyle giriş yapan herkes erişebilir. Örnek: array('1.2.3.4')
$FM_ALLOWED_IPS = array();

// Root adayları otomatik eklenecek. İstersen buraya özel path ekleyebilirsin.
$FM_EXTRA_ROOTS = array(
    // '/home/kullanici',
    // '/home/kullanici/public_html',
);
/* =================== AYARLAR BİTİŞ =================== */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('memory_limit', '256M');
@set_time_limit(0);
@session_start();
@ob_start();

/* ===================== UYUMLULUK ===================== */
function fm_server($key, $default) { return isset($_SERVER[$key]) ? $_SERVER[$key] : $default; }
function fm_post($key, $default) { return isset($_POST[$key]) ? $_POST[$key] : $default; }
function fm_get($key, $default) { return isset($_GET[$key]) ? $_GET[$key] : $default; }

function fm_starts_with($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function fm_ends_with($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return true;
    return substr($haystack, -strlen($needle)) === $needle;
}

function fm_hash_equals($known, $user) {
    $known = (string)$known;
    $user = (string)$user;
    if (function_exists('hash_equals')) return hash_equals($known, $user);
    if (strlen($known) !== strlen($user)) return false;
    $res = 0;
    for ($i = 0; $i < strlen($known); $i++) $res |= ord($known[$i]) ^ ord($user[$i]);
    return $res === 0;
}

function fm_random_token() {
    if (function_exists('random_bytes')) return bin2hex(random_bytes(32));
    if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes(32));
    return hash('sha256', uniqid('', true) . mt_rand() . microtime(true));
}

function fm_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (fm_server('SERVER_PORT', '') === '443');
}

function fm_cookie($name, $value, $expire) {
    @setcookie($name, $value, $expire, '/', '', fm_is_https(), true);
}

function fm_json($data) {
    while (@ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function fm_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fm_clean_slashes($p) {
    $p = str_replace('\\', '/', (string)$p);
    $p = preg_replace('#/+#', '/', $p);
    return $p;
}

function fm_real($path) {
    $r = @realpath($path);
    if ($r === false || $r === null || $r === '') return false;
    return rtrim(fm_clean_slashes($r), '/');
}

function fm_parent($path) {
    $d = dirname($path);
    if ($d === '.' || $d === '') return '/';
    return rtrim(fm_clean_slashes($d), '/');
}

function fm_format_size($bytes) {
    if ($bytes === '-' || $bytes === null) return '-';
    $bytes = (float)$bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function fm_perms($path) {
    $p = @fileperms($path);
    if ($p === false) return '----';
    return substr(sprintf('%o', $p), -4);
}

function fm_mtime($path) {
    $t = @filemtime($path);
    return $t ? date('Y-m-d H:i:s', $t) : '-';
}

function fm_ext($name) {
    $pos = strrpos($name, '.');
    if ($pos === false) return '';
    return strtolower(substr($name, $pos + 1));
}

function fm_join($dir, $name) {
    return rtrim($dir, '/') . '/' . ltrim($name, '/');
}

function fm_safe_name($name) {
    $name = trim((string)$name);
    $name = str_replace(array("\0", '/', '\\'), '', $name);
    return $name;
}

/* ===================== ROOT / PATH ===================== */
function fm_collect_roots($extra_roots) {
    $candidates = array();
    $candidates[] = __DIR__;
    $candidates[] = @getcwd();
    $doc = fm_server('DOCUMENT_ROOT', '');
    if ($doc !== '') {
        $candidates[] = $doc;
        $candidates[] = dirname($doc);
    }
    $script_dir = dirname(fm_server('SCRIPT_FILENAME', __FILE__));
    if ($script_dir !== '') {
        $candidates[] = $script_dir;
        $candidates[] = dirname($script_dir);
    }
    $home = getenv('HOME');
    if ($home) $candidates[] = $home;
    if (isset($_SERVER['HOME']) && $_SERVER['HOME']) $candidates[] = $_SERVER['HOME'];
    if (isset($_SERVER['USER']) && $_SERVER['USER']) {
        $candidates[] = '/home/' . $_SERVER['USER'];
    }
    if (isset($_SERVER['USERNAME']) && $_SERVER['USERNAME']) {
        $candidates[] = '/home/' . $_SERVER['USERNAME'];
    }
    if (is_array($extra_roots)) {
        foreach ($extra_roots as $r) $candidates[] = $r;
    }

    $roots = array();
    foreach ($candidates as $r) {
        if (!$r) continue;
        $real = fm_real($r);
        if ($real !== false && @is_dir($real)) $roots[$real] = $real;
    }

    // En geniş faydalı root en üste gelsin: /home/user varsa önce onu koy.
    $arr = array_values($roots);
    usort($arr, function($a, $b) {
        return strlen($a) - strlen($b);
    });
    return $arr;
}

function fm_path_inside($path, $root) {
    $path = rtrim(fm_clean_slashes($path), '/');
    $root = rtrim(fm_clean_slashes($root), '/');
    if ($path === $root) return true;
    return substr($path, 0, strlen($root) + 1) === ($root . '/');
}

function fm_roots() {
    global $FM_EXTRA_ROOTS;
    return fm_collect_roots($FM_EXTRA_ROOTS);
}

function fm_default_root() {
    $roots = fm_roots();
    if (empty($roots)) return fm_real(__DIR__);
    // Mümkünse hosting HOME veya document root parent kullan, böylece tüm altyapılar görülebilir.
    $doc = fm_real(fm_server('DOCUMENT_ROOT', ''));
    if ($doc !== false) {
        $parent = fm_real(dirname($doc));
        if ($parent !== false) {
            foreach ($roots as $r) if ($r === $parent) return $r;
        }
        foreach ($roots as $r) if ($r === $doc) return $r;
    }
    return $roots[0];
}

function fm_allowed_path($path, $must_exist) {
    $roots = fm_roots();
    if ($must_exist) {
        $real = fm_real($path);
        if ($real === false) return false;
        foreach ($roots as $root) if (fm_path_inside($real, $root)) return $real;
        return false;
    }
    $parent = fm_real(dirname($path));
    if ($parent === false) return false;
    foreach ($roots as $root) if (fm_path_inside($parent, $root)) return fm_join($parent, basename($path));
    return false;
}

function fm_current_root() {
    if (isset($_SESSION['fm_root'])) {
        $r = fm_allowed_path($_SESSION['fm_root'], true);
        if ($r !== false && @is_dir($r)) return $r;
    }
    $r = fm_default_root();
    $_SESSION['fm_root'] = $r;
    return $r;
}

function fm_current_dir() {
    $root = fm_current_root();
    if (isset($_SESSION['fm_cwd'])) {
        $d = fm_allowed_path($_SESSION['fm_cwd'], true);
        if ($d !== false && @is_dir($d) && fm_path_inside($d, $root)) return $d;
    }
    $_SESSION['fm_cwd'] = $root;
    return $root;
}

/* ===================== AUTH ===================== */
function fm_client_ip() {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return fm_server('REMOTE_ADDR', '');
}

function fm_ip_allowed() {
    global $FM_ALLOWED_IPS;
    if (!is_array($FM_ALLOWED_IPS) || count($FM_ALLOWED_IPS) === 0) return true;
    return in_array(fm_client_ip(), $FM_ALLOWED_IPS, true);
}

function fm_logged_in() {
    return isset($_SESSION['fm_auth']) && $_SESSION['fm_auth'] === true;
}

function fm_require_auth() {
    if (!fm_ip_allowed()) fm_json(array('ok' => false, 'error' => 'IP izinli değil.'));
    if (!fm_logged_in()) fm_json(array('ok' => false, 'error' => 'AUTH_REQUIRED'));
}

function fm_csrf() {
    if (!isset($_SESSION['fm_csrf']) || !$_SESSION['fm_csrf']) $_SESSION['fm_csrf'] = fm_random_token();
    return $_SESSION['fm_csrf'];
}

function fm_require_csrf() {
    $sent = fm_post('csrf', '');
    if (!fm_hash_equals(fm_csrf(), $sent)) fm_json(array('ok' => false, 'error' => 'CSRF doğrulaması başarısız. Sayfayı yenile.'));
}

/* ===================== ALTYAPI TESPİT ===================== */
function fm_file_contains($file, $needle, $limit) {
    if (!@is_file($file) || !@is_readable($file)) return false;
    $size = @filesize($file);
    if ($size !== false && $size > $limit) return false;
    $c = @file_get_contents($file);
    if ($c === false) return false;
    return strpos($c, $needle) !== false;
}

function fm_detect_stack($dir) {
    $dir = rtrim($dir, '/');
    $hits = array();
    $add = function($name, $conf) use (&$hits) { $hits[] = array('name' => $name, 'confidence' => $conf); };

    if (@is_file($dir . '/wp-config.php') || (@is_dir($dir . '/wp-content') && @is_dir($dir . '/wp-includes'))) $add('WordPress', 'yüksek');
    if (@is_file($dir . '/artisan') && (@is_dir($dir . '/app') || @is_dir($dir . '/routes'))) $add('Laravel', 'yüksek');
    if (@is_file($dir . '/composer.json') && fm_file_contains($dir . '/composer.json', 'laravel/framework', 1024 * 1024)) $add('Laravel/Composer', 'yüksek');
    if (@is_file($dir . '/app/Config/Routes.php') || @is_file($dir . '/system/core/CodeIgniter.php')) $add('CodeIgniter', 'yüksek');
    if (@is_file($dir . '/configuration.php') && @is_dir($dir . '/components') && @is_dir($dir . '/administrator')) $add('Joomla', 'yüksek');
    if (@is_file($dir . '/sites/default/settings.php') || @is_dir($dir . '/core/modules/system')) $add('Drupal', 'yüksek');
    if (@is_file($dir . '/app/etc/env.php') && @is_file($dir . '/bin/magento')) $add('Magento', 'yüksek');
    if (@is_file($dir . '/config.php') && @is_file($dir . '/admin/config.php') && @is_dir($dir . '/catalog')) $add('OpenCart', 'yüksek');
    if (@is_file($dir . '/config/settings.inc.php') || @is_file($dir . '/app/config/parameters.php')) $add('PrestaShop', 'orta/yüksek');
    if (@is_file($dir . '/src/config.php') && (@is_file($dir . '/src/XF.php') || @is_dir($dir . '/src/XF'))) $add('XenForo', 'yüksek');
    if (@is_file($dir . '/configuration.php') && @is_file($dir . '/includes/init.php') && @is_dir($dir . '/templates')) $add('WHMCS', 'orta/yüksek');
    if (@is_file($dir . '/package.json')) {
        if (@is_file($dir . '/next.config.js') || @is_file($dir . '/next.config.mjs')) $add('Next.js/Node', 'yüksek');
        elseif (@is_file($dir . '/nuxt.config.js') || @is_file($dir . '/nuxt.config.ts')) $add('Nuxt/Node', 'yüksek');
        elseif (@is_file($dir . '/vite.config.js') || @is_file($dir . '/vite.config.ts')) $add('Vite/Node', 'orta/yüksek');
        else $add('Node.js project', 'orta');
    }
    if (@is_file($dir . '/composer.json')) $add('Composer PHP project', 'orta');
    if (@is_file($dir . '/index.php')) $add('Plain/PHP entrypoint', 'orta');
    if (@is_file($dir . '/index.html') || @is_file($dir . '/index.htm')) $add('Static HTML', 'orta');

    if (empty($hits)) $hits[] = array('name' => 'Bilinmeyen / özel altyapı', 'confidence' => 'düşük');
    return $hits;
}

function fm_project_scan($root) {
    $result = array();
    $items = @scandir($root);
    if ($items === false) return $result;

    $candidates = array($root);
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = fm_join($root, $i);
        if (@is_dir($p)) $candidates[] = $p;
    }

    foreach ($candidates as $p) {
        $stack = fm_detect_stack($p);
        $known = false;
        foreach ($stack as $s) {
            if ($s['name'] !== 'Bilinmeyen / özel altyapı') { $known = true; break; }
        }
        if ($known || $p === $root) {
            $result[] = array('path' => $p, 'stack' => $stack);
        }
    }
    return $result;
}

/* ===================== DOSYA İŞLEMLERİ ===================== */
function fm_entry_priority($name, $is_dir) {
    if ($is_dir) return 50;
    $lower = strtolower($name);
    $prio = array(
        'index.php' => 1,
        'wp-config.php' => 2,
        '.env' => 3,
        '.htaccess' => 4,
        'composer.json' => 5,
        'package.json' => 6,
        'config.php' => 7,
        'configuration.php' => 8
    );
    if (isset($prio[$lower])) return $prio[$lower];
    $ext = fm_ext($lower);
    if ($ext === 'php' || $ext === 'phtml' || $ext === 'phar') return 10;
    if ($ext === 'html' || $ext === 'htm' || $ext === 'js' || $ext === 'css') return 20;
    return 30;
}

function fm_list_dir($dir) {
    global $FM_SHOW_HIDDEN;
    $real = fm_allowed_path($dir, true);
    if ($real === false || !@is_dir($real)) return array('ok' => false, 'error' => 'Klasör bulunamadı veya izin dışı.');
    if (!@is_readable($real)) return array('ok' => false, 'error' => 'Bu klasör okunamıyor. Yetki/open_basedir kontrol et.');

    $items = @scandir($real);
    if ($items === false) return array('ok' => false, 'error' => 'scandir başarısız. Klasör izni veya open_basedir engeli olabilir.');

    $entries = array();
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!$FM_SHOW_HIDDEN && strlen($name) > 0 && $name[0] === '.') continue;
        $path = fm_join($real, $name);
        $is_dir = @is_dir($path);
        $is_file = @is_file($path);
        $entries[] = array(
            'name' => $name,
            'path' => $path,
            'dir' => $is_dir ? 1 : 0,
            'file' => $is_file ? 1 : 0,
            'size' => $is_dir ? '-' : (@filesize($path) === false ? '-' : @filesize($path)),
            'size_h' => $is_dir ? '-' : fm_format_size(@filesize($path)),
            'perm' => fm_perms($path),
            'mtime' => fm_mtime($path),
            'readable' => @is_readable($path) ? 1 : 0,
            'writable' => @is_writable($path) ? 1 : 0,
            'priority' => fm_entry_priority($name, $is_dir)
        );
    }

    usort($entries, function($a, $b) {
        if ($a['priority'] != $b['priority']) return ($a['priority'] < $b['priority']) ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });

    $root = fm_current_root();
    $can_up = ($real !== $root && fm_path_inside(fm_parent($real), $root));
    return array(
        'ok' => true,
        'cwd' => $real,
        'root' => $root,
        'can_up' => $can_up ? 1 : 0,
        'parent' => $can_up ? fm_parent($real) : '',
        'entries' => $entries,
        'stack' => fm_detect_stack($real),
        'writable' => @is_writable($real) ? 1 : 0
    );
}

function fm_read_file($file) {
    global $FM_MAX_EDIT_BYTES;
    $real = fm_allowed_path($file, true);
    if ($real === false || !@is_file($real)) return array('ok' => false, 'error' => 'Dosya bulunamadı veya izin dışı.');
    if (!@is_readable($real)) return array('ok' => false, 'error' => 'Dosya okunamıyor.');
    $size = @filesize($real);
    if ($size !== false && $size > $FM_MAX_EDIT_BYTES) return array('ok' => false, 'error' => 'Dosya edit limiti üzerinde: ' . fm_format_size($size));
    $data = @file_get_contents($real);
    if ($data === false) return array('ok' => false, 'error' => 'Dosya okunamadı.');
    return array('ok' => true, 'path' => $real, 'name' => basename($real), 'data' => base64_encode($data), 'perm' => fm_perms($real), 'writable' => @is_writable($real) ? 1 : 0);
}

function fm_save_file($file, $data64) {
    global $FM_BACKUP_ON_SAVE;
    $target = fm_allowed_path($file, @file_exists($file));
    if ($target === false) return array('ok' => false, 'error' => 'Hedef dosya izin dışı.');
    $parent = fm_allowed_path(dirname($target), true);
    if ($parent === false || !@is_dir($parent)) return array('ok' => false, 'error' => 'Üst klasör geçersiz.');
    if (@file_exists($target) && !@is_writable($target)) return array('ok' => false, 'error' => 'Dosya yazılabilir değil. chmod/owner kontrol et.');
    if (!@file_exists($target) && !@is_writable($parent)) return array('ok' => false, 'error' => 'Klasör yazılabilir değil. Yeni dosya oluşturulamıyor.');

    $data = base64_decode($data64, true);
    if ($data === false) return array('ok' => false, 'error' => 'Base64 içerik çözülemedi.');

    if ($FM_BACKUP_ON_SAVE && @is_file($target)) {
        $bak = $target . '.bak-' . date('Ymd-His');
        @copy($target, $bak);
    }
    $w = @file_put_contents($target, $data, LOCK_EX);
    if ($w === false) return array('ok' => false, 'error' => 'Kaydetme başarısız.');
    return array('ok' => true, 'message' => 'Kaydedildi.', 'bytes' => $w, 'path' => $target, 'perm' => fm_perms($target));
}

function fm_mkdir($dir, $name) {
    $base = fm_allowed_path($dir, true);
    if ($base === false || !@is_dir($base)) return array('ok' => false, 'error' => 'Klasör geçersiz.');
    if (!@is_writable($base)) return array('ok' => false, 'error' => 'Bu klasöre yazma yetkisi yok.');
    $name = fm_safe_name($name);
    if ($name === '') return array('ok' => false, 'error' => 'Klasör adı boş.');
    $target = fm_join($base, $name);
    if (@file_exists($target)) return array('ok' => false, 'error' => 'Aynı isimde dosya/klasör var.');
    if (!@mkdir($target, 0755, true)) return array('ok' => false, 'error' => 'Klasör oluşturulamadı.');
    return array('ok' => true, 'path' => $target);
}

function fm_new_file($dir, $name) {
    $base = fm_allowed_path($dir, true);
    if ($base === false || !@is_dir($base)) return array('ok' => false, 'error' => 'Klasör geçersiz.');
    if (!@is_writable($base)) return array('ok' => false, 'error' => 'Bu klasöre yazma yetkisi yok.');
    $name = fm_safe_name($name);
    if ($name === '') return array('ok' => false, 'error' => 'Dosya adı boş.');
    $target = fm_join($base, $name);
    if (@file_exists($target)) return array('ok' => false, 'error' => 'Bu dosya zaten var.');
    $r = @file_put_contents($target, '');
    if ($r === false) return array('ok' => false, 'error' => 'Dosya oluşturulamadı.');
    return array('ok' => true, 'path' => $target);
}

function fm_rename_path($old, $newname) {
    $oldreal = fm_allowed_path($old, true);
    if ($oldreal === false) return array('ok' => false, 'error' => 'Kaynak izin dışı.');
    $newname = fm_safe_name($newname);
    if ($newname === '') return array('ok' => false, 'error' => 'Yeni ad boş.');
    $target = fm_join(dirname($oldreal), $newname);
    if (@file_exists($target)) return array('ok' => false, 'error' => 'Hedef isim zaten var.');
    if (!@is_writable(dirname($oldreal))) return array('ok' => false, 'error' => 'Üst klasörde yazma yetkisi yok.');
    if (!@rename($oldreal, $target)) return array('ok' => false, 'error' => 'Yeniden adlandırılamadı.');
    return array('ok' => true, 'path' => $target);
}

function fm_chmod_path($path, $perm) {
    $real = fm_allowed_path($path, true);
    if ($real === false) return array('ok' => false, 'error' => 'Path izin dışı.');
    $perm = trim((string)$perm);
    if (!preg_match('/^[0-7]{3,4}$/', $perm)) return array('ok' => false, 'error' => 'İzin formatı 644, 0644, 755 gibi olmalı.');
    $oct = octdec($perm);
    if (!@chmod($real, $oct)) return array('ok' => false, 'error' => 'chmod başarısız. Owner/izin engeli olabilir.');
    return array('ok' => true, 'perm' => fm_perms($real));
}

function fm_delete_path($path) {
    global $FM_ALLOW_DELETE;
    if (!$FM_ALLOW_DELETE) return array('ok' => false, 'error' => 'Silme kapalı.');
    $real = fm_allowed_path($path, true);
    if ($real === false) return array('ok' => false, 'error' => 'Path izin dışı.');
    if (@is_file($real) || @is_link($real)) {
        if (!@is_writable(dirname($real))) return array('ok' => false, 'error' => 'Üst klasörde yazma yetkisi yok.');
        return @unlink($real) ? array('ok' => true) : array('ok' => false, 'error' => 'Dosya silinemedi.');
    }
    if (@is_dir($real)) {
        $items = @scandir($real);
        if ($items === false) return array('ok' => false, 'error' => 'Klasör okunamadı.');
        foreach ($items as $i) if ($i !== '.' && $i !== '..') return array('ok' => false, 'error' => 'Klasör boş değil. Güvenlik için recursive silme yok.');
        return @rmdir($real) ? array('ok' => true) : array('ok' => false, 'error' => 'Klasör silinemedi.');
    }
    return array('ok' => false, 'error' => 'Silinecek şey bulunamadı.');
}

function fm_upload_files($dir) {
    global $FM_MAX_UPLOAD_BYTES;
    $base = fm_allowed_path($dir, true);
    if ($base === false || !@is_dir($base)) return array('ok' => false, 'error' => 'Klasör geçersiz.');
    if (!@is_writable($base)) return array('ok' => false, 'error' => 'Bu klasöre yazma yetkisi yok.');
    if (!isset($_FILES['files'])) return array('ok' => false, 'error' => 'Dosya gelmedi.');

    $saved = array();
    $errors = array();
    $names = $_FILES['files']['name'];
    $tmp = $_FILES['files']['tmp_name'];
    $sizes = $_FILES['files']['size'];
    $errs = $_FILES['files']['error'];
    if (!is_array($names)) {
        $names = array($names); $tmp = array($tmp); $sizes = array($sizes); $errs = array($errs);
    }
    for ($i = 0; $i < count($names); $i++) {
        if ($errs[$i] !== UPLOAD_ERR_OK) { $errors[] = $names[$i] . ': upload error ' . $errs[$i]; continue; }
        if ($sizes[$i] > $FM_MAX_UPLOAD_BYTES) { $errors[] = $names[$i] . ': dosya çok büyük'; continue; }
        $safe = fm_safe_name($names[$i]);
        if ($safe === '') { $errors[] = 'geçersiz dosya adı'; continue; }
        $target = fm_join($base, $safe);
        if (!@move_uploaded_file($tmp[$i], $target)) { $errors[] = $safe . ': taşınamadı'; continue; }
        $saved[] = $safe;
    }
    return array('ok' => count($errors) === 0, 'saved' => $saved, 'errors' => $errors);
}

function fm_recursive_search($root, $q) {
    global $FM_SEARCH_LIMIT;
    $root = fm_allowed_path($root, true);
    if ($root === false || !@is_dir($root)) return array('ok' => false, 'error' => 'Root geçersiz.');
    $q = trim((string)$q);
    if ($q === '') $q = '.php';
    $results = array();
    $stack = array($root);
    $visited = 0;
    while (!empty($stack) && count($results) < $FM_SEARCH_LIMIT && $visited < 20000) {
        $dir = array_pop($stack);
        $visited++;
        $items = @scandir($dir);
        if ($items === false) continue;
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') continue;
            $p = fm_join($dir, $i);
            if (@is_dir($p)) {
                // ağır vendor/node_modules/cache klasörlerini aramada atla
                $low = strtolower($i);
                if ($low === 'node_modules' || $low === 'vendor' || $low === '.git' || $low === 'cache' || $low === 'caches') continue;
                $stack[] = $p;
            } else {
                if (stripos($i, $q) !== false || ($q === '.php' && fm_ext($i) === 'php')) {
                    $results[] = array('name' => $i, 'path' => $p, 'size_h' => fm_format_size(@filesize($p)), 'mtime' => fm_mtime($p));
                    if (count($results) >= $FM_SEARCH_LIMIT) break;
                }
            }
        }
    }
    return array('ok' => true, 'query' => $q, 'count' => count($results), 'results' => $results);
}

/* ===================== LOGIN / LOGOUT / DOWNLOAD ===================== */
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (function_exists('session_destroy')) @session_destroy();
    header('Location: ' . strtok(fm_server('REQUEST_URI', ''), '?'));
    exit;
}

if (isset($_POST['login_password'])) {
    if (!fm_ip_allowed()) {
        $login_error = 'IP izinli değil.';
    } elseif (fm_hash_equals($FM_PASSWORD, (string)$_POST['login_password'])) {
        $_SESSION['fm_auth'] = true;
        $_SESSION['fm_csrf'] = fm_random_token();
        $_SESSION['fm_root'] = fm_default_root();
        $_SESSION['fm_cwd'] = $_SESSION['fm_root'];
        header('Location: ' . strtok(fm_server('REQUEST_URI', ''), '?') . '?v=' . rawurlencode($FM_VERSION));
        exit;
    } else {
        $login_error = 'Şifre hatalı. Bu dosyada varsayılan şifre: admin';
    }
}

if (fm_logged_in() && isset($_GET['download'])) {
    $file = fm_allowed_path($_GET['download'], true);
    if ($file !== false && @is_file($file) && @is_readable($file)) {
        while (@ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($file)) . '"');
        header('Content-Length: ' . @filesize($file));
        @readfile($file);
        exit;
    }
    http_response_code(404);
    echo 'Dosya bulunamadı.';
    exit;
}

/* ===================== API ===================== */
if (isset($_POST['action'])) {
    fm_require_auth();
    fm_require_csrf();
    $action = fm_post('action', '');

    if ($action === 'status') {
        fm_json(array(
            'ok' => true,
            'app' => $GLOBALS['FM_APP_NAME'],
            'version' => $GLOBALS['FM_VERSION'],
            'php' => PHP_VERSION,
            'root' => fm_current_root(),
            'cwd' => fm_current_dir(),
            'roots' => fm_roots(),
            'csrf' => fm_csrf(),
            'docroot' => fm_server('DOCUMENT_ROOT', ''),
            'open_basedir' => @ini_get('open_basedir') ? @ini_get('open_basedir') : '',
            'stack' => fm_detect_stack(fm_current_dir())
        ));
    }
    if ($action === 'set_root') {
        $root = fm_allowed_path(fm_post('root', ''), true);
        if ($root === false || !@is_dir($root)) fm_json(array('ok' => false, 'error' => 'Root geçersiz.'));
        $_SESSION['fm_root'] = $root;
        $_SESSION['fm_cwd'] = $root;
        fm_json(array('ok' => true, 'root' => $root, 'cwd' => $root));
    }
    if ($action === 'list') {
        $dir = fm_post('dir', fm_current_dir());
        $res = fm_list_dir($dir);
        if (isset($res['ok']) && $res['ok']) $_SESSION['fm_cwd'] = $res['cwd'];
        fm_json($res);
    }
    if ($action === 'read') fm_json(fm_read_file(fm_post('file', '')));
    if ($action === 'save') fm_json(fm_save_file(fm_post('file', ''), fm_post('data', '')));
    if ($action === 'mkdir') fm_json(fm_mkdir(fm_post('dir', fm_current_dir()), fm_post('name', '')));
    if ($action === 'new_file') fm_json(fm_new_file(fm_post('dir', fm_current_dir()), fm_post('name', '')));
    if ($action === 'rename') fm_json(fm_rename_path(fm_post('path', ''), fm_post('name', '')));
    if ($action === 'chmod') fm_json(fm_chmod_path(fm_post('path', ''), fm_post('perm', '')));
    if ($action === 'delete') fm_json(fm_delete_path(fm_post('path', '')));
    if ($action === 'upload') fm_json(fm_upload_files(fm_post('dir', fm_current_dir())));
    if ($action === 'projects') fm_json(array('ok' => true, 'root' => fm_current_root(), 'projects' => fm_project_scan(fm_current_root())));
    if ($action === 'search') fm_json(fm_recursive_search(fm_current_root(), fm_post('q', '.php')));

    fm_json(array('ok' => false, 'error' => 'Bilinmeyen action.'));
}

/* ===================== HTML ===================== */
$csrf = fm_csrf();
while (@ob_get_level() > 0) @ob_end_clean();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<title><?php echo fm_html($FM_APP_NAME . ' ' . $FM_VERSION); ?></title>
<style>
:root{--bg:#07111f;--panel:#0f1b2d;--panel2:#101827;--border:#263449;--text:#e5edf7;--muted:#9fb2c7;--blue:#2563eb;--green:#16a34a;--red:#dc2626;--yellow:#ca8a04}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}button,input,select,textarea{font:inherit}a{color:#93c5fd;text-decoration:none}
.login{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.login form{width:380px;max-width:100%;background:var(--panel);border:1px solid var(--border);border-radius:18px;padding:24px;box-shadow:0 18px 60px rgba(0,0,0,.35)}.login h1{margin:0 0 8px;font-size:22px}.login p{margin:0 0 18px;color:var(--muted)}.field{width:100%;padding:12px;border-radius:10px;border:1px solid var(--border);background:#06101e;color:var(--text);outline:none}.btn{border:0;border-radius:10px;padding:9px 13px;color:#fff;background:#1f2937;cursor:pointer;font-weight:700}.btn:hover{filter:brightness(1.1)}.btn.blue{background:var(--blue)}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn.yellow{background:var(--yellow)}.btn.small{padding:6px 9px;font-size:12px}.err{background:#3f1212;border:1px solid #7f1d1d;color:#fecaca;padding:10px;border-radius:10px;margin:10px 0}
.app{display:grid;grid-template-columns:300px 1fr;min-height:100vh}.side{background:#050c17;border-right:1px solid var(--border);padding:14px;overflow:auto}.main{min-width:0;display:flex;flex-direction:column}.brand{font-size:18px;font-weight:900;margin-bottom:4px}.version{font-size:11px;color:#8ba3bb;margin-bottom:14px;word-break:break-all}.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:13px;margin-bottom:12px}.card h3{margin:0 0 10px;font-size:15px}.hint{color:var(--muted);font-size:12px}.row{display:flex;gap:8px;align-items:center}.row>*{min-width:0}.top{height:56px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;padding:10px 14px;background:#081321;position:sticky;top:0;z-index:2}.path{flex:1;padding:10px;border-radius:10px;border:1px solid var(--border);background:#060f1d;color:var(--text);font-family:ui-monospace,Consolas,monospace}.search{width:210px;padding:10px;border-radius:10px;border:1px solid var(--border);background:#060f1d;color:var(--text)}.status{font-size:12px;color:var(--muted);padding:8px 14px;border-bottom:1px solid var(--border);background:#0b1422}.content{padding:14px;overflow:auto}.tablewrap{overflow:auto;border:1px solid var(--border);border-radius:14px;background:var(--panel2)}table{width:100%;border-collapse:collapse;min-width:940px}th,td{padding:9px 10px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}th{font-size:12px;color:#cfe0f5;background:#0a1322;position:sticky;top:0;z-index:1}tr:hover{background:#132238}.name{font-family:ui-monospace,Consolas,monospace;font-weight:700}.tag{display:inline-block;font-size:11px;border-radius:999px;padding:2px 7px;background:#1e293b;color:#cbd5e1;margin-right:7px}.tag.file{background:#143322;color:#bbf7d0}.tag.dir{background:#172554;color:#bfdbfe}.tag.hot{background:#4a1d1d;color:#fecaca}.actions{display:flex;gap:5px;justify-content:flex-end}.muted{color:var(--muted)}.stackitem{display:block;margin:5px 0;padding:7px;border-radius:9px;background:#0a1322;border:1px solid var(--border);font-size:12px}.split{display:grid;grid-template-columns:1fr 1fr;gap:8px}.full{width:100%}.mt{margin-top:8px}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:20;align-items:center;justify-content:center;padding:18px}.modal.on{display:flex}.box{width:min(1100px,96vw);height:min(820px,92vh);background:#081321;border:1px solid var(--border);border-radius:16px;display:flex;flex-direction:column;overflow:hidden}.boxhead{padding:10px 12px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center}.boxbody{flex:1;padding:10px;display:flex;flex-direction:column;min-height:0}textarea{flex:1;width:100%;resize:none;background:#030712;color:#e5edf7;border:1px solid var(--border);border-radius:12px;padding:12px;font-family:ui-monospace,Consolas,monospace;font-size:13px;line-height:1.45;outline:none}.grow{flex:1}.toast{position:fixed;right:16px;bottom:16px;max-width:520px;background:#0b1728;border:1px solid var(--border);color:#fff;padding:12px 14px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35);display:none;z-index:40}.toast.err{background:#3f1212;color:#fecaca}.list{max-height:260px;overflow:auto}.project{border:1px solid var(--border);border-radius:10px;padding:8px;margin:7px 0;background:#0a1322}.project b{font-family:ui-monospace,Consolas,monospace;font-size:12px}.project button{margin-top:6px}.drop{border:1px dashed #38506e;border-radius:12px;padding:12px;text-align:center;background:#071426}.danger{color:#fecaca}
@media(max-width:900px){.app{grid-template-columns:1fr}.side{border-right:0;border-bottom:1px solid var(--border)}.top{flex-wrap:wrap;height:auto}.search{width:100%}}
</style>
</head>
<body>
<?php if (!fm_logged_in()): ?>
<div class="login">
<form method="post" autocomplete="off">
    <h1>📁 <?php echo fm_html($FM_APP_NAME); ?></h1>
    <p>FINAL v6 — PHP 7 uyumlu. Varsayılan şifre: <b>admin</b></p>
    <?php if (isset($login_error)): ?><div class="err"><?php echo fm_html($login_error); ?></div><?php endif; ?>
    <input class="field" type="password" name="login_password" placeholder="Şifre" autofocus>
    <button class="btn blue full mt" type="submit">Giriş</button>
</form>
</div>
<?php else: ?>
<div class="app">
    <aside class="side">
        <div class="brand">📁 Safe Web File Manager</div>
        <div class="version" id="versionBox"><?php echo fm_html($FM_VERSION); ?> — Şifre: admin</div>
        <div class="card">
            <h3>Root Seç</h3>
            <select class="field" id="rootSelect"></select>
            <button class="btn blue full mt" onclick="setRoot()">Bu root'a geç</button>
            <div class="hint mt">Tüm altyapıları görmek için genelde <b>/home/kullanıcı</b> veya document root'un üst dizini seçilir.</div>
        </div>
        <div class="card">
            <h3>Altyapı Algılama</h3>
            <div id="stackBox" class="hint">Yükleniyor...</div>
            <button class="btn small mt" onclick="loadProjects()">Root içinde projeleri tara</button>
            <div id="projectsBox" class="list"></div>
        </div>
        <div class="card">
            <h3>Yeni Dosya / Klasör</h3>
            <div class="split">
                <input class="field" id="newFileName" placeholder="index.php">
                <button class="btn green" onclick="newFile()">Dosya</button>
            </div>
            <div class="split mt">
                <input class="field" id="newDirName" placeholder="klasör adı">
                <button class="btn" onclick="mkdir()">Klasör</button>
            </div>
        </div>
        <div class="card">
            <h3>Dosya Yükle</h3>
            <div class="drop">
                <input type="file" id="uploadInput" multiple>
                <button class="btn green mt" onclick="uploadFiles()">Yükle</button>
            </div>
        </div>
        <div class="card">
            <h3>PHP Dosyalarını Bul</h3>
            <div class="split">
                <input class="field" id="searchQ" value=".php" placeholder="index.php / .php / .env">
                <button class="btn yellow" onclick="searchFiles()">Bul</button>
            </div>
            <div id="searchBox" class="list"></div>
        </div>
        <div class="card hint">
            <b class="danger">Not:</b> Dosya görünmüyorsa ya gerçekten o klasörde değildir ya da PHP kullanıcısının okuma yetkisi/open_basedir engeli vardır. Bu panel owner/permission engelini aşamaz.
        </div>
    </aside>
    <main class="main">
        <div class="top">
            <button class="btn" onclick="goUp()">⬆ Üst</button>
            <button class="btn" onclick="reloadList()">↻ Yenile</button>
            <input class="path" id="pathInput" value="">
            <button class="btn blue" onclick="goPath()">Git</button>
            <input class="search" id="filterInput" placeholder="Listede filtrele..." oninput="renderTable()">
            <a class="btn red" href="?logout=1">Çıkış</a>
        </div>
        <div class="status" id="statusBar">Yükleniyor...</div>
        <div class="content">
            <div class="tablewrap">
                <table>
                    <thead><tr><th>AD</th><th>BOYUT</th><th>İZİN</th><th>YAZMA</th><th>DEĞİŞİM</th><th style="text-align:right">İŞLEM</th></tr></thead>
                    <tbody id="fileBody"></tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal" id="editorModal">
    <div class="box">
        <div class="boxhead">
            <b>✏️ Edit/Güncelle:</b>
            <span class="muted grow" id="editPath"></span>
            <button class="btn green" onclick="saveEditor()">Kaydet/Güncelle</button>
            <button class="btn" onclick="closeEditor()">Kapat</button>
        </div>
        <div class="boxbody"><textarea id="editorText" spellcheck="false"></textarea></div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
var CSRF = <?php echo json_encode($csrf); ?>;
var cwd = '';
var root = '';
var entries = [];
var currentEditFile = '';

function enc(s){ return btoa(unescape(encodeURIComponent(s))); }
function dec(s){ return decodeURIComponent(escape(atob(s))); }
function h(s){ return String(s).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];}); }
function toast(msg, bad){ var t=document.getElementById('toast'); t.className='toast'+(bad?' err':''); t.innerHTML=h(msg); t.style.display='block'; setTimeout(function(){t.style.display='none';},4500); }
function post(action, data, cb){
    var fd = new FormData(); fd.append('action', action); fd.append('csrf', CSRF);
    data = data || {}; for(var k in data){ if(data.hasOwnProperty(k)) fd.append(k, data[k]); }
    fetch(location.pathname, {method:'POST', body:fd, credentials:'same-origin', cache:'no-store'}).then(function(r){return r.json();}).then(function(j){cb(j);}).catch(function(e){toast('İstek hatası: '+e, true);});
}
function status(){
    post('status', {}, function(j){
        if(!j.ok){toast(j.error||'status error', true); return;}
        CSRF=j.csrf||CSRF; root=j.root; cwd=j.cwd;
        document.getElementById('versionBox').innerHTML = h(j.version)+' — PHP '+h(j.php)+' — Şifre: admin';
        var sel=document.getElementById('rootSelect'); sel.innerHTML='';
        for(var i=0;i<j.roots.length;i++){ var o=document.createElement('option'); o.value=j.roots[i]; o.textContent=j.roots[i]; if(j.roots[i]===j.root)o.selected=true; sel.appendChild(o); }
        showStack(j.stack);
        loadList(cwd);
    });
}
function showStack(stack){
    var box=document.getElementById('stackBox'); var html='';
    for(var i=0;i<stack.length;i++) html += '<span class="stackitem">'+h(stack[i].name)+' <em>('+h(stack[i].confidence)+')</em></span>';
    box.innerHTML=html;
}
function setRoot(){ var r=document.getElementById('rootSelect').value; post('set_root',{root:r},function(j){ if(!j.ok){toast(j.error,true);return;} root=j.root; cwd=j.cwd; loadList(cwd); loadProjects(); }); }
function loadList(dir){
    post('list', {dir:dir}, function(j){
        if(!j.ok){toast(j.error||'Listeleme hatası', true); return;}
        cwd=j.cwd; root=j.root; entries=j.entries||[]; document.getElementById('pathInput').value=cwd;
        document.getElementById('statusBar').innerHTML='Root: <b>'+h(root)+'</b> | Klasör: <b>'+h(cwd)+'</b> | Yazılabilir: <b>'+(j.writable?'Evet':'Hayır')+'</b> | Kayıt: <b>'+entries.length+'</b>';
        showStack(j.stack||[]); renderTable();
    });
}
function renderTable(){
    var q=document.getElementById('filterInput').value.toLowerCase(); var body=document.getElementById('fileBody'); var html='';
    for(var i=0;i<entries.length;i++){
        var e=entries[i]; if(q && e.name.toLowerCase().indexOf(q)<0) continue;
        var tag=e.dir?'<span class="tag dir">DIR</span>':(e.name.toLowerCase().match(/\.php$|\.phtml$/)?'<span class="tag hot">PHP</span>':'<span class="tag file">FILE</span>');
        var openBtn = e.dir ? '<button class="btn small blue" onclick="openDir('+i+')">📂 Aç</button>' : '<button class="btn small green" onclick="editFile('+i+')">Edit/Güncelle</button>';
        var downBtn = e.dir ? '' : '<a class="btn small" href="?download='+encodeURIComponent(e.path)+'">İndir</a>';
        html += '<tr><td class="name">'+tag+h(e.name)+'</td><td>'+h(e.size_h)+'</td><td>'+h(e.perm)+'</td><td>'+(e.writable?'✅':'❌')+'</td><td>'+h(e.mtime)+'</td><td><div class="actions">'+openBtn+downBtn+'<button class="btn small" onclick="renameItem('+i+')">Ad</button><button class="btn small yellow" onclick="chmodItem('+i+')">İzin</button><button class="btn small red" onclick="deleteItem('+i+')">Sil</button></div></td></tr>';
    }
    if(html==='') html='<tr><td colspan="6" class="muted">Bu görünümde dosya yok. Filtre açıksa temizle. PHP dosyalarını bulmak için soldaki aramayı kullan.</td></tr>';
    body.innerHTML=html;
}
function openDir(i){ loadList(entries[i].path); }
function goUp(){ var parts=cwd.split('/'); parts.pop(); var p=parts.join('/')||'/'; if(cwd===root){toast('Root dışına çıkış engelli. Root seçiciden üst dizini seç.', true);return;} loadList(p); }
function goPath(){ loadList(document.getElementById('pathInput').value); }
function reloadList(){ loadList(cwd); }
function editFile(i){
    var p=entries[i].path; post('read',{file:p},function(j){
        if(!j.ok){toast(j.error,true);return;}
        currentEditFile=j.path; document.getElementById('editPath').textContent=j.path+' | perm '+j.perm+' | yazma '+(j.writable?'var':'yok');
        document.getElementById('editorText').value=dec(j.data); document.getElementById('editorModal').className='modal on';
    });
}
function closeEditor(){ document.getElementById('editorModal').className='modal'; currentEditFile=''; }
function saveEditor(){ if(!currentEditFile)return; post('save',{file:currentEditFile,data:enc(document.getElementById('editorText').value)},function(j){ if(!j.ok){toast(j.error,true);return;} toast('Kaydedildi: '+j.bytes+' byte'); loadList(cwd); }); }
function newFile(){ var n=document.getElementById('newFileName').value; post('new_file',{dir:cwd,name:n},function(j){ if(!j.ok){toast(j.error,true);return;} toast('Dosya oluşturuldu'); loadList(cwd); setTimeout(function(){ for(var i=0;i<entries.length;i++){ if(entries[i].path===j.path){editFile(i);break;} } },350); }); }
function mkdir(){ var n=document.getElementById('newDirName').value; post('mkdir',{dir:cwd,name:n},function(j){ if(!j.ok){toast(j.error,true);return;} toast('Klasör oluşturuldu'); loadList(cwd); }); }
function renameItem(i){ var e=entries[i]; var n=prompt('Yeni ad:', e.name); if(!n)return; post('rename',{path:e.path,name:n},function(j){ if(!j.ok){toast(j.error,true);return;} toast('Ad değişti'); loadList(cwd); }); }
function chmodItem(i){ var e=entries[i]; var p=prompt('Yeni izin (örn 0644 / 0755):', e.perm); if(!p)return; post('chmod',{path:e.path,perm:p},function(j){ if(!j.ok){toast(j.error,true);return;} toast('İzin güncellendi: '+j.perm); loadList(cwd); }); }
function deleteItem(i){ var e=entries[i]; if(!confirm('Silinsin mi?\n'+e.path))return; post('delete',{path:e.path},function(j){ if(!j.ok){toast(j.error,true);return;} toast('Silindi'); loadList(cwd); }); }
function uploadFiles(){
    var inp=document.getElementById('uploadInput'); if(!inp.files.length){toast('Dosya seçilmedi', true);return;}
    var fd=new FormData(); fd.append('action','upload'); fd.append('csrf',CSRF); fd.append('dir',cwd);
    for(var i=0;i<inp.files.length;i++) fd.append('files[]', inp.files[i]);
    fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(j){ if(!j.ok && j.errors && j.errors.length){toast(j.errors.join(' | '),true);} else if(!j.ok){toast(j.error||'upload error',true);} else {toast('Yüklendi: '+j.saved.join(', ')); inp.value=''; loadList(cwd);} }).catch(function(e){toast('Upload hatası '+e,true);});
}
function loadProjects(){ post('projects',{},function(j){ if(!j.ok){toast(j.error,true);return;} var box=document.getElementById('projectsBox'); var html=''; for(var i=0;i<j.projects.length;i++){ var p=j.projects[i]; var names=[]; for(var k=0;k<p.stack.length;k++) names.push(p.stack[k].name); html+='<div class="project"><b>'+h(p.path)+'</b><div class="hint">'+h(names.join(', '))+'</div><button class="btn small blue" onclick="loadList(\''+String(p.path).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\')">Aç</button></div>'; } box.innerHTML=html||'<div class="hint">Proje bulunamadı</div>'; }); }
function searchFiles(){ var q=document.getElementById('searchQ').value; post('search',{q:q},function(j){ if(!j.ok){toast(j.error,true);return;} var box=document.getElementById('searchBox'); var html='<div class="hint mt">'+j.count+' sonuç</div>'; for(var i=0;i<j.results.length;i++){ var r=j.results[i]; html+='<div class="project"><b>'+h(r.name)+'</b><div class="hint">'+h(r.path)+' | '+h(r.size_h)+'</div><button class="btn small green" onclick="openSearchFile(\''+String(r.path).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\')">Edit</button><button class="btn small blue" onclick="loadList(\''+String(r.path.substring(0,r.path.lastIndexOf('/'))).replace(/\\/g,'\\\\').replace(/'/g,"\\'")+'\')">Klasörü Aç</button></div>'; } box.innerHTML=html; }); }
function openSearchFile(p){ post('read',{file:p},function(j){ if(!j.ok){toast(j.error,true);return;} currentEditFile=j.path; document.getElementById('editPath').textContent=j.path+' | perm '+j.perm+' | yazma '+(j.writable?'var':'yok'); document.getElementById('editorText').value=dec(j.data); document.getElementById('editorModal').className='modal on'; }); }
status();
</script>
<?php endif; ?>
</body>
</html>
