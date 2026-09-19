# FPDP Interactive HTML Mockup

This folder contains a responsive, dependency-free UI prototype for the FPDP public site and owner dashboard.

Folder ini berisi prototype UI responsif tanpa dependency untuk public site dan owner dashboard FPDP.

## Run / Menjalankan

The locale files are loaded with `fetch()`, so serve this directory over HTTP instead of opening the HTML through `file://`.

File locale dimuat dengan `fetch()`, sehingga direktori harus disajikan melalui HTTP dan bukan dibuka melalui `file://`.

From the repository root / Dari root repository:

```bash
php -S localhost:8080 -t documentation/mockup
```

Open / Buka:

- Dashboard: <http://localhost:8080/index.html>
- Public profile: <http://localhost:8080/public-profile.html>

## Included screens / Tampilan yang tersedia

- Overview dashboard / Ringkasan dashboard
- Content and draft management / Manajemen konten dan draft
- Unified timeline / Timeline terpadu
- External integrations / Integrasi eksternal
- Products and orders / Produk dan order
- Payments / Pembayaran
- Federation status / Status federasi
- Analytics / Analitik
- Settings: profile, appearance, language, security / Pengaturan: profil, tampilan, bahasa, keamanan
- Public personal digital home / Personal digital home publik
- Embedded YouTube videos on the public profile (privacy-enhanced `youtube-nocookie.com` iframe) / Video YouTube tersemat pada public profile (iframe privacy-enhanced `youtube-nocookie.com`)

## Navigation map / Peta navigasi

See [NAVIGATION-MAP.en.md](NAVIGATION-MAP.en.md) / [NAVIGATION-MAP.id.md](NAVIGATION-MAP.id.md) for a diagram of the dashboard and public-profile menu structure and what each menu does.

Lihat [NAVIGATION-MAP.id.md](NAVIGATION-MAP.id.md) untuk diagram struktur menu dashboard dan public profile beserta fungsi setiap menu.

## Adding a language / Menambah bahasa

1. Copy `locales/en.json` to a new file, for example `locales/ja.json`.
2. Translate the values without changing the keys.
3. Add the locale to `locales/languages.json`:

   ```json
   {
     "code": "ja",
     "name": "日本語",
     "englishName": "Japanese",
     "direction": "ltr",
     "file": "ja.json",
     "enabled": true
   }
   ```

4. Reload the mockup. The language switchers are generated automatically.

Untuk menambah bahasa, salin `en.json`, terjemahkan value tanpa mengubah key, daftarkan locale pada `languages.json`, lalu muat ulang mockup. Gunakan `direction: "rtl"` untuk bahasa seperti Arabic. Locale pilihan disimpan di `localStorage` dengan key `fpdp.locale`.

## Implementation notes / Catatan implementasi

- `languages.json` is the locale registry and can contain any number of enabled languages.
- Locale dictionaries are flat JSON objects with dot-delimited keys.
- English is the fallback dictionary when a translation is unavailable.
- Elements use `data-i18n`, `data-i18n-placeholder`, and `data-i18n-aria-label` attributes.
- User-controlled values must be inserted as text, never as untranslated HTML.
- The data shown here is fictional and is not connected to the FPDP backend.
- The Video section on the public profile embeds two real, openly embeddable YouTube videos as sample data (`jNQXAC9IVRw`, `YE7VzlLtp-4`) via `https://www.youtube-nocookie.com/embed/{videoId}`; see [NAVIGATION-MAP](NAVIGATION-MAP.en.md) and the [content aggregation guide](../CONTENT-AGGREGATION-GUIDE.en.md#68-youtube) for how this maps to the RSS/Atom connector.

