# The signal catalogue

<!-- Generated from lib/Catalog.php by tools/gen-signals-doc.php. Do not edit by hand. -->

Every signal the detector can fire, what it means, and what it is worth.

Weights are in **log-odds**, which is what makes them summable. Scoring starts from a
prior of −1.0 (the assumption that a given subject is *not* generated) and adds the
weight of each signal found, positive for AI, negative for human. The total goes through
a logistic curve to become the percentage. As a rough guide, a weight of 0.7 doubles the
odds; 4.5 ends the argument.

There are **130 signals** across 10 categories.

| Category | Signals | Direction |
|---|---|---|
| [Platform fingerprint](#platform-fingerprint) | 7 | raises the score |
| [Repository history](#repository-history) | 11 | raises the score |
| [Repository contents](#repository-contents) | 7 | raises the score |
| [Site-wide](#site-wide) | 9 | raises the score |
| [Structural](#structural) | 17 | raises the score |
| [Code style](#code-style) | 27 | raises the score |
| [Content](#content) | 11 | raises the score |
| [Security profile](#security-profile) | 6 | raises the score |
| [Aesthetic](#aesthetic) | 18 | raises the score |
| [Human authorship](#human-authorship) | 17 | lowers the score |

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

How the code arrived, rather than what it looks like. The strongest evidence available short of a fingerprint, and the hardest to fake after the fact: a convincing forged history means inventing plausible timestamps, authors, mistakes and reverts for every commit. Read from a pasted `git log`, or from a public repository.

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


## Repository contents

What is in the repository, as opposed to what the commits did to it: which files exist, what the README is made of, whether anything is tested, whether an assistant's own configuration was committed along with the code. A served page cannot tell you any of this. Available in repository mode.

### An assistant's own configuration is committed

`rp.agent_config` · weight **1.5** (strong)

CLAUDE.md, AGENTS.md, .cursorrules, .windsurfrules, a copilot-instructions file. Somebody set an AI agent up to work in this repository and committed the setup. It does not follow that the agent wrote everything — this is the one signal here that names the tool honestly rather than inferring it — but it establishes that one was working in the tree.

### Summaries of the work, written by whoever did it

`rp.session_docs` · weight **0.9** (moderate)

IMPLEMENTATION_SUMMARY.md, FIXES_APPLIED.md, PHASE_2_COMPLETE.md, PROJECT_STRUCTURE.md sitting at the top of the repository. Developers write documentation for readers; agents write a report at the end of each session, and nobody deletes them.

### The furniture a project acquires from being used

`rp.project_furniture` · weight **0.7** (moderate)

A changelog with dated entries, contribution guidelines, issue templates, a code of conduct. These accrete because other people turned up, and they are tedious to fabricate for a project nobody has used.

### A README assembled from the standard sections

`rp.readme_generated` · weight **0.7** (moderate)

Emoji section headings, a Features list of adjectives, Getting Started, Contributing and License, in that order, for a project with no contributors and nothing to license. The shape is right and nothing in it was learned by using the thing.

### A substantial codebase with no tests at all

`rp.no_tests` · weight **0.6** (moderate)

Dozens of source files and not one test file anywhere in the tree. Generated projects arrive working rather than verified, and the tests are the part nobody asks for.

### A test suite in proportion to the code

`rp.tests_present` · weight **0.6** (moderate)

Tests amounting to a real share of the tree. Someone cared whether this kept working, which is a different activity from making it work once.

### Dependencies declared but never locked

`rp.dependency_soup` · weight **0.5** (weak)

A manifest listing dependencies with no lockfile committed beside it, in an application rather than a library. Anyone who has deployed this twice commits the lockfile the first time the two deployments disagree.


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

### The navigation promises pages that do not exist

`xs.broken_nav_links` · weight **0.65** (moderate)

Top-level links in the site's own navigation answer with an error. Links rot over years, but they rot deep in a site; a front page pointing at a pricing page that was never built is a page nobody clicked before publishing.


## Structural

The shape of the whole rather than the style of the line. These are the hardest signals to produce by accident and the hardest to remove by editing, which is why they carry the most weight after fingerprints.

### Served straight from the development server

`st.dev_server_page` · weight **1.6** (strong)

The document loads unbundled TypeScript or JSX modules, or the dev server's own client script. Nothing was built for production: what is online is the editor's preview, published as-is.

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

### Forms that submit nowhere

`st.form_to_nowhere` · weight **0.85** (moderate)

A contact or signup form with no action, no endpoint and no handler behind it. The page looks like it collects something and collects nothing, which is what a generated interface does before anybody wires it up.

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

### The whole application in one file

`st.single_file_page` · weight **0.55** (moderate)

Markup, styles and behaviour in a single document, at a size nobody maintains that way. This is what one prompt returns and what a project acquires a directory for on about day two.

### None of the furniture a published page acquires

`st.no_seo_furniture` · weight **0.5** (weak)

No description, no canonical, no social card, no favicon — on a page otherwise built to be shown to people. Anyone who has ever shared a link fixes this the first time it looks wrong in a message.

### Empty client-rendered shell with a hashed bundle

`st.spa_shell` · weight **0.45** (weak)

An almost-empty root div plus a single hashed asset bundle. This is the default output shape of the generator stack, but hand-built single-page apps look identical, so it is only a starting point.

### Still on the platform's own preview domain

`st.preview_host` · weight **0.4** (weak)

The site answers on a deployment platform's default subdomain with no custom domain in front of it. Plenty of real projects live there too, which is why this is weighted as a nudge rather than a finding.

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

### CSS declarations in alphabetical order

`cd.css_alphabetical` · weight **0.6** (moderate)

Properties inside the rules are sorted A to Z. People group declarations by what they do — position, then box, then type, then colour — because that is how you read a rule back. Alphabetical order is what you get when nobody is reading it back. One innocent explanation, and it is a good one: a linter set to enforce exactly this.

### Formal, grammatically complete error messages

`cd.formal_errors` · weight **0.6** (moderate)

"The provided email address is not in a valid format." where a human writes "Invalid email".

### Model typography pasted straight into the source

`cd.typography` · weight **0.6** (moderate)

Curly quotes and em dashes in code, comments or markup, which an editor does not produce on its own.

### Hyper-verbose identifier names

`cd.verbose_names` · weight **0.6** (moderate)

Names like currentLoggedInUserAuthTokenValue, where a human would have written the same thing in a third of the characters.

### Every block of the stylesheet labelled, nothing explained

`cd.css_labelled_sections` · weight **0.55** (moderate)

A comment naming each section — Header, Buttons, Footer — and not one comment saying why any value is what it is. Stylesheets accumulate the opposite: a magic number with an explanation attached, a hack with a browser named next to it.

### Debug logging left in shipped code

`cd.console_noise` · weight **0.5** (weak)

Dense, uniform console output that was never cleaned up.

### Every CSS rule crushed onto one line

`cd.css_one_line` · weight **0.5** (weak)

Rule bodies written as a single line each, in a stylesheet that has otherwise not been minified. Minifiers do this to whole files and strip the newlines with it; this is the shape of CSS emitted a rule at a time and never opened again.

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

### Every function is the same length

`cd.uniform_function_length` · weight **0.5** (weak)

Function bodies cluster tightly around one size. Human files are lumpy — a three-line helper next to a hundred-line one nobody has split yet — because they were written at different times for different reasons.

### Every branch has its counterpart

`cd.over_symmetric_branches` · weight **0.45** (weak)

An else for every if, a default for every switch, a fallback for every path, whether or not the case can occur. Human control flow is lopsided because real requirements are lopsided.

### Entry-point guard on a module nothing runs

`cd.main_guard` · weight **0.4** (weak)

if __name__ == "__main__": on a library module that is only ever imported.


## Content

What the page says, as opposed to how it is built. Placeholder people, house-voice marketing copy and navigation that goes nowhere.

### Contact details nobody can reach

`ct.placeholder_contact` · weight **0.75** (moderate)

An @example.com address, a 555 phone number, 123 Main Street, or social links pointing at the platform's home page rather than an account. A business that wants to be contacted fixes these before launch; a demo never had to.

### Statistically generic placeholder people

`ct.generic_names` · weight **0.7** (moderate)

Testimonials from the most common names in the training data, with titles like "Verified User" or "Head of Operations".

### Testimonial faces from an avatar service

`ct.stock_avatars` · weight **0.7** (moderate)

The people quoted on the page are served by pravatar, randomuser.me, DiceBear or a placeholder image host. Whoever built it needed a face in that slot and took the first one available, which means there was no person to photograph.

### Emoji used as the icon system

`ct.emoji_icons` · weight **0.6** (moderate)

A rocket for performance, a lightbulb for smart. Emoji do not inherit colour, adapt to dark mode or scale cleanly, so this reliably means the page never went through design review.

### The model's sentence rhythm

`ct.llm_prose` · weight **0.6** (moderate)

Not the vocabulary but the shape: "it's not just X, it's Y", "whether you're X or Y", the three-item list where two would do, the sentence that opens by naming the era we live in. Human marketing copy uses these; it does not use all of them at once.

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

### Alt text written as image descriptions

`ct.model_alt_text` · weight **0.4** (weak)

Every image carries a long, evenly-worded description of what is in the picture. Real alt text is uneven — terse where the image is decorative, specific where it matters, missing where somebody forgot.

### Three tiers with the middle one starred

`ct.pricing_three` · weight **0.35** (weak)

Exactly three pricing columns with a "Most popular" badge on the centre one. A real pricing page is shaped by what a business actually sells; this shape is shaped by every pricing page in the training data.


## Security profile

The most durable family. Syntax correctness in generated code has climbed past 95% while security pass rates have stayed near 55%, so these signals decay far more slowly than the stylistic ones and will outlive most of this document.

### A secrets file is committed to the repository

`se.committed_secrets` · weight **0.9** (moderate)

A .env, a service-account key or a credentials file sitting in version control, sometimes alongside the .env.example that says not to. The generated project needed the file to run, so the file was created and committed with everything else.

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

### Neon colours outside any normal palette

`ae.neon_palette` · weight **0.45** (weak)

Electric cyan, hot magenta, acid lime — #00ffff, #ff00ff, #39ff14 and the rest of the saturated corners of the colour space, usually with a matching glow behind them. These are the colours a model reaches for when asked for something futuristic, and almost nobody picks them for a real product, because they are unreadable at body-text sizes and unprintable at any size.

### A cue telling you to scroll

`ae.scroll_indicator` · weight **0.45** (weak)

A bouncing chevron or an animated mouse outline at the bottom of the first screen. People have known how to scroll for thirty years; this is decoration that has learned to look like affordance.

### The bento grid

`ae.bento_grid` · weight **0.4** (weak)

Feature tiles of deliberately unequal spans arranged into a mosaic. A real 2023 design idea, now the default answer to "show several features at once".

### Blurred colour behind the hero

`ae.glow_orbs` · weight **0.4** (weak)

Large soft gradient blobs, heavily blurred, floating behind the opening section. One of a handful of effects a model reaches for when asked to make something feel premium.

### The whole background is a gradient

`ae.gradient_background` · weight **0.4** (weak)

Not the headline and not one panel: the page itself, or every section of it, laid over a multi-stop colour ramp. A background is the largest surface on a page and the one a designer usually leaves alone; filling it with a gradient is the cheapest way to make an empty layout look considered.

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

### Content dated across months or years

`hu.content_dates` · weight **0.6** (moderate)

Published dates, "last updated" lines or changelog entries spread over real time. Somebody came back to this page after the day it was made, which is the one thing a single generation pass cannot produce.

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

### Accessibility somebody actually did

`hu.a11y_care` · weight **0.45** (weak)

A skip link, reduced-motion handling, focus-visible styles, labelled inputs: work that no visitor sees and no generator is asked for. It is done by people who have been told off about it before.

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
