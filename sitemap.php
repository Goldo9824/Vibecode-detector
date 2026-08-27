<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Seo.php';

/**
 * The sitemap, served at /sitemap.xml.
 *
 * A PHP file rather than a static XML one because the addresses in it have to
 * be this installation's. VCD_SITE_URL is a default, not a fact: the project
 * is meant to be forked and uploaded by FTP to somebody else's domain, and a
 * committed sitemap.xml would send every fork's crawler to this site instead
 * of to their own. vcd_site_url() reads the host the request actually arrived
 * on, so a fork gets its own map with nothing to edit.
 *
 * Only the pages worth finding are in it. The verify page is a form with one
 * useful state and a different URL per certificate; the admin panel is gated
 * and disallowed; the API is for callers who were given a key, not for
 * readers.
 */

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pages = array(
    array('path' => '/',          'changefreq' => 'monthly', 'priority' => '1.0'),
    array('path' => '/signs.php', 'changefreq' => 'monthly', 'priority' => '0.8'),
    array('path' => '/catalogue.php', 'changefreq' => 'monthly', 'priority' => '0.8'),
);

// The last time the deployed files themselves changed, which is the only
// honest answer available without a database and without a build step.
$lastmod = gmdate('Y-m-d', max(
    (int) @filemtime(__DIR__ . '/index.php'),
    (int) @filemtime(__DIR__ . '/signs.php'),
    (int) @filemtime(__DIR__ . '/catalogue.php'),
    (int) @filemtime(__DIR__ . '/lib/Catalog.php'),
    time() - 86400 * 365
));

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= h(Seo::url($page['path'])) ?></loc>
    <lastmod><?= h($lastmod) ?></lastmod>
    <changefreq><?= h($page['changefreq']) ?></changefreq>
    <priority><?= h($page['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
