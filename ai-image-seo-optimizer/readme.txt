=== AI Image SEO Optimizer ===
Contributors: shahidirfan
Tags: image seo, alt text, ai, openrouter, media library
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.3.1
License: GPLv2 or later

BYOK AI image SEO for WordPress using OpenRouter and NVIDIA vision models.

== Description ==

AI Image SEO Optimizer helps site owners create useful, contextual image metadata from their own OpenRouter or NVIDIA account. It can generate ALT text, Media Library titles, captions, descriptions, internal keyword phrases, and SEO-friendly filename suggestions.

The plugin preserves human-written metadata by default. Existing image files and URLs are not renamed. Optional physical filename renaming is limited to new uploads, before WordPress creates the attachment URL and thumbnails.

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

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin, or install it from the WordPress.org plugin directory.
2. Activate AI Image SEO Optimizer.
3. Open AI Image SEO > Settings > AI Providers.
4. Add at least one API key and compatible vision model for OpenRouter or NVIDIA.
5. Save the settings, then use Test connection.
6. Review the General and Metadata sections before enabling automatic processing.
7. Start with Dashboard > Images missing ALT text for the safest first bulk run.

== Frequently Asked Questions ==

= Does this plugin send images to an external service? =

Yes, but only when an optimization is requested or automatic processing is enabled. The selected image and limited WordPress context are sent to the configured OpenRouter or NVIDIA API.

= Do I need my own API key? =

Yes. This is a bring-your-own-key plugin. Provider availability, limits, model terms, and charges are controlled by your provider account.

= Will existing image URLs change? =

No. Existing Media Library files receive metadata and filename suggestions only. Optional physical renaming applies only to new uploads before WordPress finalizes the file URL.

= Will the plugin overwrite manual ALT text? =

Not by default. Existing metadata is preserved unless you explicitly enable an overwrite option or use force overwrite for a specific run.

= Can I restore previous metadata? =

Yes, when metadata backup is enabled before the first AI update. Use Restore from Library SEO.

= Does it work with WooCommerce and SEO plugins? =

It can use WooCommerce product details and focus keywords from Yoast SEO, Rank Math, SEOPress, and AIOSEO as optional context when those plugins provide the data.

== Screenshots ==

1. Dashboard with image SEO coverage, background bulk optimization, setup status, and recent activity.
2. AI Provider settings with OpenRouter/NVIDIA routing, masked API keys, model lists, and connection testing.
3. Library SEO view for filtering images, reviewing ALT text and filename suggestions, regenerating metadata, and restoring backups.

== External services ==

This plugin connects to one or both of the following services only after an administrator supplies an API key and requests image processing or enables automatic processing.

= OpenRouter =

Service endpoint: `https://openrouter.ai/api/v1/chat/completions`

Data sent: the selected image as encoded image data; the attachment filename; enabled parent post, page, WooCommerce, and SEO keyword context; the configured prompt, language, and output requirements; and technical request headers including the site URL as the HTTP referrer.

Purpose: send the image and context to the administrator-selected vision model and return generated image metadata.

OpenRouter Terms of Service: https://openrouter.ai/terms

OpenRouter Privacy Policy: https://openrouter.ai/privacy

= NVIDIA =

Service endpoint: `https://integrate.api.nvidia.com/v1/chat/completions`

Data sent: the selected image as encoded image data; the attachment filename; enabled parent post, page, WooCommerce, and SEO keyword context; the configured prompt, language, and output requirements; and technical request headers.

Purpose: send the image and context to the administrator-selected NVIDIA-hosted vision model and return generated image metadata.

NVIDIA Developer Terms of Use: https://developer.nvidia.com/legal/terms

NVIDIA Privacy Policy: https://www.nvidia.com/en-us/about-nvidia/privacy-policy/

== Notes ==
This plugin focuses on image semantics/metadata and safe WordPress integration. It intentionally does not duplicate byte-level image compression/CDN features from dedicated performance plugins.

== Changelog ==

= 1.3.1 =
* Replaced the admin sidebar raster icon with a crisp, purpose-built 20-pixel SVG mark.
* Added explicit sidebar sizing so the icon remains centered and uncropped in active and inactive menu states.

= 1.3.0 =
* Added a distinct branded logo to the WordPress admin menu and plugin dashboard.
* Reduced admin page weight by relying on the standard WordPress asset enqueue system.
* Added WordPress.org branding assets and an external illustrated user guide.
* Improved plugin metadata for WordPress.org compatibility.

= 1.2.1 =
* Previous stable release.
