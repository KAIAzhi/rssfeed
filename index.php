<?php
// rss_parser.php
// Simple RSS parser without caching, includes author names and improved image extraction

$feed_url = 'https://www.vox.com/rss/index.xml';

function fetch_feed($url) {
    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RSS Parser/1.0 (+https://example.com)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header'  => "User-Agent: RSS Parser/1.0\r\n"
            ]
        ]);
        return @file_get_contents($url, false, $context);
    }
}

function safe_text($text) {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$feed_data = false;
$error_message = '';
$raw = fetch_feed($feed_url);
if ($raw === false || trim($raw) === '') {
    $error_message = "Unable to fetch feed.";
} else {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        $error_message = "Invalid feed format.";
    } else {
        $feed = [];
        $feed['title'] = (string) ($xml->channel->title ?? $xml->title ?? 'Feed');
        $feed['link'] = (string) ($xml->channel->link ?? $xml->link ?? '');
        $feed['description'] = (string) ($xml->channel->description ?? $xml->subtitle ?? '');

        $items = [];
        if (isset($xml->channel->item)) {
            $raw_items = $xml->channel->item;
        } elseif (isset($xml->entry)) {
            $raw_items = $xml->entry;
        } else {
            $raw_items = [];
        }

        $count = 0;
        foreach ($raw_items as $it) {
            if ($count++ >= 50) break;
            $namespaces = $it->getNameSpaces(true);

            $title = (string) ($it->title ?? '');
            $link = '';
            if (isset($it->link) && (string)$it->link != '') {
                $link = (string)$it->link;
            } else {
                foreach ($it->link as $l) {
                    $attrs = $l->attributes();
                    if (!isset($attrs['rel']) || (string)$attrs['rel'] === 'alternate') {
                        $link = (string)$attrs['href'];
                        break;
                    }
                }
            }

            $pubDate = (string) ($it->pubDate ?? $it->published ?? $it->updated ?? '');
            $description = (string) ($it->description ?? $it->summary ?? $it->content ?? '');

            // Extract author name
            $author = '';
            if (isset($it->author)) {
                if (isset($it->author->name)) {
                    $author = (string) $it->author->name;
                } else {
                    $author = (string) $it->author;
                }
            } elseif (isset($it->children($namespaces['dc'])['creator'])) {
                $author = (string) $it->children($namespaces['dc'])['creator'];
            }

            // Extract image
            $image = '';
            if (isset($it->enclosure) && isset($it->enclosure->attributes()->url)) {
                $image = (string)$it->enclosure->attributes()->url;
            } else {
                if (isset($namespaces['media'])) {
                    $media = $it->children($namespaces['media']);
                    if (isset($media->content) && isset($media->content->attributes()->url)) {
                        $image = (string)$media->content->attributes()->url;
                    } elseif (isset($media->thumbnail) && isset($media->thumbnail->attributes()->url)) {
                        $image = (string)$media->thumbnail->attributes()->url;
                    }
                }
                if ($image === '') {
                    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $m)) {
                        $image = $m[1];
                    }
                }
            }

            // NEW: Extract image from <content type="html"> blocks
            if (isset($it->content) && $image === '') {
                $contentHtml = (string)$it->content;
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
                    $image = $m[1];
                }
            }

            // NEW: Extract from <content:encoded> namespace (WordPress-style)
            if ($image === '' && isset($namespaces['content'])) {
                $content = $it->children($namespaces['content']);
                if (isset($content->encoded)) {
                    $contentHtml = (string)$content->encoded;
                    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
                        $image = $m[1];
                    }
                }
            }

            $items[] = [
                'title' => $title,
                'link' => $link,
                'pubDate' => $pubDate,
                'description' => $description,
                'author' => $author,
                'image' => $image,
            ];
        }

        $feed['items'] = $items;
        $feed_data = $feed;
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vox RSS Reader</title>
<style>
    body {
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
        margin: 0;
        padding: 0;
        background: #f6f7f9;
        color: #111;
    }
    header {
        background: #0a6cad;
        color: #fff;
        padding: 18px 20px;
    }
    .container {
        max-width: 1000px;
        margin: 18px auto;
        padding: 0 16px;
    }
    .feed-title {
        margin: 0;
        font-size: 20px;
    }
    .meta {
        opacity: 0.9;
        margin-top: 6px;
        font-size: 13px;
    }
    .card {
        background: #fff;
        border-radius: 8px;
        padding: 14px;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        display: flex;
        gap: 12px;
        margin-bottom: 12px;
    }
    .thumb {
        width: 140px;
        flex: 0 0 140px;
        border-radius: 6px;
        overflow: hidden;
        background: #eee;
    }
    .thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .content {
        flex: 1;
    }
    .title {
        font-size: 18px;
        margin: 0;
    }
    .excerpt {
        color: #333;
        margin-top: 8px;
    }
    .byline {
        font-size: 12px;
        color: #666;
        margin-top: 8px;
    }
    a.readmore {
        display: inline-block;
        margin-top: 10px;
    }
    footer {
        max-width: 1000px;
        margin: 14px auto;
        padding: 8px 16px;
        color: #666;
        font-size: 13px;
    }
    .error {
        color: #a33;
        padding: 12px;
        background: #fff;
        border-radius: 6px;
    }
    @media (max-width: 640px) {
        .card {
            flex-direction: column;
        }
        .thumb {
            width: 100%;
            height: 200px;
        }
    }
</style>
</head>
<body>
<header>
  <div class="container">
    <h1 class="feed-title"><?php echo safe_text($feed_data['title'] ?? 'RSS Feed'); ?></h1>
    <div class="meta"><?php echo safe_text($feed_data['description'] ?? ''); ?></div>
  </div>
</header>

<div class="container">
<?php if (!empty($error_message)): ?>
    <div class="error"><?php echo safe_text($error_message); ?></div>
<?php endif; ?>

<div id="list">
<?php
if (!empty($feed_data['items']) && is_array($feed_data['items'])) {
    $show = array_slice($feed_data['items'], 0, 20);
    foreach ($show as $it) {
        $title = $it['title'];
        $link = $it['link'];
        $pub = $it['pubDate'];
        $desc = $it['description'];
        $img = $it['image'];
        $author = $it['author'];
        $excerpt = strip_tags($desc);
        if (strlen($excerpt) > 280) $excerpt = substr($excerpt,0,277).'...';
        $date_disp = '';
        if ($pub != '') {
            $ts = strtotime($pub);
            if ($ts !== false) $date_disp = date('M j, Y, g:ia', $ts);
            else $date_disp = safe_text($pub);
        }
        ?>
        <article class="card">
            <div class="thumb">
                <?php if ($img): ?>
                    <img src="<?php echo safe_text($img); ?>" alt="">
                <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#888;font-size:14px">No image</div>
                <?php endif; ?>
            </div>
            <div class="content">
                <h3 class="title"><a href="<?php echo safe_text($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo safe_text($title); ?></a></h3>
                <div class="byline">By <?php echo safe_text($author ?: 'Unknown'); ?><?php if ($date_disp) echo ' | ' . safe_text($date_disp); ?></div>
                <div class="excerpt"><?php echo $excerpt; ?></div>
                <a class="readmore" href="<?php echo safe_text($link); ?>" target="_blank" rel="noopener noreferrer">Read on site →</a>
            </div>
        </article>
        <?php
    }
} else {
    echo '<p>No items found in feed.</p>';
}
?>
</div>
</div>
</body>
</html>
