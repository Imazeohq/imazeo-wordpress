# WordPress architecture

## Connection and authorization

A site administrator verifies a project API key, approves external service use, and connects one project per site. The connection uses a configuration-supplied key or a value sealed with OpenSSL using the WordPress authentication key. Changing the key or connected project changes the connection generation and fences existing work. The transport uses the WordPress HTTP API at the fixed Imazeo HTTPS origin, with certificate verification, response and time limits, and redirects disabled.

The Media Library and processing queue require the upload capability, with separate attachment-edit and parent visibility checks for local input. Remote project management requires the site administration capability. Nonces and explicit confirmation guard changes and imports. The project's own API permissions and entitlements apply to every call. Customer-session management stays in the customer portal.

## Processing

Each paid request starts with a quote. The review stores the selected attachment hashes, user, parameters, project generation and ceiling. Approval consumes the review once. A database compare-and-swap protects queue updates and allows one worker lease per site. The worker repeats the permissions and file checks, obtains a fresh quote, and submits only if the price stays within the approved ceiling. Async conversions retain their job ID for status polling. After an uncertain submission, the worker looks up the original idempotency key and retrieves a retained result where available. Paid submissions and uncertain uploads are never retried automatically.

The Media Library workflow transfers up to 5 MiB of verified local bytes per request and accepts up to ten attachments. Bulk images are queued individually. PDF merge preserves multipart order. PDF render supports up to ten pages and reserves the maximum ten units for the capped all-pages option. The API may impose additional plan or operation limits. Responses are capped at 16 MiB, including multi-page JSON. The plugin never fetches the user's public source URL from the WordPress host.

## Media

WordPress verifies the permitted MIME type before importing raster and PDF results. Imported media gets a new attachment with native metadata and image sizes where the site's tools support the format. A per-job import marker allows a recovered result to reuse an existing attachment. SVG results are downloaded privately and excluded from the uploads directory. The featured-image workflow compares the selected item's current reference with the reviewed one and retains the previous reference for explicit restoration. It preserves the source and generated media. Inline content and product galleries use the native media pickers.

The `imazeo_media_imported` action receives the new attachment ID, source attachment ID (zero for a source outside the Media Library), and MIME type. The existing `imazeo_webp_imported` action retains its original two arguments for WebP results. Deactivation clears scheduled jobs. Uninstall removes settings, queue data and plugin-owned markers while preserving attachments and their current references.

The [API coverage matrix](api-coverage.md) specifies the production contract and integration boundaries.
