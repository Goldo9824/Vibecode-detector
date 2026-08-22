# The signal catalogue

<!-- Generated from lib/Catalog.php by tools/gen-signals-doc.php. Do not edit by hand. -->

Every signal the detector can fire, what it means, and what it is worth.

Weights are in **log-odds**, which is what makes them summable. Scoring starts from a
prior of −1.0 (the assumption that a given subject is *not* generated) and adds the
weight of each signal found, positive for AI, negative for human. The total goes through
a logistic curve to become the percentage. As a rough guide, a weight of 0.7 doubles the
odds; 4.5 ends the argument.

There are **104 signals** across 9 categories.

| Category | Signals | Direction |
|---|---|---|
| [Platform fingerprint](#platform-fingerprint) | 7 | raises the score |
| [Repository history](#repository-history) | 11 | raises the score |
| [Site-wide](#site-wide) | 8 | raises the score |
| [Structural](#structural) | 12 | raises the score |
| [Code style](#code-style) | 23 | raises the score |
| [Content](#content) | 7 | raises the score |
| [Security profile](#security-profile) | 5 | raises the score |
| [Aesthetic](#aesthetic) | 16 | raises the score |
| [Human authorship](#human-authorship) | 15 | lowers the score |

---

## Platform fingerprint

A builder naming itself. These are positive identifications rather than inferences, so one is enough to settle the question. Their absence means nothing whatsoever: agentic editors write into an ordinary repository and leave none of this behind.

### Base44 fingerprint

`fp.base44` · weight **4.5** (decisive)

The Base44 SDK or deployment host is referenced by the page.

### Bolt.new build fingerprint

`fp.bolt` · weight **4.5** (decisive)

Bolt's badge, host or bundle signature is present in the served page.

### Lovable build fingerprint

`fp.lovable` · weight **4.5** (decisive)

The page carries Lovable's own runtime, upload paths or tagger. This is a positive identification of the builder, not an inference.

### v0 by Vercel fingerprint

`fp.v0` · weight **4.5** (decisive)

A "Built with v0" badge or v0 deployment host identifies the generator directly.

### Replit Agent fingerprint

`fp.replit` · weight **4.2** (decisive)

Replit badge or hosting signature. Replit hosts hand-written projects too, so this identifies the host with high but not total confidence.

### Another AI builder's fingerprint

`fp.builder_other` · weight **4** (decisive)

A generator outside the main five identified itself in the page: its SDK, deployment host or badge is present. The specific tool is named in the evidence.

### AI builder named in the generator meta tag

`fp.generator_meta` · weight **4** (decisive)

The document's own <meta name="generator"> names an AI site builder.


## Repository history

How the code arrived, rather than what it looks like. The strongest evidence available short of a fingerprint, and the hardest to fake after the fact: a convincing forged history means inventing plausible timestamps, authors, mistakes and reverts for every commit. Read from a pasted `git log`.

### The repository arrives fully formed

`gh.big_bang` · weight **1.4** (strong)

An opening commit carrying most of the codebase at once. Hand-built projects start small and accrete; a first commit with hundreds of lines across dozens of files is a generated tree being put under version control after the fact.

### More code than anyone types

`gh.velocity` · weight **1.2** (strong)

Hundreds of lines landing within minutes. Not proof by itself — a paste, a vendored library or a generated file does this too — but combined with the rest it is the shape of accepting output rather than writing it.

### A large drop followed by a trail of one-line fixes

`gh.micro_fix_trail` · weight **1.1** (strong)

"fix typo", "fix import", "add missing dependency" in a run after a huge commit. The signature of code that was never read before it was committed, then corrected as each error surfaced at runtime.

### Work spread across real time

`gh.steady_cadence` · weight **1.1** (strong)

Commits over weeks or months, on many separate days. This is the hardest property to manufacture and the most informative single thing about a repository.

### More than one person committed

`gh.multiple_authors` · weight **1** (strong)

Several distinct authors in the history. Collaboration is expensive to fake and generators do not produce it.

### Commit messages that read like the prompt

`gh.prompt_messages` · weight **1** (strong)

Subjects such as "add REST API for user management with JWT authentication": a complete specification in the imperative, describing what was asked for rather than what changed.

### The entire history is one sitting

`gh.single_session` · weight **0.9** (moderate)

Every commit inside a few hours, with nothing before and nothing after. Real projects have evenings, weekends and abandonment in them.

### Commits tied to tracked work

`gh.issue_refs` · weight **0.8** (moderate)

Issue numbers and ticket keys in the subjects, linking the code to a conversation happening somewhere else.

### Branches, merges and reverts

`gh.merges_and_reverts` · weight **0.8** (moderate)

Work that went in, came out again, or arrived from a branch. Evidence of a process with second thoughts in it.

### Visible frustration in the log

`gh.human_mess` · weight **0.7** (moderate)

"oops", "actually fix it this time", "why". The residue of a person losing an argument with their own code.

### Interchangeable commit messages

`gh.generic_messages` · weight **0.6** (moderate)

"update", "changes", "fix", "wip" over and over, carrying no information about what happened.


## Site-wide

What only becomes visible once several pages have been read together. A single page cannot tell you whether a site was built in one pass or accreted over years; ten pages usually can. Available in whole-site mode.

### Every page is one template with the words swapped

`xs.template_uniformity` · weight **1.2** (strong)

The same structure, the same class fingerprints and the same section order across the whole site. Generated sites are stamped from one mould; sites that grew have pages that remember when they were made.

### Pages from different eras

`xs.style_drift` · weight **1** (strong)

One page on an older stack, another rebuilt more recently; inconsistent markup conventions between sections. Drift like this is what accretion looks like, and it is expensive to fake.

### Pages built and linked but never filled

`xs.placeholder_pages` · weight **0.9** (moderate)

Routes that exist, appear in the navigation, and carry almost nothing. The scaffolding was generated along with everything else and nobody came back to write the content.

### The sitemap records months of edits

`xs.sitemap_history` · weight **0.8** (moderate)

Pages last modified across a spread of dates rather than all at once. This is a record of a site being worked on over time, kept by the tooling rather than by the author, and it is tedious to fabricate.

### Pages genuinely differ in shape

`xs.varied_pages` · weight **0.8** (moderate)

Substantial variation in length, structure and density from one page to the next, in the way that follows from pages having different jobs.

### Somebody wrote a lot of this

`xs.deep_content` · weight **0.7** (moderate)

At least one page carrying substantially more prose than the rest — an article, a manual, a history. Volume of specific writing is the least automatable thing on a website.

### The sitemap was written in one pass

`xs.sitemap_one_pass` · weight **0.7** (moderate)

Every URL in sitemap.xml carries the same <lastmod>, or none carries one at all. A sitemap that grew alongside a site records when each page last changed; one generated with the site records the day the site was generated.

### Every page is the same weight

`xs.uniform_page_size` · weight **0.7** (moderate)

Pages within a few percent of each other in size and element count. Real sites are lumpy because real content is lumpy: an About page is not the same length as a pricing table.


## Structural

The shape of the whole rather than the style of the line. These are the hardest signals to produce by accident and the hardest to remove by editing, which is why they carry the most weight after fingerprints.

### Navigational section comments survive in production

`st.section_comments` · weight **1.1** (strong)

Comments like <!-- Hero --> above each block. Models write these to orient themselves. Real build pipelines strip HTML comments, so finding them on a deployed page means the file was hand-deployed exactly as generated.

### The same problem is solved several different ways

`st.multiple_solutions` · weight **1** (strong)

Two HTTP clients, three date helpers, four validation styles. Local perfection with global incoherence: each prompt session solved its own problem in isolation.

### Comment density is uniform across the file

`st.uniform_comment_density` · weight **1** (strong)

Humans cluster comments around the parts that were hard. A flat comment rate from top to bottom is the signature of a generator that documents every line equally.

### The starter template was never renamed

`st.untouched_scaffold` · weight **0.95** (strong)

The document still identifies itself as the scaffold it was created from: a title of "Vite + React + TS", the description create-next-app writes, a stock favicon at /vite.svg. These are the first things anyone who cared about the page would change, and the last things anyone who only prompted it would notice.

### Every function carries a docblock, including trivial ones

`st.docblock_on_everything` · weight **0.9** (moderate)

Uniform, complete docblocks on getters and one-line helpers alike.

### Fully-built code wired to nothing

`st.dead_code` · weight **0.8** (moderate)

Complete components, routes or helpers that nothing imports or calls.

### The default generated component stack, whole

`st.generated_stack` · weight **0.7** (moderate)

Radix primitives, class-variance-authority, tailwind-merge, Lucide and a toast library, all together and all at their defaults. Any one of these is an ordinary choice; the full set arriving at once is the component kit these builders scaffold from, adopted rather than assembled.

### The browser is the whole application

`st.client_only_backend` · weight **0.6** (moderate)

A database called straight from the page, or localStorage standing in for one. There is no server-side anything: what the tool could generate in one file it did, and what would have needed a second system it worked around.

### Explanatory file-header block

`st.file_header_block` · weight **0.6** (moderate)

A banner comment at the top of the file restating the filename and describing the module's purpose in prose. Humans write these for libraries other people consume; generators write them for every file including the ones nobody reads.

### Imports are perfectly grouped and alphabetised

`st.import_block_sorted` · weight **0.6** (moderate)

Standard library, third-party, then local, sorted within each group, with no organic accretion.

### Empty client-rendered shell with a hashed bundle

`st.spa_shell` · weight **0.45** (weak)

An almost-empty root div plus a single hashed asset bundle. This is the default output shape of the generator stack, but hand-built single-page apps look identical, so it is only a starting point.

### Textbook-complete document scaffold

`st.full_scaffold` · weight **0.35** (weak)

Complete head, full meta and social tags, alt text and ARIA labels everywhere, arriving fully formed rather than accreting over time. This is also just good practice, hence the low weight.


## Code style

Line-level habits. Individually weak and easy to mask by renaming or reformatting; convincing only when four or more of them converge across a file.

### The assistant is still talking

`cd.assistant_chatter` · weight **1.4** (strong)

Conversational replies left in the file: "Sure!", "Here's a comprehensive solution", "Let's fetch the users", "Feel free to adjust". A sentence that would read naturally pasted into a chat window has no business in a repository, and nobody types these into an editor on purpose.

### Emoji inside code comments

`cd.emoji_comments` · weight **1.3** (strong)

One of the most reliable single markers reviewers report. Emoji in source comments are rare in hand-written production code and routine in generated code.

### Emoji inside log and status output

`cd.emoji_logging` · weight **1.1** (strong)

Ticks, crosses and party poppers in console output. Nearly as reliable as emoji in comments, and it survives longer because log strings get cleaned up less often than comments do.

### Comments explain what the code does, not why

`cd.what_comments` · weight **1** (strong)

Restating the next line in English is the clearest cross-language tell. Human comments carry outside context: a ticket, an audit, a bug, a reason.

### Tests that assert nothing

`cd.empty_tests` · weight **0.9** (moderate)

Happy-path-only tests, or true == true wearing a costume. No edge cases, no negative paths.

### Error handling wrapped around everything

`cd.blanket_try` · weight **0.8** (moderate)

Try/catch around routine, non-throwing code, applied uniformly rather than where failure is actually expected.

### Names that describe no business

`cd.generic_domain_names` · weight **0.8** (moderate)

data, item, result, obj, processData. It compiles and it says nothing. If the name cannot be swapped for a word from the product — invoice, trip, booking, vote — then nobody has decided yet what the thing is.

### Endpoints that point nowhere

`cd.placeholder_endpoint` · weight **0.8** (moderate)

api.example.com, YOUR_API_URL, "add your endpoint here". The shape of the call was generated correctly and the one detail only the author could supply was left blank.

### Exceptions caught and discarded

`cd.swallowed_errors` · weight **0.8** (moderate)

catch blocks that log and continue, or swallow silently. The blindfold that stops the program crashing so nobody ever learns it is broken.

### Two conventions in one file

`cd.mixed_conventions` · weight **0.7** (moderate)

camelCase beside snake_case, single quotes beside double, bracket access beside dot access, on the same object. Not the drift of a codebase over years — the seam where two generated fragments were joined.

### Decorative section-header comments

`cd.section_header_comments` · weight **0.7** (moderate)

Banner comments such as # ===== User Authentication ===== used as navigation inside a single file.

### Documentation that restates the signature

`cd.tautological_params` · weight **0.7** (moderate)

@param {number} a - The first number to add. Every parameter described by spelling its own name back, and nothing at all about units, ranges, ownership or what happens at the edges — which is the only part worth writing down.

### Architecture for a light switch

`cd.ceremony_for_nothing` · weight **0.6** (moderate)

A factory, a provider and a memo to hold a boolean. The problem fitted in six lines and the solution takes sixty. Generated code reaches for a pattern because patterns are what it has seen, not because this problem needed one.

### Formal, grammatically complete error messages

`cd.formal_errors` · weight **0.6** (moderate)

"The provided email address is not in a valid format." where a human writes "Invalid email".

### Model typography pasted straight into the source

`cd.typography` · weight **0.6** (moderate)

Curly quotes and em dashes in code, comments or markup, which an editor does not produce on its own.

### Hyper-verbose identifier names

`cd.verbose_names` · weight **0.6** (moderate)

Names like currentLoggedInUserAuthTokenValue, where a human would have written the same thing in a third of the characters.

### Debug logging left in shipped code

`cd.console_noise` · weight **0.5** (weak)

Dense, uniform console output that was never cleaned up.

### Optional chaining and fallbacks on everything

`cd.defensive_chaining` · weight **0.5** (weak)

Every property access guarded and every value given a default, applied uniformly rather than where a value is genuinely optional. The same instinct as the blanket try/catch: nothing can throw, so nothing can be diagnosed.

### Utils / Manager / Handler / Helper pile-up

`cd.helper_pileup` · weight **0.5** (weak)

Layers of ceremony classes stacked without a need that justifies them.

### Iteration-scar names

`cd.lazy_names` · weight **0.5** (weak)

data2, result_final, handleClick2, newFunction: the residue of re-prompting until something worked.

### TODO and placeholder blocks in shipped code

`cd.todo_placeholders` · weight **0.5** (weak)

Stub bodies and "implement this" markers that were never returned to.

### Every branch has its counterpart

`cd.over_symmetric_branches` · weight **0.45** (weak)

An else for every if, a default for every switch, a fallback for every path, whether or not the case can occur. Human control flow is lopsided because real requirements are lopsided.

### Entry-point guard on a module nothing runs

`cd.main_guard` · weight **0.4** (weak)

if __name__ == "__main__": on a library module that is only ever imported.


## Content

What the page says, as opposed to how it is built. Placeholder people, house-voice marketing copy and navigation that goes nowhere.

### Statistically generic placeholder people

`ct.generic_names` · weight **0.7** (moderate)

Testimonials from the most common names in the training data, with titles like "Verified User" or "Head of Operations".

### Emoji used as the icon system

`ct.emoji_icons` · weight **0.6** (moderate)

A rocket for performance, a lightbulb for smart. Emoji do not inherit colour, adapt to dark mode or scale cleanly, so this reliably means the page never went through design review.

### Copy in the house model voice

`ct.marketing_cliche` · weight **0.5** (weak)

"Ship faster", "unlock the power of", "everything you need to". Marketing language that tells rather than shows, with no specifics anywhere.

### Placeholder copy left in production

`ct.placeholder_copy` · weight **0.5** (weak)

Lorem ipsum, "Coming soon", "Your text here" on a live page.

### Round, unsourced statistics

`ct.stat_inflation` · weight **0.5** (weak)

Suspiciously tidy numbers doing persuasive work: 10,000+ users, 99.9% uptime, 10x faster, 2M downloads. Nothing is attributed and the roundness is the tell, because measured figures are rarely this neat.

### Navigation that goes nowhere

`ct.dead_links` · weight **0.4** (weak)

A full nav and footer where most links are href="#".

### Three tiers with the middle one starred

`ct.pricing_three` · weight **0.35** (weak)

Exactly three pricing columns with a "Most popular" badge on the centre one. A real pricing page is shaped by what a business actually sells; this shape is shaped by every pricing page in the training data.


## Security profile

The most durable family. Syntax correctness in generated code has climbed past 95% while security pass rates have stayed near 55%, so these signals decay far more slowly than the stylistic ones and will outlive most of this document.

### Placeholder or hardcoded secret

`se.placeholder_secret` · weight **0.9** (moderate)

Literals such as "your-secret-key", "change-me" or an inline credential where an environment variable belongs.

### A credential shipped to the browser

`se.exposed_client_key` · weight **0.85** (moderate)

A project key, a service token or a model-provider key sitting in the page's own JavaScript, where anyone can read it. Some of these are meant to be public and safe only behind row-level rules nobody set; others — a provider key, or an SDK started with its own escape hatch for running in a browser — are never meant to leave a server.

### Authentication or ownership checks missing at the boundary

`se.weak_auth` · weight **0.7** (moderate)

Endpoints that authenticate but never check whether this user owns this row, or tokens issued without expiry.

### Authentication decided in the browser

`se.client_side_auth` · weight **0.65** (moderate)

A login flag, a role or an admin bit kept in localStorage and trusted. It looks like auth and demonstrates like auth, and it is bypassed by editing one value in dev tools, which is the difference between generating the shape of a feature and building one.

### Textbook-insecure defaults

`se.insecure_defaults` · weight **0.6** (moderate)

Wide-open CORS, disabled TLS verification, raw string-concatenated queries, unescaped output.


## Aesthetic

How it looks. Weak by construction and **capped as a group**, because a purple gradient and a default icon set are a reason to look closer and never a conclusion.

### The little badge above the headline

`ae.hero_pill` · weight **0.5** (weak)

A small rounded pill sitting over the h1 — "Introducing v2", "Now with AI", usually with a sparkle. Almost every generated landing page has one, and it almost never says anything.

### The indigo-to-violet default palette

`ae.indigo` · weight **0.45** (weak)

Indigo 500 into violet, on slate. Not a design decision so much as a statistical average of every Tailwind tutorial written between 2019 and 2024.

### A cue telling you to scroll

`ae.scroll_indicator` · weight **0.45** (weak)

A bouncing chevron or an animated mouse outline at the bottom of the first screen. People have known how to scroll for thirty years; this is decoration that has learned to look like affordance.

### The bento grid

`ae.bento_grid` · weight **0.4** (weak)

Feature tiles of deliberately unequal spans arranged into a mosaic. A real 2023 design idea, now the default answer to "show several features at once".

### Blurred colour behind the hero

`ae.glow_orbs` · weight **0.4** (weak)

Large soft gradient blobs, heavily blurred, floating behind the opening section. One of a handful of effects a model reaches for when asked to make something feel premium.

### The gradient-filled headline

`ae.gradient_text` · weight **0.4** (weak)

A heading painted with a clipped background gradient instead of a colour. Almost unheard of in hand-built pages before 2023 and near-universal in generated ones since.

### The coloured left-border card

`ae.left_border_card` · weight **0.4** (weak)

A 3-4px accent strip down the left edge of a panel, applied to every callout on the page.

### An endless strip of logos

`ae.logo_marquee` · weight **0.4** (weak)

A "trusted by" band scrolling on a loop, often with placeholder or invented marks. The social proof is a component before it is a fact.

### Untouched component-kit defaults

`ae.shadcn_defaults` · weight **0.4** (weak)

rounded-2xl, shadow-lg, p-6, pill buttons, cards nested inside cards, every radius identical.

### The floating blurred navbar

`ae.floating_nav` · weight **0.35** (weak)

A detached pill-shaped header with a backdrop blur, hovering a little way down from the top of the page rather than sitting on it.

### Default icon set, unchanged

`ae.lucide` · weight **0.35** (weak)

Lucide or Heroicons throughout, because the component kit these tools build on ships them as the default.

### Frosted-glass panels throughout

`ae.glassmorphism` · weight **0.3** (weak)

Backdrop blur over translucent white on every card and bar. One of the handful of effects a model reaches for when asked to make something look modern.

### The default display typeface

`ae.inter_font` · weight **0.3** (weak)

Inter, or its replacements Geist, Poppins, Space Grotesk and Manrope, doing all the typographic work.

### Rigid symmetry

`ae.three_cards` · weight **0.3** (weak)

Exactly three feature cards, 01/02/03 steps, descriptions of near-identical length. Human pages are lumpier because human content is lumpier.

### No real photography

`ae.no_real_images` · weight **0.25** (weak)

Gradients, abstract shapes and generated faces instead of pictures of anything that exists.

### Uniform generous spacing

`ae.uniform_whitespace` · weight **0.25** (weak)

The same large vertical padding on every section: the safest way to guarantee a responsive layout never overlaps.


## Human authorship

Evidence of a human having been present: outside context a generator has no access to, inconsistency, accretion, mess. These subtract from the score and are weighted as heavily as the signals pointing the other way.

### Comments carry outside context

`hu.why_comments` · weight **1** (strong)

References to a standard, an audit, an outage or a decision. This is knowledge the code cannot contain and a generator has no access to.

### Informal, exasperated or profane commentary

`hu.informal` · weight **0.9** (moderate)

HACK, XXX, "do not touch this", "no idea why this works". Models are relentlessly polite.

### References to tickets, issues, dates or people

`hu.ticket_refs` · weight **0.9** (moderate)

Links into a tracker, issue numbers, dated notes, names of colleagues.

### Built on a classic CMS or site builder

`hu.cms` · weight **0.7** (moderate)

WordPress, Wix, Squarespace, Webflow, Shopify. These predate the current generation of tools and are a different phenomenon entirely, not evidence of it.

### Misspellings in the copy

`hu.typos` · weight **0.7** (moderate)

Models do not typo. A page carrying "recieve" or "seperate" was typed by somebody, and typos are one of the few tells that masking makes worse rather than better, since cleaning them up is exactly what nobody bothers to do.

### Inconsistent formatting

`hu.inconsistent_format` · weight **0.6** (moderate)

Mixed tabs and spaces, drifting indentation, irregular line lengths. Small inconsistency is the human default.

### Signs of a business actually operating

`hu.operational_stack` · weight **0.6** (moderate)

A cookie banner, legal and privacy pages, a tag manager, a real analytics property, a newsletter provider. Accumulated obligations rather than generated features.

### Commented-out code left behind

`hu.commented_code` · weight **0.5** (weak)

Old implementations kept around just in case, which generators do not produce.

### Older or hand-rolled stack

`hu.legacy_stack` · weight **0.5** (weak)

jQuery, table layouts, hand-written includes, vendor-prefixed CSS: an accretion history rather than a single generation.

### Copy with specifics

`hu.long_tail_copy` · weight **0.5** (weak)

Real prices, dates, addresses, named people, opening hours: details that had to come from somewhere.

### Response headers somebody configured

`hu.hardened_headers` · weight **0.45** (weak)

A content security policy with real directives in it, HSTS with a long max-age, a permissions policy. Nothing generates these: they are written by a person who thought about the deployment, usually after being told to.

### Abbreviated, idiomatic identifiers

`hu.abbrevs` · weight **0.4** (weak)

authSvc, cfg, mgr, activeSub. Shorthand that assumes a reader who already has the context.

### A footer that has fallen out of date

`hu.dated_copyright` · weight **0.4** (weak)

A copyright year or "last updated" date already in the past. Generated pages are current by construction; drift means the page has been sitting there.

### Real and varied media

`hu.real_media` · weight **0.4** (weak)

Photographs at irregular sizes from irregular sources, rather than one generated hero image.

### Output of a build pipeline

`hu.build_stripped` · weight **0.2** (weak)

Minified, comment-stripped, content-hashed assets. This used to mean somebody set a toolchain up; it is now what every generator emits by default too, so it carries much less than it did and is withheld entirely when the same page is carrying a builder fingerprint or an untouched scaffold.


---

## Rules the scoring will not break

These are enforced in `Report::score()` and covered by `tests/run.php`.

1. **Aesthetic evidence is capped as a group** at 1.0 log-odds, and a subject with no
   non-aesthetic AI signals cannot score above 55% no matter how purple it is.
2. **No reading reaches 0% or 100%.** The scale is clamped to 3–97.
3. **Thin input is pulled toward the middle** rather than guessed at, and reports
   insufficient confidence.
4. **Confidence never exceeds 'moderate' without a platform fingerprint**, because
   pattern-reading without repository history does not earn more than that.
5. **Human signals are first-class** and weighted on the same scale.

## Adding a signal

Add the entry to `lib/Catalog.php`, fire it from `SiteAnalyzer` or `CodeAnalyzer`,
add a fixture case to `tests/`, then run `php tools/gen-signals-doc.php` to refresh
this file. See [CONTRIBUTING.md](../CONTRIBUTING.md) for what a new signal has to
justify before it is worth adding.
