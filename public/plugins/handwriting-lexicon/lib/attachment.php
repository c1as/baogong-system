<?php
/**
 * 附件图片发现与受控输出
 *
 * 三重安全约束：
 *  1) 所有来自请求或日志的路径必须经 bglx_rev_safe_path()（拒绝 .. + realpath + base 前缀）；
 *  2) 扩展名白名单 + getimagesize() MIME 白名单（拒 SVG）+ 字节数上限 + 像素积上限；
 *  3) 缩略图优先 GD，缺失时降级原图，输出带 nosniff 与 inline 头。
 */

if (!defined('BGLX_REV_ATTACHMENT_LOADED')) {
    define('BGLX_REV_ATTACHMENT_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';

if (!defined('BGLX_REV_MAX_SCAN_FILES')) {
    define('BGLX_REV_MAX_SCAN_FILES', 5000);
}
if (!defined('BGLX_REV_MAX_PIXELS')) {
    define('BGLX_REV_MAX_PIXELS', 40000000); // 防解压炸弹：4000 万像素
}
if (!defined('BGLX_REV_MAX_DEPTH')) {
    define('BGLX_REV_MAX_DEPTH', 6);
}

/**
 * 解析附件候选目录为绝对路径；相对路径一律相对 bglx_data_dir()。
 *
 * @param string $dir 配置中的目录
 * @return string 绝对路径或空串
 */
function bglx_rev_resolve_base($dir)
{
    $dir = str_replace('\\', '/', trim((string)$dir));
    if ($dir === '') {
        return '';
    }
    if ($dir[0] === '/' || preg_match('#^[A-Za-z]:/#', $dir)) {
        return rtrim($dir, '/');
    }
    return rtrim(bglx_data_dir(), '/') . '/' . ltrim($dir, '/');
}

/**
 * 多候选目录顺序探测：首个存在 + 是目录 + 可读者胜出。
 *
 * @return array array('dirs'=>array, 'warnings'=>array)
 */
function bglx_rev_attachment_dirs()
{
    $dirs = bglx_rev_setting_list('attachmentDirs', bglx_rev_defaults()['attachmentDirs']);
    $out = array('dirs' => array(), 'warnings' => array());
    foreach ($dirs as $d) {
        $abs = bglx_rev_resolve_base($d);
        $real = $abs !== '' ? realpath($abs) : false;
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            $out['warnings'][] = '附件目录不可读：' . bglx_cut($d, 120);
            continue;
        }
        $normalized = rtrim(str_replace('\\', '/', $real), '/');
        if (!in_array($normalized, $out['dirs'], true)) {
            $out['dirs'][] = $normalized;
        }
    }
    return $out;
}

/**
 * 唯一安全路径入口：任何来自请求/日志的路径都必须经过本函数。
 *
 * 1) 显式拒绝 .. 段；2) realpath 解析符号链接；3) base 前缀（带尾部 /）比对。
 *
 * @param string $base 已解析的绝对基目录
 * @param string $rel  相对路径或同前缀绝对路径
 * @return string|null 绝对路径；不安全或不存在时返回 null
 */
function bglx_rev_safe_path($base, $rel)
{
    $rel = str_replace('\\', '/', (string)$rel);
    if ($rel === '' || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
        return null;
    }
    $baseReal = realpath((string)$base);
    if ($baseReal === false) {
        return null;
    }
    $target = ($rel[0] === '/')
        ? $rel : rtrim(str_replace('\\', '/', $baseReal), '/') . '/' . $rel;
    $real = realpath($target);
    if ($real === false) {
        return null;
    }
    $prefix = rtrim(str_replace('\\', '/', $baseReal), '/') . '/';
    $norm = rtrim(str_replace('\\', '/', $real), '/') . '/';
    return (strpos($norm, $prefix) === 0) ? $real : null;
}

/**
 * 求绝对路径相对于基目录的相对路径；不在基目录内返回空串。
 *
 * @param string $base 绝对基目录
 * @param string $abs  绝对路径
 * @return string
 */
function bglx_rev_rel_to_base($base, $abs)
{
    $baseReal = realpath((string)$base);
    $absReal = realpath((string)$abs);
    if ($baseReal === false || $absReal === false) {
        return '';
    }
    $prefix = rtrim(str_replace('\\', '/', $baseReal), '/') . '/';
    $norm = str_replace('\\', '/', $absReal);
    return (strpos($norm, $prefix) === 0) ? substr($norm, strlen($prefix)) : '';
}

/**
 * 反向解析：把 review_image 收到的 path 还原为受控绝对路径。
 *
 * 依次尝试 data 目录与全部候选附件目录；绝对路径也走前缀校验，
 * 因此符号链接越界与 ../ 穿越在此同样被挡。
 *
 * @param string $relPath 相对路径，或已验证过的绝对路径
 * @return string 绝对路径或空串
 */
function bglx_rev_image_abs($relPath)
{
    $relPath = str_replace('\\', '/', (string)$relPath);
    if ($relPath === '') {
        return '';
    }
    $bases = array(bglx_data_dir());
    $info = bglx_rev_attachment_dirs();
    foreach ($info['dirs'] as $d) {
        $bases[] = $d;
    }
    foreach ($bases as $base) {
        $abs = bglx_rev_safe_path($base, $relPath);
        if ($abs !== null && is_file($abs)) {
            return $abs;
        }
    }
    return '';
}

/**
 * 校验图片并返回元数据；失败时返回带 skipped_reason 的数组，不中断整批扫描。
 *
 * @param string $absPath 绝对路径
 * @return array ImageMeta 或含 skipped_reason 的数组
 */
function bglx_rev_image_meta($absPath)
{
    $meta = array(
        'rel_path' => '', 'abs_path' => '', 'ext' => '', 'mime' => '',
        'bytes' => 0, 'width' => 0, 'height' => 0, 'mtime' => 0,
        'thumb_url' => '', 'thumb_fallback' => 'original',
    );
    $abs = realpath((string)$absPath);
    if ($abs === false || !is_file($abs) || !is_readable($abs)) {
        $meta['skipped_reason'] = '文件不存在或不可读';
        return $meta;
    }
    $bytes = @filesize($abs);
    if ($bytes === false) {
        $meta['skipped_reason'] = '无法读取文件大小';
        return $meta;
    }
    $maxBytes = bglx_rev_setting_int('attachmentMaxBytes', 8388608);
    if ($bytes > $maxBytes) {
        $meta['skipped_reason'] = '超过 ' . $maxBytes . ' 字节上限';
        return $meta;
    }

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $allowedExt = array_map(function ($v) {
        return strtolower((string)$v);
    }, bglx_rev_setting_list('attachmentExtensions', bglx_rev_defaults()['attachmentExtensions']));
    if (!in_array($ext, $allowedExt, true)) {
        $meta['skipped_reason'] = '扩展名不在白名单';
        return $meta;
    }

    $size = @getimagesize($abs);
    if ($size === false || !isset($size['mime'])) {
        $meta['skipped_reason'] = '不是有效图片';
        return $meta;
    }
    $mimeMap = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    $mime = strtolower((string)$size['mime']);
    if (!isset($mimeMap[$mime])) {
        // 明确拒绝 SVG（可内嵌脚本）与 GIF 动画等风险类型
        $meta['skipped_reason'] = 'MIME 类型不允许：' . bglx_cut($mime, 40);
        return $meta;
    }
    $extForMime = array($mimeMap[$mime]);
    if ($mime === 'image/jpeg') {
        $extForMime[] = 'jpeg';
    }
    if (!in_array($ext, $extForMime, true)) {
        $meta['skipped_reason'] = '扩展名与实际类型不符';
        return $meta;
    }
    $width = (int)$size[0];
    $height = (int)$size[1];
    if ($width <= 0 || $height <= 0 || ($width * $height) > BGLX_REV_MAX_PIXELS) {
        $meta['skipped_reason'] = '图片像素尺寸异常或过大';
        return $meta;
    }

    $rel = bglx_rev_rel_to_base(bglx_data_dir(), $abs);
    if ($rel === '') {
        $rel = $abs; // 位于 data 目录之外的绝对候选目录
    }
    $meta['rel_path'] = $rel;
    $meta['abs_path'] = $abs;
    $meta['ext'] = $ext;
    $meta['mime'] = $mime;
    $meta['bytes'] = (int)$bytes;
    $meta['width'] = $width;
    $meta['height'] = $height;
    $meta['mtime'] = (int)@filemtime($abs);

    $thumb = bglx_rev_thumbnail($abs, bglx_rev_setting_int('thumbnailSize', 320));
    $meta['thumb_url'] = $thumb['url'];
    $meta['thumb_fallback'] = $thumb['fallback'];
    return $meta;
}

/**
 * 计算缩略图缓存路径与 MIME；缓存键含相对路径、mtime、size 与边长。
 *
 * @param string $absPath 原图绝对路径
 * @param int    $size    缩略图边长
 * @return array array('file','rel','mime','ext')
 */
function bglx_rev_thumb_cache($absPath, $size)
{
    $size = max(32, min(1024, (int)$size));
    $rel = bglx_rev_rel_to_base(bglx_data_dir(), (string)$absPath);
    if ($rel === '') {
        $rel = (string)$absPath;
    }
    $mtime = (int)@filemtime((string)$absPath);
    $bytes = (int)@filesize((string)$absPath);
    $hash = md5($rel . '|' . $mtime . '|' . $bytes . '|' . $size);

    $info = @getimagesize((string)$absPath);
    $isWebp = is_array($info) && isset($info['mime'])
        && $info['mime'] === 'image/webp'
        && function_exists('imagewebp') && function_exists('imagecreatefromwebp');
    $ext = $isWebp ? 'webp' : 'jpg';
    $dir = bglx_rev_data_path('review-thumbs');
    $file = $dir . '/' . $hash . '.' . $ext;
    return array(
        'file' => $file,
        'rel' => bglx_rev_rel_to_base(bglx_data_dir(), $dir) . '/' . $hash . '.' . $ext,
        'mime' => $isWebp ? 'image/webp' : 'image/jpeg',
        'ext' => $ext,
    );
}

/**
 * 用 GD 生成缩略图；任何失败返回 false，由调用方降级原图。
 *
 * @param string $absPath 原图
 * @param string $target  目标缓存文件
 * @param int    $size    边长
 * @return bool
 */
function bglx_rev_make_thumb($absPath, $target, $size)
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        return false;
    }
    $info = @getimagesize((string)$absPath);
    if (!is_array($info) || !isset($info[0], $info[1])) {
        return false;
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w <= 0 || $h <= 0 || ($w * $h) > BGLX_REV_MAX_PIXELS) {
        return false;
    }
    $mime = isset($info['mime']) ? (string)$info['mime'] : '';
    $src = false;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg((string)$absPath);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $src = @imagecreatefrompng((string)$absPath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp((string)$absPath);
    }
    if ($src === false) {
        return false;
    }
    $size = max(32, min(1024, (int)$size));
    $ratio = min(1.0, $size / (float)max($w, $h));
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));
    $dst = @imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    if (!@imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
        imagedestroy($src);
        imagedestroy($dst);
        return false;
    }
    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ok = false;
    if (preg_match('/\.webp$/i', $target) && function_exists('imagewebp')) {
        $ok = @imagewebp($dst, $target, 82);
    } else {
        // JPEG 无透明通道：先铺白底再合成，避免透明区域变黑
        $flat = @imagecreatetruecolor($nw, $nh);
        if ($flat !== false) {
            $white = imagecolorallocate($flat, 255, 255, 255);
            imagefilledrectangle($flat, 0, 0, $nw, $nh, $white);
            imagecopy($flat, $dst, 0, 0, 0, 0, $nw, $nh);
            $ok = @imagejpeg($flat, $target, 82);
            imagedestroy($flat);
        }
    }
    imagedestroy($src);
    imagedestroy($dst);
    return (bool)$ok;
}

/**
 * 生成缩略图访问信息（实际生成在 review_image 受控输出时按需完成）。
 *
 * @param string $absPath 原图绝对路径
 * @param int    $size    边长
 * @return array array('url','fallback','width','height')
 */
function bglx_rev_thumbnail($absPath, $size)
{
    $abs = realpath((string)$absPath);
    $width = 0;
    $height = 0;
    if ($abs !== false) {
        $info = @getimagesize($abs);
        if (is_array($info)) {
            $width = (int)$info[0];
            $height = (int)$info[1];
        }
    }
    $rel = $abs === false ? (string)$absPath : bglx_rev_rel_to_base(bglx_data_dir(), $abs);
    if ($rel === '') {
        $rel = (string)$absPath;
    }
    $gd = function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    $url = 'api.php?action=review_image&path=' . rawurlencode($rel) . '&thumb=1';
    return array(
        'url' => $url,
        'fallback' => $gd ? 'gd' : 'original',
        'width' => $width,
        'height' => $height,
    );
}

/**
 * 扫描全部候选目录下的图片（递归，带深度/数量/字节三重上限）。
 *
 * @param array $opts array('keyword','date','limit')
 * @return array array('items','warnings','truncated','scanned','skipped')
 */
function bglx_rev_scan_images($opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    $keyword = trim((string)(isset($opts['keyword']) ? $opts['keyword'] : ''));
    $date = trim((string)(isset($opts['date']) ? $opts['date'] : ''));
    $limit = (int)(isset($opts['limit']) ? $opts['limit'] : BGLX_REV_MAX_SCAN_FILES);
    $limit = max(1, min(BGLX_REV_MAX_SCAN_FILES, $limit));

    $dirInfo = bglx_rev_attachment_dirs();
    $items = array();
    $warnings = $dirInfo['warnings'];
    $truncated = false;
    $scanned = 0;
    $skipped = 0;

    foreach ($dirInfo['dirs'] as $base) {
        $queue = array(array($base, '', 0));
        while ($queue) {
            $node = array_shift($queue);
            $curAbs = (string)$node[0];
            $curRel = (string)$node[1];
            $depth = (int)$node[2];

            $safeDir = ($curRel === '') ? realpath($curAbs) : bglx_rev_safe_path($base, $curRel);
            if ($safeDir === null || $safeDir === false || !is_dir($safeDir)) {
                continue;
            }
            $entries = @scandir($safeDir);
            if ($entries === false) {
                $warnings[] = '无法列出附件子目录：' . bglx_cut($curRel, 100);
                continue;
            }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $childRel = ($curRel === '') ? (string)$name : $curRel . '/' . $name;
                $childAbs = bglx_rev_safe_path($base, $childRel);
                if ($childAbs === null) {
                    continue;
                }
                if (is_dir($childAbs)) {
                    if ($depth < BGLX_REV_MAX_DEPTH) {
                        $queue[] = array($childAbs, $childRel, $depth + 1);
                    }
                    continue;
                }
                if (!is_file($childAbs)) {
                    continue;
                }
                $scanned++;
                if ($scanned > BGLX_REV_MAX_SCAN_FILES) {
                    $truncated = true;
                    break 2;
                }
                $ext = strtolower(pathinfo($childAbs, PATHINFO_EXTENSION));
                if ($ext === '') {
                    continue;
                }
                $meta = bglx_rev_image_meta($childAbs);
                if (isset($meta['skipped_reason'])) {
                    $skipped++;
                    continue;
                }
                if ($keyword !== '' && stripos($meta['rel_path'], $keyword) === false) {
                    continue;
                }
                if ($date !== '') {
                    $compact = str_replace('-', '', $date);
                    $slashDate = str_replace('-', '/', $date);
                    if (strpos($meta['rel_path'], $date) === false
                        && strpos($meta['rel_path'], $compact) === false
                        && strpos($meta['rel_path'], $slashDate) === false) {
                        continue;
                    }
                }
                $items[] = $meta;
                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }
            }
        }
    }
    usort($items, function ($a, $b) {
        $ma = isset($a['mtime']) ? (int)$a['mtime'] : 0;
        $mb = isset($b['mtime']) ? (int)$b['mtime'] : 0;
        return $mb <=> $ma;
    });
    return array(
        'items' => $items,
        'warnings' => $warnings,
        'truncated' => $truncated,
        'scanned' => $scanned,
        'skipped' => $skipped,
    );
}

/**
 * 受控输出图片流：穿越校验 + MIME 白名单 + nosniff，输出后直接 exit。
 *
 * @param string $relPath 相对路径（可含 thumb 参数对应的原图路径）
 * @param bool   $thumb   是否输出缩略图
 * @return void
 */
function bglx_rev_serve_image($relPath, $thumb)
{
    $abs = bglx_rev_image_abs($relPath);
    if ($abs === '') {
        http_response_code(404);
        exit;
    }
    $meta = bglx_rev_image_meta($abs);
    if (isset($meta['skipped_reason'])) {
        http_response_code(404);
        exit;
    }
    $serve = $abs;
    $mime = $meta['mime'];
    if ($thumb && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled')) {
        $size = bglx_rev_setting_int('thumbnailSize', 320);
        $cache = bglx_rev_thumb_cache($abs, $size);
        if (!is_file($cache['file'])) {
            bglx_rev_make_thumb($abs, $cache['file'], $size);
        }
        if (is_file($cache['file'])) {
            $cacheAbs = bglx_rev_safe_path(bglx_data_dir(), $cache['rel']);
            if ($cacheAbs !== null) {
                $serve = $cacheAbs;
                $mime = $cache['mime'];
            }
        }
    }
    if (!headers_sent()) {
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="image"');
        header('Cache-Control: private, max-age=3600');
    }
    @readfile($serve);
    exit;
}
