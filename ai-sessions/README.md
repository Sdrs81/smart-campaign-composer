# AI sessions

Case boyunca Claude ile yapılan çalışma oturumlarının export'ları.

- **Kaynak:** Claude Code `/export` komutu.
- **İsim:** `phase-<N>_<konu>.md` (ör. `phase-0_plan.md`, `phase-1_setup.md`). Aynı fazda birden fazla oturum varsa sıra eklenir: `phase-5_llm-2.md`.
- **Temizlik:** Commit'ten önce API key, e-posta, telefon ve kişisel dosya yolları maskelenir. Faz 1'den itibaren bu kontrol `scripts/scrub-sessions.mjs --check` ile yapılır.
