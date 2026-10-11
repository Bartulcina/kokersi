
<?php
// shared wiki functions
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$mysqli->set_charset('utf8mb4');

function wikiEscape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function wikiArticleUrl($title, $mysqli) {
    static $cache = [];
    $title = trim($title);

    if (!array_key_exists($title, $cache)) {
        $stmt = $mysqli->prepare('SELECT id FROM wiki_articles WHERE title = ? LIMIT 1');
        $stmt->bind_param('s', $title);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $cache[$title] = $row
            ? 'article.php?id=' . (int)$row['id']
            : 'index.php?search=' . rawurlencode($title);
    }

    return $cache[$title];
}

function wikiStyledText($text) {
    // don't escape apostrophes because they are wiki formatting markers
    $text = htmlspecialchars((string)$text, ENT_NOQUOTES, 'UTF-8');
    $text = preg_replace("/'''''(.*?)'''''/s", '<strong><em>$1</em></strong>', $text);
    $text = preg_replace("/'''(.*?)'''/s", '<strong>$1</strong>', $text);
    return preg_replace("/''(.*?)''/s", '<em>$1</em>', $text);
}

function wikiInline($text, $mysqli) {
    $parts = preg_split('/(\[\[[^\[\]\r\n]+\]\])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';

    foreach ($parts as $part) {
        if (preg_match('/^\[\[([^\[\]]+)\]\]$/', $part, $match)) {
            $link = explode('|', $match[1], 2);
            $title = trim($link[0]);
            $label = trim($link[1] ?? $title);
            if ($title !== '') {
                $html .= '<a href="' . wikiEscape(wikiArticleUrl($title, $mysqli)) . '">'
                    . wikiStyledText($label) . '</a>';
                continue;
            }
        }
        $html .= wikiStyledText($part);
    }

    return $html;
}

function wikiBooruImage($id, $caption, $mysqli, $small = false) {
    if ($id < 1) return '';

    static $images = [];
    if (!array_key_exists($id, $images)) {
        $stmt = $mysqli->prepare('SELECT filename FROM booru_posts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $images[$id] = $stmt->get_result()->fetch_assoc();
    }

    if (!$images[$id]) {
        return '<span class="wiki-missing">Booru image #' . $id . ' not found.</span>';
    }

    $filename = basename($images[$id]['filename']);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return '';

    $original = __DIR__ . '/../booru/uploads/original/' . $filename;
    if (!is_file($original)) {
        return '<span class="wiki-missing">Booru image file #' . $id . ' is missing.</span>';
    }

    $thumb = __DIR__ . '/../booru/uploads/thumbs/' . $filename;
    $folder = ($small && is_file($thumb)) ? 'thumbs' : 'original';
    $src = '../booru/uploads/' . $folder . '/' . rawurlencode($filename);
    $postUrl = '../booru/post.php?id=' . $id;
    $class = $small ? 'wiki-image wiki-image-small' : 'wiki-image';

    $html = '<figure class="' . $class . '"><a href="' . wikiEscape($postUrl) . '">';
    $html .= '<img src="' . wikiEscape($src) . '" alt="' . wikiEscape($caption) . '" loading="lazy">';
    $html .= '</a>';
    if ($caption !== '') $html .= '<figcaption>' . wikiEscape($caption) . '</figcaption>';
    return $html . '</figure>';
}

function wikiExtractInfobox($content, $mysqli) {
    if (!preg_match('/\{\{(character|event)[ \t]*\r?\n(.*?)\}\}/is', $content, $match)) {
        return ['', $content];
    }

    $type = strtolower($match[1]);
    $fields = [];
    foreach (preg_split('/\r\n|\r|\n/', $match[2]) as $line) {
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = strtolower(trim($key));
        if ($key !== '') $fields[$key] = trim($value);
    }

    $name = $fields['name'] ?? ucfirst($type);
    $html = '<aside class="wiki-infobox">';
    $html .= '<h3>' . wikiEscape($name) . '</h3>';

    if (!empty($fields['image']) && ctype_digit($fields['image'])) {
        $html .= wikiBooruImage((int)$fields['image'], $fields['caption'] ?? '', $mysqli);
    }

    foreach ($fields as $key => $value) {
        if (in_array($key, ['name', 'image', 'caption'], true)) continue;
        $html .= '<div class="wiki-info-row"><strong>' . wikiEscape(ucfirst($key)) . '</strong>';
        $html .= '<span>' . wikiInline($value, $mysqli) . '</span></div>';
    }

    $html .= '</aside>';
    return [$html, str_replace($match[0], '', $content)];
}

function wikiExtractNotices($content, $mysqli) {
    $html = '';
    $labels = [
        'spoiler' => ['Spoiler warning', '!'],
        'nsfw' => ['NSFW content', '18+'],
        'warning' => ['Warning', '!'],
        'info' => ['Information', 'i']
    ];

    $content = preg_replace_callback('/\{\{notice\|([^{}\r\n]+)\}\}/i',
        function ($match) use (&$html, $labels, $mysqli) {
            $pieces = explode('|', $match[1]);
            $type = strtolower(trim(array_shift($pieces)));

            if ($type === 'custom') {
                if (count($pieces) < 2) return $match[0];
                $title = trim(array_shift($pieces));
                $message = trim(array_shift($pieces));
                $imageId = trim($pieces[0] ?? '');
                $class = 'info';
                $symbol = 'i';
            } elseif (isset($labels[$type])) {
                $title = $labels[$type][0];
                $symbol = $labels[$type][1];
                $message = trim(array_shift($pieces) ?? '');
                $imageId = trim($pieces[0] ?? '');
                $class = $type;
            } else {
                return $match[0];
            }

            $html .= '<div class="wiki-notice ' . $class . '">';
            if ($imageId !== '' && ctype_digit($imageId) && (int)$imageId > 0) {
                $html .= '<div class="notice-image">'
                    . wikiBooruImage((int)$imageId, '', $mysqli, true) . '</div>';
            } else {
                $html .= '<div class="notice-symbol">' . wikiEscape($symbol) . '</div>';
            }
            $html .= '<div class="notice-text"><strong>' . wikiEscape($title) . '</strong>';
            $html .= '<p>' . wikiEscape($message) . '</p></div></div>';
            return '';
        }, $content
    );

    return [$html, $content];
}

function wikiRenderBody($content, $mysqli) {
    $html = '';
    $paragraph = [];
    $flush = function () use (&$paragraph, &$html, $mysqli) {
        if ($paragraph) {
            $html .= '<p>' . wikiInline(implode(' ', $paragraph), $mysqli) . '</p>';
            $paragraph = [];
        }
    };

    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
        } elseif (preg_match('/^\[\[booru:([1-9][0-9]*)(?:\|([^\]]*))?\]\]$/i', $line, $m)) {
            $flush();
            $html .= wikiBooruImage((int)$m[1], $m[2] ?? '', $mysqli);
        } elseif (preg_match('/^===\s*(.*?)\s*===$/', $line, $m)) {
            $flush();
            $html .= '<h3>' . wikiInline($m[1], $mysqli) . '</h3>';
        } elseif (preg_match('/^==\s*(.*?)\s*==$/', $line, $m)) {
            $flush();
            $html .= '<h2>' . wikiInline($m[1], $mysqli) . '</h2>';
        } else {
            $paragraph[] = $line;
        }
    }
    $flush();
    return $html;
}
