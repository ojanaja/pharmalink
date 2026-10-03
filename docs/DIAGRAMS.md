# Diagram Pharmalink (Mermaid)

Kode sumber diagram untuk dokumentasi/sidang. Render di GitHub/GitLab/Notion/mermaid.live yang mendukung Mermaid. Berasarkan implementasi aktual (bukan rencana) — lihat `docs/IMPLEMENTATION_PLAN.md`.

## 1. Use Case Diagram

```mermaid
flowchart LR
    owner(["👤 Owner"])
    apoteker(["👤 Apoteker"])

    subgraph SYS["Pharmalink — Sistem Manajemen Apotek"]
        UC1((Login / Logout))
        UC2((Kelola Data Obat))
        UC3((Kelola Supplier))
        UC4((Kelola Kategori & Satuan))
        UC5((Buat Transaksi Penjualan))
        UC6((Lihat Riwayat Penjualan))
        UC7((Batalkan Transaksi / Void))
        UC8((Buat Purchase Order))
        UC9((Konfirmasi Penerimaan Barang))
        UC10((Retur Penjualan))
        UC11((Retur Pembelian))
        UC12((Koreksi Stok))
        UC13((Stock Opname))
        UC14((Impor Data XLSX))
        UC15((Lihat Dashboard))
        UC16((Lihat Laporan & Ekspor))
        UC17((Kelola Pengguna & Hak Akses))
        UC18((Ubah Profil Apotek & Pengaturan))
    end

    owner --> UC1
    apoteker --> UC1
    apoteker --> UC5
    apoteker --> UC6
    apoteker --> UC8
    apoteker --> UC9
    apoteker --> UC10
    apoteker --> UC11
    apoteker --> UC12
    apoteker --> UC13
    apoteker --> UC14
    apoteker --> UC15
    apoteker --> UC16
    owner --> UC2
    owner --> UC3
    owner --> UC4
    owner --> UC7
    owner --> UC17
    owner --> UC18
    owner --> UC15
    owner --> UC16

    UC2 -. include .-> UC14
    UC5 -. include .-> UC6
    UC13 -. include .-> UC12
```

## 2. DFD Level 0 (Context Diagram)

```mermaid
flowchart LR
    owner(["Owner"])
    apoteker(["Apoteker"])

    SYS(("Pharmalink<br/>Sistem Manajemen Apotek"))

    owner -->|data master, transaksi, pengaturan| SYS
    apoteker -->|transaksi, penerimaan, opname| SYS
    SYS -->|dashboard, laporan, kartu stok, struk| owner
    SYS -->|dashboard, laporan, kartu stok, struk| apoteker
```

## 3. DFD Level 1

```mermaid
flowchart LR
    owner(["Owner"])
    apoteker(["Apoteker"])

    subgraph P[" "]
        P1["1.0<br/>Autentikasi &<br/>Otorisasi"]
        P2["2.0<br/>Master Data<br/>(obat, supplier,<br/>kategori, satuan)"]
        P3["3.0<br/>Pembelian &<br/>Penerimaan"]
        P4["4.0<br/>Penjualan<br/>(Kasir FEFO)"]
        P5["5.0<br/>Koreksi &<br/>Stock Opname"]
        P6["6.0<br/>Retur &<br/>Pembatalan"]
        P7["7.0<br/>Laporan, Dashboard<br/>& Ekspor"]
    end

    D1[("D1<br/>Pengguna")]
    D2[("D2<br/>Obat & Master")]
    D3[("D3<br/>Batch Stok")]
    D4[("D4<br/>Mutasi Stok<br/>(ledger)")]
    D5[("D5<br/>Transaksi<br/>Penjualan & PO")]
    D6[("D6<br/>Pengaturan")]

    owner --> P1
    apoteker --> P1
    P1 <--> D1

    owner --> P2
    P2 <--> D2
    P2 --> D3

    apoteker --> P3
    owner --> P3
    P3 <--> D2
    P3 --> D3
    P3 --> D4
    P3 <--> D5

    apoteker --> P4
    P4 <--> D2
    P4 <--> D3
    P4 --> D4
    P4 --> D5

    apoteker --> P5
    P5 <--> D3
    P5 --> D4

    apoteker --> P6
    owner --> P6
    P6 <--> D3
    P6 --> D4
    P6 <--> D5

    P7 <--> D2
    P7 <--> D3
    P7 <--> D4
    P7 <--> D5
    P7 -->|dashboard & laporan| owner
    P7 -->|dashboard & laporan| apoteker
```

## 4. ERD

```mermaid
erDiagram
    USERS ||--o{ SALES : "mencatat"
    USERS ||--o{ PURCHASE_ORDERS : "membuat"
    USERS ||--o{ STOCK_MOVEMENTS : "menulis"
    USERS ||--o{ STOCK_ADJUSTMENTS : "menulis"
    USERS ||--o{ STOCK_OPNAMES : "melakukan"
    USERS ||--o{ PURCHASE_RETURNS : "membuat"

    CATEGORIES ||--o{ MEDICINES : "mengelompokkan"
    UNITS ||--o{ MEDICINES : "menyatakan"
    SUPPLIERS ||--o{ PURCHASE_ORDERS : "dituju"
    SUPPLIERS ||--o{ PURCHASE_RETURNS : "menerima retur"

    MEDICINES ||--o{ BATCHES : "memiliki"
    MEDICINES ||--o{ STOCK_MOVEMENTS : "ditelusuri"
    BATCHES ||--o{ STOCK_MOVEMENTS : "saldo per batch"
    MEDICINES ||--o{ MEDICINE_SUPPLIER : "harga beli per supplier"
    SUPPLIERS ||--o{ MEDICINE_SUPPLIER : "menjual"

    SALES ||--o{ SALE_ITEMS : "berisi"
    SALE_ITEMS }o--|| MEDICINES : "menjual"
    SALE_ITEMS }o--|| BATCHES : "batch asal FEFO"
    SALE_RETURNS ||--o{ SALE_RETURN_ITEMS : "berisi"
    SALE_RETURN_ITEMS }o--|| SALE_ITEMS : "mengembalikan"

    PURCHASE_ORDERS ||--o{ PURCHASE_ORDER_ITEMS : "berisi"
    PURCHASE_ORDER_ITEMS }o--|| MEDICINES : "memesan"
    PURCHASE_ORDERS ||--o{ PURCHASE_RECEIPTS : "diterima oleh"
    PURCHASE_RECEIPTS ||--o{ PURCHASE_RECEIPT_ITEMS : "berisi"
    PURCHASE_RECEIPT_ITEMS }o--|| BATCHES : "membentuk"
    PURCHASE_RETURN_ITEMS }o--|| PURCHASE_RECEIPT_ITEMS : "mengembalikan"

    STOCK_OPNAMES ||--o{ STOCK_OPNAME_ITEMS : "menghitung"
    STOCK_OPNAME_ITEMS }o--|| BATCHES : "dihitung"

    USERS {
        bigint id PK
        string name
        string email
        enum role "owner | apoteker"
        boolean is_active
    }
    MEDICINES {
        bigint id PK
        string code
        string name
        decimal sale_price
        int min_stock
        boolean is_active
    }
    BATCHES {
        bigint id PK
        string batch_number
        date expiry_date
        int quantity_on_hand
        decimal purchase_price
    }
    STOCK_MOVEMENTS {
        bigint id PK
        enum type "receipt|sale|cancellation|adjustment|opname|return_in|return_out"
        int quantity "signed"
        int balance_after
        string reference_type
        bigint reference_id
        string reason
    }
    SALES {
        bigint id PK
        string invoice_number
        datetime sold_at
        decimal subtotal
        decimal discount
        decimal total
        enum status "completed | cancelled"
    }
    PURCHASE_ORDERS {
        bigint id PK
        string po_number
        enum status "ordered|partially_received|received|cancelled"
    }
```

## 5. Flowchart — Penjualan (Kasir, FEFO)

```mermaid
flowchart TD
    A([Mulai]) --> B[Kasir pilih obat & jumlah]
    B --> C[Server ambil harga jual saat ini]
    C --> D[Validasi diskon ≤ subtotal]
    D --> E{Untuk tiap item:}
    E --> F[Kunci batch FEFO<br/>expiry terdekat dulu, stok > 0,<br/>belum kedaluwarsa]
    F --> G{Stok total cukup?}
    G -- Tidak --> H[422 detail per item<br/>ROLLBACK TOTAL]
    H --> Z([Transaksi ditolak])
    G -- Ya --> I[Kurangi qty per batch<br/>pecah antar batch bila perlu]
    I --> J[Tulis mutasi stok keluar<br/>+ balance_after + snapshot harga]
    J --> K[Ulangi item berikutnya]
    K --> E
    E --> L[Simpan header sale<br/>nomor TRX-ymd-seq]
    L --> M{Pembayaran tunai cukup?}
    M -- Tidak --> H
    M -- Ya --> N([COMMIT<br/>Struk tersimpan])
```

## 6. Flowchart — Penerimaan Barang dari PO

```mermaid
flowchart TD
    A([PO dibuat: status ORDERED]) --> B["Stok TIDAK berubah"]
    B --> C[Supplier mengirim barang]
    C --> D[Apoteker input penerimaan:<br/>po_item, qty, nomor batch, tanggal kedaluwarsa]
    D --> E{Qty ≤ sisa pesanan?}
    E -- Tidak --> F[422 detail sisa<br/>ROLLBACK]
    E -- Ya --> G{Nomor batch valid?<br/>identitas fisik unik}
    G -- Tidak --> F
    G -- Ya --> H[Cari atau buat batch]
    H --> I[Tambah stok batch + mutasi masuk<br/>reference receipt item]
    I --> J{Semua item PO diterima penuh?}
    J -- Ya --> K[Status PO: RECEIVED]
    J -- Tidak --> L[Status PO: PARTIALLY_RECEIVED]
    K --> M([COMMIT])
    L --> M
```

## 7. Activity Diagram — Stock Opname

```mermaid
flowchart TD
    S([Mulai]) --> A[Buat sesi opname OPN-ymd-seq]
    A --> B[Snapshot saldo SEMUA batch berstok<br/>system_qty + lock konsisten]
    B --> C[Petugas menghitung fisik per batch]
    C --> D{Fisik ≠ sistem?}
    D -- Ya --> E[Isi jumlah fisik + ALASAN WAJIB]
    D -- Tidak --> F[Lewati / cocok]
    E --> G[Tampilkan preview selisih<br/>sebelum konfirmasi]
    F --> G
    G --> H{Saldo sistem berubah<br/>sejak snapshot?}
    H -- Ya --> I[422: buat sesi opname baru]
    H -- Tidak --> J[Konfirmasi]
    J --> K[Tulis mutasi opname hanya<br/>untuk batch berselisih]
    K --> L[Status: CONFIRMED — immutable]
    L --> E2([Selesai])
    I --> E2
```

## 8. Sequence Diagram — Transaksi Penjualan

```mermaid
sequenceDiagram
    actor K as Kasir (Apoteker)
    participant FE as Frontend (React)
    participant API as Laravel API
    participant SS as SaleService
    participant ST as StockService
    participant DB as MySQL

    K->>FE: Pilih obat, qty, bayar
    FE->>API: POST /api/sales {items, discount}
    API->>SS: create()
    SS->>DB: BEGIN TRANSACTION
    SS->>DB: lockForUpdate batch FEFO per obat
    DB-->>SS: daftar batch + saldo
    alt stok tidak cukup
        SS-->>API: throw 422 detail item
        API-->>FE: 422 {medicine_id, requested, available}
        FE-->>K: Panel stok tidak cukup
    else stok cukup
        SS->>ST: deduct(batch, qty, sale_item)
        ST->>DB: UPDATE batch qty + INSERT movement (balance_after)
        SS->>DB: INSERT sale + sale_items (snapshot harga & HPP)
        SS->>DB: COMMIT
        DB-->>SS: ok
        SS-->>API: Sale + nomor TRX-ymd-seq
        API-->>FE: 201
        FE-->>K: Layar Transaksi Berhasil
    end
```

## 9. Class Diagram — Domain Backend (ringkas)

```mermaid
classDiagram
    class StockService {
        +add(Batch, qty, type, reference, reason)
        +deduct(Batch, qty, type, reference, reason)
        <<satu-satunya pintu ubah stok>>
    }
    class BatchService {
        +findOrCreateForReceipt(medicine, batchNo, expiry)
        <<nomor batch = identitas fisik>>
    }
    class SaleService {
        +create(items, discount) Sale
        <<FEFO, snapshot harga + cost_price>>
    }
    class PurchaseOrderService {
        +create(supplier, items) PurchaseOrder
    }
    class PurchaseService {
        +receive(po, items) PurchaseReceipt
    }
    class StockAdjustmentService {
        +create(batch, qty, reason)
    }
    class StockOpnameService {
        +create() StockOpname
        +updateCounts(counts)
        +confirm()
        <<diff dari saldo live>>
    }
    class SaleVoidService {
        +void(sale, reason)
        <<reversal ke batch asal, owner only>>
    }
    class SaleReturnService {
        +return(sale, items, reason)
    }
    class PurchaseReturnService {
        +create(items, reason)
        <<batas returnable per receipt item>>
    }
    class ReportService {
        +sales(period) +purchases(period)
        +stock() +expiry(days)
        +profitLoss(period)
    }

    SaleService --> StockService
    PurchaseService --> StockService
    PurchaseService --> BatchService
    PurchaseReceiptService --> BatchService
    PurchaseReturnService --> StockService
    StockAdjustmentService --> StockService
    StockOpnameService --> StockService
    SaleVoidService --> StockService
    SaleReturnService --> StockService
    ReportService --> Sale : baca snapshot
```

## 10. State Diagram — Status Dokumen

```mermaid
stateDiagram-v2
    [*] --> Ordered : PO dibuat
    Ordered --> PartiallyReceived : penerimaan parsial
    PartiallyReceived --> Received : sisa diterima penuh
    Ordered --> Received : penerimaan penuh sekali tahap
    Ordered --> Cancelled : batal (belum ada penerimaan)
    Received --> [*]

    [*] --> Completed : sale dibuat
    Completed --> Cancelled : void oleh owner (reversal stok)
    Completed --> [*] : retur parsial (sale tetap completed)

    [*] --> Draft : sesi opname dibuat
    Draft --> Confirmed : konfirmasi
    Confirmed --> [*] : immutable
```

## 11. Deployment Diagram

```mermaid
flowchart LR
    subgraph Client["Mesin Pengguna"]
        BR["Browser<br/>(React + Vite dev :5173)"]
    end

    subgraph Docker["Docker Compose"]
        FE["frontend<br/>node:22-alpine<br/>Vite dev server"]
        BE["backend<br/>php:8.5-cli + ext gd<br/>Laravel serve :8000"]
        DB[("mysql:8.4<br/>volume mysql_data")]
    end

    BR -- "HTTP :5173 (UI + HMR)" --> FE
    BR -- "HTTP /api/* (Bearer token)" --> FE
    FE -- "proxy /api → backend:8000" --> BE
    BE -- "PDO MySQL" --> DB
```
