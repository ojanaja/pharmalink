# Prompt pertama untuk Kimi

Jalankan Kimi dari root repo dengan agent `pharmalink-lead`, lalu kirim prompt ini sekali:

```text
Mulai development Pharmalink sesuai AGENTS.md dan docs/PRODUCT_BRIEF.md. Baca juga docs/ARCHITECTURE.md, docs/SETUP.md, dan docs/DOCUMENTATION_STANDARD.md, lalu audit repository, Figma, dependency, dan kondisi Docker/Git sebelum mengubah apa pun.

Gunakan agent spesialis project yang tersedia. Minta Software Architect menyusun urutan milestone dan model domain awal; minta Backend Architect dan Database Optimizer mengulas alur data/stok; minta Frontend Developer memetakan layar Figma ke struktur UI. Pakai Explore untuk pembacaan paralel dan Code Reviewer/API Tester untuk pemeriksaan setelah ada perubahan. Jangan minta beberapa agent mengedit file yang sama bersamaan. Lead tetap pemilik keputusan, integrasi, dan hasil akhir.

Tulis atau perbarui docs/IMPLEMENTATION_PLAN.md dengan milestone kecil, acceptance criteria, keputusan, asumsi, dan dependensi. Setelah rencana jelas, mulai milestone pertama yang aman dan bisa didemokan; lanjutkan pekerjaan sesuai rencana tanpa membangun seluruh scope sekaligus. Pertahankan stack di AGENTS.md. Stok, batch kedaluwarsa, harga historis, otorisasi owner/apoteker, dan konsistensi transaksi adalah prioritas domain.

UI wajib mengikuti desain Figma yang dapat diperiksa; tidak ada fallback visual dari brief, screenshot parsial, atau dugaan. Jika Figma tidak dapat diakses atau detail layar, komponen, atau interaksi belum jelas, hentikan pekerjaan UI yang bergantung padanya dan minta akses atau klarifikasi. Lanjutkan pekerjaan non-UI yang independen bila memungkinkan. Jangan menghapus database/volume, mengganti stack, commit, push, atau deploy. Jalankan pemeriksaan yang relevan untuk perubahan yang dibuat. Akhiri dengan ringkasan perubahan, hasil pemeriksaan, milestone berikutnya, dan pertanyaan yang benar-benar memblokir.

Untuk kode, tulis komentar inline Bahasa Indonesia yang ringkas, objektif, dan faktual hanya saat menjelaskan aturan bisnis atau edge case yang tidak jelas dari kode. Jangan menulis komentar yang sekadar mengulang instruksi baris kode. Dokumentasikan kontrak publik PHP/TypeScript hanya jika signature atau tipe belum menjelaskan format, satuan, efek samping, atau invariant. Jika perilaku, kontrak API/data, atau setup berubah, perbarui dokumentasi serah-terima yang relevan.
```

Prompt ini memulai implementasi bertahap. Agent diminta membuat rencana terlebih dahulu, lalu mengerjakan milestone pertama.
