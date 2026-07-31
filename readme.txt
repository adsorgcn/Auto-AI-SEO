=== Auto-AI-SEO ===
Contributors: adsorgcn
Tags: seo, ai, alt text, structured data, internal links
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

SEO that understands your content instead of matching patterns: AI-written descriptions, image alt text and internal links, in any language.

== Description ==

Google's own documentation says a good page description "summarizes the whole page", that link text should be descriptive enough to make sense on its own, and that content should leave a reader feeling they achieved their goal. None of those can be satisfied by pattern matching — they all require understanding the page.

Auto-AI-SEO reads your content and does the work:

* **Page descriptions** written after reading the whole post, not cut from the first 160 characters. Posts that already have a good description are left alone.
* **Image alt text** based on what each image actually shows, so every image gets its own description instead of the post title repeated.
* **Internal link suggestions** with link text you could read out of context and still know where it goes. Suggestions only — nothing touches published content until you approve it.
* **Descriptions for category and tag pages**, the ones nobody gets around to.
* **Broken links that are genuinely broken**, separated from bot-protection responses and redirects.
* **Filenames for pasted screenshots.** Paste an image into the editor and it is saved under a descriptive English filename with alt text — not image-3.png. Files you named yourself are recognized and left alone.
* **The mechanical work too**: canonical tags, structured data Google still supports, robots rules and outbound link attributes — each behind its own switch.

= Built for any language =

A model that understands your content understands it in any language and writes in the language your site already uses. Same code, every language.

= Costs are always visible =

Every batch shows an estimate before it starts, tracks real usage as it runs, and stops at the daily limit you set.

= Never blocks publishing =

If the AI is slow, unavailable or unsure, the plugin steps aside and WordPress behaves exactly as it would without it. Everything it writes can be reverted.

= External service =

This plugin requires an API key for an OpenAI-compatible AI service. By default it calls SiliconFlow (https://siliconflow.cn); any compatible provider can be used instead.

What is sent, and when: the text of the post or term being processed, and — for image alt text — the image itself. Only when you start a task or publish a post with the relevant feature enabled. Nothing is transmitted until you enter your own API key.

SiliconFlow terms of service: https://docs.siliconflow.cn/en/legals/terms-of-service
SiliconFlow privacy policy: https://docs.siliconflow.cn/en/legals/privacy-policy

The author's SiliconFlow referral link appears on the settings screen. Signing up through it grants bonus credits to both the new user and the author. Using it is optional; a key from any compatible provider works identically.

This plugin bundles the Action Scheduler library (https://actionscheduler.org, GPLv3) for background processing.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Auto-AI-SEO and enter your API key.
3. Pick a task and start it. Nothing runs on its own.

== Frequently Asked Questions ==

= Does it change my published content? =

Page descriptions and image alt text are written for you and can be reverted. Internal links are only ever suggestions you approve one by one.

= What happens on a slow or restricted host? =

The plugin detects what your server can actually do and sizes its batches accordingly, rather than trusting reported PHP limits. Work continues across requests, so a web timeout cannot stop a batch. WP-CLI is supported for the fastest path.

= Can I use a provider other than the default? =

Yes. Any OpenAI-compatible endpoint works. Only HTTPS endpoints are accepted.

= My theme (or SEO plugin) already handles descriptions. Can they work together? =

Yes, at either level. On the settings screen you can turn off the plugin's own description tag, JSON-LD and canonicals individually. For deeper integration, three filters are provided: `aaseo_description_meta_key` and `aaseo_term_description_meta_key` change where descriptions are stored — point them at your theme's own SEO field and the theme displays what the AI writes; `aaseo_output_description_tag` suppresses the plugin's tag programmatically.

= There are many suggestions. Do I have to click them one by one? =

No. The internal-link review screen has select-all with bulk approve and bulk reject; every applied link stays individually revertible.

== Changelog ==

= 1.0.0 =
* Image alt text: vision-based description of what each image actually shows, written in the site language, validated and repaired rather than discarded.
* Meta descriptions: judge-then-write for posts, pages, categories and tags — descriptions that are already good are kept, not overwritten.
* Internal links: reviewed suggestions with select-all bulk approve/reject; nothing is applied without approval and every applied link can be reverted.
* Broken-link audit: tells genuinely dead targets apart from bot walls, rate limits and soft redirects before flagging anything.
* Robots & indexing: additive robots.txt rules and noindex headers for redirect endpoints, each behind its own switch.
* Structured data: Article, breadcrumb, site and organization JSON-LD plus canonicals where themes commonly miss them.
* Pasted screenshots get descriptive English filenames and alt text on upload.
* Theme integration filters: aaseo_description_meta_key, aaseo_term_description_meta_key, aaseo_output_description_tag.

= 0.1.0 =
* Framework: background queue, environment probing, model fallback, usage accounting with cost estimates, WP-CLI commands.
