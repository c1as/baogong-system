<?php
/* 模块: lib/image
   职责: 零件图片/附件/图纸的命名、存取、改名
   依赖: lib/config
   注意: 图纸支持图片与 PDF，原样存盘 */

/* ================================================================ 数据层 */

function bg_image_name($v)
{
    if (!is_string($v) || $v === '') {
        return '';
    }
    if (strpos($v, 'data:') === 0) {
        return '';
    }
    $p = strpos($v, '?');
    if ($p !== false) {
        $v = substr($v, 0, $p);
    }
    $p = strpos($v, '#');
    if ($p !== false) {
        $v = substr($v, 0, $p);
    }
    $v = ltrim(str_replace('\\', '/', $v), '/');
    if (preg_match('#^(parts|attachments|drawings)/([^/]+)$#', $v, $m)) {
        return $m[1] . '/' . $m[2];
    }
    $slash = strrpos($v, '/');
    if ($slash !== false) {
        $v = substr($v, $slash + 1);
    }
    return $v;
}

/* 按名字在各图片目录中定位文件：parts/ → attachments/ → 旧版 images 根目录 */
function bg_image_path($name)
{
    global $IMG_ROOT, $PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR;
    $name = bg_image_name($name);
    if ($name === '') {
        return '';
    }
    $segs = explode('/', $name);
    $file = end($segs);
    if (!preg_match('/^[0-9A-Za-z_.\-\x{4e00}-\x{9fa5}]+\.(jpg|jpeg|png|webp|pdf)$/u', $file)) {
        return '';
    }
    $dirs = array();
    if (count($segs) === 2) {
        if ($segs[0] === 'parts') {
            $dirs[] = $PART_IMG_DIR;
        } elseif ($segs[0] === 'attachments') {
            $dirs[] = $ATT_IMG_DIR;
        } elseif ($segs[0] === 'drawings') {
            $dirs[] = $DRAW_DIR;
        }
    }
    $dirs[] = $PART_IMG_DIR;
    $dirs[] = $ATT_IMG_DIR;
    $dirs[] = $DRAW_DIR;
    $dirs[] = $IMG_ROOT;
    foreach ($dirs as $d) {
        if (is_file($d . '/' . $file)) {
            return $d . '/' . $file;
        }
    }
    return '';
}

/* 零件图片文件名：优先零件名称，其次图号；去掉文件系统不允许的字符 */
function bg_part_image_base($part)
{
    $label = isset($part['name']) ? trim((string)$part['name']) : '';
    if ($label === '') {
        $label = isset($part['no']) ? trim((string)$part['no']) : '';
    }
    $label = preg_replace('/[\\\\\/:*?"<>|\s\x{00a0}]+/u', '-', $label);
    $label = trim($label, '.-_');
    $label = function_exists('mb_substr') ? mb_substr($label, 0, 40, 'UTF-8') : substr($label, 0, 40);
    return $label !== '' ? $label : '零件';
}

/* 当天附件序号最大值 */
function bg_attach_max_seq($day)
{
    global $ATT_IMG_DIR;
    $max = 0;
    $items = @scandir($ATT_IMG_DIR);
    if (!is_array($items)) {
        return $max;
    }
    foreach ($items as $f) {
        if (preg_match('/^' . preg_quote($day, '/') . '-(\d{3,4})\.(jpg|jpeg|png|webp)$/i', $f, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

/* 附件文件名：上传日期 + 当天序号，如 20261120-001 */
function bg_attach_base()
{
    $day = date('Ymd');
    $next = bg_attach_max_seq($day) + 1;
    return $day . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* 零件改名后同步图片文件名 */
function bg_part_image_rename($part, $old)
{
    global $PART_IMG_DIR;
    $src = bg_image_path($old);
    if ($src === '' || dirname(str_replace('\\', '/', $src)) !== str_replace('\\', '/', $PART_IMG_DIR)) {
        return bg_image_name($old);
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $base = bg_part_image_base($part);
    $name = $base;
    $i = 2;
    while (is_file($PART_IMG_DIR . '/' . $name . '.' . $ext) && $PART_IMG_DIR . '/' . $name . '.' . $ext !== $src) {
        $name = $base . '-' . $i;
        $i++;
    }
    $dst = $PART_IMG_DIR . '/' . $name . '.' . $ext;
    if ($dst !== $src && !@rename($src, $dst)) {
        return bg_image_name($old);
    }
    return 'parts/' . $name . '.' . $ext;
}

/* ================================================================ 图片 */

function bg_image_remove($name)
{
    $path = bg_image_path($name);
    if ($path !== '') {
        @unlink($path);
    }
}

function bg_image_save($sub, $base, $dataUrl, $dedupe = '')
{
    global $PART_IMG_DIR, $ATT_IMG_DIR;
    if (!is_string($dataUrl) || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m)) {
        bg_fail(400, '图片格式不支持（请用 JPG / PNG / WebP）');
    }
    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === false || $bin === '') {
        bg_fail(400, '图片内容为空');
    }
    if (strlen($bin) > 8388608) {
        bg_fail(400, '图片太大，请压缩后再上传');
    }
    $ext = '.jpg';
    if ($m[1] === 'png') {
        $ext = '.png';
    }
    if ($m[1] === 'webp') {
        $ext = '.webp';
    }
    if (!preg_match('/^[0-9A-Za-z_.\-\x{4e00}-\x{9fa5}]+$/u', (string)$base)) {
        bg_fail(400, '图片文件名不合法');
    }
    $isAtt = $sub === 'attachments';
    $dir = $isAtt ? $ATT_IMG_DIR : $PART_IMG_DIR;
    $prefix = $isAtt ? 'attachments' : 'parts';
    $name = $base;
    if (is_file($dir . '/' . $name . $ext)) {
        $suffix = $dedupe !== '' ? substr(md5($dedupe), 0, 4) : '2';
        $name = $base . '-' . $suffix;
        $i = 2;
        while (is_file($dir . '/' . $name . $ext) && $i < 100) {
            $name = $base . '-' . $suffix . '-' . $i;
            $i++;
        }
    }
    if (@file_put_contents($dir . '/' . $name . $ext, $bin) === false) {
        bg_fail(500, '图片保存失败，请检查 ' . $prefix . ' 目录权限');
    }
    return $prefix . '/' . $name . $ext;
}

/* 图纸列表规范化：只保留 drawings/ 下的图片或 PDF */
function bg_normalize_drawings($in, $existing)
{
    $src = null;
    if (isset($in['drawings']) && is_array($in['drawings'])) {
        $src = $in['drawings'];
    } elseif ($existing && isset($existing['drawings']) && is_array($existing['drawings'])) {
        $src = $existing['drawings'];
    }
    $out = array();
    if (is_array($src)) {
        foreach ($src as $d) {
            if (!is_array($d)) {
                continue;
            }
            $path = bg_image_name(isset($d['path']) ? $d['path'] : '');
            if ($path === '' || strpos($path, 'drawings/') !== 0) {
                continue;
            }
            $name = isset($d['name']) && (string)$d['name'] !== '' ? basename((string)$d['name']) : basename($path);
            $type = isset($d['type']) && $d['type'] === 'pdf' ? 'pdf' : 'image';
            $out[] = array('path' => $path, 'name' => $name, 'type' => $type);
            if (count($out) >= 20) {
                break;
            }
        }
    }
    return $out;
}

/* 保存一张图纸：图片（JPG/PNG/WebP）或 PDF，原样存盘不压缩 */
function bg_drawing_save($part, $dataUrl)
{
    global $DRAW_DIR;
    if (!is_string($dataUrl) || $dataUrl === '') {
        bg_fail(400, '图纸内容为空');
    }
    $ext = '';
    $bin = false;
    if (preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m)) {
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bin !== false && strlen($bin) > 8388608) {
            bg_fail(400, '图片图纸太大（限 8MB）');
        }
    } elseif (preg_match('#^data:application/pdf;base64,(.+)$#s', $dataUrl, $m)) {
        $ext = 'pdf';
        $bin = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($bin !== false && strlen($bin) > 20971520) {
            bg_fail(400, 'PDF 图纸太大（限 20MB）');
        }
    } else {
        bg_fail(400, '图纸格式不支持（请用 JPG / PNG / WebP / PDF）');
    }
    if ($bin === false || $bin === '') {
        bg_fail(400, '图纸内容为空');
    }
    $base = bg_part_image_base($part);
    $i = 1;
    while (is_file($DRAW_DIR . '/' . $base . '-' . $i . '.' . $ext)) {
        $i++;
    }
    $fname = $base . '-' . $i . '.' . $ext;
    if (@file_put_contents($DRAW_DIR . '/' . $fname, $bin) === false) {
        bg_fail(500, '图纸保存失败，请检查 drawings 目录权限');
    }
    return array('path' => 'drawings/' . $fname, 'name' => $fname, 'type' => $ext === 'pdf' ? 'pdf' : 'image');
}

/* 零件改名后同步图纸文件名 */
function bg_drawings_rename($drawings, $part)
{
    global $DRAW_DIR;
    if (!is_array($drawings) || !count($drawings)) {
        return array();
    }
    $base = bg_part_image_base($part);
    $out = array();
    $i = 0;
    foreach ($drawings as $d) {
        $i++;
        $src = bg_image_path(isset($d['path']) ? $d['path'] : '');
        $type = isset($d['type']) && $d['type'] === 'pdf' ? 'pdf' : 'image';
        if ($src === '' || str_replace('\\', '/', dirname($src)) !== str_replace('\\', '/', $DRAW_DIR)) {
            $out[] = array(
                'path' => isset($d['path']) ? $d['path'] : '',
                'name' => isset($d['name']) ? $d['name'] : '',
                'type' => $type,
            );
            continue;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $name = $base . '-' . $i;
        $try = $name;
        $n = 2;
        while (is_file($DRAW_DIR . '/' . $try . '.' . $ext) && $DRAW_DIR . '/' . $try . '.' . $ext !== $src) {
            $try = $name . '-' . $n;
            $n++;
        }
        $dst = $DRAW_DIR . '/' . $try . '.' . $ext;
        if ($dst !== $src && !@rename($src, $dst)) {
            $out[] = array(
                'path' => isset($d['path']) ? $d['path'] : '',
                'name' => isset($d['name']) ? $d['name'] : '',
                'type' => $type,
            );
            continue;
        }
        $out[] = array('path' => 'drawings/' . $try . '.' . $ext, 'name' => $try . '.' . $ext, 'type' => $type);
    }
    return $out;
}
