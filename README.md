# Uploads Media Importer (DT)

WordPress plugin to scan `wp-content/uploads`, find files present on the filesystem but missing from the Media Library, and import them in batches via an Admin page.

## Requirements
- WordPress 5.8+
- PHP 7.4+
- User capability: `upload_files`

## Installation
1. Copy the plugin folder into your plugins directory (e.g., `wp-content/plugins/uploads-media-importer-dway`).
2. Activate it from Plugins > Installed Plugins.
3. Go to Media > Uploads Importer.

## How it works
The plugin:
- Scans the uploads folder (optionally a subfolder) for files matching the provided extensions.
- Compares found relative paths with existing `_wp_attached_file` values.
- Shows how many files are “missing” from the Media Library.
- Allows importing them in batches to avoid timeouts.

## Page options
- Subfolder: e.g., `2025/10` to limit scanning.
- Extensions: comma-separated list, e.g., `jpg,jpeg,png,gif,webp,svg,pdf`.
- Batch size: how many files to process per AJAX request.
- Recursive: include subfolders of the selected subfolder.
- Dry run: simulate the import without creating attachments; useful for previewing.
- Generate only minimal metadata for non-images: for non-image files (or svg) avoid full metadata generation.

## Import and metadata
- For image files (except `svg`), after `wp_insert_attachment`, `wp_generate_attachment_metadata` is called and the attachment metadata is updated.
- For non-image or `svg` files, a minimal set of metadata may be saved (e.g., `filesize`).

## Attachment date from uploads path
During import:
- If the file lives under a `YYYY/MM/` path (e.g., `2018/01/file.jpg` or `2018/01/sub/another.pdf`), the attachment post date is set to the first day of that month at 12:00 using the site’s local timezone.
- `post_date_gmt` is set in UTC based on the site’s timezone.
- This ensures imported attachments appear chronologically in the month corresponding to their folder.

## Security
- Access limited to users with `upload_files` capability.
- AJAX nonce (`dt_umi_nonce`) with verification via `check_ajax_referer`.
- Server-side sanitization (`sanitize_text_field`) and UI escaping applied.

## Performance notes
- Import runs in batches via AJAX; batch size is configurable.
- Scanning reads the filesystem, so on very large sites it may take time.

## Logging and diagnostics
- Per-file errors are returned by the AJAX responses and displayed live in the results area.
- Use "Dry run" to verify detection before performing a real import.

## Known limitations
- Supported extensions are filtered in the UI. On the server side, any scanned file will be imported with the `mime` determined by `wp_check_filetype`.
- The date is only set when the relative path matches the `YYYY/MM/` format. For non-standard paths, the insertion date is used.

## Uninstall
- The plugin stores only the `dt_umi_last_scan` option with the last scan timestamp; no tables are created.
- Deactivate and remove the plugin folder to uninstall.

## Support
Built by DWAY SRL — `https://dway.agency`.
