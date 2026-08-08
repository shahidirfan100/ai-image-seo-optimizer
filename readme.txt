=== AI Image SEO Optimizer ===
Contributors: shahidirfan
Tags: image seo, alt text, ai, openrouter, nvidia, woocommerce, media library
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

BYOK AI image SEO for WordPress using OpenRouter and NVIDIA vision models.

== Features ==
* Generate ALT text, Media Library title, caption, description and internal keyword phrases.
* SEO-friendly filename suggestion for every processed image.
* Optional SAFE filename renaming for NEW uploads only, before WordPress finalizes the upload.
* Automatic processing for new image uploads.
* Bulk queue for existing Media Library images, all images, missing ALT only, or failed jobs.
* Action Scheduler integration when available; WP-Cron fallback otherwise.
* WooCommerce product context (name, SKU, categories, short description).
* Focus keyword context from Yoast SEO, Rank Math, SEOPress and AIOSEO when available.
* OpenRouter and NVIDIA provider modes with key/model rotation and provider failover.
* Multiple API keys and multiple vision model IDs.
* Encrypted API key storage when OpenSSL is available.
* Per-image regenerate and restore-original-metadata actions.
* Decorative image flag that deliberately keeps alt="" and skips AI.
* Front-end ALT synchronization for WordPress attachment images and core/image blocks.
* Logs, status counts and dashboard.
* Does not physically rename existing media, avoiding broken URLs and references.

== Privacy ==
When AI generation is requested, image data and limited contextual metadata are sent to the selected external AI provider (OpenRouter and/or NVIDIA). Review the provider's terms and privacy policy before enabling the feature.

== Notes ==
This plugin focuses on image semantics/metadata and safe WordPress integration. It intentionally does not duplicate byte-level image compression/CDN features from dedicated performance plugins.
