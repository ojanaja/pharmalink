/// <reference types="vite/client" />

/** Versi aplikasi dari package.json — di-inject vite define saat build. */
declare const __APP_VERSION__: string
/** Tanggal commit terakhir (dev) atau tanggal build (image produksi). */
declare const __APP_UPDATED__: string
/** Short hash commit terakhir; kosong di image produksi. */
declare const __APP_COMMIT__: string
