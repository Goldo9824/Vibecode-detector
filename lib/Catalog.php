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
    const CAT_HISTORY     = 'history';
    const CAT_SITEWIDE    = 'sitewide';
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
            self::CAT_HISTORY     => 'Repository history',
            self::CAT_SITEWIDE    => 'Site-wide',
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
        $g = self::CAT_HISTORY;
        $w = self::CAT_SITEWIDE;
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

            // ---- Repository history -----------------------------------------
            // The strongest evidence available short of a fingerprint, and the
            // hardest to fake after the fact: rewriting a history to look lived-in
            // means inventing plausible timestamps, authors, mistakes and reverts
            // for every commit. Weighted accordingly.
            'gh.big_bang' => self::mk($g, $ai, 1.4, 'The repository arrives fully formed',
                'An opening commit carrying most of the codebase at once. Hand-built projects start small and accrete; a first commit with hundreds of lines across dozens of files is a generated tree being put under version control after the fact.'),
            'gh.velocity' => self::mk($g, $ai, 1.2, 'More code than anyone types',
                'Hundreds of lines landing within minutes. Not proof by itself — a paste, a vendored library or a generated file does this too — but combined with the rest it is the shape of accepting output rather than writing it.'),
            'gh.micro_fix_trail' => self::mk($g, $ai, 1.1, 'A large drop followed by a trail of one-line fixes',
                '"fix typo", "fix import", "add missing dependency" in a run after a huge commit. The signature of code that was never read before it was committed, then corrected as each error surfaced at runtime.'),
            'gh.prompt_messages' => self::mk($g, $ai, 1.0, 'Commit messages that read like the prompt',
                'Subjects such as "add REST API for user management with JWT authentication": a complete specification in the imperative, describing what was asked for rather than what changed.'),
            'gh.single_session' => self::mk($g, $ai, 0.9, 'The entire history is one sitting',
                'Every commit inside a few hours, with nothing before and nothing after. Real projects have evenings, weekends and abandonment in them.'),
            'gh.generic_messages' => self::mk($g, $ai, 0.6, 'Interchangeable commit messages',
                '"update", "changes", "fix", "wip" over and over, carrying no information about what happened.'),

            'gh.steady_cadence' => self::mk($g, $hu, 1.1, 'Work spread across real time',
                'Commits over weeks or months, on many separate days. This is the hardest property to manufacture and the most informative single thing about a repository.'),
            'gh.multiple_authors' => self::mk($g, $hu, 1.0, 'More than one person committed',
                'Several distinct authors in the history. Collaboration is expensive to fake and generators do not produce it.'),
            'gh.merges_and_reverts' => self::mk($g, $hu, 0.8, 'Branches, merges and reverts',
                'Work that went in, came out again, or arrived from a branch. Evidence of a process with second thoughts in it.'),
            'gh.issue_refs' => self::mk($g, $hu, 0.8, 'Commits tied to tracked work',
                'Issue numbers and ticket keys in the subjects, linking the code to a conversation happening somewhere else.'),
            'gh.human_mess' => self::mk($g, $hu, 0.7, 'Visible frustration in the log',
                '"oops", "actually fix it this time", "why". The residue of a person losing an argument with their own code.'),

            // ---- Site-wide ---------------------------------------------------
            // Only available when more than one page has been read. A single
            // page cannot tell you whether a site was built or accreted; ten
            // pages usually can.
            'xs.template_uniformity' => self::mk($w, $ai, 1.2, 'Every page is one template with the words swapped',
                'The same structure, the same class fingerprints and the same section order across the whole site. Generated sites are stamped from one mould; sites that grew have pages that remember when they were made.'),
            'xs.placeholder_pages' => self::mk($w, $ai, 0.9, 'Pages built and linked but never filled',
                'Routes that exist, appear in the navigation, and carry almost nothing. The scaffolding was generated along with everything else and nobody came back to write the content.'),
            'xs.sitemap_one_pass' => self::mk($w, $ai, 0.7, 'The sitemap was written in one pass',
                'Every URL in sitemap.xml carries the same <lastmod>, or none carries one at all. A sitemap that grew alongside a site records when each page last changed; one generated with the site records the day the site was generated.'),
            'xs.uniform_page_size' => self::mk($w, $ai, 0.7, 'Every page is the same weight',
                'Pages within a few percent of each other in size and element count. Real sites are lumpy because real content is lumpy: an About page is not the same length as a pricing table.'),

            'xs.style_drift' => self::mk($w, $hu, 1.0, 'Pages from different eras',
                'One page on an older stack, another rebuilt more recently; inconsistent markup conventions between sections. Drift like this is what accretion looks like, and it is expensive to fake.'),
            'xs.varied_pages' => self::mk($w, $hu, 0.8, 'Pages genuinely differ in shape',
                'Substantial variation in length, structure and density from one page to the next, in the way that follows from pages having different jobs.'),
            'xs.sitemap_history' => self::mk($w, $hu, 0.8, 'The sitemap records months of edits',
                'Pages last modified across a spread of dates rather than all at once. This is a record of a site being worked on over time, kept by the tooling rather than by the author, and it is tedious to fabricate.'),
            'xs.deep_content' => self::mk($w, $hu, 0.7, 'Somebody wrote a lot of this',
                'At least one page carrying substantially more prose than the rest — an article, a manual, a history. Volume of specific writing is the least automatable thing on a website.'),

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
            'st.untouched_scaffold' => self::mk($s, $ai, 0.95, 'The starter template was never renamed',
                'The document still identifies itself as the scaffold it was created from: a title of "Vite + React + TS", the description create-next-app writes, a stock favicon at /vite.svg. These are the first things anyone who cared about the page would change, and the last things anyone who only prompted it would notice.'),
            'st.generated_stack' => self::mk($s, $ai, 0.7, 'The default generated component stack, whole',
                'Radix primitives, class-variance-authority, tailwind-merge, Lucide and a toast library, all together and all at their defaults. Any one of these is an ordinary choice; the full set arriving at once is the component kit these builders scaffold from, adopted rather than assembled.'),
            'st.client_only_backend' => self::mk($s, $ai, 0.6, 'The browser is the whole application',
                'A database called straight from the page, or localStorage standing in for one. There is no server-side anything: what the tool could generate in one file it did, and what would have needed a second system it worked around.'),
            'st.spa_shell' => self::mk($s, $ai, 0.45, 'Empty client-rendered shell with a hashed bundle',
                'An almost-empty root div plus a single hashed asset bundle. This is the default output shape of the generator stack, but hand-built single-page apps look identical, so it is only a starting point.'),
            'st.file_header_block' => self::mk($s, $ai, 0.6, 'Explanatory file-header block',
                'A banner comment at the top of the file restating the filename and describing the module\'s purpose in prose. Humans write these for libraries other people consume; generators write them for every file including the ones nobody reads.'),
            'st.full_scaffold' => self::mk($s, $ai, 0.35, 'Textbook-complete document scaffold',
                'Complete head, full meta and social tags, alt text and ARIA labels everywhere, arriving fully formed rather than accreting over time. This is also just good practice, hence the low weight.'),

            // ---- Code style -------------------------------------------------
            'cd.emoji_comments' => self::mk($c, $ai, 1.3, 'Emoji inside code comments',
                'One of the most reliable single markers reviewers report. Emoji in source comments are rare in hand-written production code and routine in generated code.'),
            'cd.assistant_chatter' => self::mk($c, $ai, 1.4, 'The assistant is still talking',
                'Conversational replies left in the file: "Sure!", "Here\'s a comprehensive solution", "Let\'s fetch the users", "Feel free to adjust". A sentence that would read naturally pasted into a chat window has no business in a repository, and nobody types these into an editor on purpose.'),
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
            'cd.generic_domain_names' => self::mk($c, $ai, 0.8, 'Names that describe no business',
                'data, item, result, obj, processData. It compiles and it says nothing. If the name cannot be swapped for a word from the product — invoice, trip, booking, vote — then nobody has decided yet what the thing is.'),
            'cd.placeholder_endpoint' => self::mk($c, $ai, 0.8, 'Endpoints that point nowhere',
                'api.example.com, YOUR_API_URL, "add your endpoint here". The shape of the call was generated correctly and the one detail only the author could supply was left blank.'),
            'cd.tautological_params' => self::mk($c, $ai, 0.7, 'Documentation that restates the signature',
                '@param {number} a - The first number to add. Every parameter described by spelling its own name back, and nothing at all about units, ranges, ownership or what happens at the edges — which is the only part worth writing down.'),
            'cd.mixed_conventions' => self::mk($c, $ai, 0.7, 'Two conventions in one file',
                'camelCase beside snake_case, single quotes beside double, bracket access beside dot access, on the same object. Not the drift of a codebase over years — the seam where two generated fragments were joined.'),
            'cd.ceremony_for_nothing' => self::mk($c, $ai, 0.6, 'Architecture for a light switch',
                'A factory, a provider and a memo to hold a boolean. The problem fitted in six lines and the solution takes sixty. Generated code reaches for a pattern because patterns are what it has seen, not because this problem needed one.'),
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
            'se.exposed_client_key' => self::mk($x, $ai, 0.85, 'A credential shipped to the browser',
                'A project key, a service token or a model-provider key sitting in the page\'s own JavaScript, where anyone can read it. Some of these are meant to be public and safe only behind row-level rules nobody set; others — a provider key, or an SDK started with its own escape hatch for running in a browser — are never meant to leave a server.'),
            'se.client_side_auth' => self::mk($x, $ai, 0.65, 'Authentication decided in the browser',
                'A login flag, a role or an admin bit kept in localStorage and trusted. It looks like auth and demonstrates like auth, and it is bypassed by editing one value in dev tools, which is the difference between generating the shape of a feature and building one.'),

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
            'ae.hero_pill' => self::mk($a, $ai, 0.50, 'The little badge above the headline',
                'A small rounded pill sitting over the h1 — "Introducing v2", "Now with AI", usually with a sparkle. Almost every generated landing page has one, and it almost never says anything.'),
            'ae.scroll_indicator' => self::mk($a, $ai, 0.45, 'A cue telling you to scroll',
                'A bouncing chevron or an animated mouse outline at the bottom of the first screen. People have known how to scroll for thirty years; this is decoration that has learned to look like affordance.'),
            'ae.glow_orbs' => self::mk($a, $ai, 0.40, 'Blurred colour behind the hero',
                'Large soft gradient blobs, heavily blurred, floating behind the opening section. One of a handful of effects a model reaches for when asked to make something feel premium.'),
            'ae.bento_grid' => self::mk($a, $ai, 0.40, 'The bento grid',
                'Feature tiles of deliberately unequal spans arranged into a mosaic. A real 2023 design idea, now the default answer to "show several features at once".'),
            'ae.logo_marquee' => self::mk($a, $ai, 0.40, 'An endless strip of logos',
                'A "trusted by" band scrolling on a loop, often with placeholder or invented marks. The social proof is a component before it is a fact.'),
            'ae.floating_nav' => self::mk($a, $ai, 0.35, 'The floating blurred navbar',
                'A detached pill-shaped header with a backdrop blur, hovering a little way down from the top of the page rather than sitting on it.'),
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
            'hu.hardened_headers' => self::mk($h, $hu, 0.45, 'Response headers somebody configured',
                'A content security policy with real directives in it, HSTS with a long max-age, a permissions policy. Nothing generates these: they are written by a person who thought about the deployment, usually after being told to.'),
            'hu.inconsistent_format' => self::mk($h, $hu, 0.6, 'Inconsistent formatting',
                'Mixed tabs and spaces, drifting indentation, irregular line lengths. Small inconsistency is the human default.'),
            'hu.commented_code' => self::mk($h, $hu, 0.5, 'Commented-out code left behind',
                'Old implementations kept around just in case, which generators do not produce.'),
            'hu.legacy_stack' => self::mk($h, $hu, 0.5, 'Older or hand-rolled stack',
                'jQuery, table layouts, hand-written includes, vendor-prefixed CSS: an accretion history rather than a single generation.'),
            'hu.abbrevs' => self::mk($h, $hu, 0.4, 'Abbreviated, idiomatic identifiers',
                'authSvc, cfg, mgr, activeSub. Shorthand that assumes a reader who already has the context.'),
            'hu.build_stripped' => self::mk($h, $hu, 0.2, 'Output of a build pipeline',
                'Minified, comment-stripped, content-hashed assets. This used to mean somebody set a toolchain up; it is now what every generator emits by default too, so it carries much less than it did and is withheld entirely when the same page is carrying a builder fingerprint or an untouched scaffold.'),
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
