# Agency Agents untuk Kimi Code

Project memakai pola role spesialis dari [Agency Agents](https://github.com/msitarzewski/agency-agents), diadaptasi untuk format custom agent Markdown Kimi Code dan scope Pharmalink. Profil tersimpan lokal di `.agents/agents/`; profil project tidak mengubah agent global Kimi. Kimi Code mengenali direktori ini sebagai agent scope project sesuai [dokumentasi agent](https://moonshotai.github.io/kimi-code/en/customization/agents).

## Profil tersedia

- `pharmalink-lead`: agent utama; membaca brief, merencanakan fase, mendelegasikan subtask, mengintegrasikan hasil.
- `software-architect`: domain, batas modul, trade-off, dan keputusan arsitektur.
- `backend-architect`: Laravel API, auth, transaksi, dan aturan bisnis.
- `frontend-developer`: React, alur apoteker, dan pemetaan desain Figma.
- `database-optimizer`: MySQL, batch stok, mutasi, index, dan migrasi.
- `api-tester`: kontrak API, validasi, akses role, dan skenario kegagalan.
- `code-reviewer`: review keamanan, konsistensi stok, regression, dan maintainability.

Agent Kimi adalah profil/prompt, bukan proses terpisah yang otomatis mengerjakan semua hal. Agent utama menentukan kapan sub-agent membantu. Tugas dan file yang sama jangan dikerjakan paralel oleh dua agent.

## Cara pakai

Kimi Code mengenali custom agents project dari `.agents/agents/`. Dari root repository:

```bash
kimi --agent pharmalink-lead
```

atau lewat `./scripts/start-kimi.sh`. Setelah sesi terbuka, arahkan agent lewat `AGENTS.md` root dan `docs/IMPLEMENTATION_PLAN.md` untuk melanjutkan pekerjaan.

## Asal, format, dan lisensi

Role disarikan dan disesuaikan dari konsep dan profil di Agency Agents, yang repo-nya menyatakan berlisensi MIT. Implementasi ini memakai instruksi ringkas khusus Pharmalink dan format agent Kimi Code saat ini. Converter Kimi di repo sumber menghasilkan `agent.yaml` di `~/.config/kimi/agents/`; itu format/path Kimi CLI lama, sedangkan Kimi Code CLI yang terpasang di workstation ini menemukan custom agent Markdown project-local di `.agents/agents/`. Karena itu profil disiapkan dalam format yang didukung runtime aktif. Sumber: [Agency Agents](https://github.com/msitarzewski/agency-agents), [format agent Kimi Code](https://moonshotai.github.io/kimi-code/en/customization/agents).
