# The signal catalogue

<!-- Generated from lib/Catalog.php by tools/gen-signals-doc.php. Do not edit by hand. -->

Every signal the detector can fire, what it means, and what it is worth.

Weights are in **log-odds**, which is what makes them summable. Scoring starts from a
prior of −1.0 (the assumption that a given subject is *not* generated) and adds the
weight of each signal found, positive for AI, negative for human. The total goes through
a logistic curve to become the percentage. As a rough guide, a weight of 0.7 doubles the
odds; 4.5 ends the argument.

There are **55 signals** across 7 categories.

| Category | Signals | Direction |
|---|---|---|
| [Platform fingerprint](#platform-fingerprint) | 6 | raises the score |
| [Structural](#structural) | 8 | raises the score |
| [Code style](#code-style) | 14 | raises the score |
| [Content](#content) | 5 | raises the score |
| [Security profile](#security-profile) | 3 | raises the score |
| [Aesthetic](#aesthetic) | 8 | raises the score |
| [Human authorship](#human-authorship) | 11 | lowers the score |

---

## Platform fingerprint

A builder naming itself. These are positive identifications rather than inferences, so one is enough to settle the question. Their absence means nothing whatsoever: agentic editors write into an ordinary repository and leave none of this behind.

### Lovable build fingerprint

`fp.lovable` · weight **4.5** (decisive)

The page carries Lovable's own runtime, upload paths or tagger. This is a positive identification of the builder, not an inference.

### Bolt.new build fingerprint

`fp.bolt` · weight **4.5** (decisive)

Bolt's badge, host or bundle signature is present in the served page.

### v0 by Vercel fingerprint

`fp.v0` · weight **4.5** (decisive)

A "Built with v0" badge or v0 deployment host identifies the generator directly.

### Base44 fingerprint

`fp.base44` · weight **4.5** (decisive)

The Base44 SDK or deployment host is referenced by the page.

### Replit Agent fingerprint

`fp.replit` · weight **4.2** (decisive)

Replit badge or hosting signature. Replit hosts hand-written projects too, so this identifies the host with high but not total confidence.

### AI builder named in the generator meta tag

`fp.generator_meta` · weight **4** (decisive)

The document's own <meta name="generator"> names an AI site builder.


## Structural

The shape of the whole rather than the style of the line. These are the hardest signals to produce by accident and the hardest to remove by editing, which is why they carry the most weight after fingerprints.

### Navigational section comments survive in production

`st.section_comments` · weight **1.1** (strong)

Comments like <!-- Hero --> above each block. Models write these to orient themselves. Real build pipelines strip HTML comments, so finding them on a deployed page means the file was hand-deployed exactly as generated.

### Comment density is uniform across the file

`st.uniform_comment_density` · weight **1** (strong)

Humans cluster comments around the parts that were hard. A flat comment rate from top to bottom is the signature of a generator that documents every line equally.

### The same problem is solved several different ways

`st.multiple_solutions` · weight **1** (strong)

Two HTTP clients, three date helpers, four validation styles. Local perfection with global incoherence: each prompt session solved its own problem in isolation.

### Every function carries a docblock, including trivial ones

`st.docblock_on_everything` · weight **0.9** (moderate)

Uniform, complete docblocks on getters and one-line helpers alike.

### Fully-built code wired to nothing

`st.dead_code` · weight **0.8** (moderate)

Complete components, routes or helpers that nothing imports or calls.

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

### Emoji inside code comments

`cd.emoji_comments` · weight **1.3** (strong)

One of the most reliable single markers reviewers report. Emoji in source comments are rare in hand-written production code and routine in generated code.

### Comments explain what the code does, not why

`cd.what_comments` · weight **1** (strong)

Restating the next line in English is the clearest cross-language tell. Human comments carry outside context: a ticket, an audit, a bug, a reason.

### Tests that assert nothing

`cd.empty_tests` · weight **0.9** (moderate)

Happy-path-only tests, or true == true wearing a costume. No edge cases, no negative paths.

### Error handling wrapped around everything

`cd.blanket_try` · weight **0.8** (moderate)

Try/catch around routine, non-throwing code, applied uniformly rather than where failure is actually expected.

### Exceptions caught and discarded

`cd.swallowed_errors` · weight **0.8** (moderate)

catch blocks that log and continue, or swallow silently. The blindfold that stops the program crashing so nobody ever learns it is broken.

### Decorative section-header comments

`cd.section_header_comments` · weight **0.7** (moderate)

Banner comments such as # ===== User Authentication ===== used as navigation inside a single file.

### Hyper-verbose identifier names

`cd.verbose_names` · weight **0.6** (moderate)

Names like currentLoggedInUserAuthTokenValue, where a human would have written the same thing in a third of the characters.

### Formal, grammatically complete error messages

`cd.formal_errors` · weight **0.6** (moderate)

"The provided email address is not in a valid format." where a human writes "Invalid email".

### Model typography pasted straight into the source

`cd.typography` · weight **0.6** (moderate)

Curly quotes and em dashes in code, comments or markup, which an editor does not produce on its own.

### Iteration-scar names

`cd.lazy_names` · weight **0.5** (weak)

data2, result_final, handleClick2, newFunction: the residue of re-prompting until something worked.

### Debug logging left in shipped code

`cd.console_noise` · weight **0.5** (weak)

Dense, uniform console output that was never cleaned up.

### Utils / Manager / Handler / Helper pile-up

`cd.helper_pileup` · weight **0.5** (weak)

Layers of ceremony classes stacked without a need that justifies them.

### TODO and placeholder blocks in shipped code

`cd.todo_placeholders` · weight **0.5** (weak)

Stub bodies and "implement this" markers that were never returned to.

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

### Navigation that goes nowhere

`ct.dead_links` · weight **0.4** (weak)

A full nav and footer where most links are href="#".


## Security profile

The most durable family. Syntax correctness in generated code has climbed past 95% while security pass rates have stayed near 55%, so these signals decay far more slowly than the stylistic ones and will outlive most of this document.

### Placeholder or hardcoded secret

`se.placeholder_secret` · weight **0.9** (moderate)

Literals such as "your-secret-key", "change-me" or an inline credential where an environment variable belongs.

### Authentication or ownership checks missing at the boundary

`se.weak_auth` · weight **0.7** (moderate)

Endpoints that authenticate but never check whether this user owns this row, or tokens issued without expiry.

### Textbook-insecure defaults

`se.insecure_defaults` · weight **0.6** (moderate)

Wide-open CORS, disabled TLS verification, raw string-concatenated queries, unescaped output.


## Aesthetic

How it looks. Weak by construction and **capped as a group**, because a purple gradient and a default icon set are a reason to look closer and never a conclusion.

### The indigo-to-violet default palette

`ae.indigo` · weight **0.45** (weak)

Indigo 500 into violet, on slate. Not a design decision so much as a statistical average of every Tailwind tutorial written between 2019 and 2024.

### Untouched component-kit defaults

`ae.shadcn_defaults` · weight **0.4** (weak)

rounded-2xl, shadow-lg, p-6, pill buttons, cards nested inside cards, every radius identical.

### The coloured left-border card

`ae.left_border_card` · weight **0.4** (weak)

A 3-4px accent strip down the left edge of a panel, applied to every callout on the page.

### Default icon set, unchanged

`ae.lucide` · weight **0.35** (weak)

Lucide or Heroicons throughout, because the component kit these tools build on ships them as the default.

### Rigid symmetry

`ae.three_cards` · weight **0.3** (weak)

Exactly three feature cards, 01/02/03 steps, descriptions of near-identical length. Human pages are lumpier because human content is lumpier.

### The default display typeface

`ae.inter_font` · weight **0.3** (weak)

Inter, or its replacements Geist, Poppins, Space Grotesk and Manrope, doing all the typographic work.

### Uniform generous spacing

`ae.uniform_whitespace` · weight **0.25** (weak)

The same large vertical padding on every section: the safest way to guarantee a responsive layout never overlaps.

### No real photography

`ae.no_real_images` · weight **0.25** (weak)

Gradients, abstract shapes and generated faces instead of pictures of anything that exists.


## Human authorship

Evidence of a human having been present: outside context a generator has no access to, inconsistency, accretion, mess. These subtract from the score and are weighted as heavily as the signals pointing the other way.

### Comments carry outside context

`hu.why_comments` · weight **1** (strong)

References to a standard, an audit, an outage or a decision. This is knowledge the code cannot contain and a generator has no access to.

### References to tickets, issues, dates or people

`hu.ticket_refs` · weight **0.9** (moderate)

Links into a tracker, issue numbers, dated notes, names of colleagues.

### Informal, exasperated or profane commentary

`hu.informal` · weight **0.9** (moderate)

HACK, XXX, "do not touch this", "no idea why this works". Models are relentlessly polite.

### Built on a classic CMS or site builder

`hu.cms` · weight **0.7** (moderate)

WordPress, Wix, Squarespace, Webflow, Shopify. These predate the current generation of tools and are a different phenomenon entirely, not evidence of it.

### Inconsistent formatting

`hu.inconsistent_format` · weight **0.6** (moderate)

Mixed tabs and spaces, drifting indentation, irregular line lengths. Small inconsistency is the human default.

### Copy with specifics

`hu.long_tail_copy` · weight **0.5** (weak)

Real prices, dates, addresses, named people, opening hours: details that had to come from somewhere.

### Commented-out code left behind

`hu.commented_code` · weight **0.5** (weak)

Old implementations kept around just in case, which generators do not produce.

### Older or hand-rolled stack

`hu.legacy_stack` · weight **0.5** (weak)

jQuery, table layouts, hand-written includes, vendor-prefixed CSS: an accretion history rather than a single generation.

### Abbreviated, idiomatic identifiers

`hu.abbrevs` · weight **0.4** (weak)

authSvc, cfg, mgr, activeSub. Shorthand that assumes a reader who already has the context.

### Output of a real build pipeline

`hu.build_stripped` · weight **0.4** (weak)

Minified, comment-stripped, content-hashed assets: someone set up a toolchain and ran it.

### Real and varied media

`hu.real_media` · weight **0.4** (weak)

Photographs at irregular sizes from irregular sources, rather than one generated hero image.


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
