# OctoTidy

OctoTidy is a small WordPress plugin MVP for finding Media Library assets that are likely unused.

Tagline: **Media Attachment Verifier**.

It adds **Media > OctoTidy Verifier** in WP Admin. Click **Run Verification** to check common WordPress storage locations for attachment URLs, generated image-size URLs, and common attachment ID references.

## What It Checks

- Post and page content
- Post excerpts
- Post meta, including builder JSON/serialized data
- Term meta
- Comment meta
- Options, excluding transients
- Original attachment URLs and generated image-size files
- Common ID references such as `wp-image-123`, gallery IDs, `attachment_id`, `image_id`, `thumbnail_id`, and serialized equivalents

## Large Libraries (WP-CLI)

The scan reads the database once in small batches, but a big site can still outlast a web request timeout. Run it over SSH at low priority instead:

```sh
nice -n 19 wp octotidy scan
```

Results are saved the same way as a browser run, so review and export them under **Media > OctoTidy Verifier**. Each attachment keeps up to 10 found locations.

## Bulk Cleanup

After a verification run, OctoTidy can move the current **Likely unused** results to the Media Library Trash. The cleanup form requires a checkbox, typing `DELETE`, and a browser confirmation.

## MVP Limits

The report says **Likely unused**, not definitely unused. This first version does not crawl rendered frontend pages or parse theme/plugin source files.

Use it as a review tool before removing media.
