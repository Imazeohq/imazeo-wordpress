=== Imazeo ===
Tags: image optimization, webp, pdf, qr code, media library
Requires at least: 6.5
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Transform images, process PDFs and Office documents, capture websites, and create QR codes and barcodes from WordPress.

== Description ==

Imazeo connects your WordPress site to an Imazeo project. Start with an editable attachment in the Media Library, or open **Media → Create with Imazeo**. Review the estimated credit ceiling before approving processing.

* Resize, crop, optimize, and convert individual images or batches. Save JPEG, PNG, WebP, AVIF or GIF results where the site's image editor supports the format.
* Inspect PDFs, render individual pages as images, merge PDFs in order, or extract selected pages into a new PDF.
* Render DOCX, XLSX and PPTX previews, and capture screenshots of public websites.
* Generate printable QR codes and barcodes. Manage dynamic QR links, destinations, and scan analytics from the Imazeo Project Library.
* Browse processing jobs and project files. Import supported saved outputs into the Media Library or download results privately.
* Insert generated images and PDFs using the standard WordPress Image and File blocks. Preview and replace a selected post, page, or product's featured image and restore its previous reference.

The plugin is free and open source. Processing runs on the Imazeo service, subject to your project's plan, credits, and storage limits. A project API key is required. [Imazeo](https://imazeo.com/) · [API documentation](https://imazeo.com/docs/)

== Installation ==

1. Upload the installable ZIP through **Plugins → Add New → Upload Plugin**, then activate Imazeo.
2. Open **Settings → Imazeo**. Get a project API key from the [Imazeo customer portal](https://app.imazeo.com/), review the authorization, and connect the project.
3. Choose **Process with Imazeo** from the Media Library list, or open **Media → Create with Imazeo** to select a workflow.
4. Review the credit ceiling and approve the request. Open **Media → Imazeo Processing Queue** to see progress and access the result.
5. Administrators can open **Media → Imazeo Project Library** to manage outputs, conversions, and dynamic QR links.

Local source files must be available in the WordPress uploads directory. A transfer can contain up to 5 MiB of source files, and a selection can contain up to 10 attachments. Image processing and imports depend on allowed MIME types and support from GD or Imagick. Background jobs use WP-Cron.

== Frequently Asked Questions ==

= What happens to my source attachments? =

Results imported into WordPress get their own attachment IDs and URLs. The featured image workflow changes only the selected item's featured reference and can restore it while both attachments are available. Use the WordPress editor for existing content blocks or galleries.

= How do I embed a QR code or document preview? =

Choose an image output for the QR code or rendered page, then select it in a WordPress Image block. Use a File block to link an imported PDF. SVG results are available as time-limited private downloads.

= Why is my job queued or marked as needing attention? =

WordPress schedules work through WP-Cron. Check **Tools → Site Health** if a job stays queued. For an interrupted request, check the processing queue and Imazeo Project Library before approving another attempt. The plugin looks for a retained result when the project supports recovery.

= What does disconnecting or removing Imazeo do? =

Disconnecting stops new work through this connection. Removing the plugin clears its settings, queue, and restoration pointers. Media already imported into WordPress remain ordinary attachments. Use the Imazeo Project Library or customer portal to manage remote files and dynamic QR links.

== External service ==

Imazeo sends the project API key to api.imazeo.com when verifying a connection or making a project request. Quotes send the operation, unit count and whether a remote source is used. Approved work can send selected attachments, public URLs, QR or barcode text, and processing options. Remote originals and saved outputs can remain in the project. Dynamic QR redirects are served by Imazeo and may collect scan analytics when enabled. Importing an output places it in the WordPress uploads directory, where the site's existing access rules apply.

[Service terms](https://imazeo.com/legal/terms/) · [Privacy notice](https://imazeo.com/legal/privacy/)

== Changelog ==

= 1.1.0 =
* Expanded Imazeo to image transformations, PDF and Office workflows, website screenshots, and static and dynamic codes.
* Added a project library, approval-based processing queue, Media Library selection, and featured-image replacement and restoration.
* Set the WordPress and PHP minimums to 6.5 and 8.2.

= 1.0.0 =
* Added credit-reviewed WebP copies from eligible Media Library images.
