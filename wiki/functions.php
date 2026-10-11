
<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$mysqli->set_charset('utf8mb4');


function wikiEscape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// look up wiki links by article title
function wikiArticleUrl($title, $mysqli) {
    static $cache = [];
    $title = trim($title);

    if (!array_key_exists($title, $cache)) {
        $stmt = $mysqli->prepare(
            'SELECT id FROM wiki_articles WHERE title = ? LIMIT 1'
        );
        $stmt->bind_param('s', $title);
        $stmt->execute();
        $article = $stmt->get_result()->fetch_assoc();

        $cache[$title] = $article
            ? 'article.php?id=' . (int)$article['id']
            : 'index.php?search=' . rawurlencode($title);
    }

    return $cache[$title];
}

// bold and italic wiki formatting
function wikiStyledText($text) {
    $text = htmlspecialchars((string)$text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $text = preg_replace("/'''''(.*?)'''''/s", '<strong><em>$1</em></strong>', $text);
    $text = preg_replace("/'''(.*?)'''/s", '<strong>$1</strong>', $text);
    $text = preg_replace("/''(.*?)''/s", '<em>$1</em>', $text);

    return $text;
}

// internal and external links, together with bold and italic
function wikiInline($text, $mysqli) {
    $parts = preg_split(
        '~(\[\[[^\[\]\r\n]+\]\]|\[https?://[^\s\]\r\n]+(?:[ \t]+[^\]\r\n]*)?\])~i',
        (string)$text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    $html = '';

    foreach ($parts as $part) {
        // internal link: [[Page]] or [[Page|Label]]
        if (preg_match('/^\[\[([^\[\]]+)\]\]$/', $part, $match)) {
            $pieces = explode('|', $match[1], 2);
            $title = trim($pieces[0]);
            $label = trim($pieces[1] ?? $title);

            if ($title !== '') {
                $url = wikiArticleUrl($title, $mysqli);
                $html .= '<a href="' . wikiEscape($url) . '">'
                    . wikiStyledText($label) . '</a>';
                continue;
            }
        }

        // external link: [https://example.com Label]
        if (preg_match(
            '~^\[(https?://[^\s\]\r\n]+)(?:[ \t]+([^\]\r\n]*))?\]$~i',
            $part,
            $match
        )) {
            $url = $match[1];
            $label = trim($match[2] ?? $url);

            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $html .= '<a href="' . wikiEscape($url) . '"'
                    . ' target="_blank" rel="noopener noreferrer">'
                    . wikiStyledText($label) . '</a>';
                continue;
            }
        }

        $html .= wikiStyledText($part);
    }

    return $html;
}

// retrieve an existing booru image by post id
function wikiBooruImage($id, $caption, $mysqli, $small = false) {
    if ($id < 1) return '';

    static $images = [];

    if (!array_key_exists($id, $images)) {
        $stmt = $mysqli->prepare(
            'SELECT filename FROM booru_posts WHERE id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $images[$id] = $stmt->get_result()->fetch_assoc();
    }

    if (!$images[$id]) {
        return '<span class="wiki-missing">Booru image #'
            . $id . ' not found.</span>';
    }

    $filename = basename($images[$id]['filename']);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return '';
    }

    $original = __DIR__ . '/../booru/uploads/original/' . $filename;

    if (!is_file($original)) {
        return '<span class="wiki-missing">Booru image file #'
            . $id . ' is missing.</span>';
    }

    $thumb = __DIR__ . '/../booru/uploads/thumbs/' . $filename;
    $folder = ($small && is_file($thumb)) ? 'thumbs' : 'original';
    $src = '../booru/uploads/' . $folder . '/' . rawurlencode($filename);
    $postUrl = '../booru/post.php?id=' . $id;
    $class = $small ? 'wiki-image wiki-image-small' : 'wiki-image';

    $html = '<figure class="' . $class . '">';
    $html .= '<a href="' . wikiEscape($postUrl) . '">';
    $html .= '<img src="' . wikiEscape($src) . '" alt="'
        . wikiEscape($caption) . '" loading="lazy">';
    $html .= '</a>';

    if ($caption !== '') {
        $html .= '<figcaption>' . wikiEscape($caption) . '</figcaption>';
    }

    return $html . '</figure>';
}

// insert a booru, local, or https image into a notice
function wikiNoticeImage($source, $mysqli) {
    $source = trim($source);

    if ($source === '') return '';

    // booru post id: 15 or booru:15
    if (preg_match('/^(?:booru:)?([1-9][0-9]*)$/i', $source, $match)) {
        return wikiBooruImage((int)$match[1], '', $mysqli, true);
    }

    // local image in kokersi/slike/
    if (preg_match(
        '~^/?slike/([a-zA-Z0-9._-]+\.(?:png|jpg|jpeg|gif|webp))$~i',
        $source,
        $match
    )) {
        $filename = $match[1];
        $path = __DIR__ . '/../slike/' . $filename;

        if (!is_file($path)) {
            return '<span class="wiki-missing">Image not found.</span>';
        }

        $url = '../slike/' . rawurlencode($filename);
        return '<img src="' . wikiEscape($url)
            . '" alt="Notice image" loading="lazy">';
    }

    // external image URL (https only)
    if (
        filter_var($source, FILTER_VALIDATE_URL) &&
        strtolower((string)parse_url($source, PHP_URL_SCHEME)) === 'https'
    ) {
        return '<img src="' . wikiEscape($source)
            . '" alt="Notice image" loading="lazy">';
    }

    return '<span class="wiki-missing">Invalid image source.</span>';
}

// character or event infobox
function wikiExtractInfobox($content, $mysqli) {
    if (!preg_match(
        '~\{\{(character|event)[ \t]*(?:\r\n|\r|\n)(.*?)\}\}~is',
        $content,
        $match
    )) {
        return ['', $content];
    }

    $type = strtolower($match[1]);
    $fields = [];

    foreach (preg_split('/\r\n|\r|\n/', $match[2]) as $line) {
        if (strpos($line, '=') === false) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = strtolower(trim($key));

        if ($key !== '') {
            $fields[$key] = trim($value);
        }
    }

    $name = $fields['name'] ?? ucfirst($type);
    $html = '<aside class="wiki-infobox">';
    $html .= '<h3>' . wikiEscape($name) . '</h3>';

    if (!empty($fields['image']) && ctype_digit($fields['image'])) {
        $html .= wikiBooruImage(
            (int)$fields['image'],
            $fields['caption'] ?? '',
            $mysqli
        );
    }

    foreach ($fields as $key => $value) {
        if (in_array($key, ['name', 'image', 'caption'], true)) continue;

        $html .= '<div class="wiki-info-row">';
        $html .= '<strong>' . wikiEscape(ucfirst($key)) . '</strong>';
        $html .= '<span>' . wikiInline($value, $mysqli) . '</span>';
        $html .= '</div>';
    }

    $html .= '</aside>';
    return [$html, str_replace($match[0], '', $content)];
}

// notice boxes displayed above the article
function wikiExtractNotices($content, $mysqli) {
    $html = '';
    $labels = [
        'spoiler' => ['Spoiler warning', '!'],
        'nsfw' => ['NSFW content', '18+'],
        'warning' => ['Warning', '!'],
        'info' => ['Information', 'i']
    ];

    $content = preg_replace_callback(
        '/\{\{notice\|([^{}\r\n]+)\}\}/i',
        function ($match) use (&$html, $labels, $mysqli) {
            $pieces = explode('|', $match[1]);
            $type = strtolower(trim(array_shift($pieces)));

            if ($type === 'custom') {
                if (count($pieces) < 2) return $match[0];

                $title = trim(array_shift($pieces));
                $message = trim(array_shift($pieces));
                $imageSource = trim($pieces[0] ?? '');
                $class = 'info';
                $symbol = 'i';
            } elseif (isset($labels[$type])) {
                $title = $labels[$type][0];
                $symbol = $labels[$type][1];
                $message = trim(array_shift($pieces) ?? '');
                $imageSource = trim($pieces[0] ?? '');
                $class = $type;
            } else {
                return $match[0];
            }

            $html .= '<div class="wiki-notice ' . $class . '">';

            if ($imageSource !== '') {
                $html .= '<div class="notice-image">';
                $html .= wikiNoticeImage($imageSource, $mysqli);
                $html .= '</div>';
            } else {
                $html .= '<div class="notice-symbol">'
                    . wikiEscape($symbol) . '</div>';
            }

            $html .= '<div class="notice-text">';
            $html .= '<strong>' . wikiEscape($title) . '</strong>';
            $html .= '<p>' . wikiEscape($message) . '</p>';
            $html .= '</div></div>';
            return '';
        },
        $content
    );

    return [$html, $content];
}

// main article parser
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
        } elseif (preg_match(
            '/^\[\[booru:([1-9][0-9]*)(?:\|([^\]]*))?\]\]$/i',
            $line,
            $match
        )) {
            $flush();
            $html .= wikiBooruImage((int)$match[1], $match[2] ?? '', $mysqli);
        } elseif (preg_match('/^===\s*(.*?)\s*===$/', $line, $match)) {
            $flush();
            $html .= '<h3>' . wikiInline($match[1], $mysqli) . '</h3>';
        } elseif (preg_match('/^==\s*(.*?)\s*==$/', $line, $match)) {
            $flush();
            $html .= '<h2>' . wikiInline($match[1], $mysqli) . '</h2>';
        } else {
            $paragraph[] = $line;
        }
    }

    $flush();
    return $html;
}
