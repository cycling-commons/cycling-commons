# Blog

An index, a post, and a feed. Canonical. Owns `/blog`, `/blog/{slug}`,
`/blog.atom`, `blog_post`, and the EasyAdmin CRUD behind them.

## 1. Why it exists

Everything this project publishes today is either a page that describes what
the site IS (`/about`, `/terms`) or a machine-generated list of what changed
(`/whats-new`, `/roadmap`). There was nowhere to say "here is what happened
this month and what we think about it", which is the only writing that turns a
tool into a thing people follow.

**Writing one.** [../blog-post-prompt.md](../blog-post-prompt.md) is the
prompt every post is written with, by a person or a model: who reads it, how
it sounds, its shape and the markup below. English first; the Dutch post
follows to the same rules.

## 2. What a post is

`App\Blog\Entity\BlogPost`, `Version20260829090000`.

Title, slug, locale, an optional lede, a body, an illustration, the invitation
beside it (§5), a status, and the date it first went live. Nothing else.

**The body is the same restricted markdown the bug desk uses**
(`App\Support\BugMarkdown`). Six rules: bold, italic, inline code, fenced code,
bullet lists, numbered lists. Not a shortcut: it means no second sanitiser
profile, no second allowlist and no second place to get wrong. It also means a
post cannot embed an image or an external link, which for a first blog is a
feature rather than a limit; the one exception is the link rule below. A body holds up to 20000 characters
(`BlogPost::BODY_MAX`), and the post page passes that limit to the renderer,
so all of it is published; a bug report keeps the renderer's own 8000.

**A bold line on its own is a section title** (`|blog_headings`,
`App\Twig\BlogBodyExtension`, 2026-10-25): a paragraph that is one bold
line and nothing else becomes `<h2 class="bh">`, close above the text it
heads. It runs on the renderer's sanitised output and only renames the tags
around plain text, so the markdown keeps its six rules and its one
allowlist. The post's first paragraph is its opening: larger, in the
brand's spruce (`post.css`).

**A link is `[words](address)`** (`|blog_links`, `App\Twig\BlogBodyExtension`):
the address is a path on the site (`/map`, `/nl/licenties`), opened in the
same tab, or an `https://` address, opened in a new tab with
`rel="noopener"`; an `https://cyclingcommons.org` address counts as the site.
Any other scheme, `http://` and `//host` stay text, and so does a link inside
code. Like the titles, it runs on the sanitised output, so a quote a writer
types arrives as an entity and cannot leave the `href`. Every address in a post
is written as a link with words that say where it goes, never as a bare
address (`docs/blog-post-prompt.md`).

**One image per post, always in the same place** (`blog_post.illustration`,
`App\Blog\BlogIllustrations`, `Version20261025030000`): the first thing in
the post, the post's own width, and beside the title on the index. A post
names it in the admin; it is never written in the body. It is either a
drawing (an SVG in `templates/blog/illustrations/`, coloured with the site's
tokens, hidden from screen readers because the title and text carry the
meaning) or an image file in `assets/images/blog/`, such as a screenshot of a
real route, with alt text `blog.image_alt.<name>` in every language. Both live
in the repository: nothing is uploaded, so no image is decoded on a web host,
and a new image file reaches production with a deploy. An unknown name is
stored as none, so a stored value never becomes a template or file path.

**Two statuses**, `BlogStatus`. Draft or published. Scheduling, review states
and expiry are all real and all somebody's second problem.

**A draft is a 404**, everywhere: not on the index, not by its own URL, not in
the feed. A 404 and not a 403, because a 403 confirms the slug exists and turns
an unpublished URL into something worth guessing at before it is ready.

**Published means both.** Every public query filters on the status AND a
non-null `published_at`. A row can only be in one of those states by hand, and
"not ready" is the safe reading of a contradiction.
`BlogTest::testAPublishedRowWithNoDateIsStillNotPublic` pins it.

**The date is stamped once.** Withdrawing a post keeps it; republishing does
not restamp. Otherwise pulling a post and putting it back would jump it to the
top of the index and misdate it for everybody who already read it.

## 3. Two languages, five readable

`App\Blog\BlogLocales`: written in `en` and `nl`.

The interface is translated one reviewed string at a time. A post is eight
hundred words of voice, and running that through the same workflow five times
per post is the cost that quietly stops anybody writing the second one.

A reader in French, German or Spanish gets the English posts on their own URL,
and the index says so once at the top, with a link to each of the blog's
languages that this deployment serves, named by the one provider
(`App\Routing\Languages`, dev-environment.md §7 i18n); the admin's post
language offers the same set. That is a smaller lie than an empty page
and a much smaller one than a blog nobody updates. A Dutch reader following a
link to an English-only post reads it rather than meeting a 404.

**A Dutch post is a post, not a translation.** Locale is a column and each row
stands alone, so a Dutch-only post is normal. `translation_of` links a pair
when one exists, and a post that has a sibling offers it.

**Each language reads its own version** (2026-10-25). The language menu and
the hreflang lines of a post name, per language, the version that language
reads (`BlogLocales::resolve()`): Dutch the Dutch sibling, French, German and
Spanish the English one, else the post itself. The post sets them per
language in a request attribute (`LocaleExtension::PARAMS_ATTRIBUTE`), as a
slug differs per version. An address of the other version leads a reader to
theirs: `/nl/blog/<english slug>` redirects to the Dutch sibling, and an
English address of a Dutch slug to the English one. A post with no version in
the reader's language is read as it is.

Widening this is a one-line change plus the copy to fill it. The constraint is
people, not code.

## 4. Reads

`App\Blog\BlogRepository`. The queries for one language (the index page, its
count, one post by slug, the archive beside a post, the feed) all go through
one private `liveQuery()`, so the filter cannot drift between them. The two
that cross languages, the siblings of a post and the sitemap's `liveSlugs()`,
carry the same two conditions, status published and a non-null
`published_at`, written out. The sitemap lists the index and every live post.

The index orders by `published_at DESC` then `id DESC`: two posts published the
same day would otherwise swap places between page loads.

## 5. Pages

`/blog` and `/blog/{slug}`, locale-prefixed like every other page, in the
ordinary page shape: dark `.lhero`, then the body. `/blog.atom` alongside,
next to the changelog's feed.

**One feed per locale prefix**, like every other page: `/blog.atom` is the
English feed, `/nl/blog.atom` the Dutch one, and a language with no posts of
its own (`BlogLocales::resolve()`) serves the English posts. The feed declares
its language in `xml:lang`. Not one feed chosen by `Accept-Language`: a feed
reader fetches from its own server and often sends no such header, so a Dutch
subscriber would get the English posts. The index links the feed of the
locale it is in.

Entries carry the lede, not the body: a feed is a standfirst and a link.

### The archive beside a post

A post page carries the other posts in a sticky right
column. Somebody who has just finished one is the likeliest person on the site
to read a second, and the index is a click most of them will not make.

`BlogRepository::othersThan()` excludes the post being read: a list of "other
posts" that includes the page you are on wastes the top slot, which is the only
one most people look at.

Below 1000px the two columns stack and the archive follows the post rather than
preceding it. On a phone somebody came to read this one.

### The invitation above the archive

The right column opens with one invitation, *Cycling Commons needs you*: a
light panel with a trail-coloured left edge, the drawing of what it asks for
(`build` or `contribute`), a heading, one sentence and one link, above the
archive (`blog.side.*`). A post chooses it in the admin (`blog_post.side_call`,
`App\Blog\BlogSideCall`, `Version20261025040000`):

- **help** (the default) links the help post, `help-build-cycling-commons`
  (`BlogSideCall::HELP_SLUG`), in the reader's language when it has a version
  there.
- **contribute** links the contribute page, for a post written to every rider.

The help post itself and its translations always show **contribute**, so the
link never points at the page it is on; so does every post while the help post
is not published. The rule is `BlogController::helpSlug()`.

## 6. Writing one

`/admin/blog-post`, EasyAdmin, `ROLE_ADMIN`. `admin stays EasyAdmin` is the
standing rule and this is the case it is for: a small CRUD over one table used
by a handful of people.

The slug is made from the title when it is left blank, on create only: changing
a published post's slug breaks every link to it, so that stays a deliberate
act. The author is whoever was signed in.

## 7. Deliberately not built

* **Comments.** There is a contact form, a bug form and a report route, all of
  which reach a person. A comment thread is a moderation surface, and
  `one-way-to-moderate` says a new content type does not get new mechanics.
* **Images inside the body, and uploaded images.** A post has its one image
  (§2); an image anywhere else in the text, or one uploaded through the admin,
  would mean the media pipeline and an image rule in the markdown.
* **Categories and tags.** With four posts they are furniture.
* **Scheduling.** Publish is a person deciding it is finished.

## 8. Deploy prerequisite

`Version20260829090000` creates `blog_post`; `Version20261025030000` adds
`illustration`.
