# PDF Reader API

Symfony 7.4 (PHP ≥ 8.2) API for scanning QR/barcodes in PDF files and splitting PDFs into per-page ZIP archives.

## Requirements

- PHP 8.2+
- Extensions: `ctype`, `iconv`, `mbstring`, `zip` (`gd` optional — for QR in embedded images)
- Composer packages only — **no** system tools (`pdftoppm`, `zbarimg`, `qpdf`) needed

## Setup

```bash
composer install
cp .env.example .env   # if needed
# set API_ACCESS_TOKEN and APP_SECRET in .env
php -S 127.0.0.1:8000 -t public
```

## Code detection (3 methods)

The scanner runs every available method and merges unique results:

1. **text** — PDF text via `smalot/pdfparser` (ticket barcodes as fonts/text)
2. **imagick** — render pages with PHP `imagick`, decode QR in PHP (+ `zbarimg` on images if present)
3. **cli-zbar** — `pdftoppm` + `zbarimg` when both CLI tools exist

On shared hosting with only `imagick` enabled, methods 1–2 are enough.

All `/api/*` routes require an access token from `.env` (`API_ACCESS_TOKEN`).

Send one of:

- `Authorization: Bearer <token>`
- `X-Access-Token: <token>`

## Endpoints

### 1. Detect QR / barcodes in a PDF

`POST /api/pdf/codes`

Multipart form field: `file` (or `pdf`)

```bash
curl -X POST http://127.0.0.1:8000/api/pdf/codes \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@document.pdf"
```

Example response:

```json
{
  "count": 2,
  "codes": [
    { "page": 1, "type": "QR-Code", "data": "https://example.com" },
    { "page": 2, "type": "EAN-13", "data": "5901234123457" }
  ]
}
```

### 2. Split PDF pages into a ZIP archive

`POST /api/pdf/split`

Multipart form field: `file` (or `pdf`)

```bash
curl -X POST http://127.0.0.1:8000/api/pdf/split \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@document.pdf"
```

Example response:

```json
{
  "id": "a1b2c3...",
  "pages": 5,
  "download_url": "http://127.0.0.1:8000/api/downloads/a1b2c3..."
}
```

### 3. Download archive

`GET /api/downloads/{id}`

```bash
curl -OJ http://127.0.0.1:8000/api/downloads/a1b2c3... \
  -H "Authorization: Bearer YOUR_TOKEN"
```

The ZIP contains `page_0001.pdf`, `page_0002.pdf`, …

## CLI: scan codes in a directory

```bash
php bin/console app:pdf:scan-codes /path/to/pdfs /path/to/scan.log
# all folders; all files in root; max 2 files per nested folder; first 2 pages each
php bin/console app:pdf:scan-codes 2222/pdf var/storage/scan.log --limit=2
```

Arguments:

- `path` — root directory (scanned recursively through all nested folders)
- `log` — path to the output log file

Options:

- `-l`, `--limit=N` — max N PDF files **per nested subdirectory only**. Files directly in the root `path` are never limited. When set, each PDF is scanned on the first 2 pages only.
