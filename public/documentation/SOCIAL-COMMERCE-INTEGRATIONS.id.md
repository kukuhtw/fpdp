# Panduan Integrasi Facebook, LinkedIn, TikTok, Shopee, Instagram, X, dan Threads

## 1. Tujuan dan tanggal verifikasi

Dokumen ini menjelaskan apakah platform tersebut menyediakan RSS first-party, login identity, delegated API authorization, serta API konten atau commerce yang dapat digunakan—termasuk cara mengintegrasikannya ke FPDP.

Informasi diverifikasi melalui dokumentasi developer resmi pada **19 September 2026**. Produk, permission, harga, persyaratan review, dan versi endpoint platform sering berubah. Verifikasi kembali dokumentasi resmi sebelum implementasi dan setiap rilis production.

## 2. Tiga capability yang tidak boleh dicampur

| Capability | Arti | Penggunaan pada FPDP |
|---|---|---|
| RSS/Atom | URL feed publik yang dapat diambil tanpa OAuth | `RSSConnector` atau `AtomConnector` yang sudah tersedia |
| Login identity | Memungkinkan seseorang masuk ke FPDP dengan identitas eksternal | Login provider FPDP opsional |
| API authorization | Mengizinkan FPDP mengakses data atau bertindak untuk account/shop | Connector konten atau commerce eksternal |

OAuth yang berhasil tidak otomatis mengizinkan FPDP membaca seluruh post, produk, follower, atau analytics. Setiap endpoint memiliki scope, aturan App Review, jenis account, access tier, rate limit, dan data-retention policy sendiri.

## 3. Matriks capability

“Tidak ada RSS” berarti dokumentasi developer resmi yang diperiksa tidak menyediakan RSS/Atom first-party untuk feed account tersebut. Ini tidak mencakup converter tidak resmi atau scraping service.

| Platform | RSS/Atom resmi | Login identity | Delegated API authorization | Penggunaan praktis pada FPDP |
|---|---|---|---|---|
| Facebook | Tidak ada RSS account/Page terdokumentasi | Ya, Facebook Login | Ya, Meta Graph API | Login opsional; connector Page/konten yang disetujui |
| LinkedIn | Tidak ada RSS member/company terdokumentasi | Ya, OpenID Connect di atas OAuth 2.0 | Ya, tetapi permission membaca konten dibatasi | Login praktis; agregasi membutuhkan approval |
| TikTok | Tidak ada RSS profil/video terdokumentasi | Ya, Login Kit | Ya, OAuth 2.0 + Display API | Login dan connector video/profil berizin |
| Shopee | Tidak ada RSS toko/produk terdokumentasi | Tidak ada general consumer identity login | Ya, otorisasi seller/shop melalui Open Platform | Commerce connector untuk toko berizin |
| Instagram | Tidak ada RSS profil/media terdokumentasi | Otorisasi connector melalui Instagram Login; tidak disarankan sebagai login FPDP universal | Ya, Instagram API untuk professional account yang memenuhi syarat | Connector profil dan media Business/Creator |
| X | Tidak ada RSS post pengguna terdokumentasi | OAuth dapat mengautentikasi user | Ya, OAuth 2.0 PKCE atau OAuth 1.0a sesuai endpoint | Connector user/post sesuai access dan harga |
| Threads | Tidak ada RSS profil/thread terdokumentasi | Ada API authorization; bukan produk identity login umum | Ya, Threads API authorization | Connector profil/thread dan publishing jika disetujui |

## 4. Detail setiap platform

### 4.1 Facebook

#### Ketersediaan

- **RSS:** tidak ada RSS first-party yang didukung untuk profil pribadi atau Page.
- **Login:** Facebook Login menyediakan autentikasi pengguna berbasis OAuth.
- **Content API:** Meta Graph API dapat memberikan data Page/account yang diizinkan. Ketersediaan bergantung pada resource, role Page, access token, permission, mode aplikasi, business verification, dan App Review.

#### Penggunaan pada FPDP

Gunakan dua produk dan consent record terpisah:

1. `FACEBOOK_LOGIN` untuk login account FPDP opsional; dan
2. `FACEBOOK_PAGE` untuk impor konten Page yang disetujui.

Jangan berasumsi token basic login dapat membaca post Page. Minta hanya permission yang diperlukan connector dan tampilkan capability yang diminta sebelum redirect.

Alur yang disarankan:

```text
Owner memilih Connect Facebook Page
→ FPDP membuat state + PKCE jika didukung
→ redirect ke Meta authorization
→ verifikasi state callback
→ tukar code pada server
→ temukan Page/resource yang diizinkan token
→ owner memilih satu Page secara eksplisit
→ enkripsi token dan simpan external account
→ antrekan sinkronisasi pertama
```

Normalisasi item menjadi `source_type=EXTERNAL`, `source_provider=FACEBOOK`, author/Page asli, dan canonical Facebook URL.

Referensi resmi:

- [Dokumentasi Facebook Login](https://developers.facebook.com/docs/facebook-login/)
- [Dokumentasi Meta Graph API](https://developers.facebook.com/docs/graph-api/)
- [Meta App Review](https://developers.facebook.com/docs/app-review/)

### 4.2 LinkedIn

#### Ketersediaan

- **RSS:** tidak ada RSS first-party untuk profil member atau company Page.
- **Login:** Sign In with LinkedIn memakai OpenID Connect (OIDC), identity layer di atas OAuth 2.0. Scope umumnya `openid`, `profile`, dan opsional `email`.
- **Content API:** LinkedIn Posts API tersedia, tetapi pembacaan member post membutuhkan permission restricted `r_member_social`. Konten organization membutuhkan permission organization dan Page role yang sesuai. Akses dapat membutuhkan approval LinkedIn.

#### Penggunaan pada FPDP

LinkedIn OIDC cocok sebagai login provider opsional. Validasi signature ID token, issuer, audience, nonce, dan expiry; kemudian hubungkan claim `sub` yang stabil ke user FPDP. Jangan gunakan email sebagai satu-satunya external-account key.

Perlakukan agregasi konten sebagai connector terpisah:

- tampilkan “Membutuhkan approval LinkedIn” sampai FPDP memiliki product access;
- jangan menjanjikan impor member post hanya dengan scope OIDC;
- simpan person/organization URN dan scope yang diberikan;
- kirim version header yang diwajibkan versi API saat ini;
- pertahankan URL post dan atribusi LinkedIn.

Referensi resmi:

- [Sign In with LinkedIn menggunakan OpenID Connect](https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2)
- [Mendapatkan akses LinkedIn API](https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access)
- [LinkedIn Posts API dan permission](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api)

### 4.3 TikTok

#### Ketersediaan

- **RSS:** tidak ada RSS first-party profil/video yang didukung.
- **Login:** TikTok Login Kit berbasis OAuth 2.0 dan mendukung autentikasi web.
- **Content API:** Display API dapat mengembalikan informasi profil/video yang diizinkan. `user.info.basic` adalah scope profil dasar; `video.list` memberikan akses read ke video publik pengguna dan dapat membutuhkan approval.

#### Penggunaan pada FPDP

Daftarkan aplikasi, tambahkan Login Kit dan produk API yang diperlukan, daftarkan exact HTTPS redirect URI, lalu minta scope minimum.

```text
GET /api/v1/integrations/tiktok/authorize
→ buat state
→ redirect ke https://www.tiktok.com/v2/auth/authorize/
→ callback menerima code dan state
→ validasi state
→ tukar code pada server
→ enkripsi access/refresh token
→ panggil Display API
→ normalisasi video menjadi external post
```

Simpan client secret dan refresh token hanya pada server. Tangani partial consent karena user dapat menolak scope opsional. Refresh sebelum expiry dan hentikan sinkronisasi ketika consent dicabut.

Referensi resmi:

- [TikTok Login Kit overview](https://developers.tiktok.com/doc/login-kit-overview/)
- [TikTok Login Kit untuk Web](https://developers.tiktok.com/doc/login-kit-web/)
- [Pengelolaan TikTok user access token](https://developers.tiktok.com/doc/oauth-user-access-token-management/)
- [TikTok Display API](https://developers.tiktok.com/doc/display-api-overview/)

### 4.4 Shopee

#### Ketersediaan

- **RSS:** tidak ada RSS first-party toko, produk, atau order yang didukung.
- **Login:** otorisasi Shopee Open Platform digunakan seller/shop untuk memberikan akses integrasi. Ini bukan produk general “Sign in to FPDP with Shopee”.
- **Commerce API:** partner berizin dapat mengakses shop API yang tersedia bagi aplikasinya, misalnya capability produk dan order. Akses, environment, signature, dan ketersediaan berbeda berdasarkan market dan approval partner.

#### Penggunaan pada FPDP

Shopee tidak cocok dimasukkan ke `ExternalContentProviderInterface` karena nilai utamanya adalah commerce. Tambahkan interface khusus, misalnya:

```php
interface CommerceProviderInterface
{
    public function authorize(array $configuration): array;
    public function refreshToken(array $account): array;
    public function fetchProducts(array $account, ?string $cursor = null): array;
    public function fetchOrders(array $account, ?string $cursor = null): array;
    public function disconnect(array $account): bool;
}
```

Alur yang disarankan:

```text
Admin mendaftarkan partner app FPDP pada Shopee Open Platform
→ FPDP menandatangani URL otorisasi shop dengan partner credential
→ seller mengotorisasi shop
→ callback membawa authorization data seperti code dan identitas shop
→ FPDP menukar data tersebut menjadi access/refresh credential
→ credential dienkripsi per shop
→ job sinkronisasi produk/order memakai signed API request
→ Shopee push event diverifikasi dan dideduplikasi
```

Jangan tampilkan partner key pada browser. Pisahkan konfigurasi sandbox dan production. Pertahankan `shop_id`, region/market, external product/order ID, dan canonical Shopee URL.

Referensi resmi:

- [Shopee Open Platform developer guide](https://open.shopee.com/developer-guide)
- [Shopee Open Platform API reference](https://open.shopee.com/documents/v2/api-reference)

### 4.5 Instagram

#### Ketersediaan

- **RSS:** tidak ada RSS first-party profil/media Instagram yang didukung.
- **Login/authorization:** Instagram API with Instagram Login menyediakan authorization bagi use case professional account yang didukung. Perlakukan ini sebagai connector authorization, bukan identity provider universal untuk seluruh user FPDP.
- **Content API:** Business dan Creator account yang memenuhi syarat dapat memberikan permission yang disetujui untuk capability profil/media. Ketersediaan consumer account dan fitur memang dibatasi.

#### Penggunaan pada FPDP

Buat connector `INSTAGRAM` dan gunakan Instagram Login yang berlaku saat ini, bukan produk legacy/deprecated. Pada onboarding:

1. jelaskan bahwa professional account yang memenuhi syarat diperlukan;
2. minta hanya permission profil/media yang benar-benar digunakan;
3. validasi callback state dan tukar code pada server;
4. enkripsi credential jangka panjang dan catat scope yang diberikan;
5. ambil account dan media yang diizinkan;
6. simpan permalink Instagram dan atribusi media;
7. implementasikan revocation serta deletion callback sesuai policy Meta.

Jangan melakukan scraping HTML Instagram publik sebagai pengganti akses API.

Referensi resmi:

- [Instagram Platform overview](https://developers.facebook.com/docs/instagram-platform/)
- [Instagram API with Instagram Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/)
- [Permission Instagram/Meta](https://developers.facebook.com/docs/permissions/)

### 4.6 X (x.com)

#### Ketersediaan

- **RSS:** tidak ada RSS first-party untuk post pengguna yang didukung.
- **Login/API authorization:** X mendukung user-context authorization, termasuk OAuth 2.0 Authorization Code with PKCE serta OAuth 1.0a untuk endpoint yang membutuhkannya.
- **Content API:** X API menyediakan post dan user sesuai access aplikasi, endpoint, scope, rate limit, serta ketentuan pay-per-use atau enterprise saat ini.

#### Penggunaan pada FPDP

Prioritaskan OAuth 2.0 Authorization Code with PKCE untuk operasi read v2 yang mendukungnya. Gunakan adapter OAuth 1.0a hanya ketika endpoint yang diperlukan mewajibkannya.

Connector harus:

- hanya meminta identity/read scope untuk impor post;
- mengambil authenticated user ID;
- mengambil post owner dengan cursor pagination;
- memetakan post ID, teks, media, author, waktu pembuatan, dan canonical `https://x.com/{username}/status/{id}` URL;
- menyimpan informasi rate limit dan melakukan backoff pada `429`;
- memperhitungkan biaya API serta usage cap sebelum mengaktifkan sinkronisasi sering.

Referensi resmi:

- [X Developer Platform overview](https://docs.x.com/overview)
- [Dokumentasi X API](https://docs.x.com/x-api/)
- [Panduan autentikasi X API](https://developer.x.com/en/docs/authentication/overview)

### 4.7 Threads

#### Ketersediaan

- **RSS:** tidak ada RSS first-party profil/thread yang didukung.
- **Login/API authorization:** Threads API menyediakan flow user authorization dan token untuk akses Threads API. Perlakukan sebagai connector authorization, bukan general identity login FPDP.
- **Content API:** Threads API menyediakan capability read profil/thread dan publishing yang disetujui berdasarkan permission serta App Review saat ini.

#### Penggunaan pada FPDP

Buat connector `THREADS` terpisah walaupun Instagram dan Threads sama-sama dioperasikan Meta. Jangan memakai ulang token Instagram kecuali flow resmi menyatakan token tersebut valid untuk endpoint Threads.

Implementasi yang disarankan:

- daftarkan Threads use case dan redirect URI pada Meta app;
- buat dan validasi OAuth `state`;
- tukar callback code pada server;
- ambil profil Threads yang diizinkan;
- ambil thread dengan pagination dan normalisasi sebagai external post;
- pertahankan permalink thread dan identity provider `THREADS`;
- refresh/extend token hanya melalui endpoint yang terdokumentasi;
- dukung deauthorization dan data-deletion handling.

Referensi resmi:

- [Threads API overview](https://developers.facebook.com/docs/threads/)
- [Threads API getting started](https://developers.facebook.com/docs/threads/get-started/)
- [Threads access token dan permission](https://developers.facebook.com/docs/threads/get-started/get-access-tokens-and-permissions/)

## 5. Arsitektur FPDP yang disarankan

### 5.1 Pisahkan login provider dari data connector

Gunakan record terpisah walaupun satu platform mendukung keduanya:

```text
IdentityProviderAccount
  └─ digunakan untuk login user FPDP

ExternalContentAccount
  └─ digunakan untuk impor profil/media/post

CommerceProviderAccount
  └─ digunakan untuk sinkronisasi shop/produk/order
```

Pemisahan ini mencegah token login diperluas tanpa sengaja menjadi akses konten yang lebih luas dan memungkinkan user mencabut satu tujuan tanpa merusak tujuan lain.

### 5.2 Rekomendasi adapter provider

| Kode provider | Keluarga adapter | Capability awal |
|---|---|---|
| `FACEBOOK_LOGIN` | Identity | Login dan account linking |
| `FACEBOOK_PAGE` | External content | Konten Page yang disetujui |
| `LINKEDIN_OIDC` | Identity | Login dan account linking |
| `LINKEDIN` | External content | Member/organization post yang disetujui |
| `TIKTOK` | Identity + external content | Login, profil, authorized video list |
| `SHOPEE` | Commerce | Shop, produk, order, push event |
| `INSTAGRAM` | External content | Professional profile dan media |
| `X` | Identity + external content | User identity dan post |
| `THREADS` | External content | Profil, thread, publishing opsional |

### 5.3 Endpoint integrasi yang diusulkan

Endpoint berikut memperluas target kontrak API saat ini:

| Method | Path | Tujuan |
|---|---|---|
| GET | `/api/v1/integrations/providers` | Daftar ketersediaan provider, account requirement, dan approval state |
| POST | `/api/v1/integrations/{provider}/authorize` | Membuat state/PKCE dan mengembalikan authorization URL |
| GET | `/api/v1/integrations/{provider}/callback` | Memvalidasi callback dan menyelesaikan token exchange pada server |
| GET | `/api/v1/integrations/accounts` | Daftar external account dan scope yang diberikan |
| POST | `/api/v1/integrations/accounts/{id}/sync` | Mengantrekan sinkronisasi manual |
| POST | `/api/v1/integrations/accounts/{id}/refresh` | Refresh atau re-authorize credential |
| DELETE | `/api/v1/integrations/accounts/{id}` | Revoke/disconnect dan menerapkan retention policy |
| POST | `/api/v1/webhooks/{provider}` | Menerima callback/push event provider yang terverifikasi |

Gunakan POST untuk memulai authorization agar server dapat mengikat state dengan session FPDP yang sudah terautentikasi. Callback dapat tetap GET jika diwajibkan provider.

## 6. Keamanan OAuth dan connector bersama

Setiap implementasi provider wajib:

1. memakai exact HTTPS redirect URI pada production;
2. membuat `state` cryptographically random, sekali pakai, dan berumur pendek;
3. menggunakan PKCE ketika didukung atau diwajibkan;
4. menukar authorization code hanya pada server;
5. menjauhkan client/partner secret dan refresh token dari browser;
6. mengenkripsi access dan refresh token ketika disimpan;
7. menyimpan granted scope, provider account ID, expiry, dan revocation state;
8. meminta least-privilege scope dan mendukung partial consent;
9. menghapus credential dan authorization code dari log;
10. melakukan refresh dengan locking agar worker concurrent tidak merotasi token yang sama;
11. memverifikasi webhook signature terhadap raw body dan mendeduplikasi event ID;
12. mengimplementasikan disconnect, revocation, data deletion, dan retention policy;
13. menerapkan rate limit, backoff, pagination, dan monitoring versi API;
14. mempertahankan origin, author, timestamp, provider, dan canonical URL pada setiap item impor.

## 7. Alasan RSS tidak resmi dan scraping tidak disarankan

“RSS generator” pihak ketiga dapat melakukan scraping HTML publik atau menyimpan credential user. Service tersebut dapat rusak tanpa pemberitahuan, tidak menangkap perubahan deletion/privacy, melanggar ketentuan platform, dan menambah data processor baru. FPDP sebaiknya:

- memakai first-party API ketika tersedia dan telah disetujui;
- membiarkan user memasukkan feed yang mereka kuasai, misalnya RSS blog pribadi;
- menggunakan canonical link manual atau embed jika diizinkan platform;
- menandai connector unavailable ketika permission belum disetujui;
- tidak pernah melewati access control melalui scraping.

## 8. Prioritas implementasi

1. Pertahankan RSS, Atom, dan Custom API sebagai connector MVP yang stabil.
2. Bangun lifecycle generic OAuth account/token dan encrypted storage.
3. Implementasikan TikTok atau Instagram sebagai social connector pertama hanya setelah kelayakan App Review dikonfirmasi.
4. Implementasikan LinkedIn OIDC secara terpisah sebagai login provider opsional.
5. Implementasikan Shopee di balik interface khusus commerce setelah product/order tersedia.
6. Tambahkan Facebook, X, dan Threads berdasarkan prioritas bisnis, approval, biaya API, dan ketersediaan test account.
7. Tempatkan setiap provider di balik feature flag sampai credential production dan approval terverifikasi.

## 9. Checklist due diligence provider

Sebelum menandai provider “tersedia”, catat:

- nama app/product resmi dan pemilik developer account;
- market serta jenis account yang didukung;
- scope dan use case yang disetujui;
- ketersediaan sandbox/test user;
- versi API saat ini dan tanggal penghentiannya;
- asumsi harga, quota, dan rate limit;
- URL redirect, webhook, deauthorization, dan data deletion;
- umur token dan perilaku refresh;
- persyaratan retention/deletion data;
- bukti review dan tanggal verifikasi berikutnya.

