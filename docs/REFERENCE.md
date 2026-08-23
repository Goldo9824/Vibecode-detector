# Spotting AI-generated web code: the reference this tool implements

A condensed version of the detection reference the detector is built on, kept here
so that every weight in [`lib/Catalog.php`](../lib/Catalog.php) can be traced back to
a stated reason rather than a hunch.

---

## The short version

AI-generated web code is best detected by **pattern-reading, not by automated
detector tools**. The strongest tells are consistency failures: code that is locally
perfect (uniform comments, textbook error handling, tidy imports) but globally
incoherent (the same problem solved several ways, dependency bloat, dead code), plus
visual defaults and the hard fingerprints left by builder platforms.

Automated detectors are unreliable and must never be treated as a verdict. Every
tell is weakening. Treat any single sign as a hint and require four or five
converging signals before concluding anything.

---

## The load-bearing findings

**1. The inversion.** Humans are inconsistent in small ways (formatting, comment
placement) but consistent in architectural approach. Generators are the opposite:
uniform line by line, incoherent across the whole. *"Locally perfect and globally
incoherent."* This single observation drives most of the structural signals.

**2. Comment style is the clearest code-level tell.** AI explains *what*
(`# increment the counter`); humans explain *why* (`# legal required NIST 800-63B
lockout after Q3 audit`). AI comment density is uniform; human comments cluster
around the hard parts. Implemented as `cd.what_comments`, `hu.why_comments` and
`st.uniform_comment_density` — and the why-comment predicate in `lib/Text.php` is
the most important function in the project.

**3. The Purple Problem.** Indigo-to-violet gradients, Inter, three rounded feature
cards. Traced directly to Tailwind UI's `bg-indigo-500` default: models learned the
median of Tailwind tutorials scraped 2019–2024, and that median is purple. It "isn't
a deliberate design decision; it's a statistical average wearing Tailwind class
names." Implemented as `ae.indigo`, and deliberately capped.

**4. Git history is the most robust structural signal** and the hardest to fake
retroactively: giant single "initial commit" dumps followed by tiny "fix typo"
commits, 200+ lines in 15 minutes, commit messages that read like the original
prompt. **This tool cannot see it** — a served page and a pasted file carry no
history — which is why the interface says to look at `git log` first.

**5. Detectors fail on code specifically** because linters and formatters normalise
the statistical signal they rely on. See *Reliability*, below.

**6. The security profile is the most durable family.** Generated code chooses the
insecure option at a rate that has barely moved across model generations, even as
syntax correctness climbed past 95%. Missing ownership checks, placeholder secrets,
tokens without expiry, wide-open CORS. These signals will outlive the stylistic ones.

**7. Builder platforms leave hard fingerprints** — badges, subdomains, injected
scripts, image paths, generator meta tags — that positively identify the tool.
Their absence proves nothing.

---

## The tells, by layer

### HTML

- **Section-label comments** (`<!-- Hero -->`, `<!-- Testimonials -->`) surviving
  in production. Strong, because real build tooling strips HTML comments: finding
  them means the file was deployed exactly as generated. → `st.section_comments`
- **Emoji as a functional icon system** (🚀 performance, 💡 smart, ✨ premium).
  Emoji do not inherit CSS colour, adapt to dark mode or scale cleanly, so this
  means the page never went through design review. → `ct.emoji_icons`
- **Model typography**: curly quotes and heavy em-dash use pasted straight from the
  model. → `cd.typography`
- **Textbook-complete scaffolds** arriving fully formed rather than accreting.
  → `st.full_scaffold`
- Div soup with generic wrapper class names. *Weak* — "container" and "wrapper" are
  decades-old human conventions, and models increasingly emit correct semantic HTML.
  Not implemented as a signal for that reason.

### CSS and design system

- Indigo→violet on slate. → `ae.indigo`
- The **coloured left-border card**, repeatedly cited as the single most reliable
  aesthetic tell. → `ae.left_border_card`
- Untouched component-kit defaults: `rounded-2xl shadow-lg p-6`, pill buttons,
  cards inside cards. → `ae.shadcn_defaults`
- Rigid symmetry: exactly three cards, 01/02/03 steps, descriptions of near-equal
  length. → `ae.three_cards`
- `py-24` everywhere — generous uniform spacing is the safest way to guarantee a
  responsive layout never overlaps. → `ae.uniform_whitespace`
- Inter as the default face, and its designated replacements (Geist, Manrope,
  Poppins, Space Grotesk) as the newer default. → `ae.inter_font`

### JavaScript / TypeScript

- What-not-why comments, uniformly dense. → `cd.what_comments`
- Over-defensive error handling; `catch` blocks that log and continue — the
  blindfold that stops the app crashing so nobody learns it is broken.
  → `cd.blanket_try`, `cd.swallowed_errors`
- Verbose naming (`currentLoggedInUserAuthTokenValue`) or the hurried opposite
  (`data2`, `result_final`, `handleClick2`). → `cd.verbose_names`, `cd.lazy_names`
- Helper-class pile-ups: `Utils`, `Manager`, `Handler`, `ServiceHelper`.
  → `cd.helper_pileup`
- Tests that assert nothing: happy-path only, `true == true` in costume.
  → `cd.empty_tests`
- Dependency bloat and dead code: packages imported and never called, whole
  components wired to nothing. → `st.dead_code`
- Perfectly grouped and alphabetised imports. → `st.import_block_sorted`
- **Emoji in code comments** — reported by reviewers as a near-certain marker:
  *"if the comment has an emoji it's a guarantee."* → `cd.emoji_comments`

### Backend

- A docblock on every function, uniformly formatted, including trivial ones.
  → `st.docblock_on_everything`
- Placeholder secrets as string literals: `"your-secret-key"`, `"change-me"`,
  `"SECRET_KEY_HERE"`. → `se.placeholder_secret`
- `if __name__ == "__main__":` on modules nothing runs directly. → `cd.main_guard`
- Security happy-path gaps that double as detection signals. → `se.weak_auth`,
  `se.insecure_defaults`

### Cross-language

- The same problem solved several ways: three HTTP clients, two date libraries,
  four validation patterns, each from a different prompt session.
  → `st.multiple_solutions`
- Grammatically complete, formal error messages ("The provided email address is not
  in a valid format.") versus terse human ones ("Invalid email"). → `cd.formal_errors`
- Generic placeholder content: the most statistically common names (John Smith,
  Sarah Johnson, Michael Brown, Alex Miller), titles like "Verified User", marketing
  copy that tells rather than shows. → `ct.generic_names`, `ct.marketing_cliche`

### Publishing, rather than authorship

The steps between "it works" and "it is online", which a demo skips and a published
site accumulates. None of these is about how the code was written, which is the
point: they read the *state* a project was left in.

- The build step that never ran: `/@vite/client` in production, `/src/main.tsx`
  served unbundled, a fast-refresh preamble, a `localhost:5173` address still in an
  attribute. → `st.dev_server_page`
- A form with fields, a submit button, and no action, no form service, no handler
  and no request anywhere in the page or its scripts. → `st.form_to_nowhere`
- None of the furniture a shared page acquires — no description, no social card,
  no canonical, no favicon — on a *built* page with real copy. Guarded three ways,
  because absent meta tags are the weakest evidence there is. → `st.no_seo_furniture`
- Everything in one document: tens of kilobytes of markup carrying inline styles and
  inline script, with nothing split out. → `st.single_file_page`
- Still answering on the platform's default subdomain. A nudge, not a finding: a
  great many real projects never move off one. → `st.preview_host`
- Navigation offering top-level pages the server answers with an error. Links rot
  deep in a site, not in the nav of a front page. → `xs.broken_nav_links`

### People, contact and prose

- Testimonial faces served by pravatar, randomuser.me, DiceBear or a placeholder
  host: whoever built it needed a face and took the first one available.
  → `ct.stock_avatars`
- Contact details nobody can reach: `@example.com`, a 555 number, 123 Main Street,
  social links pointing at the platform's home page rather than an account.
  → `ct.placeholder_contact`
- The model's sentence rhythm rather than its vocabulary: "it's not just X, it's Y",
  "in today's fast-paced world", "whether you're X or Y", the three-item list where
  two would do. Copywriters use each of these; they do not use all of them at once.
  → `ct.llm_prose`
- Alt text written as even, complete descriptions of every image. Real alt text is
  uneven, because somebody knew which images mattered. → `ct.model_alt_text`
- Every function within a couple of lines of every other one. A file written over
  months is lumpy. → `cd.uniform_function_length`

Two pull the other way, for the same reason: they are evidence of time passing.
Dated content spread across months or years (`hu.content_dates`), and accessibility
work nobody was asked for — a skip link, a reduced-motion rule, labelled inputs
(`hu.a11y_care`), withheld when the page has already identified itself as a
generator's own output, since component kits ship aria attributes by the hundred.

### Live-site fingerprints (positive ID)

| Platform | What to look for |
|---|---|
| Lovable | `cdn.gpteng.co`, `gptengineer.js`, `/lovable-uploads/`, `lovable-tagger`, `/__l5e/`, `~flock.js`, "Edit with Lovable" |
| Bolt | "Made in Bolt", `bolt.host`, `bolt.new` |
| v0 | "Built with v0", `v0.dev`, `v0-*.vercel.app` |
| Replit | Replit badge, `.repl.co`, `.replit.app` |
| Base44 | `@base44/sdk`, `.base44.app` |
| Any | `<meta name="generator">` naming the builder |

**What proves nothing:** the tech stack itself. React, Vite and Supabase power
enormous amounts of hand-built software — a Supabase key in the page source is
Tuesday, not evidence. No-code builders (Wix, Squarespace, Webflow, WordPress) are a
different phenomenon that predates all of this, which is why `hu.cms` pushes the
score *down*.

---

## Reliability of automated detection

This is the part that shapes the whole product.

- **Pan et al., "Assessing AI Detectors in Identifying AI-Generated Code:
  Implications for Education" (ICSE-SEET 2024)** — 5,069 human-written Python
  solutions, five detectors (GPTZero, Sapling, GPT-2 Output Detector, DetectGPT,
  GLTR). Conclusion: existing detectors "perform poorly in distinguishing between
  human-written code and AI-generated code", accuracy "around 0.5". GPTZero labelled
  almost everything human; the best performer barely exceeded 0.6.
- **AICD Bench (EACL 2026)** — ~2M samples, 77 models. Detector performance "far
  below practical usability"; even a simple SVM performs below random guessing under
  distribution shift, with macro-F1 collapsing from ~0.63 in-domain to ~0.20
  out-of-domain.
- **RAID (Dugan et al., ACL 2024)** — 6M+ generations. Metric-based detectors lost
  36.1% accuracy to synonym swaps and 40.6% on average to homoglyph attacks.
- **Vendor claims** of 90–96% accuracy are self-reported and are contradicted by
  independent peer-reviewed benchmarks on code specifically. Fine-tuned detectors
  are accurate only on the models they were trained on.

The single most authoritative source, where it exists, is enterprise IDE telemetry
(Copilot for Business, Cursor Teams audit logs). Where trust is not adversarial:
ask the developer.

---

## Limits, and how they are encoded

**Convergent evolution.** Tailwind, semantic HTML, Prettier formatting, descriptive
naming and thorough error handling are all modern human best practice. Good human
code looks AI. → aesthetic weights are low and capped; four converging non-aesthetic
signals are required before the verdict language firms up.

**Masking is cheap.** Renaming, comment-stripping, minifying or running an
"AI humaniser" defeats stylistic detection. → minified assets are excluded from
code-level analysis entirely, and the interface says so, rather than scoring noise.
Two things survive a minifier, and both are read: the contents of string literals,
which is where a `className` and a headline live, and a source map, which hands
back the file as it was written. Neither is a way round the limit — a build that
ships no map and a page that renders its text from data are both unreadable at the
code level, and are reported as such.

**A short document is not a short page.** A client-rendered application serves an
empty mount point; its markup is a few hundred bytes and its interface is half a
megabyte of JavaScript. → "thin input" is measured on everything that could be
read, not on the document, so a single-page app is neither damped toward the middle
nor given a confidence it did not earn.

**Deployment is not authorship.** A page served from Vercel or Netlify says nothing
about who wrote it, and both host as much hand-written work as generated. → the
platform is recorded as a fact in `stats` and carries no weight. What is scored
from the response is header hygiene, because a content security policy is something
a person configured.

**Tells are decaying**, but the security profile persists. → `se.*` signals are
weighted to outlast the stylistic ones.

**Signs run in one direction only.** Presence identifies; absence proves nothing.
Agentic tools (Cursor, Claude Code, Windsurf) leave no platform fingerprint at all
because they write into a normal repo. → stated in the notes on every report.

**One occurrence is not a habit.** The tells this reads are habits, and a habit is
visible in how often it fires, not in whether it fired. → every signal carries an
occurrence count, and that count earns up to 1.5× its weight on a log scale, inside
the category ceilings and never for a fingerprint.

**Evidence you cannot check is evidence you have to trust.** A quoted line proves
nothing about the line above it. → every excerpt is published with the code around
it, the document it came from and how many times that pattern was found, with
credential-shaped strings masked in the surroundings as well as the match.

**False-positive harm is real**, especially in academic and hiring contexts, and
falls disproportionately on non-native English writers. → the score is clamped away
from 100, confidence is capped without a fingerprint, and the interface leads with
the warning rather than burying it.

**Authorship ≠ quality.** These signals mostly detect the absence of a human who
understood the system — "comprehension debt" — rather than authorship as such.
Reviewed, tested AI code is just code. The practically important question is
usually *"does anyone understand and can secure this?"*, not *"was a machine
involved?"*

---

## The thresholds that change the call

| Finding | Conclusion |
|---|---|
| A single hard platform fingerprint | Builder-built. Positive ID. |
| 4+ converging code tells, plus boulder-shaped git history | High confidence AI-generated |
| Aesthetic cues only, clean incremental history, human-explicable architecture | **Do not conclude AI.** May be human, or reviewed and owned AI. |
| A detector score with no corroboration | Insufficient. Do not act on it. |

Weight them in this order, always:

> hard platform fingerprints **>** structural and git signals **>** code-style tells
> **>** aesthetic cues **>** detector scores
