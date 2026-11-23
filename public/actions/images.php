<?php
/* 模块: actions/images
   Action: image
   职责: 零件图片/附件/图纸读取，PDF 支持 Range
   依赖: lib/image lib/http
   注意: Range 通过 header+exit 直接输出 */

function act_image()
{
    $f = isset($_GET['f']) && is_string($_GET['f']) ? $_GET['f'] : '';
    $path = bg_image_path($f);
    if ($path === '') {
        bg_fail(404, '图片不存在');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf');
    $ctype = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';
    $size = filesize($path);
    header('Content-Type: ' . $ctype);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=31536000, immutable');
    if ($ext === 'pdf') {
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
        $range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : '';
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = 0;
            $end = $size - 1;
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') {
                    $end = min((int)$m[2], $size - 1);
                }
            }
            if ($start > $end || $start >= $size) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . ($end - $start + 1));
            $fp = fopen($path, 'rb');
            if ($fp) {
                fseek($fp, $start);
                $left = $end - $start + 1;
                while ($left > 0 && !feof($fp)) {
                    $chunk = fread($fp, min(65536, $left));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    $left -= strlen($chunk);
                }
                fclose($fp);
            }
            exit;
        }
    }
    header('Content-Length: ' . $size);
    readfile($path);
    exit;
}
