# AI sessions

Case boyunca Claude ile yapılan çalışma oturumlarının export'ları.

- **Kaynak:** Claude Code `/export` komutu.
- **İsim:** `YYYY-MM-DD-<konu>.md` (ör. `2026-11-05-plan.md`).
- **Temizlik:** Commit'ten önce API key, e-posta, telefon ve kişisel dosya yolları maskelenir. Faz 1'den itibaren bu kontrol `scripts/scrub-sessions.mjs --check` ile yapılır.
