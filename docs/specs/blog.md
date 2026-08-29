# Blog

An index, a post, and a feed. Canonical. Owns `/blog`, `/blog/{slug}`,
`/blog.atom`, `blog_post`, and the EasyAdmin CRUD behind them.

Built 2026-08-29, MVP by request.

## 1. Why it exists

Everything this project publishes today is either a page that describes what
the site IS (`/about`, `/terms`) or a machine-generated list of what changed
(`/changelog`, `/roadmap`). There was nowhere to say "here is what happened
this month and what we think about it", which is the only writing that turns a
tool into a thing people follow.

## 2. What a post is

`App\Blog\Entity\BlogPost`, `Version20260829090000`.

Title, slug, locale, an optional lede, a body, a status, and the date it first
went live. Nothing else.

**The body is the same restricted markdown the bug desk uses**
(`App\Support\BugMarkdown`). Six rules: bold, italic, inline code, fenced code,
bullet lists, numbered lists. Not a shortcut: it means no second sanitiser
profile, no second allowlist and no second place to get wrong. It also means a
post cannot embed an image or an external link, which for a first blog is a
feature rather than a limit.

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
and the index says so once at the top. That is a smaller lie than an empty page
and a much smaller one than a blog nobody updates. A Dutch reader following a
link to an English-only post reads it rather than meeting a 404.

**A Dutch post is a post, not a translation.** Locale is a column and each row
stands alone, so a Dutch-only post is normal. `translation_of` links a pair
when one exists, and a post that has a sibling offers it.

Widening this is a one-line change plus the copy to fill it. The constraint is
people, not code.

## 4. Reads

`App\Blog\BlogRepository`. Four queries: the index page, its count, one post by
slug, and the siblings of a post. All of them go through one private
`liveQuery()` so the filter cannot drift between them.

The index orders by `published_at DESC` then `id DESC`: two posts published the
same day would otherwise swap places between page loads.

## 5. Pages

`/blog` and `/blog/{slug}`, locale-prefixed like every other page, in the
ordinary page shape: dark `.lhero`, then the body. `/blog.atom` alongside,
next to the changelog's feed and for the same reason.

**One feed, not one per language.** A reader subscribes once and people share
the URL; five per-locale feeds would split one small readership five ways.
Which posts a subscriber gets follows the `Accept-Language` they send, the feed
declares it in `xml:lang`, and the response carries `Vary: Accept-Language`.

Entries carry the lede, not the body: a feed is a standfirst and a link.

### The archive beside a post

*(Added 2026-08-29.)* A post page carries the other posts in a sticky right
column. Somebody who has just finished one is the likeliest person on the site
to read a second, and the index is a click most of them will not make.

`BlogRepository::othersThan()` excludes the post being read: a list of "other
posts" that includes the page you are on wastes the top slot, which is the only
one most people look at.

Below 1000px the two columns stack and the archive follows the post rather than
preceding it. On a phone somebody came to read this one.

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
* **Images in a post.** The restricted markdown has no image rule, so this
  would mean the media pipeline, alt text, and a second sanitiser profile.
* **Categories and tags.** With four posts they are furniture.
* **Scheduling.** Publish is a person deciding it is finished.

## 8. Deploy prerequisite

`Version20260829090000` creates `blog_post`. Nothing else.
