# Product brief: Pharmalink

## Tujuan

Sistem manajemen apotek skala mikro/kecil. Fokus: pencatatan barang masuk/keluar, transaksi penjualan, pembelian, pantauan stok dan kedaluwarsa, serta laporan yang bisa diekspor.

## Pengguna dan akses

- **Owner:** mengelola apotek, master data, pengguna, pengaturan, seluruh transaksi, dan laporan.
- **Apoteker:** menjalankan pekerjaan operasional yang diizinkan owner, termasuk transaksi dan pengelolaan stok.
- Hak akses harus ditentukan jelas per role. Jangan menganggap dua role punya izin identik.

## Navigasi utama

1. Dashboard
2. Penjualan
3. Persediaan
4. Pembelian
5. Master Data
6. Laporan
7. Pengaturan

## Kebutuhan per menu

### Dashboard

- Ringkasan jumlah transaksi penjualan hari ini.
- Total penjualan harian dalam Rupiah.
- Grafik penjualan tujuh hari terakhir.
- Daftar obat yang mencapai/bawah batas stok minimum dan obat habis.
- Daftar batch mendekati kedaluwarsa, dengan ambang 30 atau 60 hari.
- Quick action ke transaksi dan menu operasional lain.

### Penjualan / Transaksi

- Form transaksi untuk mencari dan menambahkan obat, jumlah, harga satuan, dan total.
- Transaksi mengurangi stok dan tercatat sebagai stock out.
- Tampilkan riwayat transaksi harian.
- Catat pengguna, waktu, item, kuantitas, harga, dan referensi transaksi.

### Persediaan

- Daftar seluruh obat, kategori, jumlah stok, batas minimum, dan status.
- Filter untuk stok menipis, stok habis, dan kedaluwarsa 30/60 hari.
- Ekspor data dan impor dengan template XLSX.
- Stock opname manual di web atau impor template Excel; tampilkan selisih sebelum dikonfirmasi.
- Batas minimum dapat diatur per obat.
- Perubahan stok perlu alasan, waktu, pengguna, serta referensi; jangan menimpa histori mutasi.

### Pembelian

- Riwayat purchase order dikelompokkan/filter berdasarkan supplier.
- Form membuat purchase order dengan item, kuantitas, harga beli, dan total.
- Ringkasan total pembelian per bulan.
- Bedakan status pemesanan dari penerimaan barang. Stok bertambah saat penerimaan dikonfirmasi, bukan saat PO dibuat.

### Master Data

- Master obat: nama, jenis, kategori, satuan, harga jual per satuan, dan batas minimum stok.
- Master supplier: informasi supplier dan harga beli obat per satuan.
- Master harga jual per satuan.
- Perubahan harga tidak boleh mengubah harga historis transaksi yang sudah terjadi.

### Laporan

- Ringkasan penjualan dan total omzet.
- Ringkasan stok obat.
- Ringkasan pembelian obat.
- Filter periode yang jelas dan ekspor laporan.

### Pengaturan

- Profil apotek.
- Pengguna dan hak akses.
- Role awal: owner dan apoteker.

## Aturan domain yang perlu dijaga

- Simpan harga dan nilai uang sebagai bilangan desimal yang presisi; jangan gunakan floating point untuk perhitungan uang.
- Persediaan obat dapat terdiri dari beberapa batch dengan tanggal kedaluwarsa berbeda. Tampilkan stok total dan rincian batch.
- Semua mutasi (penerimaan, penjualan, koreksi, opname) harus dapat ditelusuri dan tidak boleh membuat saldo negatif tanpa aturan eksplisit.
- Simpan snapshot harga jual/beli pada detail transaksi agar laporan historis tetap akurat.
- Proses tulis transaksi dan mutasi stok dalam satu database transaction.
- Jadikan tanggal, zona waktu, pembulatan Rupiah, pembatalan/retur, dan strategi pemilihan batch sebagai keputusan terdokumentasi jika brief belum menetapkan jawabannya.

## Asumsi dan batas

- Target awal satu apotek; multi-cabang belum termasuk sampai dikonfirmasi.
- Dokumen Figma adalah referensi tampilan; kebutuhan fungsional tetap ditentukan oleh brief ini.
- Scope awal tidak otomatis mencakup resep, pasien, dokter, integrasi pembayaran, atau integrasi pihak ketiga.
- Ini tugas akhir: utamakan alur yang bisa didemokan, data konsisten, dokumentasi, dan alasan keputusan yang mudah dipertahankan saat sidang.
