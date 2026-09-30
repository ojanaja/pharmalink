# Standar dokumentasi kode dan serah-terima

Tujuan dokumentasi di Pharmalink adalah membantu developer berikutnya memahami aturan, kontrak, dan keputusan yang tidak tampak langsung dari kode. Dokumentasi harus akurat terhadap implementasi saat ini.

## Komentar inline

- Gunakan Bahasa Indonesia yang ringkas, netral, dan faktual.
- Komentari alasan, batasan, invariant, atau aturan bisnis yang tidak jelas dari nama dan struktur kode.
- Letakkan komentar sedekat mungkin dengan kode yang dijelaskannya.
- Utamakan komentar untuk aturan stok dan batch, tanggal kedaluwarsa, pembulatan dan snapshot harga, otorisasi, transaksi atomik, serta batas waktu laporan jika aturannya tidak jelas dari kode.
- Jangan menjelaskan ulang operasi yang sudah terbaca jelas, menulis slogan atau opini, atau menyatakan asumsi sebagai fakta.
- Hapus atau perbarui komentar yang tidak lagi cocok dengan perilaku kode.
- Hindari TODO tanpa konteks dan langkah tindak lanjut yang dapat dikenali.

Contoh yang baik:

```php
// Simpan harga saat transaksi agar perubahan harga master tidak mengubah riwayat penjualan.
```

Contoh yang tidak membantu:

```php
// Simpan harga ke variabel.
$price = $medicine->price;
```

## PHPDoc dan JSDoc

Gunakan PHPDoc/JSDoc pada API publik bila tipe dan signature saja belum menjelaskan format input, satuan, efek samping, invariant, atau perilaku yang dijamin. Jangan menambah docblock generik pada fungsi yang sudah jelas dari nama dan tipe.

## Dokumentasi serah-terima

Perbarui dokumen yang relevan saat perubahan mengubah:

- Cara memasang, menjalankan, atau memeriksa project → `README.md` atau `docs/SETUP.md`.
- Alur, modul, atau keputusan arsitektur → `docs/ARCHITECTURE.md` atau catatan keputusan terkait.
- Kebutuhan produk yang telah diklarifikasi → `docs/PRODUCT_BRIEF.md`.
- Kontrak API, skema, aturan data, atau integrasi antar modul → dokumentasi API/arsitektur terkait.

Jangan menyalin seluruh kode ke dokumentasi. Catat perilaku yang perlu diketahui untuk memakai, menguji, atau melanjutkan perubahan. Tandai keputusan yang belum dikonfirmasi sebagai asumsi atau pertanyaan terbuka.

## Ringkasan pekerjaan

Untuk milestone atau perubahan lintas modul, catat di `docs/IMPLEMENTATION_PLAN.md` status, acceptance criteria, keputusan, asumsi, pemeriksaan yang benar-benar dijalankan, dan pekerjaan lanjutan. Jangan mengklaim pemeriksaan atau perilaku yang belum diverifikasi.
