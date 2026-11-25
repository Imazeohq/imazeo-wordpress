# Production API coverage

The WordPress integration targets the Imazeo API v1 project-key contract. The route list was checked against the published developer OpenAPI document and the deployed API release. The bundled catalog records parameter shapes and limits. The standalone tests check that every bundled operation maps to a WordPress workflow. [Production OpenAPI document](https://imazeo.com/openapi/imazeo-developer.json)

Paths below are relative to the API's `/v1` prefix. Project and media resource IDs are UUIDs.

| Operation | Method and path | WordPress workflow |
| --- | --- | --- |
| `ApiKeyContextController_context` | `GET /api-key/context` | Settings → Imazeo |
| `ImagesController_upload` | `POST /projects/{projectId}/images` | Create and Project Library |
| `ImagesController_list` | `GET /projects/{projectId}/images` | Project Library |
| `ImagesController_get` | `GET /projects/{projectId}/images/{id}` | Project Library |
| `ImagesController_remove` | `DELETE /projects/{projectId}/images/{id}` | Project Library |
| `ImagesController_content` | `GET /projects/{projectId}/images/{id}/content` | Project Library |
| `TransformsController_list` | `GET /projects/{projectId}/transforms` | Project Library |
| `TransformsController_create` | `POST /projects/{projectId}/transforms` | Create and Processing Queue |
| `TransformsController_get` | `GET /projects/{projectId}/transforms/{id}` | Project Library |
| `TransformsController_remove` | `DELETE /projects/{projectId}/transforms/{id}` | Project Library |
| `TransformsController_content` | `GET /projects/{projectId}/transforms/{id}/content` | Project Library |
| `ProcessingController_quote` | `POST /projects/{projectId}/credits/quote` | Credit review and Project Library |
| `ProcessingController_lookup` | `POST /projects/{projectId}/operations/lookup` | Queue recovery and Project Library |
| `ProcessingController_status` | `GET /projects/{projectId}/operations/{id}` | Project Library |
| `ProcessingController_result` | `GET /projects/{projectId}/operations/{id}/result` | Queue recovery and Project Library |
| `ConversionsController_capabilities` | `GET /projects/{projectId}/conversions/capabilities` | Project Library |
| `ConversionsController_lookup` | `GET /projects/{projectId}/conversions/lookup` | Queue recovery and Project Library |
| `ConversionsController_list` | `GET /projects/{projectId}/conversions` | Project Library |
| `ConversionsController_create` | `POST /projects/{projectId}/conversions` | Create and Processing Queue |
| `ConversionsController_xlsx` | `POST /projects/{projectId}/conversions/xlsx` | Create and Processing Queue |
| `ConversionsController_pptx` | `POST /projects/{projectId}/conversions/pptx` | Create and Processing Queue |
| `ConversionsController_website` | `POST /projects/{projectId}/conversions/website` | Create and Processing Queue |
| `ConversionsController_get` | `GET /projects/{projectId}/conversions/{id}` | Queue polling and Project Library |
| `ConversionsController_cancel` | `POST /projects/{projectId}/conversions/{id}/cancel` | Project Library |
| `ConversionsController_save` | `POST /projects/{projectId}/conversions/{id}/save` | Project Library |
| `ConversionsController_download` | `GET /projects/{projectId}/conversions/{id}/download` | Queue import and Project Library |
| `DynamicCodesController_capabilities` | `GET /projects/{projectId}/dynamic-codes/capabilities` | Project Library |
| `DynamicCodesController_list` | `GET /projects/{projectId}/dynamic-codes` | Project Library |
| `DynamicCodesController_create` | `POST /projects/{projectId}/dynamic-codes` | Project Library |
| `DynamicCodesController_detail` | `GET /projects/{projectId}/dynamic-codes/{id}` | Project Library |
| `DynamicCodesController_edit` | `PATCH /projects/{projectId}/dynamic-codes/{id}` | Project Library |
| `DynamicCodesController_analytics` | `GET /projects/{projectId}/dynamic-codes/{id}/analytics` | Project Library |
| `DynamicCodesController_csv` | `GET /projects/{projectId}/dynamic-codes/{id}/analytics/export` | Project Library |
| `CodesController_qr` | `POST /projects/{projectId}/tools/qr` | Create and Processing Queue |
| `CodesController_barcode` | `POST /projects/{projectId}/tools/barcode` | Create and Processing Queue |
| `PdfController_pdfInfo` | `POST /projects/{projectId}/tools/pdf/info` | Create and Processing Queue |
| `PdfController_pdfRender` | `POST /projects/{projectId}/tools/pdf/render` | Create and Processing Queue |
| `PdfController_pdfMerge` | `POST /projects/{projectId}/tools/pdf/merge` | Create and Processing Queue |
| `PdfController_pdfExtract` | `POST /projects/{projectId}/tools/pdf/extract` | Create and Processing Queue |
| `ToolArtifactsController_save` | `POST /projects/{projectId}/tools/artifacts` | Project Library |
| `ToolArtifactsController_list` | `GET /projects/{projectId}/tools/artifacts` | Project Library |
| `ToolArtifactsController_get` | `GET /projects/{projectId}/tools/artifacts/{id}` | Project Library |
| `ToolArtifactsController_remove` | `DELETE /projects/{projectId}/tools/artifacts/{id}` | Project Library |
| `ToolArtifactsController_content` | `GET /projects/{projectId}/tools/artifacts/{id}/content` | Project Library |
| `OutputsController_summary` | `GET /projects/{projectId}/outputs/summary` | Project Library |

## Request options

| Workflow | Supported controls |
| --- | --- |
| Image transformation | An editable local image, a public HTTP(S) URL, or a project original chosen by an administrator. Dimensions, fit and focal position, pixel crop, format and quality, rotation and mirroring, optimization, grayscale, negate, blur, sharpen, brightness, saturation, hue, trim threshold, padding and background. Ephemeral, cache and persistent modes with an optional output byte ceiling. Trim threshold 10 corresponds to the API's boolean shorthand. |
| PDF | Inspection with page count and dimensions, ordered merge, and explicit page or range selection for extraction. Rendering supports explicit pages, a range, or the capped all-pages option, plus format, scale and quality. Each returned raster page can be opened in the Media Library. |
| Office and website | Deployed DOCX, XLSX and PPTX conversion routes with documented engine, page, sheet or image settings as applicable. Website capture supports viewport, full-page, element and clip modes with the documented dimensions, page settings and wait controls. Capability and job queries are available to administrators. |
| QR and barcode | Documented content, formats, sizing, colors, margin, error correction and design controls as applicable. Dynamic QR supports destination, name, status, analytics, optimistic revision checks and saved QR styling. Analytics supports date window and device, browser, country and bot filters plus CSV export. |
| Project outputs and jobs | Catalogued status, type and search filters and pagination, processing quote kind/units/remote flag, idempotency-key lookup, artifact inputs and conditional transformation download with `If-None-Match`. Project API policies control eligibility, replay, and retention. |

## Integration boundaries

- Customer-session account, billing, usage event, project administration and API-key management belong in the customer portal. WordPress uses an individual project's API key and supports project context, output totals and quotes within that credential's scope.
- Dynamic QR analytics show aggregate results, with the project-authorized CSV available to administrators. The WordPress screen leaves recent event details in the service to limit tracking data displayed in wp-admin. Dynamic QR redirect addresses are public by design.
- Imported files require a supported native WordPress MIME type. SVG and other project outputs that cannot be imported can be downloaded privately. The local transfer limit is 5 MiB, the response limit is 16 MiB, and file sources must be local and editable. Offloaded-only files require a local copy before processing.
- Paid processing starts with an explicit per-operation approval. WordPress uses its standard Image and File blocks and media pickers. Replacement and restoration are scoped to a single featured-image reference after an explicit review.
