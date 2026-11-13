# KitMage Signed PDF Sharing

**Version:** 0.1.0 (WP E-Signature companion plugin)  
**Author:** Mike@KitMage  
**Plugin URL:** https://kitmage.com

**Inspected plugin versions:** WP E-Signature **2.1.7.4**, WP E-Signature Unlimited Sender Roles **2.0.4**, and the bundled Save As PDF **1.5.9**.

## What it does

- For logged-in WordPress users with the **Editor** role and approved E-Signature access, the existing **E-Signature → My Documents → Signed** page lists signed documents owned by *all current WordPress Editors*, including the viewer's own signed documents.
- Keeps the existing signed-document dashboard, document titles, signers, activity, date, sorting, search by title/signer, tab count, and pagination.
- For a signed document owned by another Editor, the title and **View signed PDF (shared)** action open a nonce-protected PDF route that checks role, E-Signature access, current document ownership, and signed status on every request.
- Removes the cross-owned row's Trash and other action controls plus bulk selection in JavaScript. Blocks mutation actions on cross-owned signed Editor documents at the E-Signature AJAX hook level and on observed legacy document-controller routes, independent of JavaScript.
- Does not make a user a signer, alter ownership, alter signed content, share drafts, or give Editors WordPress administrator privileges.

## Requirements

1. Use a **staging environment** first, with a full database and files backup.
2. Install WP E-Signature core 2.1.7.4 and Unlimited Sender Roles 2.0.4. The code was inspected against these versions; later releases require re-review.
3. Ensure the **Save As PDF** add-on is **enabled** in E-Signature Add-ons. It is included in the supplied core ZIP, but whether it is enabled on your site was not visible from the files.
4. Both the reviewing user and owner must currently have the WordPress **Editor** role. The reviewer must be approved to access E-Signature (using your existing Unlimited Sender Roles setup).

## Install and test

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
2. Select `esig-editor-signed-sharing.zip`, install, and activate it.
3. Log in as an Editor who did not create the target signed document (e.g., Diana).
4. Navigate to **E-Signature → My Documents → Signed**.
5. Confirm that signed documents belonging to other Editors (e.g., Kelly) appear, and that clicking **View signed PDF (shared)** opens the signed document in a new browser tab.
6. Confirm that cross-owned rows do not have destructive controls or enabled selection checkboxes.
7. As a WordPress subscriber or non-approved user, confirm a shared PDF URL returns **403**. Also confirm the same URL rejects a draft, a document owned by a non-Editor, and a signed document after its owner loses the Editor role.
8. Test search by document title and signer, pagination with more than 20 signed documents, sorting, and existing owner-only workflows. Also verify that a specially crafted AJAX mutation of a shared signed document is refused with **403**.

## Intentional limits and security notes

- **Native HTML preview is NOT overridden.** In core 2.1.7.4, the public signing-page preview requires the document owner or a `manage_options` user. A new PDF route is used instead, avoiding any broad capability escalation.
- Sharing applies **only to the Signed folder**, not Awaiting, Draft, Trash, or templates.
- All existing Editors can view all other current Editors' **signed** documents. This is not restricted to NDA titles or specific document categories.
- Shared PDFs are generated on demand and delivered with `Cache-Control: private, no-store` and an authorization/nonce check; no public document URL is created by the companion plugin.
- The companion intercepts the observed E-Signature AJAX mutation hooks, but **does not claim to fix every possible authorization gap in the underlying ApproveMe plugin**. A separate security review is advised for sensitive documents.
- The companion hides shared-row actions client-side, so editors should use a normal JavaScript-enabled WordPress admin browser. After making changes to your own documents using ApproveMe's AJAX controls, reload the Signed tab if the shared listing appears incomplete; ApproveMe's separate AJAX refresh still uses its owner-only query.
- The site owner reports successful testing. PHP syntax validation is also complete. This is a custom integration, not a production security audit; review it again when updating WP E-Signature or its add-ons.
- To roll back, deactivate the companion plugin. No database tables or document ownership records are changed.

## Implementation notes

Core files inspected:

- `src/Controllers/DocumentsController.php`: `esig-document-index-docs` and `esig-document-index-footer` filters.
- `src/Models/Document.php`: `fetchAllOnStatus`, `getDocumentsTotal`, `documentList` and `esig_document_preview_link`.
- `src/Hooks/Frontend/LoadTemplate.php`: native preview owner/admin authorization.
- `src/Hooks/Admin/Requests/Documents/*.php`: AJAX mutation actions.
- `add-ons/esig-save-as-pdf/src/Helpers/Pdf/GeneratePdf.php`: PDF generation via `create( $id, $filename, 'S' )`.
- `esig-unlimited-sender-roles/admin/esig-usr-admin.php`: role access settings.

## Source repository and packaging

Source: https://github.com/kitmage/wpesign-shared-docs

The repository contains the PHP plugin file at its root. **Do not install GitHub's automatic source ZIP over an existing installation:** its folder name differs from the existing `esig-editor-signed-sharing` plugin directory. To build an update ZIP using the same directory and entry-point name as the installed plugin, run from the repository root:

```sh
mkdir -p build/esig-editor-signed-sharing
cp esig-editor-signed-sharing.php build/esig-editor-signed-sharing/
cp README.md build/esig-editor-signed-sharing/
(cd build && zip -r ../esig-editor-signed-sharing.zip esig-editor-signed-sharing)
```

The built `esig-editor-signed-sharing.zip` retains the original plugin directory and file name, so the brand change does not create a separate plugin entry.
