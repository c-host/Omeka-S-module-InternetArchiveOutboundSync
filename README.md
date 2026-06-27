# IA Outbound

An unofficial Omeka S admin module that pushes metadata from Omeka to [Internet Archive](https://archive.org/), publishes Collecting submissions via S3 upload, and pushes Contribute metadata revisions. Requires an IA collection and S3 API credentials ([collections guide](https://help.archive.org/help/collections-a-basic-guide/)).

This module is designed to suit a particular archiving workflow integrating the Internet Archive with Omeka S, and may not be appropriate for all use cases.

## Built for a Georgian / English archive

This module was custom-made for a Georgian and English archive context. It merges bilingual title, description, and creator values in **Omeka value order** when no `@language` tags are set (typical for Collecting forms), and maps Omeka language values to Internet Archive MARC codes for multilingual items.

**Language support beyond English and Georgian is limited.** The module can resolve some other languages via ISO/MARC lookup, but it is not tested or designed for general multilingual archives. If your items use languages other than English and Georgian, review every metadata preview carefully before pushing.

## Data backup and integrity

**Always check metadata previews before pushing.** Preview screens show projected metadata or before/after diffs. Read them before you confirm a live push or publish. Jobs run in the background; track progress under **IA Outbound → History**.

Pushes and publishes write to Internet Archive. Keep backups of both your Omeka database and your IA collection. To back up the Omeka database in Ghent Docker:

```bash
docker compose exec -T db mariadb-dump -uomeka -pomeka omeka > ../backups/omeka-backup-$(date +%Y%m%d).sql
```

Docker must be running. Create the backup directory first if needed (`mkdir -p ../backups` from the Ghent Docker root). Credentials match `MYSQL_USER`, `MYSQL_PASSWORD`, and `MYSQL_DATABASE` in `.env`.

## Requirements

- Omeka S **4.0+**
- Background **jobs** (Omeka dispatches PHP CLI workers; verify under Admin → Jobs)
- **Push metadata:** items must already exist on Internet Archive with identifiers in mapped item sets
- **Publish user-submitted items:** Collecting module with form assigned to the Contributions item set
- **Publish metadata revisions:** Contribute module (optional but required for the revision queue)

## Install (assumes use of [Ghent Docker](https://github.com/GhentCDH/Omeka-S-Docker))

1. Install the module files under `modules/InternetArchiveOutboundSync/` (bind-mount, git clone, or ZIP URL in `OMEKA_S_MODULES` — see below).
2. Set `IA_S3_ACCESS_KEY` and `IA_S3_SECRET_KEY` in the environment (recommended) or in **Modules → Configure**.
3. **Admin → Modules → Install → Activate**.
4. **Modules → Configure** → Test connection; set default IA collection, Contributions item set, and item set ↔ collection mapping.
5. Enable metadata push when you are ready to write metadata to Internet Archive.
6. Dry-run or preview one item before a large push.

Note: this module has not been tested with other Docker setups.

### Bind-mounting the module (Ghent Docker)

File: `compose.override.yaml`

```yaml
services:
  omeka:
    volumes:
      - ../InternetArchiveOutboundSync:/volume/modules/InternetArchiveOutboundSync
```

Use this for active development: edit the module repo, commit, and push; pull changes into each checkout as needed.

### Installing from a release ZIP (Ghent Docker)

Add a GitHub Release zip URL to `OMEKA_S_MODULES` in `.env`:

```env
OMEKA_S_MODULES="Common Contribute … https://github.com/c-host/InternetArchiveOutboundSync/releases/download/v1.0.0/InternetArchiveOutboundSync.zip"
```

On container start, Ghent Docker downloads the zip into `data/omeka/modules/` if that folder does not already exist. To upgrade, remove the module directory, bump the URL, restart the container, then **Admin → Modules → Upgrade**.

### Choosing bind-mount vs ZIP URL

| Approach | Best for |
|----------|----------|
| **Bind-mount** | Development; git pull in the mounted repo updates the running code |
| **ZIP URL in `.env`** | Simpler instance setup without compose overrides per module |

ZIP install extracts plain files (not a git repository). To publish module changes, release a new zip and update `.env` on each instance.

## Admin UI

- **IA Outbound → Push metadata** — mapped item sets, before/after preview, metadata patch
- **IA Outbound → Publish user-submitted items** — Collecting staging item set, S3 upload
- **IA Outbound → Publish metadata revisions** — Contribute-approved metadata changes on existing IA items
- **IA Outbound → History** — per-run results and logs
- **Modules → Configure** — credentials, item set mapping, contributions item set, connection test

Expand the setup checklist on any IA Outbound page for anything still missing.

## Workflows

### Push metadata

Select Omeka items that already have Internet Archive identifiers and sit in a mapped item set. The module reads current IA metadata, builds projected values from your Omeka item (including bilingual joins), shows a before/after preview, waits for confirmation, then patches Internet Archive and verifies via the metadata read API.

Metadata push can be disabled under **Modules → Configure** so you can preview without writing.

### Publish user-submitted items

Lists items in the configured **Contributions item set** created by Collecting forms (oldest first). Each must have upload media and no IA identifier yet. After preview and confirmation, files upload via S3. By default, the staging Omeka item is deleted after success.

### Publish metadata revisions

When a **Contribute** submission is validated on an item that **already has an IA identifier**, it is auto-queued here. Admins preview metadata diffs and push patches to Internet Archive (no file upload).

## Metadata handling (summary)

| Topic | Behaviour |
|-------|-----------|
| Title, description, creator | Joined in Omeka value order when `@language` tags are absent; English/Georgian sort when tags are present |
| Subjects | Each `dcterms:subject` value split on commas and semicolons |
| Rights | URLs in literal text extracted for `licenseurl` |
| Languages | Omeka language values mapped to IA MARC codes via ISO lookup |

## Testing uploads safely

Use Internet Archive’s built-in **`test_collection`** for upload testing. Items there are removed automatically after about 30 days.

Set either:

- **Environment:** `IA_PUBLISH_TEST_COLLECTION=test_collection` (recommended for local/dev)
- **Modules → Configure → Publish test collection override:** `test_collection`

When active, **user-submitted item uploads** go to the override collection. **Metadata push** still uses your default and mapped collections.

## Tests

Quick smoke tests (no Composer):

```bash
php test/smoke.php
```

PHPUnit (from module root):

```bash
composer install
composer test
```

## Companion module

**InternetArchiveInboundSync** (IA Inbound) imports items from Internet Archive into Omeka. Review inbound results before using outbound on the same items. The two modules are optional companions, not hard dependencies.

Use this companion module to import items from Internet Archive into Omeka. This is especially useful for bringing into Omeka S items submited through the Collecting form since the InternetArchiveOutboundSync module does not import items submitted through the Collecting form. The reason for this is to prevent storing media directly in the Omeka S database, and instead using the Internet Archive as the primary storage for media. This is designed to suit a particular archiving workflow and may not be appropriate for all use cases.

## Upgrade note

If you used an unpublished local build with older table or setting keys, uninstall and reinstall the module from this release (**Admin → Modules → Uninstall → Reinstall**). Omeka items are unaffected; outbound run history and queue rows are cleared.
