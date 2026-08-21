<?php
declare(strict_types=1);

/**
 * The signal catalogue.
 *
 * Every weight here is in log-odds, not percent, because signals have to be
 * summable (see Report::score). A weight of 0.7 roughly doubles the odds; 4.5
 * is "we found the builder's own name in the page and the argument is over".
 *
 * The hierarchy the weights encode, strongest first:
 *   fingerprint > structure > code > content > security > aesthetic
 * Aesthetic evidence is additionally capped in Report so that a purple gradient
 * and a Lucide icon set can never, on their own, produce an accusation.
 */
final class Catalog
{
    const CAT_FINGERPRINT = 'fingerprint';
    const CAT_STRUCTURE   = 'structure';
    const CAT_CODE        = 'code';
    const CAT_CONTENT     = 'content';
    const CAT_SECURITY    = 'security';
    const CAT_AESTHETIC   = 'aesthetic';
    const CAT_PROVENANCE  = 'provenance';

    /** @var array<string,array<string,string>>|null */
    private static $signals = null;

    /** @return array<string,string> */
    public static function categories(): array
    {
        return array(
            self::CAT_FINGERPRINT => 'Platform fingerprint',
            self::CAT_STRUCTURE   => 'Structural',
            self::CAT_CODE        => 'Code style',
            self::CAT_CONTENT     => 'Content',
            self::CAT_SECURITY    => 'Security profile',
            self::CAT_AESTHETIC   => 'Aesthetic',
            self::CAT_PROVENANCE  => 'Human authorship',
        );
    }

    /** Weight tier -> how the UI should describe it. */
    public static function strengthOf(float $weight): string
    {
        $w = abs($weight);
        if ($w >= 3.0) return 'decisive';
        if ($w >= 0.95) return 'strong';
        if ($w >= 0.55) return 'moderate';
        return 'weak';
    }

    public static function has(string $id): bool
    {
        self::load();
        return isset(self::$signals[$id]);
    }

    /** @return array<string,string>|null */
    public static function get(string $id)
    {
        self::load();
        return isset(self::$signals[$id]) ? self::$signals[$id] : null;
    }

    /** @return array<string,array<string,string>> */
    public static function all(): array
    {
        self::load();
        return self::$signals;
    }

    private static function load(): void
    {
        if (self::$signals !== null) {
            return;
        }

        $f = self::CAT_FINGERPRINT;
        $s = self::CAT_STRUCTURE;
        $c = self::CAT_CODE;
        $t = self::CAT_CONTENT;
        $x = self::CAT_SECURITY;
        $a = self::CAT_AESTHETIC;
        $h = self::CAT_PROVENANCE;

        $ai = 'ai';
        $hu = 'human';

        self::$signals = array(

            // ---- Hard platform fingerprints -------------------------------
            // Presence is a positive ID. Absence means nothing at all: agentic
            // tools (Cursor, Claude Code, Windsurf) write into a normal repo
            // and leave none of this behind.
            'fp.lovable' => self::mk($f, $ai, 4.5, 'Lovable build fingerprint',
                'The page carries Lovable\'s own runtime, upload paths or tagger. This is a positive identification of the builder, not an inference.'),
            'fp.bolt' => self::mk($f, $ai, 4.5, 'Bolt.new build fingerprint',
                'Bolt\'s badge, host or bundle signature is present in the served page.'),
            'fp.v0' => self::mk($f, $ai, 4.5, 'v0 by Vercel fingerprint',
                'A "Built with v0" badge or v0 deployment host identifies the generator directly.'),
            'fp.replit' => self::mk($f, $ai, 4.2, 'Replit Agent fingerprint',
                'Replit badge or hosting signature. Replit hosts hand-written projects too, so this identifies the host with high but not total confidence.'),
            'fp.base44' => self::mk($f, $ai, 4.5, 'Base44 fingerprint',
                'The Base44 SDK or deployment host is referenced by the page.'),
            'fp.generator_meta' => self::mk($f, $ai, 4.0, 'AI builder named in the generator meta tag',
                'The document\'s own <meta name="generator"> names an AI site builder.'),
            'fp.builder_other' => self::mk($f, $ai, 4.0, 'Another AI builder\'s fingerprint',
                'A generator outside the main five identified itself in the page: its SDK, deployment host or badge is present. The specific tool is named in the evidence.'),

            // ---- Structural ------------------------------------------------
            'st.section_comments' => self::mk($s, $ai, 1.1, 'Navigational section comments survive in production',
                'Comments like <!-- Hero --> above each block. Models write these to orient themselves. Real build pipelines strip HTML comments, so finding them on a deployed page means the file was hand-deployed exactly as generated.'),
            'st.uniform_comment_density' => self::mk($s, $ai, 1.0, 'Comment density is uniform across the file',
                'Humans cluster comments around the parts that were hard. A flat comment rate from top to bottom is the signature of a generator that documents every line equally.'),
            'st.docblock_on_everything' => self::mk($s, $ai, 0.9, 'Every function carries a docblock, including trivial ones',
                'Uniform, complete docblocks on getters and one-line helpers alike.'),
            'st.import_block_sorted' => self::mk($s, $ai, 0.6, 'Imports are perfectly grouped and alphabetised',
                'Standard library, third-party, then local, sorted within each group, with no organic accretion.'),
            'st.multiple_solutions' => self::mk($s, $ai, 1.0, 'The same problem is solved several different ways',
                'Two HTTP clients, three date helpers, four validation styles. Local perfection with global incoherence: each prompt session solved its own problem in isolation.'),
            'st.dead_code' => self::mk($s, $ai, 0.8, 'Fully-built code wired to nothing',
                'Complete components, routes or helpers that nothing imports or calls.'),
            'st.spa_shell' => self::mk($s, $ai, 0.45, 'Empty client-rendered shell with a hashed bundle',
                'An almost-empty root div plus a single hashed asset bundle. This is the default output shape of the generator stack, but hand-built single-page apps look identical, so it is only a starting point.'),
            'st.file_header_block' => self::mk($s, $ai, 0.6, 'Explanatory file-header block',
                'A banner comment at the top of the file restating the filename and describing the module\'s purpose in prose. Humans write these for libraries other people consume; generators write them for every file including the ones nobody reads.'),
            'st.full_scaffold' => self::mk($s, $ai, 0.35, 'Textbook-complete document scaffold',
                'Complete head, full meta and social tags, alt text and ARIA labels everywhere, arriving fully formed rather than accreting over time. This is also just good practice, hence the low weight.'),

            // ---- Code style -------------------------------------------------
            'cd.emoji_comments' => self::mk($c, $ai, 1.3, 'Emoji inside code comments',
                'One of the most reliable single markers reviewers report. Emoji in source comments are rare in hand-written production code and routine in generated code.'),
            'cd.emoji_logging' => self::mk($c, $ai, 1.1, 'Emoji inside log and status output',
                'Ticks, crosses and party poppers in console output. Nearly as reliable as emoji in comments, and it survives longer because log strings get cleaned up less often than comments do.'),
            'cd.what_comments' => self::mk($c, $ai, 1.0, 'Comments explain what the code does, not why',
                'Restating the next line in English is the clearest cross-language tell. Human comments carry outside context: a ticket, an audit, a bug, a reason.'),
            'cd.empty_tests' => self::mk($c, $ai, 0.9, 'Tests that assert nothing',
                'Happy-path-only tests, or true == true wearing a costume. No edge cases, no negative paths.'),
            'cd.blanket_try' => self::mk($c, $ai, 0.8, 'Error handling wrapped around everything',
                'Try/catch around routine, non-throwing code, applied uniformly rather than where failure is actually expected.'),
            'cd.swallowed_errors' => self::mk($c, $ai, 0.8, 'Exceptions caught and discarded',
                'catch blocks that log and continue, or swallow silently. The blindfold that stops the program crashing so nobody ever learns it is broken.'),
            'cd.section_header_comments' => self::mk($c, $ai, 0.7, 'Decorative section-header comments',
                'Banner comments such as # ===== User Authentication ===== used as navigation inside a single file.'),
            'cd.verbose_names' => self::mk($c, $ai, 0.6, 'Hyper-verbose identifier names',
                'Names like currentLoggedInUserAuthTokenValue, where a human would have written the same thing in a third of the characters.'),
            'cd.formal_errors' => self::mk($c, $ai, 0.6, 'Formal, grammatically complete error messages',
                '"The provided email address is not in a valid format." where a human writes "Invalid email".'),
            'cd.typography' => self::mk($c, $ai, 0.6, 'Model typography pasted straight into the source',
                'Curly quotes and em dashes in code, comments or markup, which an editor does not produce on its own.'),
            'cd.defensive_chaining' => self::mk($c, $ai, 0.5, 'Optional chaining and fallbacks on everything',
                'Every property access guarded and every value given a default, applied uniformly rather than where a value is genuinely optional. The same instinct as the blanket try/catch: nothing can throw, so nothing can be diagnosed.'),
            'cd.over_symmetric_branches' => self::mk($c, $ai, 0.45, 'Every branch has its counterpart',
                'An else for every if, a default for every switch, a fallback for every path, whether or not the case can occur. Human control flow is lopsided because real requirements are lopsided.'),
            'cd.lazy_names' => self::mk($c, $ai, 0.5, 'Iteration-scar names',
                'data2, result_final, handleClick2, newFunction: the residue of re-prompting until something worked.'),
            'cd.console_noise' => self::mk($c, $ai, 0.5, 'Debug logging left in shipped code',
                'Dense, uniform console output that was never cleaned up.'),
            'cd.helper_pileup' => self::mk($c, $ai, 0.5, 'Utils / Manager / Handler / Helper pile-up',
                'Layers of ceremony classes stacked without a need that justifies them.'),
            'cd.todo_placeholders' => self::mk($c, $ai, 0.5, 'TODO and placeholder blocks in shipped code',
                'Stub bodies and "implement this" markers that were never returned to.'),
            'cd.main_guard' => self::mk($c, $ai, 0.4, 'Entry-point guard on a module nothing runs',
                'if __name__ == "__main__": on a library module that is only ever imported.'),

            // ---- Content ------------------------------------------------------
            'ct.generic_names' => self::mk($t, $ai, 0.7, 'Statistically generic placeholder people',
                'Testimonials from the most common names in the training data, with titles like "Verified User" or "Head of Operations".'),
            'ct.emoji_icons' => self::mk($t, $ai, 0.6, 'Emoji used as the icon system',
                'A rocket for performance, a lightbulb for smart. Emoji do not inherit colour, adapt to dark mode or scale cleanly, so this reliably means the page never went through design review.'),
            'ct.marketing_cliche' => self::mk($t, $ai, 0.5, 'Copy in the house model voice',
                '"Ship faster", "unlock the power of", "everything you need to". Marketing language that tells rather than shows, with no specifics anywhere.'),
            'ct.stat_inflation' => self::mk($t, $ai, 0.5, 'Round, unsourced statistics',
                'Suspiciously tidy numbers doing persuasive work: 10,000+ users, 99.9% uptime, 10x faster, 2M downloads. Nothing is attributed and the roundness is the tell, because measured figures are rarely this neat.'),
            'ct.pricing_three' => self::mk($t, $ai, 0.35, 'Three tiers with the middle one starred',
                'Exactly three pricing columns with a "Most popular" badge on the centre one. A real pricing page is shaped by what a business actually sells; this shape is shaped by every pricing page in the training data.'),
            'ct.placeholder_copy' => self::mk($t, $ai, 0.5, 'Placeholder copy left in production',
                'Lorem ipsum, "Coming soon", "Your text here" on a live page.'),
            'ct.dead_links' => self::mk($t, $ai, 0.4, 'Navigation that goes nowhere',
                'A full nav and footer where most links are href="#".'),

            // ---- Security profile ------------------------------------------
            // The most durable family: syntax correctness climbed past 95% while
            // security pass rates stayed near 55%, so these decay slowest.
            'se.placeholder_secret' => self::mk($x, $ai, 0.9, 'Placeholder or hardcoded secret',
                'Literals such as "your-secret-key", "change-me" or an inline credential where an environment variable belongs.'),
            'se.weak_auth' => self::mk($x, $ai, 0.7, 'Authentication or ownership checks missing at the boundary',
                'Endpoints that authenticate but never check whether this user owns this row, or tokens issued without expiry.'),
            'se.insecure_defaults' => self::mk($x, $ai, 0.6, 'Textbook-insecure defaults',
                'Wide-open CORS, disabled TLS verification, raw string-concatenated queries, unescaped output.'),

            // ---- Aesthetic (capped as a group) -------------------------------
            'ae.indigo' => self::mk($a, $ai, 0.45, 'The indigo-to-violet default palette',
                'Indigo 500 into violet, on slate. Not a design decision so much as a statistical average of every Tailwind tutorial written between 2019 and 2024.'),
            'ae.shadcn_defaults' => self::mk($a, $ai, 0.40, 'Untouched component-kit defaults',
                'rounded-2xl, shadow-lg, p-6, pill buttons, cards nested inside cards, every radius identical.'),
            'ae.left_border_card' => self::mk($a, $ai, 0.40, 'The coloured left-border card',
                'A 3-4px accent strip down the left edge of a panel, applied to every callout on the page.'),
            'ae.gradient_text' => self::mk($a, $ai, 0.40, 'The gradient-filled headline',
                'A heading painted with a clipped background gradient instead of a colour. Almost unheard of in hand-built pages before 2023 and near-universal in generated ones since.'),
            'ae.glassmorphism' => self::mk($a, $ai, 0.30, 'Frosted-glass panels throughout',
                'Backdrop blur over translucent white on every card and bar. One of the handful of effects a model reaches for when asked to make something look modern.'),
            'ae.lucide' => self::mk($a, $ai, 0.35, 'Default icon set, unchanged',
                'Lucide or Heroicons throughout, because the component kit these tools build on ships them as the default.'),
            'ae.three_cards' => self::mk($a, $ai, 0.30, 'Rigid symmetry',
                'Exactly three feature cards, 01/02/03 steps, descriptions of near-identical length. Human pages are lumpier because human content is lumpier.'),
            'ae.inter_font' => self::mk($a, $ai, 0.30, 'The default display typeface',
                'Inter, or its replacements Geist, Poppins, Space Grotesk and Manrope, doing all the typographic work.'),
            'ae.uniform_whitespace' => self::mk($a, $ai, 0.25, 'Uniform generous spacing',
                'The same large vertical padding on every section: the safest way to guarantee a responsive layout never overlaps.'),
            'ae.no_real_images' => self::mk($a, $ai, 0.25, 'No real photography',
                'Gradients, abstract shapes and generated faces instead of pictures of anything that exists.'),

            // ---- Human authorship (pull the score down) -----------------------
            'hu.why_comments' => self::mk($h, $hu, 1.0, 'Comments carry outside context',
                'References to a standard, an audit, an outage or a decision. This is knowledge the code cannot contain and a generator has no access to.'),
            'hu.ticket_refs' => self::mk($h, $hu, 0.9, 'References to tickets, issues, dates or people',
                'Links into a tracker, issue numbers, dated notes, names of colleagues.'),
            'hu.informal' => self::mk($h, $hu, 0.9, 'Informal, exasperated or profane commentary',
                'HACK, XXX, "do not touch this", "no idea why this works". Models are relentlessly polite.'),
            'hu.typos' => self::mk($h, $hu, 0.7, 'Misspellings in the copy',
                'Models do not typo. A page carrying "recieve" or "seperate" was typed by somebody, and typos are one of the few tells that masking makes worse rather than better, since cleaning them up is exactly what nobody bothers to do.'),
            'hu.operational_stack' => self::mk($h, $hu, 0.6, 'Signs of a business actually operating',
                'A cookie banner, legal and privacy pages, a tag manager, a real analytics property, a newsletter provider. Accumulated obligations rather than generated features.'),
            'hu.dated_copyright' => self::mk($h, $hu, 0.4, 'A footer that has fallen out of date',
                'A copyright year or "last updated" date already in the past. Generated pages are current by construction; drift means the page has been sitting there.'),
            'hu.long_tail_copy' => self::mk($h, $hu, 0.5, 'Copy with specifics',
                'Real prices, dates, addresses, named people, opening hours: details that had to come from somewhere.'),
            'hu.cms' => self::mk($h, $hu, 0.7, 'Built on a classic CMS or site builder',
                'WordPress, Wix, Squarespace, Webflow, Shopify. These predate the current generation of tools and are a different phenomenon entirely, not evidence of it.'),
            'hu.inconsistent_format' => self::mk($h, $hu, 0.6, 'Inconsistent formatting',
                'Mixed tabs and spaces, drifting indentation, irregular line lengths. Small inconsistency is the human default.'),
            'hu.commented_code' => self::mk($h, $hu, 0.5, 'Commented-out code left behind',
                'Old implementations kept around just in case, which generators do not produce.'),
            'hu.legacy_stack' => self::mk($h, $hu, 0.5, 'Older or hand-rolled stack',
                'jQuery, table layouts, hand-written includes, vendor-prefixed CSS: an accretion history rather than a single generation.'),
            'hu.abbrevs' => self::mk($h, $hu, 0.4, 'Abbreviated, idiomatic identifiers',
                'authSvc, cfg, mgr, activeSub. Shorthand that assumes a reader who already has the context.'),
            'hu.build_stripped' => self::mk($h, $hu, 0.4, 'Output of a real build pipeline',
                'Minified, comment-stripped, content-hashed assets: someone set up a toolchain and ran it.'),
            'hu.real_media' => self::mk($h, $hu, 0.4, 'Real and varied media',
                'Photographs at irregular sizes from irregular sources, rather than one generated hero image.'),
        );
    }

    /** @return array<string,string> */
    private static function mk(string $category, string $direction, float $weight, string $label, string $detail): array
    {
        return array(
            'category'  => $category,
            'direction' => $direction,
            'weight'    => $weight,
            'label'     => $label,
            'detail'    => $detail,
        );
    }
}
