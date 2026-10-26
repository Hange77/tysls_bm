<?php
/**
 * 通用辅助函数 - io
 *
 * 书签导入导出：HTML 书签文件解析 / CSV 解析 / HTML 与 CSV 导出。
 */

function export_bookmarks_html($links, $categories = []) {
    $catMap = [];
    foreach ($categories as $c) {
        $catMap[$c['id']] = $c['name'];
    }
    $date = date('Y-m-d H:i:s');
    $html = "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n";
    $html .= "<!-- This is an automatically generated file. -->\n";
    $html .= "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n";
    $html .= "<TITLE>Bookmarks</TITLE>\n";
    $html .= "<H1>Bookmarks - 导出于 {$date}</H1>\n";
    $html .= "<DL><p>\n";
    $grouped = [];
    foreach ($links as $link) {
        $cid = (int)($link['category_id'] ?? 0);
        if (!isset($grouped[$cid])) $grouped[$cid] = [];
        $grouped[$cid][] = $link;
    }
    foreach ($grouped as $cid => $catLinks) {
        $catName = $catMap[$cid] ?? '未分类';
        $html .= "    <DT><H3>{$catName}</H3>\n";
        $html .= "    <DL><p>\n";
        foreach ($catLinks as $link) {
            $title = htmlspecialchars($link['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($link['url'] ?? '', ENT_QUOTES, 'UTF-8');
            $addDate = isset($link['created_at']) ? strtotime($link['created_at']) : time();
            $html .= "        <DT><A HREF=\"{$url}\" ADD_DATE=\"{$addDate}\">{$title}</A>\n";
        }
        $html .= "    </DL><p>\n";
    }
    $html .= "</DL><p>\n";
    return $html;
}

function export_links_csv($links) {
    // 防止 Excel 公式注入: 以 = + - @ 开头的单元格前加单引号转为文本
    $sanitizeCell = function ($val) {
        $val = (string)$val;
        if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
            $val = "'" . $val;
        }
        return $val;
    };
    $output = fopen('php://temp', 'r+');
    fputcsv($output, ['标题', 'URL', '描述', '分类', '点击数', '添加时间']);
    foreach ($links as $link) {
        fputcsv($output, [
            $sanitizeCell($link['title'] ?? ''),
            $sanitizeCell($link['url'] ?? ''),
            $sanitizeCell($link['description'] ?? ''),
            $sanitizeCell($link['category_name'] ?? ''),
            $link['click_count'] ?? 0,
            $sanitizeCell($link['created_at'] ?? ''),
        ]);
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return "\xEF\xBB\xBF" . $csv;
}

// ========== v1.2 导入导出 ==========
function parse_bookmark_html($content) {
    $links = [];
    preg_match_all('/<A\s+[^>]*HREF\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/A>/is', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $url = trim($m[1]);
        $title = trim(strip_tags($m[2]));
        if ($url && $title && filter_var($url, FILTER_VALIDATE_URL)) {
            $links[] = ['title' => $title, 'url' => $url, 'description' => ''];
        }
    }
    return $links;
}

function parse_links_csv($content) {
    $links = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    foreach ($lines as $idx => $line) {
        $line = trim($line);
        if ($line === '') continue;
        if ($idx === 0 && (stripos($line, 'title') !== false || stripos($line, '标题') !== false) && stripos($line, 'url') !== false) continue;
        $parts = str_getcsv($line);
        if (count($parts) >= 2) {
            $title = trim($parts[0]);
            $url = trim($parts[1]);
            $description = count($parts) >= 3 ? trim($parts[2]) : '';
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = ['title' => $title, 'url' => $url, 'description' => $description];
            }
        }
    }
    return $links;
}

