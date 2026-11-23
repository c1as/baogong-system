<?php
/* 模块: actions/parts
   Action: parts.list parts.search parts.get parts.match part.create part.update part.delete part.drawing.remove part.stock part.import
   职责: 零件列表/搜索/详情/匹配与增删改、库存、批量导入
   依赖: lib/repo lib/validate lib/image lib/query lib/match
   注意: 零件改名/改图号后需同步图片与图纸文件名 */

function act_parts_list()
{
    $pdo = bg_pdo();
    $q = bg_get('q', 100);
    list($page, $size, $offset) = bg_page();
    $params = array();
    $where = bg_like_any($params, $q, array('p.no', 'p.name', 'p.material'), 'pl');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM parts p WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT p.* FROM parts p WHERE ' . $where . ' ORDER BY COALESCE(p.updated_at, \'\') DESC, p.id DESC LIMIT ' . $size . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $items = array();
    $ids = array();
    foreach ($stmt as $row) {
        $items[] = bg_part_row($row);
        $ids[] = $row['id'];
    }
    $usage = bg_parts_usage($pdo, $ids);
    foreach ($items as $i => $it) {
        $items[$i]['usage'] = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'times' => 0);
    }
    bg_json(200, array(
        'ok' => true,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pageSize' => $size,
        'hasMore' => $offset + count($items) < $total,
    ));
    return;
}

function act_parts_search()
{
    $pdo = bg_pdo();
    $q = bg_get('q', 100);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($limit < 1) {
        $limit = 20;
    }
    if ($limit > 50) {
        $limit = 50;
    }
    $params = array();
    $where = bg_like_any($params, $q, array('p.no', 'p.name', 'p.material'), 'ps');
    $stmt = $pdo->prepare('SELECT p.* FROM parts p WHERE ' . $where . ' ORDER BY COALESCE(p.updated_at, \'\') DESC, p.id DESC LIMIT ' . $limit);
    $stmt->execute($params);
    $items = array();
    foreach ($stmt as $row) {
        $items[] = bg_part_row($row);
    }
    bg_json(200, array('ok' => true, 'items' => $items));
    return;
}

function act_parts_get()
{
    $pdo = bg_pdo();
    $ids = bg_ids_param('ids');
    $id = bg_get('id', 60);
    if (!count($ids) && $id !== '') {
        $ids = array($id);
    }
    if (!count($ids)) {
        bg_fail(400, '缺少零件 id');
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id IN (' . $ph . ')');
    $stmt->execute($ids);
    $items = array();
    $got = array();
    foreach ($stmt as $row) {
        $items[] = bg_part_row($row);
        $got[] = $row['id'];
    }
    $usage = bg_parts_usage($pdo, $got);
    foreach ($items as $i => $it) {
        $items[$i]['usage'] = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'times' => 0);
    }
    if (isset($_GET['ids'])) {
        bg_json(200, array('items' => $items));
    }
    if (!count($items)) {
        bg_fail(404, '零件不存在');
    }
    bg_json(200, array('part' => $items[0]));
    return;
}

function act_parts_match()
{
    global $method;
    $pdo = bg_pdo();
    $parts = bg_all_parts_for_match($pdo);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
    if ($limit < 1) {
        $limit = 5;
    }
    if ($limit > 20) {
        $limit = 20;
    }
    if ($method === 'POST') {
        $body = bg_body();
        $matches = array();
        if (isset($body['queries']) && is_array($body['queries'])) {
            foreach ($body['queries'] as $qq) {
                $no = is_array($qq) && isset($qq['no']) && is_scalar($qq['no']) ? trim((string)$qq['no']) : '';
                $name = is_array($qq) && isset($qq['name']) && is_scalar($qq['name']) ? trim((string)$qq['name']) : '';
                $hit = bg_match_part($parts, $no, $name);
                if (bg_log_match_on()) {
                    bg_log_match($no, $name, $hit, bg_fuzzy_candidates($parts, trim($no . ' ' . $name), 3));
                }
                $matches[] = $hit;
            }
        } elseif (isset($body['texts']) && is_array($body['texts'])) {
            foreach ($body['texts'] as $t) {
                $t = is_scalar($t) ? trim((string)$t) : '';
                $fz = bg_fuzzy_candidates($parts, $t, 1);
                $matches[] = count($fz) ? $fz[0] : null;
            }
        }
        bg_json(200, array('ok' => true, 'matches' => $matches));
    }
    $no = bg_get('no', 100);
    $name = bg_get('name', 100);
    $text = bg_get('text', 300);
    if ($no !== '' || $name !== '') {
        bg_json(200, array(
            'ok' => true,
            'match' => bg_match_part($parts, $no, $name),
            'candidates' => bg_fuzzy_candidates($parts, trim($no . ' ' . $name), $limit),
        ));
    }
    bg_json(200, array(
        'ok' => true,
        'match' => null,
        'candidates' => bg_fuzzy_candidates($parts, $text, $limit),
    ));
    return;
}

function act_part_create()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $in = isset($body['part']) && is_array($body['part']) ? $body['part'] : $body;
    $db = bg_db_load();
    $part = bg_normalize_part($in, null);
    foreach ($db['parts'] as $p) {
        if (isset($p['no']) && $p['no'] === $part['no']) {
            bg_fail(400, '零件图号「' . $part['no'] . '」已存在');
        }
    }
    if (isset($in['image']) && is_string($in['image']) && strpos($in['image'], 'data:') === 0) {
        $part['image'] = bg_image_save('parts', bg_part_image_base($part), $in['image'], $part['id']);
    }
    $newDrawings = isset($body['newDrawings']) && is_array($body['newDrawings']) ? array_slice($body['newDrawings'], 0, 20) : array();
    foreach ($newDrawings as $nd) {
        $part['drawings'][] = bg_drawing_save($part, isset($nd['data']) ? $nd['data'] : '');
    }
    $db['parts'][] = $part;
    bg_db_save($db);
    bg_json(200, array('part' => $part));
    return;
}

function act_part_update()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $body = bg_body();
    $in = isset($body['part']) && is_array($body['part']) ? $body['part'] : $body;
    $db = bg_db_load();
    $target = -1;
    foreach ($db['parts'] as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            $target = $i;
            break;
        }
    }
    if ($target < 0) {
        bg_fail(404, '零件不存在');
    }
    $existing = $db['parts'][$target];
    $part = bg_normalize_part($in, $existing);
    foreach ($db['parts'] as $p) {
        if (isset($p['no']) && $p['no'] === $part['no'] && $p['id'] !== $existing['id']) {
            bg_fail(400, '零件图号「' . $part['no'] . '」已存在');
        }
    }
    $part['image'] = isset($existing['image']) ? bg_image_name($existing['image']) : '';
    if (isset($in['image']) && is_string($in['image']) && strpos($in['image'], 'data:') === 0) {
        bg_image_remove(isset($existing['image']) ? $existing['image'] : '');
        $part['image'] = bg_image_save('parts', bg_part_image_base($part), $in['image'], $part['id']);
    } elseif (!empty($in['removeImage'])) {
        bg_image_remove(isset($existing['image']) ? $existing['image'] : '');
        $part['image'] = '';
    } elseif ($part['image'] !== '') {
        $part['image'] = bg_part_image_rename($part, $part['image']);
    }
    /* 图纸：删除被移除的物理文件，保存新增图纸，零件改名时同步文件名 */
    $oldDrawings = isset($existing['drawings']) && is_array($existing['drawings']) ? $existing['drawings'] : array();
    $keptPaths = array();
    foreach ($part['drawings'] as $d) {
        $keptPaths[] = $d['path'];
    }
    foreach ($oldDrawings as $od) {
        $oldPath = bg_image_name(isset($od['path']) ? $od['path'] : '');
        if ($oldPath !== '' && !in_array($oldPath, $keptPaths, true)) {
            bg_image_remove($oldPath);
        }
    }
    $newDrawings = isset($body['newDrawings']) && is_array($body['newDrawings']) ? array_slice($body['newDrawings'], 0, 20) : array();
    foreach ($newDrawings as $nd) {
        $part['drawings'][] = bg_drawing_save($part, isset($nd['data']) ? $nd['data'] : '');
    }
    $oldName = isset($existing['name']) ? (string)$existing['name'] : '';
    if ($oldName !== (string)$part['name'] || (isset($existing['no']) ? $existing['no'] : '') !== $part['no']) {
        $part['drawings'] = bg_drawings_rename($part['drawings'], $part);
    }
    $db['parts'][$target] = $part;
    bg_db_save($db);
    bg_json(200, array('part' => $part));
    return;
}

function act_part_delete()
{
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $db = bg_db_load();
    $target = -1;
    foreach ($db['parts'] as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            $target = $i;
            break;
        }
    }
    if ($target < 0) {
        bg_fail(404, '零件不存在');
    }
    bg_image_remove(isset($db['parts'][$target]['image']) ? $db['parts'][$target]['image'] : '');
    $drawings = isset($db['parts'][$target]['drawings']) && is_array($db['parts'][$target]['drawings']) ? $db['parts'][$target]['drawings'] : array();
    foreach ($drawings as $d) {
        bg_image_remove(isset($d['path']) ? $d['path'] : '');
    }
    array_splice($db['parts'], $target, 1);
    bg_db_save($db);
    bg_json(200, array('ok' => true));
    return;
}

function act_part_drawing_remove()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $body = bg_body();
    $path = bg_image_name(isset($body['path']) ? $body['path'] : '');
    if ($path === '' || strpos($path, 'drawings/') !== 0) {
        bg_fail(400, '图纸路径不正确');
    }
    $db = bg_db_load();
    $target = -1;
    foreach ($db['parts'] as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            $target = $i;
            break;
        }
    }
    if ($target < 0) {
        bg_fail(404, '零件不存在');
    }
    $left = array();
    $found = false;
    foreach ($db['parts'][$target]['drawings'] as $d) {
        if (isset($d['path']) && $d['path'] === $path) {
            $found = true;
            continue;
        }
        $left[] = $d;
    }
    if (!$found) {
        bg_fail(404, '图纸不存在');
    }
    bg_image_remove($path);
    $db['parts'][$target]['drawings'] = $left;
    $db['parts'][$target]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
    bg_db_save($db);
    bg_json(200, array('part' => $db['parts'][$target]));
    return;
}

function act_part_stock()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $body = bg_body();
    $db = bg_db_load();
    $target = -1;
    foreach ($db['parts'] as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            $target = $i;
            break;
        }
    }
    if ($target < 0) {
        bg_fail(404, '零件不存在');
    }
    $stock = bg_round(bg_num(isset($body['stock']) ? $body['stock'] : 0, '库存数量', 0, 1000000000));
    $db['parts'][$target]['stock'] = $stock;
    $db['parts'][$target]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
    bg_db_save($db);
    bg_json(200, array('part' => $db['parts'][$target]));
    return;
}

function act_part_import()
{
    global $BG_THROW, $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $rows = isset($body['parts']) && is_array($body['parts']) ? $body['parts'] : array();
    if (count($rows) === 0) {
        bg_fail(400, '没有可导入的数据');
    }
    if (count($rows) > 500) {
        bg_fail(400, '一次最多导入 500 个零件');
    }
    $db = bg_db_load();
    $added = 0;
    $updated = 0;
    $errors = array();
    $BG_THROW = true;
    try {
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            try {
                $row = is_array($row) ? $row : array();
                $no = isset($row['no']) ? trim((string)$row['no']) : '';
                $existingIndex = -1;
                foreach ($db['parts'] as $pi => $p) {
                    if (isset($p['no']) && $p['no'] === $no) {
                        $existingIndex = $pi;
                        break;
                    }
                }
                $existing = $existingIndex >= 0 ? $db['parts'][$existingIndex] : null;
                $part = bg_normalize_part($row, $existing);
                if ($existingIndex >= 0) {
                    $db['parts'][$existingIndex] = $part;
                    $updated++;
                } else {
                    $db['parts'][] = $part;
                    $added++;
                }
            } catch (RuntimeException $e) {
                if (count($errors) < 20) {
                    $errors[] = '第 ' . $i . ' 行：' . $e->getMessage();
                }
            }
        }
        bg_db_save($db);
    } finally {
        $BG_THROW = false;
    }
    bg_json(200, array('added' => $added, 'updated' => $updated, 'errors' => $errors));
    return;
}
