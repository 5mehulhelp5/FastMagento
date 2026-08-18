# Roadmap — closing the gap with Algolia

> **Nothing on this page is built yet.** It is a plan, not a feature list. Everything FastMagento
> actually does today is in the [README](../README.md) and [CHANGELOG](../CHANGELOG.md); if a
> capability is described there, it ships, and if it is only described here, it does not.

FastMagento already matches Algolia on the two hardest things: **sub-second serving from a search
index** and **relevance** (searchable attributes, custom ranking, typo tolerance, symmetric
synonyms, AI thesaurus and per-product keyword discovery). What Algolia has that we do not is the
layer *above* relevance — the parts that learn from shoppers and let a merchandiser intervene:

| | Algolia | FastMagento today |
|---|---|---|
| Search analytics | ✅ | ✖ |
| Personalisation | ✅ | ✖ |
| Recommendations | ✅ (Recommend) | ✖ |
| Merchandising rules / campaigns | ✅ (Rules) | ✖ |
| Fitment-aware curation (size / vehicle / tyre size) | ✖ | ✖ — **the gap worth owning**, see §4 |
| Query suggestions | ✅ | ✖ |
| A/B testing relevance | ✅ | ✖ |

All four gaps share one missing primitive, which is why the order below matters.

---

## The prerequisite: an event pipeline

**Nothing else on this page can be built without it.** Analytics, personalisation, recommendations
and rule performance are all functions of the same three events:

```
search   (query, filters, result ids, result count, store, session)
click    (query → product, position)
convert  (add-to-cart / order → product, attributable to a query)
```

Design constraints that are non-negotiable here:

- **Off the request path.** Events post to a dedicated endpoint and land in a queue, never in the
  render. The whole point of this extension is query count per page; an analytics write that blocks
  a PDP would be self-defeating.
- **FPC-safe.** Page output cannot vary per session, so events are emitted client-side from the
  existing dependency-free bundle (`view/frontend/web/js/fastmagento.js`), which already boots on
  every theme without jQuery or RequireJS.
- **Anonymous by default.** A rotating session id, not a customer id, unless the shopper is logged
  in and the merchant has opted in. GDPR is a feature requirement, not a footnote.
- **Its own index.** `{prefix}_events`, time-partitioned, with a retention policy — never in the
  product index.

Existing seams: `Controller/Search/Instant.php` already sees every query, its filters and its result
set, which is most of a `search` event for free.

---

## 1. Search analytics

*What a merchant cannot see today: what shoppers actually type.*

- **Top queries**, with result counts and click-through rate.
- **Zero-result queries** — the single highest-value report in search. Each row is a product gap or
  a missing synonym, and we can act on it: feed it straight into the AI thesaurus generator that
  already exists (`Model/Ai/ThesaurusGenerator.php`) as a "fix this query" suggestion.
- **Low-CTR queries** — results returned, nothing clicked. Relevance is wrong even though the query
  "worked", which no error log will ever tell you.
- **Click position distribution** — are shoppers clicking result 1 or result 19?
- **Conversion per query**, and revenue attributable to search.

Delivery: an admin dashboard alongside the existing **Extension Efficiency Monitor**
(`Block/Adminhtml/Efficiency/Dashboard.php`), which already establishes the pattern for a
FastMagento reporting screen. Aggregation runs on cron into a rollup index, so opening the report is
never an expensive query.

`fastmagento:doctor` should grow a check here too: events configured but none received in N days is
exactly the kind of silent failure the doctor exists to catch.

## 2. Merchandising rules & campaign-targeted results

*The feature merchandisers ask for by name, and the one that pays for itself fastest.*

A rule is **condition → effect**, evaluated at query time:

| Condition | Effect |
|---|---|
| Query matches (exact / contains / pattern) | **Pin** a product to position N |
| Category / landing page | **Boost** or **bury** products or attribute values |
| Date range (campaign windows) | **Hide** products |
| Customer group or segment | **Inject** a banner into the results |
| Facet state | **Swap** the ranking strategy |

Why this is cheap for us specifically: it is almost entirely a **query-builder concern**, and the
query builder is already isolated in `Model/Search/InstantSearch::buildQuery()` with ranking knobs
centralised in `Model/Search/RelevanceConfig`. Pinning is a `function_score`/`rescore` clause;
burying is a negative boost; hiding is a filter. No new serving architecture.

Scheduling is what makes it a *campaign* tool rather than a config page: a Black Friday rule that
activates and expires on its own, previewable before it goes live ("show me results as they will
look on 28 Nov").

## 3. Recommendations

Four models, in the order their value-to-effort ratio favours:

1. **Frequently bought together** — from order history, computed on cron. No AI required; a
   co-occurrence matrix over `sales_order_item` is enough, and it is the highest-converting
   placement on a cart page.
2. **Related / "customers also viewed"** — from the event pipeline's co-view data.
3. **Trending** — rolling-window popularity, with a decay so last month's hit does not sit there
   forever.
4. **Similar items** — vector similarity over product content. This is where the existing Claude
   integration earns its place, and where an embedding field in the product index would go.

These slot into the placements FastMagento *already serves from OpenSearch* — the related, up-sell
and cross-sell blocks and slider widgets (`Plugin/LinkProductCollectionPlugin`). A recommendation
model becomes another id source feeding the same hydration path, so the rendering, theming and
query-count characteristics are already solved.

## 4. Personalisation — per-shopper curation, and where we can beat Algolia

Deliberately last, because it is the easiest to do badly — but it is also the item with the most
headroom, because Algolia's version is deliberately generic and ours does not have to be.

### What Algolia actually does

From their documentation: personalisation "refines it by changing the order of pre-sorted results
to promote more relevant results to the individual user" — it **re-ranks, it never filters** — and
it is applied *after* textual and business relevance but *before* Rules, with a
`personalizationImpact` dial controlling how hard it pushes. (It is also not on every plan.) The
per-facet weighting internals are not published, so treat the comparison below as being against
observable behaviour, not against their source.

The shape of it: affinities for **facet values** — categories, brands, the filters you nominate —
learned from click and conversion events, used to nudge ranking.

That is a good general-purpose model. It is also a *preference* model, and it has no concept of
whether a product is even **valid** for the shopper. For an apparel store that is fine. For a
fitment catalogue — vehicle parts, tyres, sizing — it leaves the most valuable signal on the table.

### The distinction worth building on

A shopper's preferences and a shopper's constraints are different things and deserve different
treatment:

| | Example | Confidence | Applied as |
|---|---|---|---|
| **Soft affinity** | prefers black; buys mid-range; likes brand X | probabilistic, decays | **boost** |
| **Hard fact** | owns a 2021 RZR Pro XP; wears 32×10R15; waist 34 | stated or purchased | **filter, with the toggle visible** |

Algolia only really has the first row. A part either fits your vehicle or it does not, and burying
a non-fitting part at position 40 is the wrong answer — it should not be there at all, and the
shopper should be able to see and undo that decision ("Showing parts that fit your **2021 RZR Pro
XP** — *show everything*").

That is the differentiator: **a decayed preference model and a hard compatibility model, together.**

### Per-user indexing in OpenSearch

A profile index, `{prefix}_user_profiles`, one document per shopper — customer id when logged in, a
rotating anonymous id otherwise:

```jsonc
{
  "profile_id": "…",
  "updated_at": "2026-08-18T…",

  // learned, decayed — these BOOST
  "affinities": {
    "color":     [ { "value": "black",  "w": 0.82 }, { "value": "od-green", "w": 0.31 } ],
    "size":      [ { "value": "L",      "w": 0.74 } ],
    "brand":     [ { "value": "…",      "w": 0.55 } ],
    "price_band":{ "p50": 180, "p90": 420 }
  },

  // stated or purchased — these may FILTER, with the toggle shown
  "facts": {
    "vehicle":   [ "polaris-rzr-pro-xp-2021" ],
    "tire_size": [ "32x10r15" ],
    "waist":     [ "34" ]
  },

  // for k-NN: the shopper as a point in the same space as the products
  "preference_vector": [ … ]
}
```

Query time is one `get` by id — cached per request — feeding clauses into the query builder that
already exists (`Model/Search/InstantSearch::buildQuery()`):

- affinities → `function_score` / `should` boosts, exactly the re-rank-don't-filter rule above;
- facts → a `filter` clause **only** when the storefront is showing the shopper that it is on;
- `preference_vector` → OpenSearch native **k-NN**, so "more like what this person buys" is a
  vector search rather than a hand-built rule.

That last point is a real architectural advantage and worth being explicit about: **k-NN is in the
engine we already run.** Algolia's equivalent is a separate product on a separate plan; for us it
is a field on an index we already maintain, hydrated by the same indexer pipeline as everything
else (`fastmagento_product`).

### Where AI earns its place

Not "sprinkle an LLM on it" — four jobs it is genuinely better at than a rule, all of which reuse
the Claude integration already wired up for the thesaurus and keyword layers:

1. **Normalising messy attribute values into affinity keys.** `32x10R15`, `32x10-15`, `32/10/15`
   and `32 x 10 x 15` are one tyre. Without this the profile fragments into four weak signals
   instead of one strong one. This is the same problem the existing thesaurus generator already
   solves for query terms — the machinery exists.
2. **Inferring fitment where the catalogue has no structured data.** Most real catalogues describe
   fitment in prose ("fits 2020–2023 RZR Pro XP / Turbo R") and never as an attribute. Extracting
   that into a structured, indexable field is exactly the job `fm_search_keywords` already does for
   buyer vocabulary, run against a different target.
3. **Cold start.** A first-time visitor who searches "rzr pro xp doors" has told you their vehicle
   before they have bought anything. One search should be enough to seed `facts.vehicle` — with a
   lower confidence than a purchase, and shown to them so they can correct it.
4. **Explaining it.** "Because it fits your RZR and you usually buy black." Personalisation that
   cannot explain itself reads as a bug to the shopper and is unauditable for the merchant.

### It applies to every surface, not just search

Personalisation that only touches the search results page is a demo. The shopper meets products in
a dozen places and a non-fitting part is just as wrong in an up-sell as it is in a search result —
arguably more so, because an up-sell is the store actively recommending it.

The profile therefore belongs in **one query decorator applied at every id-producing surface**, all
of which FastMagento already owns:

| Surface | Where it plugs in today |
|---|---|
| Search results | `Model/Search/InstantSearch::buildQuery()` |
| Category listings (PLP) | `Model/ResourceModel/Fulltext/Collection` |
| Related / up-sell / cross-sell | `Plugin/LinkProductCollectionPlugin` |
| Product sliders & widgets | `Plugin/FrontendProductCollectionPlugin` |
| Recommendations | new, from §3 — same hydration path |
| GraphQL | the existing GraphQL serving layer |

Every one of those already resolves an id list out of OpenSearch and hydrates it through the same
pipeline. Personalisation is a set of clauses added to the id-producing query, so it lands
everywhere at once rather than being reimplemented per block. Build it as a decorator with one
entry point, not as a feature of search.

The ordering rules differ by surface and should be explicit:

- **Fitment (`facts`) applies everywhere, hard.** An up-sell for a part that does not fit the
  shopper's vehicle is worse than no up-sell.
- **Affinity boosts apply everywhere, softly** — with the merchant's own merchandising (§2) still
  winning, exactly as Algolia orders it: relevance, then personalisation, then Rules.
- **Link blocks keep the merchant's intent.** Related products are a curated set; personalisation
  may **re-order** that set and drop non-fitting members, but it must not invent members the
  merchant never linked. Recommendations (§3) are where new products may be introduced.

### The cache constraint this creates

Related, up-sell and cross-sell blocks render **inside the page HTML**, which is Full-Page-Cache /
Varnish territory. Personalising them naively would either poison the cache for every other shopper
or force the page uncacheable — and this extension exists to make pages cheap, so neither is
acceptable.

Two workable routes, to be decided before any of this is built:

1. **Client-side hydration** — the block renders a cached, non-personalised skeleton and the
   existing dependency-free bundle swaps in a personalised set from a JSON endpoint. Same pattern
   the instant-search grid already uses, and it keeps the page fully cacheable.
2. **Segment-keyed cache** — vary the cache key by a coarse segment (vehicle, size bracket) rather
   than by individual. Far fewer cache variants than per-shopper, and for fitment specifically a
   segment *is* the useful granularity.

Per-shopper cache variants are the obvious third option and are the wrong answer at any real
traffic level; they are listed here only so nobody proposes them later.

### Rules this must obey

- **Boost by default; filter only when visible and reversible.** A shopper who cannot see why
  results changed, or cannot turn it off, files a complaint nobody can reproduce.
- **Facts beat affinities, and stated beats inferred.** If they told you their vehicle, that wins
  over anything a model guessed.
- **Decay.** Last summer's tyre size is not this summer's. Affinities age; facts persist until
  contradicted.
- **Strictly opt-in**, per store view, off by default, with the profile viewable and clearable by
  the shopper. Storing "owns a 2021 RZR" against a person is personal data and gets treated that
  way.
- **Measurable or it does not ship.** Personalised vs control has to be comparable, which is why
  A/B testing below is a dependency rather than a nice-to-have.

## 5. Supporting features

- **Query suggestions** — a popular-queries index powering the autocomplete's suggestion row.
  Currently autocomplete suggests products and categories; this adds "what other people searched".
- **A/B testing** — two relevance configurations, traffic split by session hash, judged on the
  conversion metric analytics already collects. Needed to make claims about 3 and 4 honest.
- **Dynamic re-ranking** — feed click and conversion signals back into ranking automatically, so
  relevance improves without anyone touching a config page.

---

## Sequencing

```
Event pipeline  ──┬──> Analytics ──> A/B testing ──┐
                  │                                 ├──> Personalisation ──> applied at EVERY
                  ├──> Recommendations ─────────────┘                        surface via one
                  │                                                          query decorator
Rules & campaigns ┘   (independent — can ship first)
```

Personalisation splits into two tracks that can move independently, and the second is the one worth
starting early:

- **Affinity track** (colour, brand, price band) needs the event pipeline first.
- **Fitment track** (vehicle, tyre size, sizing) does **not**. It can be seeded from order history
  and from a "your garage" prompt on day one, and it is the half that Algolia has no answer for.
  It is also the half a fitment retailer will notice immediately.

**Merchandising rules do not depend on the event pipeline** and are the fastest visible win, so they
can ship in parallel with, or ahead of, the analytics work. Everything else queues behind events.

## Principles these must not break

The reason to build this inside FastMagento rather than bolt on a SaaS is that the serving layer is
already here. That advantage is lost the moment any of the following slips:

- **Query count per page must not rise.** Every feature here is either off the request path (cron,
  queue) or a clause on a query already being made.
- **Fall back to native, always.** Same rule as the rest of the module: an outage, a missing index
  or an unservable case degrades to current behaviour rather than an error.
- **FPC/Varnish-safe.** No page output that varies per session without an explicit, cache-aware
  mechanism.
- **No theme lock-in.** Verified on Hyvä, Luma and Breeze from one dependency-free bundle
  (see [THEME-COMPATIBILITY.md](THEME-COMPATIBILITY.md)) — new storefront JS keeps that property.
- **Diagnosable.** If it can silently not work, `fastmagento:doctor` gets a check for it. That is
  the standing rule in this codebase and it applies to every item above.
