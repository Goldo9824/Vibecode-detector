<?php
declare(strict_types=1);

require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/SiteAnalyzer.php';
require_once __DIR__ . '/Text.php';

/**
 * Reads several pages of one site and reports on the site rather than a page.
 *
 * This is not "run the page check ten times and average it". Two things change
 * when you have more than one page:
 *
 *   1. Corroboration. A signal that fires on eight pages out of ten is a
 *      property of the site; the same signal on one page is a property of that
 *      page, and might be an accident. Single-page hits are held back unless
 *      they are the sort that only needs to be true once.
 *
 *   2. Comparison. Whether the pages resemble each other is itself evidence,
 *      and it is evidence no single page can give you. A site stamped from one
 *      template looks different from a site that accreted over five years, and
 *      that difference is among the harder things to fake.
 */
final class SiteSurvey
{
    /**
     * Share of pages a signal must appear on to count as a site property.
     *
     * Scaled rather than fixed. A flat "two pages is enough" rule is sensible
     * across six pages and meaningless across fifty, where two pages is four
     * per cent and indistinguishable from noise. The floor of two still applies
     * so that small crawls behave as before.
     */
    const CORROBORATION = 0.25;

    /** @var array<int,array{url:string,body:string,assets:array<string,string>,status:int}> */
    private $pages;
    /** @var string */
    private $entryUrl;
    /** @var string[] */
    private $crawlNotes;
    /** @var array{urls:int,lastmods:string[]} */
    private $sitemap;
    /** @var Report */
    private $r;

    /**
     * @param array<int,array{url:string,body:string,assets:array<string,string>,status:int}> $pages
     * @param string[] $crawlNotes
     * @param array{urls?:int,lastmods?:string[]} $sitemap
     */
    public function __construct(string $entryUrl, array $pages, array $crawlNotes = array(), array $sitemap = array())
    {
        $this->entryUrl = $entryUrl;
        $this->pages = $pages;
        $this->crawlNotes = $crawlNotes;
        $this->sitemap = array(
            'urls'     => isset($sitemap['urls']) ? (int) $sitemap['urls'] : 0,
            'lastmods' => isset($sitemap['lastmods']) ? (array) $sitemap['lastmods'] : array(),
        );
    }

    public function analyze(): Report
    {
        $host = (string) parse_url($this->entryUrl, PHP_URL_HOST);
        $count = count($this->pages);

        $this->r = new Report('site', $this->entryUrl);
        $this->r->setSubtitle($host . ' · ' . $count . ' page' . ($count === 1 ? '' : 's'));
        $this->r->stat('pages', $count);

        // Analyse each page on its own first.
        $perPage = array();
        $hits = array();      // signal id => [page index, ...]
        $evidence = array();  // signal id => first evidence seen

        foreach ($this->pages as $i => $page) {
            $report = (new SiteAnalyzer(
                $page['url'], $page['body'], $page['assets'],
                isset($page['meta']) ? (array) $page['meta'] : array()
            ))->analyze();
            $arr = $report->toArray();

            $perPage[] = array(
                'url'     => $page['url'],
                'score'   => $arr['score'],
                'signals' => count($arr['signals']),
                'bytes'   => strlen($page['body']),
                'words'   => str_word_count(Text::visibleText($page['body'])),
            );

            foreach ($report->signals() as $s) {
                if (!isset($hits[$s->id])) {
                    $hits[$s->id] = array();
                    $evidence[$s->id] = $s->evidence;
                }
                $hits[$s->id][] = $i;
            }
        }

        $this->r->stat('perPage', $perPage);
        $this->mergeSignals($hits, $evidence, $count);
        $this->compare($perPage);
        $this->checkSitemap();

        foreach ($this->crawlNotes as $note) {
            $this->r->note($note);
        }
        if ($count > 1) {
            $this->r->note(sprintf(
                'Read across %d pages. A signal is only counted site-wide when it appears on enough of them, so a single odd page cannot carry the reading on its own.',
                $count
            ));
        }
        $this->r->note('Crawling reads what a site links to. Pages behind a login, a search box or a client-side router were not seen, and a site can look very different from the inside.');

        return $this->r;
    }

    /**
     * How many pages a signal must appear on before it counts site-wide.
     *
     * Never fewer than two, so a lone page cannot carry a reading; otherwise a
     * quarter of what was read, so the bar rises with the size of the crawl.
     */
    public static function requiredPages(int $pageCount): int
    {
        return max(2, (int) ceil($pageCount * self::CORROBORATION));
    }

    /**
     * Fold per-page signals into site-wide ones.
     *
     * @param array<string,array<int,int>> $hits
     * @param array<string,string[]>       $evidence
     */
    private function mergeSignals(array $hits, array $evidence, int $pageCount): void
    {
        // Fold order is fixed so the report is identical between runs and
        // between PHP versions, for the same reason the rest of the tool sorts
        // with an explicit tiebreak.
        ksort($hits);

        foreach ($hits as $id => $pages) {
            $meta = Catalog::get($id);
            if ($meta === null) {
                continue;
            }
            $seen = count($pages);

            // A fingerprint only has to be true once: a builder's runtime on any
            // page identifies the builder for the site. Everything else has to
            // show up on enough pages to be a property of the site rather than
            // of one page that happened to be odd.
            $isFingerprint = ($meta['category'] === Catalog::CAT_FINGERPRINT);
            if (!$isFingerprint && $pageCount > 1 && $seen < self::requiredPages($pageCount)) {
                continue;
            }

            $lines = array();
            if ($pageCount > 1) {
                $lines[] = $isFingerprint
                    ? sprintf('found on %d of %d pages read', $seen, $pageCount)
                    : sprintf('on %d of %d pages read', $seen, $pageCount);
            }
            foreach (array_slice($evidence[$id], 0, 3) as $line) {
                $lines[] = $line;
            }

            $this->r->flag($id, $lines);
        }
    }

    /**
     * The checks that only exist because there is more than one page.
     *
     * @param array<int,array<string,mixed>> $perPage
     */
    private function compare(array $perPage): void
    {
        $count = count($perPage);
        if ($count < 3) {
            return; // two pages is not a pattern
        }

        // --- Are they all the same page with the words swapped? -------------
        $shapes = array();
        foreach ($this->pages as $page) {
            $shapes[] = $this->shapeOf($page['body']);
        }
        $similarity = $this->averageSimilarity($shapes);
        $this->r->stat('templateSimilarity', round($similarity, 3));

        if ($similarity >= 0.93) {
            $this->r->flag('xs.template_uniformity', array(
                sprintf('%d pages share %d%% of their structural markup', $count, (int) round($similarity * 100)),
                'the same containers, in the same order, with different words in them',
            ));
        } elseif ($similarity <= 0.62) {
            $this->r->flag('xs.style_drift', array(
                sprintf('only %d%% structural overlap between pages', (int) round($similarity * 100)),
                'sections of this site were not built at the same time or in the same way',
            ));
        }

        // --- Is every page the same weight? ---------------------------------
        $words = array();
        foreach ($perPage as $p) {
            $words[] = (int) $p['words'];
        }
        $mean = array_sum($words) / $count;
        $spread = $mean > 0 ? Text::stddev($words) / $mean : 0.0;
        $this->r->stat('lengthSpread', round($spread, 3));

        if ($mean > 40 && $spread < 0.18) {
            $this->r->flag('xs.uniform_page_size', array(
                sprintf('%d pages averaging %d words, varying by only %d%%', $count, (int) $mean, (int) round($spread * 100)),
                'real sites are lumpy, because an About page is not the length of a pricing table',
            ));
        } elseif ($spread > 0.75) {
            $this->r->flag('xs.varied_pages', array(
                sprintf('page length varies by %d%% around a mean of %d words', (int) round($spread * 100), (int) $mean),
            ));
        }

        // --- Pages that were built and never filled -------------------------
        $thin = array();
        foreach ($perPage as $p) {
            if ((int) $p['words'] < 45) {
                $thin[] = $this->pathOf((string) $p['url']) . sprintf(' (%d words)', (int) $p['words']);
            }
        }
        if (count($thin) >= 2 && count($thin) < $count) {
            $this->r->flag('xs.placeholder_pages', array_merge(
                array(sprintf('%d of %d pages carry almost no content', count($thin), $count)),
                array_slice($thin, 0, 3)
            ));
        }

        // --- Did somebody actually write a lot of this? ---------------------
        $longest = 0;
        $longestPath = '';
        foreach ($perPage as $p) {
            if ((int) $p['words'] > $longest) {
                $longest = (int) $p['words'];
                $longestPath = $this->pathOf((string) $p['url']);
            }
        }
        if ($longest >= 900 && $longest > $mean * 1.8) {
            $this->r->flag('xs.deep_content', array(
                sprintf('%s runs to %s words, well beyond the rest of the site', $longestPath, number_format($longest)),
            ));
        }
    }

    /**
     * What the sitemap's lastmod column says about how the site was made.
     *
     * A sitemap generated alongside a site stamps every entry with the same
     * day, or omits the column entirely. One that grew with a site records a
     * spread of dates, because the pages were not written at the same time.
     * This is a record kept by tooling rather than by an author, which is what
     * makes it worth reading: nobody curates it, so nobody thinks to fake it.
     */
    private function checkSitemap(): void
    {
        $listed = (int) $this->sitemap['urls'];
        if ($listed < 5) {
            return; // a five-page sitemap has nothing to say about spread
        }
        $this->r->stat('sitemapUrls', $listed);

        $dates = array_values(array_filter($this->sitemap['lastmods']));
        $distinct = count(array_unique($dates));

        if (!$dates) {
            $this->r->flag('xs.sitemap_one_pass', array(
                sprintf('%d pages listed and not one <lastmod> among them', $listed),
            ));
            return;
        }
        $this->r->stat('sitemapDates', $distinct);

        // Entries without a date are ignored rather than counted against the
        // spread: a partial column still records the edits it recorded.
        if ($distinct === 1 && count($dates) >= 5) {
            $this->r->flag('xs.sitemap_one_pass', array(
                sprintf('all %d dated pages were last modified on %s', count($dates), $dates[0]),
            ));
            return;
        }

        sort($dates);
        $first = strtotime($dates[0] . ' 00:00:00 UTC');
        $last = strtotime($dates[count($dates) - 1] . ' 00:00:00 UTC');
        if ($first === false || $last === false) {
            return;
        }
        $days = (int) round(($last - $first) / 86400);
        $this->r->stat('sitemapSpreadDays', $days);

        if ($days >= 60 && $distinct >= 5) {
            $this->r->flag('xs.sitemap_history', array(
                sprintf('%d distinct modification dates spread over %d days, from %s to %s',
                    $distinct, $days, $dates[0], $dates[count($dates) - 1]),
            ));
        } elseif ($days <= 1) {
            $this->r->flag('xs.sitemap_one_pass', array(
                sprintf('%d dated pages, all last modified within a day of each other', count($dates)),
            ));
        }
    }

    /**
     * A page's structural fingerprint: tag and container sequence, no words.
     *
     * Comparing text would just measure whether two pages are about the same
     * thing. Comparing structure measures whether they were made the same way,
     * which is the actual question.
     *
     * @return array<string,int>
     */
    private function shapeOf(string $html): array
    {
        $body = $html;
        if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) {
            $body = $m[1];
        }
        $body = (string) preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $body);

        $shape = array();

        if (preg_match_all('~<([a-z][a-z0-9]*)\b~i', $body, $tags)) {
            foreach ($tags[1] as $tag) {
                $key = 't:' . strtolower($tag);
                $shape[$key] = ($shape[$key] ?? 0) + 1;
            }
        }
        // Class atoms, which is where a template's identity actually lives.
        if (preg_match_all('~\bclass=["\']([^"\']+)["\']~i', $body, $classes)) {
            foreach ($classes[1] as $attr) {
                foreach (preg_split('~\s+~', trim($attr)) as $cls) {
                    if ($cls === '') continue;
                    $key = 'c:' . strtolower($cls);
                    $shape[$key] = ($shape[$key] ?? 0) + 1;
                }
            }
        }
        return $shape;
    }

    /**
     * Mean pairwise cosine similarity of the page shapes.
     *
     * @param array<int,array<string,int>> $shapes
     */
    private function averageSimilarity(array $shapes): float
    {
        $n = count($shapes);
        if ($n < 2) {
            return 0.0;
        }
        $total = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $total += $this->cosine($shapes[$i], $shapes[$j]);
                $pairs++;
            }
        }
        return $pairs > 0 ? $total / $pairs : 0.0;
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    private function cosine(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $dot = 0.0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) {
                $dot += $v * $b[$k];
            }
        }
        $magA = 0.0;
        foreach ($a as $v) {
            $magA += $v * $v;
        }
        $magB = 0.0;
        foreach ($b as $v) {
            $magB += $v * $v;
        }
        if ($magA <= 0 || $magB <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    private function pathOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }
}
