# Imazeo for WordPress

Imazeo connects WordPress to your Imazeo project for image processing, PDFs, Office document previews, website screenshots, QR codes and barcodes. Generated media can be reused in standard WordPress blocks, featured images and WooCommerce product media.

## Install and use

1. Upload the installable ZIP in **Plugins → Add New → Upload Plugin** and activate Imazeo.
2. In **Settings → Imazeo**, connect a project key and review what the plugin sends to the service. [Imazeo customer portal](https://app.imazeo.com/)
3. Start with **Process with Imazeo** from a Media Library row, bulk action or attachment editor. For a public URL, QR code or barcode, open **Media → Create with Imazeo** directly.
4. Choose the processing options, review the credit ceiling, and approve. Follow the job in **Media → Imazeo Processing Queue**, then open the result in the Media Library.
5. Administrators use **Media → Imazeo Project Library** to browse remote originals and outputs, inspect conversions, or manage dynamic QR links and scan analytics.

Imazeo requires WordPress 6.5 or later, PHP 8.2 or later, HTTPS access to the Imazeo API, and working WP-Cron. Stored credentials use OpenSSL and the site's unique WordPress authentication key. Hosts can supply the project key in the WordPress configuration as `IMAZEO_API_KEY`. Image imports depend on the site's allowed file types and image editor support. The release ZIP has no Composer or npm runtime dependencies.

## Working with outputs

- Transform up to ten selected editable images. The source attachments and their existing references stay intact.
- Render PDF pages and Office previews into raster media and use the standard Image block. Merge or extract PDFs, then link them through the File block. Explicitly review the visibility of media imported from a private project before saving it in WordPress.
- Capture a public website or create a QR code or barcode for a post, product or print asset. Keep a dynamic QR image in use while changing the destination in the Project Library. SVG results are offered as private downloads.
- Use **Use as featured image** from a generated raster result in the queue. Review the current image and replacement on a selected post, page or product. Restore from **Media → Imazeo Featured Image** or the featured-image panel where the editor exposes it. Use standard WordPress controls for inline images and product galleries.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Connection fails | Confirm the project key and access to the API. If saving the key in WordPress, check that OpenSSL and a unique WordPress authentication key are available. Reconnect after rotating credentials. |
| Queue stays idle | Check **Tools → Site Health** for cron and loopback problems. Low-traffic sites may need host-managed WP-Cron. |
| Source is unavailable | Check attachment and parent visibility, editing permissions, the requested file type, and that the file is stored under the local uploads directory. |
| Output is too large | Reduce the page count, image dimensions or capture scale. The plugin caps inputs at 5 MiB per request and responses at 16 MiB. |
| A job needs attention | Review the status and find the operation or conversion in the Project Library before authorizing another request. |

The plugin is free and GPL-licensed. Imazeo service usage is subject to the connected project's existing plan, storage and credits. Review the data sent in [the plugin readme](readme.txt). [Imazeo](https://imazeo.com/) · [API documentation](https://imazeo.com/docs/) · [Service terms](https://imazeo.com/legal/terms/) · [Privacy notice](https://imazeo.com/legal/privacy/)

[Contributing](CONTRIBUTING.md) · [Architecture](docs/architecture.md) · [API coverage](docs/api-coverage.md) · [Publishing](docs/releasing.md) · [Security](SECURITY.md)
