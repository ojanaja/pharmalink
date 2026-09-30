# Pharmalink: arahan agent

## Mulai dari sini

1. Baca `docs/PRODUCT_BRIEF.md`, `docs/ARCHITECTURE.md`, `docs/SETUP.md`, dan `docs/DOCUMENTATION_STANDARD.md` sebelum merencanakan perubahan.
2. Periksa kode dan keadaan Git aktual. Dokumen tidak mengalahkan bukti dari kode.
3. Gunakan Figma sebagai referensi visual: https://www.figma.com/design/r7UmjKIxj0CZbCMEATGnmQ/Sistem-manajemen-apotek?node-id=0-1
4. Pecah implementasi jadi irisan vertikal kecil yang bisa dijalankan. Catat milestone dan acceptance criteria di `docs/IMPLEMENTATION_PLAN.md`.

## Tujuan produk

Bangun sistem manajemen apotek mikro/kecil untuk owner dan apoteker. Ruang lingkup fungsional ada di `docs/PRODUCT_BRIEF.md`; jangan menambah domain di luar scope tanpa alasan dan catatan.

## Stack tetap

- React + TypeScript + Vite di `frontend/`.
- Laravel 13 API + Sanctum di `backend/`.
- MySQL 8.4, Docker Compose untuk local runtime.
- Zona waktu `Asia/Jakarta`, bahasa antarmuka Indonesia, mata uang Rupiah.
- Ikuti dependency dan lockfile yang ada; jangan mengganti framework atau versi mayor tanpa kebutuhan yang dijelaskan.

## Aturan implementasi

- Pertahankan arsitektur sederhana untuk tim kecil; hindari microservices dan abstraksi tanpa kebutuhan nyata.
- Stok harus dapat ditelusuri dari penerimaan, penjualan, koreksi, dan stock opname. Jaga perubahan kuantitas dan transaksi tetap konsisten secara atomik.
- Pertimbangkan batch dan tanggal kedaluwarsa untuk stok obat; dokumentasikan aturan bisnis yang belum diputuskan sebelum mengunci perilaku.
- Perubahan schema harus melalui Laravel migration. Jangan mengubah data production atau menghapus volume/database.
- Implementasikan UI hanya berdasarkan desain Figma yang dapat diperiksa. Tidak ada fallback visual dari brief, screenshot parsial, atau dugaan.
- Jika Figma tidak dapat diakses atau suatu layar/komponen/interaksi tidak jelas, hentikan pekerjaan UI yang bergantung padanya dan minta akses atau klarifikasi. Lanjutkan pekerjaan non-UI yang independen bila memungkinkan.
- Jaga autentikasi dan otorisasi per role. Role awal: owner dan apoteker.
- Tulis komentar inline Bahasa Indonesia yang ringkas dan faktual hanya untuk aturan bisnis atau edge case yang tidak jelas dari kode. Jangan mengomentari baris yang sudah jelas.
- Dokumentasikan kontrak publik PHP/TypeScript jika signature dan tipe belum menjelaskan format, satuan, efek samping, atau invariannya.
- Perbarui dokumentasi serah-terima terkait saat perilaku, kontrak API/data, atau instruksi setup berubah. Ikuti `docs/DOCUMENTATION_STANDARD.md`.
- Tambahkan atau jalankan pemeriksaan yang relevan untuk tiap perubahan; laporkan perintah dan hasilnya.
- Jangan commit, push, deploy, atau menghapus data tanpa instruksi eksplisit.

## Pemakaian agent

Agent project ada di `.agents/agents/`. `pharmalink-lead` mengoordinasi pekerjaan; minta agent spesialis untuk bagian yang terpisah. Agent spesialis harus mengembalikan temuan lengkap, file yang disentuh, keputusan, dan risiko ke lead. Lead yang menggabungkan perubahan dan memeriksa integrasi.
