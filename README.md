# Auto-AI-SEO

**SEO that understands your content — not just matches it.**

[English] | [简体中文](README.zh-CN.md)

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net/)
[![Works in any language](https://img.shields.io/badge/Languages-any-orange.svg)](#built-multilingual-from-day-one)

---

## Start with what Google actually says

Google's own documentation has been telling us the answer for years. Most SEO tools still aren't listening.

> **On page descriptions** — a good description *"summarizes the whole page."*
> If yours doesn't, Google ignores it and writes its own.
> — [Control your snippets in Search results](https://developers.google.com/search/docs/appearance/snippet)

> **On link text** — *"Good anchor text is descriptive, reasonably concise, and relevant."*
> The test: read the anchor text alone, out of context. Does it still tell you where it goes?
> — [Make your links crawlable](https://developers.google.com/search/docs/crawling-indexing/links-crawlable)

> **On content quality** — *"After reading your content, will someone leave feeling they've learned enough about a topic to help achieve their goal?"*
> — [Creating helpful, reliable, people-first content](https://developers.google.com/search/docs/fundamentals/creating-helpful-content)

Read those three sentences again. **Not one of them can be satisfied by pattern matching.**
Every one of them requires actually understanding the page.

That is the entire premise of this plugin.

---

## The problem with SEO plugins

Open any established SEO plugin and you get the same thing: a dashboard of red and yellow lights. *Your description is too short. Your keyword density is 0.4%. You have 14 issues.*

It found the problems. Now you fix them. Times every post you've ever written.

That model made sense when SEO was a checklist. It doesn't anymore — and it never scaled. The traffic-light dashboard is not a solution, it's a **to-do list generator**. The work was always the hard part, and the tool hands all of it back to you.

Auto-AI-SEO takes the opposite position:

> **If a feature leaves you staring at a long list of things to do, that feature isn't finished.**

An AI that only *identifies* work has done half a job. The point is to absorb the judgment, do the work, and tell you what changed.

---

## What it does

**Writes page descriptions that summarize the page.** Not the first 160 characters of your body text with a truncated sentence and a typo in it — an actual summary, written after reading the whole thing. Posts that already have a good description are left alone.

**Looks at your images and describes what's in them.** Every image gets its own description based on what it actually shows. Not your post title copied into all seven images on the page. Filenames and templates never made an image searchable; describing the image does.

**Suggests internal links a human editor would suggest.** With link text you could read out of context and still know where it goes — *"the Japanese cloud storage service with WebDAV support"*, not *"cloud storage"*. Suggestions only. Nothing touches your published content until you approve it, one by one.

**Writes descriptions for your category and tag pages.** The pages nobody ever gets around to. Hundreds of them, done.

**Finds the links that are actually broken.** Not the ones that returned a bot-protection error, not the ones that redirected, not the ones pointing at posts you deleted years ago. The ones that are genuinely dead — with a suggestion for what to replace them with.

**Handles the mechanical work properly too.** Canonical tags, structured data that Google still supports, robots rules, outbound link attributes, crawl directives. No AI needed here — just done correctly, and kept current as Google's guidance changes.

---

## Built for the answer era

Search is no longer a list of ten links you compete to top. Increasingly, it is an answer — and the question that decides your traffic is no longer *"does this page rank?"* but:

> **Can a machine understand this page well enough to use it as the answer?**

Everything in this plugin serves that one question. Descriptions that genuinely summarize. Link text that states relationships in plain language. Image descriptions with real content in them. Structured data that says what a page is. None of it is keyword games — all of it is making your content legible to machines that are trying to understand rather than index.

The tools built for the ranking era optimized for a world that is quietly being replaced. **We are building for the one that's arriving.**

---

## Built multilingual from day one

Most SEO tools are English-first, with other languages handled by whatever rules someone bolted on later. Romanization, transliteration, per-language dictionaries — every language is a separate engineering project.

Understanding doesn't work that way. A model that comprehends your content comprehends it in Chinese, Arabic, Russian, Japanese or Spanish, and writes in the language your site is already in. **Same code, every language, no extra work.**

This is not a roadmap item. It is a structural consequence of the approach — and something rule-based tools cannot retrofit.

---

## Getting started

1. Install and activate the plugin
2. Add an API key from any OpenAI-compatible AI provider — [SiliconFlow](https://cloud.siliconflow.cn/i/tJXyk0DQ) is the default, and signing up through that link grants **bonus credits to both you and this project**
3. Open the settings screen and run a connection test
4. Nothing runs on its own. You choose what to work on and when.

**You always know what it costs.** Every batch shows an estimate before it starts, tracks real usage as it runs, and stops at the daily limit you set. No surprises on your bill.

**It never blocks publishing.** If the AI is slow, unavailable, or unsure, the plugin steps aside and WordPress behaves exactly as it would without it. Everything it writes can be reverted.

---

## Design principles

**Understand, don't match.** Every step is comprehension first, action second. There is no template, no truncation, no keyword table anywhere in this plugin.

**Restraint over volume.** Two internal links that genuinely belong beat eight that almost fit. Google's own guidance on how many links a page should have is *"there's no magical ideal number — if you think it's too much, then it probably is."* We agree. An empty field is better than a bad one.

**Assist, don't replace.** This is a tool for people who write their own content and want the mechanical parts handled. It is not a content generator, and it does not pretend the work isn't yours.

---

## License

GPL-3.0-or-later (the package bundles Action Scheduler, which is GPLv3). The original code outside `libraries/` is additionally available under the MIT License — see LICENSE-MIT.txt. Contributions welcome.

---

*Built by [静水流深 / adsorgcn](https://github.com/adsorgcn) — building the internet across borders since 1999. Build with blocks, don't reinvent wheels.*
