# Smart Campaign Composer: Uygulama Planı

Tarih: 2026-11-04, güncelleme 2026-11-09 · Durum: onaylandı · Tasarım seçenekleri ve yanıtlanan sorular Bölüm 2'de (K1–K23)

## 0. Bağlam

Insider One case'i: pazarlamacı doğal dille bir brief yazar; Claude brief'ten segment kuralı ve SMS/email içeriği üretir; insan önizler, düzenler ve onaylar; mesajlar kuyruk üzerinden mock provider'a gider; dashboard durumu canlı gösterir. Değerlendirmede en ağır kalem LLM entegrasyonu (%25): Claude çıktısının yapılandırılmış, backend'de doğrulanmış ve gerçek key olmadan test edilebilir olması. Teslim süresi 7 gün; öncelik çekirdek akışın sağlam olması. Repo şu an boş (yalnızca `docs/`).

## 1. Mimari ve teknoloji özeti

- **Backend:** Laravel 12 (PHP 8.4), yalnızca JSON API, FrankenPHP container. Feature klasörleri: `app/Segment`, `app/Llm`, `app/Content`, `app/Delivery`; davranış zengin modellerde (`Campaign::approve()`, `Delivery::markSent()`).
- **Veri ve kuyruk:** PostgreSQL 17 (kullanıcılar, kampanyalar, gönderimler, LLM çağrı kayıtları); Redis 7 (Laravel queue, kanal başına ayrı `sms` ve `email` kuyruğu, global rate limiter, kilitler).
- **LLM:** `LlmClient` arayüzü. `ClaudeLlmClient` (Messages API, JSON şemalı çıktı, streaming) ve `FakeLlmClient` (testler ve key'siz geliştirme). Key yoksa sistem otomatik olarak fake ile açılır.
- **Güvenilirlik zinciri:** LLM JSON → şema ve iş kuralı doğrulaması → hata varsa hatalar Claude'a geri verilip en fazla 2 onarım denemesi → tipli değer nesnesi (`SegmentRule`, `MessageContent`). LLM çıktısı asla SQL olmaz: kural, izinli alan/operatör kayıt defterinden geçip binding'li query builder'a çevrilir.
- **Gönderim:** Onayda kitle `deliveries` tablosuna tek bir `INSERT … SELECT` ile dondurulur (unique: kampanya + kişi + kanal) → mesaj başına job → Redis throttle (kanal başına ≤100/sn) → mock provider (`Idempotency-Key` ile). Retry: hata sınıfına göre üstel backoff + jitter, 429'da `Retry-After`.
- **Mock provider:** Ayrı container, sade PHP + Redis. Gecikme, 500, 429, timeout ve "kabul edip 500 dön" hatalarını ayarlanabilir oranlarla üretir; tekrar eden mesajları sayar.
- **Canlı akış:** İçerik üretimi SSE (POST + `fetch` stream), dashboard SSE (`EventSource`, değişince snapshot). `/api/health`, `/api/metrics` (Prometheus text).
- **Frontend:** Vue 3 + TypeScript + Vite + Pinia + Vue Router + Tailwind + vue-i18n (Türkçe/İngilizce arayüz, K23); nginx statik servis ve `/api` reverse proxy.
- **Test ve kurulum:** Pest (unit + gerçek Postgres/Redis'e karşı integration), Vitest, Playwright e2e (fake LLM). `docker compose up` (migrate + seed otomatik), `make test` (docker içinde, host bağımlılığı yok).

```
backend/  frontend/  mock-provider/  docker/  scripts/  ai-sessions/  docs/
docker-compose.yml  Makefile  .env.example  README.md  DECISIONS.md  CLAUDE.md  .claude/  .mcp.json
```

## 2. Kararlar

Yalnızca `DECISIONS.md`'ye girecek kararlar: case'te doğrudan geçenler, mimariyi belirleyenler ve proje akışını kolaylaştıranlar. Tasarım seçenekleri ve yanıtlanan sorular burada birleşti. Her karar, "Görevler" sütunundaki ilk görevin commit'inde `DECISIONS.md`'ye yazılır; aynı sütun kararın plana nerede işlendiğini gösterir. Küçük varsayımlar (seed boyutu, içerik dili, yasal metin, Windows'ta `make`) ilgili görevde ve README'de (11.1); süreç kuralları (süre, raporlama, `.env` erişimi, Claude araçları) Bölüm 3'te ve Faz 1'de.

### Altyapı

| #   | Karar                                                                                                                        | Gerekçe (ilke)                                                                                                                                   | Alternatifler                  | Görevler      |
| --- | ---------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------ | ------------- |
| K1  | Laravel 12, FrankenPHP klasik mod (Octane yok)                                                                               | Queue, Redis throttle, migration, seeder, Pest, SSE hazır; tek container, stream'de tampon ayarı yok; Octane'in state sızıntısı riski yok (KISS) | Symfony, Slim; nginx + php-fpm | 2.1, 2.4      |
| K2  | PostgreSQL                                                                                                                   | `COUNT(*) FILTER` ile tek sorguda kanal sayımı, `ON CONFLICT DO NOTHING` ile idempotent snapshot, JSONB                                          | MySQL, SQLite                  | 2.8, 4.4, 8.5 |
| K3  | Redis: kuyruk + global rate limiter + kilit                                                                                  | Tek bileşen üç işi görür; bu ölçekte RabbitMQ ek operasyon yükü (YAGNI)                                                                          | RabbitMQ, DB queue             | 2.3, 2.8, 8.4 |
| K4  | Unit testler bağımlılıksız; integration testler docker'daki Postgres/Redis'e karşı (ayrı test DB); `make test` docker içinde | Gerçek bileşen davranışı (`FILTER`, `ON CONFLICT`, throttle) test edilir; değerlendiricinin host'unda bağımlılık gerekmez                        | SQLite in-memory               | 2.8, 2.9      |

### Kitle

| #   | Karar                                                                                                                                                                                                                | Gerekçe (ilke)                                                                                                                                                       | Alternatifler                    | Görevler      |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | ------------- |
| K5  | Davranış verisi kullanıcı üzerinde geniş özet kolonlarda (demografi, hesap, alışveriş, sepet, sadakat, izin). Örnek brief: `last_cart_add_at` son 30 gün ∧ `last_purchase_at` son 30 günde değil (hiç almamış dahil) | Olay toplama hattı kapsam dışı; koşullar indeksli kolon karşılaştırması (KISS). Örnek brief yalnızca örnek, alanlar dar tutulmaz. Yeni alan = kolon + registry kaydı | Olay tabloları + `EXISTS`        | 3.1, 3.2, 4.3 |
| K6  | Segment dili: düz AND listesi, `in`/`not_in` ile çoklu değer, şema versiyonlu                                                                                                                                        | Tipik brief'leri karşılar; doğrulama ve prompt kısa kalır (YAGNI); AND/OR ağacına genişleme kolay                                                                    | İç içe AND/OR ağacı, JSON Logic  | 4.1, 4.2      |
| K7  | LLM çıktısı asla SQL olmaz: `FieldRegistry` (tek kaynak) → `SegmentRuleParser` (geçersiz `SegmentRule` var olamaz) → binding'li query builder                                                                        | Case şartı. Registry prompt'u, JSON şemasını, doğrulayıcıyı ve derleyiciyi besler (DRY); kolon adı yalnızca registry'den (Defensive)                                 | LLM'den SQL veya `where` üretmek | 4.1–4.3, 5.5  |
| K8  | İzin iki noktada kontrol edilir: kitle sayımı ve snapshot'ta, gönderim anında tekrar (değiştiyse `skipped`)                                                                                                          | Case şartı; onay ile gönderim arasında izin değişebilir (Defensive)                                                                                                  | Yalnızca kitle anında            | 4.4, 8.3, 8.5 |

### LLM

| #   | Karar                                                                                                                                                                                                                                 | Gerekçe (ilke)                                                                                                                                                 | Alternatifler               | Görevler             |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- | -------------------- |
| K9  | `LlmClient` arayüzü; `ClaudeLlmClient` Laravel HTTP client ile; model env'den (`claude-sonnet-5-5`, Haiku 5.5 maliyet seçeneği); key yoksa `FakeLlmClient` otomatik                                                                   | Case şartı: testler key'siz. `docker compose up` key'siz çalışır; `Http::fake` ile test kolay; SDK'ya geçiş tek sınıf (DIP)                                    | Resmi PHP SDK               | 2.3, 5.1, 5.2, 5.11  |
| K10 | Yapılandırılmış çıktı: structured outputs (JSON şeması registry'den); API desteklemiyorsa zorunlu tool use. Backend doğrulaması her durumda                                                                                           | Case şartı: JSON çıktı. Şema uyumu modelde garanti edilir, onarım turu azalır; iş kuralları şemaya sığmaz. Güncel API 5.2'de `claude-api` skill ile doğrulanır | Yalnızca prompt ile JSON    | 5.2, 5.5, 5.9        |
| K11 | Güvenilirlik zinciri: parse → doğrula → hataları Claude'a geri ver, en fazla 2 onarım → tipli değer nesnesi ya da yol bazlı anlamlı hata. Eşleşmeyen ifade `unsupported_criteria`'ya. Her çağrı `llm_calls`'a; `llm:eval` ile ölçülür | Case'in en önemli kısmı. Uydurma yerine açık "eşleştiremedim"; prompt injection doğrulamayı aşamaz; hata ve gecikme görünür                                    | Tek deneme; sınırsız retry  | 5.4, 5.6, 5.10, 5.12 |
| K12 | İçerik stream'i: SSE, POST + `fetch` stream; backend ham JSON delta'larını iletir, frontend kısmi JSON'u gösterir, doğrulanmış sonuç `final` olayıyla gelir                                                                           | Case şartı: stream. Tek yönlü akışa yeterli; yan etkili işlem GET olmaz. Kısmi ayrıştırma her karede O(n²) ama n < 3 KB                                        | WebSocket (Reverb), polling | 6.3, 10.1, 10.4      |

### İçerik ve onay

| #   | Karar                                                                                                                                                                                                                                                                                                                                                           | Gerekçe (ilke)                                                                                                                                                 | Alternatifler                                    | Görevler                       |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------------------------ |
| K13 | Limitler kullanıcının gördüğü karakterle (grapheme: backend `grapheme_strlen`, frontend `Intl.Segmenter`): SMS ≤160, konu ≤80, email gövdesi düz metin ≤2.000. Kişiye giden metin üzerinden, kitlenin her değişken için en uzun değeriyle en kötü durum. LLM ve insan aynı doğrulayıcıdan geçer; limitler tek config'te, frontend `/api/content/rules`'tan alır | Case şartı. Limit alıcıda garanti edilir, gönderimde tekrar kontrol (Defensive); tek kaynak (DRY). Operatördeki GSM-7/UCS-2 segment farkı bilinen sadeleştirme | Şablon uzunluğu, sabit bütçe, GSM segment sayımı | 2.4, 4.4, 5.7, 5.8, 6.4, 10.5  |
| K14 | Kişiselleştirme: izinli değişken listesi (`{{first_name}}`, `{{last_name}}`, `{{city}}`), her biri fallback'li; tek geçişte render; bilinmeyen değişken reddedilir                                                                                                                                                                                              | Case şartı. Şablon enjeksiyonu yok; render O(n)                                                                                                                | Twig/Blade gibi şablon motoru                    | 5.7, 5.8                       |
| K15 | İnsan onayı: kampanya durum makinesi (zengin model). Yalnızca üretilen SMS ve email metni, yalnızca onaydan önce düzenlenir; kural salt-okur (brief değiştirilip yeniden üretilir); onay kilitli ve idempotent                                                                                                                                                  | Case akışı; geçersiz geçiş erken hata verir (Rich Domain Model, Defensive); kural editörü gereksiz (YAGNI)                                                     | Kural editörü                                    | 3.3, 6.2, 6.4, 8.5, 10.5, 10.6 |

### Gönderim

| #   | Karar                                                                                                                                                                                            | Gerekçe (ilke)                                                                                                                                                            | Alternatifler                                | Görevler           |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- | ------------------ |
| K16 | Onayda kitle `INSERT … SELECT … ON CONFLICT DO NOTHING` ile `deliveries`'e dondurulur; mesaj başına job, `lazyById` ile dağıtılır                                                                | Set-based, tek round-trip, bellek sabit; mesaj başına job retry'ı basit tutar (KISS)                                                                                      | PHP'de döngüyle ekleme; toplu (batch) job    | 8.5                |
| K17 | Rate limit: kanal başına ayrı kuyruk + `Redis::throttle(...)->block()`; tüm worker'lar için global giden sınır 100/sn; mock provider da aynı sınırı uygular ve 429 döner                         | Case şartı. Atomik Redis işlemleri O(1); `block` ile beklenir, deneme sayacı yanmaz. Sabit pencere sınırda kısa patlamaya izin verebilir; ölçülür, gerekirse token bucket | Lua token bucket; saniyelik batch dispatcher | 7.3, 8.4, 8.7, 8.8 |
| K18 | Retry hata sınıfına göre: 429 → `Retry-After`; 5xx/timeout → üstel backoff + full jitter, en fazla 5 deneme; diğer 4xx → kalıcı. Kalıcı hata `failed` kalır, dashboard'da görünür                | Case: "retry stratejisini sen tasarla". Gereksiz tekrar ve senkron dalgalanma olmaz; `RetryPolicy` saf sınıf, unit test edilir (SRP)                                      | Sabit aralık; circuit breaker                | 8.1, 8.2, 8.3      |
| K19 | Çift gönderim üç katmanda önlenir: DB unique (kampanya + kişi + kanal), atomik claim (koşullu UPDATE), provider `Idempotency-Key`. Farklı kampanyalar aynı kişiye gidebilir (frekans sınırı yok) | Case şartı. Satır iki kez oluşmaz, iki worker aynı satırı alamaz, timeout sonrası tekrar provider'da tekilleşir (Defensive)                                               | Redis set; yalnızca `ShouldBeUnique` job     | 3.5, 7.2, 8.3, 8.5 |
| K20 | Mock provider ayrı container (sade PHP + Redis): idempotency, ayarlanabilir hata enjeksiyonu (gecikme, 500, 429, timeout, kabul edip 500), `/stats`                                              | Case şartı. Gerçek ağ hataları oluşur; bağımsız sayaçlar tekrarın olmadığını kanıtlar (SRP)                                                                               | Ana uygulamada route; başka dil              | 7.1–7.3            |

### Takip ve kapsam

| #   | Karar                                                                                                                                                                                                          | Gerekçe (ilke)                                                                                                                                           | Alternatifler                                                                                   | Görevler      |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | ------------- |
| K21 | Canlı durum SSE ile (1 sn, yalnızca değişince, tek `GROUP BY` sorgusu); `/api/metrics` Prometheus text, sorgu anında hesaplanır; `/api/health`: DB/Redis kritik, provider `degraded`                           | Case şartı. Ek container ve sayaç durumu yok (KISS); `EventSource` kendiliğinden yeniden bağlanır                                                        | Polling; Reverb; sayaç tabanlı metrik                                                           | 9.1–9.4, 10.7 |
| K22 | Kimlik doğrulama yok: tek pazarlamacı, yerel demo                                                                                                                                                              | Case'te istenmiyor (YAGNI); README'de açıkça yazılır                                                                                                     | Sanctum token                                                                                   | 11.1          |
| K23 | Arayüz dili tarayıcıda seçilir (vue-i18n, Türkçe/İngilizce, başlıkta tek buton, seçim `localStorage`'da); yalnızca frontend'in kendi metinleri çevrilir, API mesajları ve model içeriği olduğu gibi gösterilir | Dil bir görüntüleme tercihidir: sunucu durumu gerekmez, müşteriye giden içerik değişmez; kural etiketleri ve hata mesajları tek kaynakta (backend) kalır | `Accept-Language` ile backend çevirisi; hata kodlarını frontend'de çevirmek; dil önekli rotalar | 10.9          |

## 3. Çalışma akışı

- **Dal:** faz başına bir dal (`phase-N/<ad>`). `main`'e commit yok (hook engeller).
- **Görev döngüsü:** `/task N.M` → Claude: önce ilgili rule ve dokümanlar (Docs first) → uygulama → test → lint → rapor. Sen doğrularsın, "onay" dersen Claude commit atar ve `plan.md`'deki kutuyu işaretler.
- **Git öncesi özet (zorunlu):** Her görevin raporu ve her fazın kapanışı, herhangi bir git komutundan (`add`, `commit`, `push`, PR) hemen önce, değişen/eklenen her dosya için bir özet tablosu içerir: `dosya | yeni/değişti | ne yapıldı | neden`. Ardından kabul kriteri kanıtı (komut + çıktı) ve doküman sorguları gelir. Faz kapanışındaki özet fazın tüm dosyalarını kapsar.
- **Faz sonu:** `/finish-phase` → `make test` + `make lint`, `review-pr`, `/code-review` (genelde `medium`; Faz 5 ve 8 için `high`), en fazla 2 tur, reddedilen bulgu listesi → dosya bazlı faz özeti → onayla push (`ask`) ve PR. Merge sende.
- **Kararlar:** Bölüm 2'deki her karar, "Görevler" sütunundaki ilk görevin commit'inde `DECISIONS.md`'ye girer. Uygulama sırasında çıkan yeni bir önemli karar önce Bölüm 2'ye eklenir, sonra aynı şekilde yazılır.
- **Session export:** Her oturum sonunda `/export` → `ai-sessions/phase-<N>_<konu>.md` (ör. `phase-1_setup.md`; aynı fazda ikinci oturum `phase-1_setup-2.md`); commit'ten önce `node scripts/scrub-sessions.mjs --check`.
- **Dil:** Projenin tüm dosyaları ve içerikleri İngilizce yazılır: kod, yorumlar, config dosyaları (`.gitignore` yorumları dahil), `README.md`, `DECISIONS.md`, `CLAUDE.md`, rules/skills/agents, hook ve script mesajları, commit mesajları, PR başlığı ve açıklaması. Türkçe kalanlar: bu plan (`docs/plan.md`, çalışma belgesi), Claude'un sohbetteki raporları ve dosya bazlı özetler. Ürün içeriği (LLM'in ürettiği kampanya metni) ürün gereği Türkçe kalır (5.9, 11.1). Arayüz metinleri ise Türkçe/İngilizce seçilebilir (K23); çeviri dosyaları (`frontend/src/i18n/tr.ts`) de bu "proje dosyaları İngilizce" kuralının bilinçli istisnasıdır.
- **Takvim (çekirdek akış 5 günde):**

  | Gün | Fazlar                                  | Gün sonu ölçütü                                                                 |
  | --- | --------------------------------------- | ------------------------------------------------------------------------------- |
  | G1  | Faz 1, Faz 2                            | `docker compose up` sağlıklı, `make test` yeşil, Claude araçları çalışıyor      |
  | G2  | Faz 3, Faz 4, Faz 5.1–5.4               | Seed ve segment derleyicisi çalışıyor, LLM istemcisi testli                     |
  | G3  | Faz 5.5–5.11, Faz 6, Faz 7              | Brief → kural → sayılar → stream içerik API'den çalışıyor; mock provider ayakta |
  | G4  | Faz 8, Faz 9                            | `make smoke` yeşil, stats/health/metrics hazır                                  |
  | G5  | Faz 10                                  | Çekirdek akış tarayıcıda uçtan uca, `make e2e` yeşil                            |
  | G6  | Faz 11                                  | Teslim paketi                                                                   |
  | G7  | Tampon, İsteğe Bağlı Kısımlar (CI önce) |                                                                                 |

- **Gerideysek önce kesilecekler** (çekirdek akışı bozmayanlar, README'de "yapılmadı" olarak yazılır): 5.12 `llm:eval` (altın brief testleri fake ile unit testte kalır), 9.5 JSON log, 2.1'de Larastan, 10.8 Playwright (yerine `make smoke` + elle tarayıcı kontrolü), 1.13 deneme koşusu (Faz 2'nin ilk görevinde yapılır).

Görev formatı: **İş** · **Dosyalar** · **Bağımlı** · **Kabul** (senin doğrulama yöntemin).

---

## Faz 0: Plan · tamamlandı (2026-11-05)

`docs/plan.md` ve `ai-sessions/` (planlama oturumunun export'u: `phase-0_plan.md`), `phase-0/plan` dalı ve PR #1. Kurulum araçları henüz olmadığı için görev formatı ve `finish-phase` dışında, elle yapıldı.

---

## Faz 1: Kurulum (Claude araçları) · G1

Önceki projeden alınanlar: tam `settings.json` baştan, test script'li fail-closed guard hook, iş akışı skill'leri ilk günden, inceleme döngüsünde tur sınırı, hook kanıtı için log satırı. Bu projeye göre değişenler: görev bazlı ilerleme olduğu için tek `task` skill'i (önceki `phase`, `api-endpoint` ve `frontend-feature` yerine), hook'lar Node ile, `.env.example` deny dışında.

**Araç kararları**

| Araç            | Karar                                                                                          | Neden                                                                                                                      |
| --------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `CLAUDE.md`     | Var (~500 token)                                                                               | Komutlar, mimari değişmezler, çalışma kuralları her oturumda lazım.                                                        |
| Rules           | `clean-code`, `backend`, `frontend` (`paths:` ile)                                             | Senin ilkelerin ve stack kuralları yalnızca kod işinde yüklenir.                                                           |
| Skills          | `task`, `review-pr`, `finish-phase`                                                            | Görev döngüsü, salt-okur inceleme ve faz kapanışı. Docs-first adımı `task` içinde zorunlu.                                 |
| Agents          | `test-runner` (haiku)                                                                          | Test çıktısını ana bağlamdan uzak tutar.                                                                                   |
| Hooks           | `guard.mjs` (PreToolUse), `format.mjs` (PostToolUse)                                           | Tehlikeli komut ve sır engeli; otomatik format. Node ile: `jq` ve Windows/Git Bash farkı yok, `node:test` ile test edilir. |
| `settings.json` | Tam liste bir kerede                                                                           | İzin sorularını baştan keser; Claude kendisi düzenleyemez, sen uygularsın.                                                 |
| MCP             | `context7` (Faz 1), `laravel-boost` (Görev 2.2)                                                | Vue, Pinia, Tailwind, Vitest, Playwright dokümanları; Laravel dokümanı, DB şeması.                                         |
| Plugin          | `security-guidance`                                                                            | Commit/push sonrası güvenlik bulgusu; önceki projede gerçek bulgular yakaladı.                                             |
| Built-in        | `claude-api` skill, `/code-review`, `/security-review`, `/doctor`, `/fewer-permission-prompts` | Claude API dokümanı, inceleme, sağlık kontrolü, izin listesini kullanıma göre genişletme.                                  |
| Atlananlar      | `phase` skill                                                                                  | Her görevi sen doğruluyorsun; gözetimsiz faz koşusu gerekmiyor.                                                            |
|                 | `api-endpoint`, `frontend-feature` skill'leri                                                  | Docs-first `task`'ta, konvansiyonlar rule'larda; tekrar olur (DRY).                                                        |
|                 | `browser-check` skill, Playwright MCP                                                          | `make e2e` script'i tekrarlanabilir ve testlerin parçası.                                                                  |
|                 | GitHub MCP, DB MCP                                                                             | `gh` CLI ve Boost yeterli.                                                                                                 |
|                 | Global `~/.claude/CLAUDE.md`, output style, statusline                                         | Bu projeye katkısı yok.                                                                                                    |

### 1.1 Repo başlangıcı

- **İş:** Faz 0 PR'ı merge edildikten sonra güncel `main`'den `phase-1/setup` dalı; `.gitignore` (vendor, node_modules, `.env`, build çıktıları, `.claude/logs`), `.gitattributes` (`* text=auto eol=lf`: Windows'ta CRLF'li shell script'ler container'da bozulmasın).
- **Dosyalar:** `.gitignore`, `.gitattributes`
- **Bağımlı:** Faz 0
- **Kabul:** `git branch --show-current` → `phase-1/setup`; `git log` Faz 0 merge commit'ini içerir; `git check-attr eol -- x.sh` → `lf`.

### 1.2 `settings.json` (sen uygularsın)

- **İş:** Claude tam içeriği hazırlar, sen `.claude/settings.json`'a koyarsın.
  - `defaultMode: acceptEdits`.
  - `allow`: `make test*|lint*|up|ps|logs*|fresh|smoke|e2e`; `docker compose ps|logs|build|up -d|exec|run --rm`; `php artisan test|route:list|migrate:status|config:show|llm:eval`; `vendor/bin/pest|pint|phpstan`; `npm run lint|test|type-check|build`; `npx playwright test`; `node scripts/<tam ad>.mjs` (joker yok); `git status|diff|log|branch|show|add|commit|switch|checkout -b`; `gh pr create|view|list`; `ls`; `curl http://localhost:*`; context7 ve boost araçları.
  - `ask`: `git push`, `composer require`, `npm install <paket>` (yeni bağımlılık), `docker compose down -v`.
  - `deny`: her dizinde gerçek env dosyaları için Read/Edit/Write: `**/.env`, `**/.env.local`, `**/.env.testing`, `**/.env.production`, `**/.env.*.local`. `.env.*` jokeri kullanılmaz, çünkü deny allow'u ezer ve `.env.example` da engellenirdi; `.env.example` serbestçe okunur ve yazılır. Ayrıca `**/*.pem`, `**/*credentials*` okuma; `git clean`, `git reset --hard`, `docker system prune`.
  - Container içinden erişim de kapalı: `docker compose exec … cat .env`, `printenv`, `env` gibi komutlar guard hook'ta engellenir (1.3).
  - Hook kaydı, `enabledPlugins: security-guidance`.
- **Dosyalar:** `.claude/settings.json`
- **Bağımlı:** 1.1
- **Kabul:** `/permissions` listesi beklenenle aynı; Claude'un `.env` ve `backend/.env` için Read, Edit ve Write denemeleri reddedilir; `.env.example` okuma ve düzenleme sorusuz geçer; `git status` sorusuz çalışır.

### 1.3 Guard hook ve test script'i

- **İş:** `guard.mjs` (Bash ve PowerShell). Engeller: özyinelemeli silme; `main`'e veya `main`'deyken push, force push, `main`'de commit; shell veya container üzerinden gerçek env dosyalarına erişim (`cat`, `type`, `Get-Content`, `cp`, `grep`, `source`, `docker compose exec … cat .env`, container'da `env`/`printenv`; "aracı değil etkiyi engelle"), yalnızca `.env.example` serbest; `docker compose down -v`, `docker volume rm`, `docker system prune`; staged diff'te `sk-ant-` deseni varken `git commit`. Riskli komutta ayrıştıramadığı yapıda (`$(`, backtick, satır devamı, tırnak) fail-closed. Her engelde `.claude/logs/hooks.log`'a satır yazar.
- **Dosyalar:** `.claude/hooks/guard.mjs`, `scripts/test-guard.mjs`
- **Bağımlı:** 1.2
- **Kabul:** `node scripts/test-guard.mjs` tümü PASS (≥30 vaka, engellenecek ve geçecek, iki shell; `cat .env` ve `cat backend/.env` engellenir, `cat .env.example` geçer); canlı deneme: `git push origin main` engellenir, log'da satır görünür. (Classifier self-modification diye reddederse Claude test edilmiş dosyayı verir, sen koyarsın.)

### 1.4 Format hook'u

- **İş:** `format.mjs`: `.php` → Pint, `.vue/.ts/.js/.css/.json/.md` → Prettier. Araç henüz yoksa sessizce atlar, hiç engellemez, log satırı yazar.
- **Dosyalar:** `.claude/hooks/format.mjs`
- **Bağımlı:** 1.2
- **Kabul:** Bozuk girintili bir `.md` yazılınca düzelir; `hooks.log`'da satır var.

### 1.5 `CLAUDE.md`

- **İş:** Stack; komutlar; dev ortamı (host'ta PHP 8.4, Composer, Node, Docker; Windows'ta `make`); mimari değişmezler (LLM çıktısı her zaman doğrulanır ve asla SQL olmaz; izin kontrolü hem kitlede hem gönderimde; çift gönderim katmanları; limitler tek kaynaktan; `.env`'e doğrudan erişim yok, yalnızca `.env.example`); çalışma kuralları (`/task`, docs-first, tek görev, her git adımından önce dosya bazlı özet, onaysız commit yok, reddedilen araçta dur ve raporla, başka araca geçme, karar → `DECISIONS.md`, session export).
- **Dosyalar:** `CLAUDE.md`
- **Bağımlı:** 1.1
- **Kabul:** `/context`'te ≤ ~600 token; içerik okunarak onaylanır.

### 1.6 Rules

- **İş:** `clean-code.md` (DRY/KISS/YAGNI, "neden" yorumları, isimlendirme, magic number yok, boş `catch` yok, erken dönüş, anlamlı hata mesajı); `backend.md` (FormRequest, API Resource, ince controller, davranış modelde veya servis sınıfında, `env()` yalnızca config'de, tipli exception → tek JSON hata formatı, Unit/Feature test yerleşimi, nötr fixture); `frontend.md` (`<script setup lang="ts">`, `any` yok, Pinia, composable'larda cleanup, lazy route, `shallowRef`, erişilebilirlik temeli).
- **Dosyalar:** `.claude/rules/*.md`
- **Bağımlı:** 1.5
- **Kabul:** Her dosyada doğru `paths:`; toplam ~1.5K token; içerik okunarak onaylanır.

### 1.7 `task` skill'i

- **İş:** Argüman görev ID'si. Adımlar: 0) `plan.md`'den görevi ve bağımlılıkları oku, ilgili rule dosyalarını önceden oku; 1) Docs first: dokunulan her kütüphane için bir context7/Boost sorgusu, LLM görevlerinde `claude-api` skill; ya da gerekçe; 2) en küçük uygulama; 3) testler (`test-runner`); 4) lint/type-check ana oturumda; 5) görev Bölüm 2'de bir kararın ilk görevi ise o kararı `DECISIONS.md`'ye yaz; 6) rapor: dosya bazlı özet tablosu (`dosya | yeni/değişti | ne yapıldı | neden`), kabul kriteri kanıtı, doküman sorguları, açık konular; hiçbir git komutu çalıştırmadan dur.
- **Dosyalar:** `.claude/skills/task/SKILL.md`
- **Bağımlı:** 1.5, 1.6
- **Kabul:** `/task 1.13` deneme koşusunda tüm adımlar raporda görünür.

### 1.8 `review-pr` ve `finish-phase` skill'leri

- **İş:** `review-pr`: salt-okur (`Write`/`Edit` yasak); `git diff main...HEAD` + `git diff HEAD` + izlenmeyen dosyalar; kontrol listesi: proje değişmezleri, sır yok, nötr fixture, test var mı. `finish-phase`: test + lint → `review-pr` → `/code-review` (seviye fazdan) → yalnızca bug/güvenlik/kural ihlali düzeltilir, en fazla 2 tur, reddedilenler listesi → fazın tüm dosyaları için dosya bazlı özet tablosu, kontroller, düzeltmeler, PR başlığı → onayla push + `gh pr create` (başlık ve açıklama İngilizce).
- **Dosyalar:** `.claude/skills/review-pr/SKILL.md`, `.claude/skills/finish-phase/SKILL.md`
- **Bağımlı:** 1.7
- **Kabul:** `review-pr` commit'lenmemiş değişiklikte boş diff vermez (1.13'te denenir).

### 1.9 `test-runner` agent'ı

- **İş:** `model: haiku`, araçlar `Bash, Read, Grep, Glob`; `make test-backend` / `make test-frontend` / filtreli Pest çalıştırır, yalnızca sonucu ve hata veren testleri döner.
- **Dosyalar:** `.claude/agents/test-runner.md`
- **Bağımlı:** 1.5
- **Kabul:** Faz 2'deki ilk testte özet ≤15 satır.

### 1.10 MCP: context7

- **İş:** `.mcp.json`'a context7 (remote HTTP). Boost Laravel kurulunca (2.2) eklenir.
- **Dosyalar:** `.mcp.json`
- **Bağımlı:** 1.2
- **Kabul:** `/mcp` → context7 connected; "vue router lazy loading" sorgusu sonuç döner.

### 1.11 Session export temizliği

- **İş:** `scrub-sessions.mjs`: API key desenleri, e-posta, telefon, Windows kullanıcı yolu maskelenir; `--check` temiz değilse non-zero döner. `ai-sessions/README.md` (Faz 0'da açıldı) bu adımla güncellenir; Faz 0 export'u da script'ten geçirilir.
- **Dosyalar:** `ai-sessions/README.md`, `scripts/scrub-sessions.mjs`
- **Bağımlı:** 1.1
- **Kabul:** Sahte key ve e-posta içeren örnek dosya maskelenir; `--check` önce 1, sonra 0 döner.

### 1.12 Plugin ve sağlık kontrolü

- **İş:** `security-guidance` etkin mi kontrol; `/doctor` çalıştır; bulgular Fix/Defer/Disagree diye ayrılır (durma kuralı: güvenlik ve izin dışı öneriler ertelenir).
- **Dosyalar:** - (gerekirse `CLAUDE.md`)
- **Bağımlı:** 1.2–1.10
- **Kabul:** `/plugin` listesinde etkin; `/doctor` çıktısı ve karar listesi raporda.

### 1.13 Deneme koşusu

- **İş:** Küçük bir değişiklikle (README taslağı) `task` → commit → `finish-phase` → PR döngüsünü baştan sona çalıştır, bozulanı düzelt.
- **Dosyalar:** `README.md` (taslak) + düzeltmeler
- **Bağımlı:** 1.1–1.12
- **Kabul:** Faz 1 PR'ı açık; döngüde çıkan sorunlar ve düzeltmeleri PR açıklamasında.

---

## Faz 2: İskelet ve altyapı · G1

### 2.1 Laravel iskeleti

- **İş:** `backend/`'e Laravel 12, Pest, Pint, Larastan (seviye 6). Örnek dosyalar temizlenir.
- **Dosyalar:** `backend/**` (scaffold), `backend/phpstan.neon`
- **Bağımlı:** 1.13
- **Kabul:** `vendor/bin/pest`, `vendor/bin/pint --test`, `vendor/bin/phpstan` yeşil.

### 2.2 Laravel Boost MCP

- **İş:** `laravel/boost` (dev), `.mcp.json`'a ekle.
- **Dosyalar:** `backend/composer.json`, `.mcp.json`
- **Bağımlı:** 2.1
- **Kabul:** `/mcp` → laravel-boost connected; `search-docs "queue rate limiting"` sonuç döner.

### 2.3 Ortam ve config

- **İş:** Kökte `.env.example`: `ANTHROPIC_API_KEY=`, `ANTHROPIC_MODEL=claude-sonnet-5-5`, `LLM_DRIVER=auto|claude|fake`, DB/Redis, `QUEUE_CONNECTION=redis`, `PROVIDER_URL`, `SEND_RATE_PER_SECOND=100`, `WORKERS_PER_CHANNEL`, mock hata oranları. `config/llm.php`, `config/delivery.php` (limitlerin tek kaynağı). `auto`: key boşsa fake ve uyarı log'u.
- **Dosyalar:** `.env.example`, `backend/config/llm.php`, `backend/config/delivery.php`
- **Bağımlı:** 2.1
- **Kabul:** Key boşken `php artisan config:show llm` → driver `fake`; unit test aynı şeyi doğrular.

### 2.4 Backend imajı

- **İş:** FrankenPHP + PHP 8.4 (pgsql, redis, pcntl, intl: `grapheme_strlen` için, zip: Composer paketleri açar). Entrypoint: DB'yi bekle → `migrate --force` → tablo boşsa seed. Aynı imaj worker olarak da çalışır.
- **Dosyalar:** `backend/Dockerfile`, `docker/backend/entrypoint.sh`
- **Bağımlı:** 2.1
- **Kabul:** `docker compose build app` başarılı.

### 2.5 Health endpoint (temel)

- **İş:** `GET /api/health`: DB ve Redis ping, LLM sürücü adı; biri düşükse 503. Tek JSON hata formatı (`{error:{code,message,details}}`) ve exception eşlemesi burada kurulur.
- **Dosyalar:** `backend/routes/api.php`, `backend/app/Http/Controllers/HealthController.php`, `backend/bootstrap/app.php`, test
- **Bağımlı:** 2.3
- **Kabul:** Feature test: sağlıklıyken 200, Redis düşük simülasyonunda 503 ve hata gövdesi.

### 2.6 Frontend iskeleti

- **İş:** Vue 3 + TS + Vite + Pinia + Router + Tailwind + Vitest + ESLint + Prettier.
- **Dosyalar:** `frontend/**` (scaffold)
- **Bağımlı:** 1.13
- **Kabul:** `npm run build`, `lint`, `type-check`, `test` yeşil.

### 2.7 Frontend imajı ve nginx

- **İş:** Çok aşamalı build → nginx. `/api` proxy; SSE için `proxy_buffering off`, uzun `proxy_read_timeout`; SPA fallback.
- **Dosyalar:** `frontend/Dockerfile`, `docker/nginx/default.conf`
- **Bağımlı:** 2.6
- **Kabul:** `http://localhost:8080` uygulamayı, `http://localhost:8080/api/health` JSON'u döner.

### 2.8 `docker-compose.yml`

- **İş:** `postgres` (init script'i test DB'sini de açar), `redis`, `app`, `worker-sms`, `worker-email` (replica sayısı env'den), `web`; healthcheck'ler, `depends_on: service_healthy`. Mock provider 7.1'de eklenir.
- **Dosyalar:** `docker-compose.yml`, `docker/postgres/init.sql`
- **Bağımlı:** 2.4, 2.5, 2.7
- **Kabul:** Temiz durumda `docker compose up -d` → `docker compose ps` tümü healthy.

### 2.9 Makefile

- **İş:** `up` (`.env` yoksa `.env.example`'dan kopyalar), `down`, `fresh` (volume'suz yeniden migrate+seed), `logs`, `test` (= `test-backend` + `test-frontend`, docker içinde), `lint`, `smoke` (8.8), `e2e` (10.8). Test ortamı (K4): `test-backend` konteynerde `APP_ENV=testing` ve `DB_DATABASE=campaign_test` ile çalışır; `phpunit.xml` bu iki değeri `force="true"` ile sabitler, böylece konteynerin `env_file` değerleri geliştirme veritabanına bağlanmaz. Backend imajına boş bir `.env` konur (dotenv'in "dosya yok" uyarısı Pest çıktısını kirletmesin).
- **Dosyalar:** `Makefile`, `backend/phpunit.xml`, `backend/Dockerfile`
- **Bağımlı:** 2.8
- **Kabul:** `make test` temiz klonda yeşil ve uyarısız; testler `campaign_test`'e bağlanır (`AppBootTest` konteynerde `testing` görür); `make lint` yeşil.

### 2.10 README ve DECISIONS iskeleti

- **İş:** README başlıkları (kurulum, mimari, akış, testler, eksikler); `DECISIONS.md` iskeleti ve şablonu (karar, gerekçe, alternatifler); K1–K4 bu görevde yazılır.
- **Dosyalar:** `README.md`, `DECISIONS.md`
- **Bağımlı:** 2.8
- **Kabul:** Okuyarak onay.

---

## Faz 3: Veri modeli ve seed · G2

### 3.1 `customers` tablosu ve modeli

- **İş:** Örnek brief yalnızca bir örnek; alanlar farklı brief türlerini karşılayacak genişlikte:
  - **Kimlik ve iletişim:** `first_name` (nullable, fallback testi için), `last_name`, `email`, `phone` (E.164).
  - **Demografi:** `city`, `gender`, `birth_date` (yaş türetilir), `language` (`tr`/`en`).
  - **Hesap ve etkileşim:** `registered_at`, `last_login_at`, `platform` (`ios`/`android`/`web`), `loyalty_tier` (`bronze`/`silver`/`gold`/`platinum`).
  - **Alışveriş:** `orders_count`, `total_spent`, `avg_order_value`, `first_purchase_at`, `last_purchase_at`, `favorite_category` (elektronik, moda, ev, kozmetik, spor, kitap, market…).
  - **Sepet:** `last_cart_add_at`, `cart_items_count`, `cart_value`.
  - **İzinler:** `sms_opt_in`, `email_opt_in`.

  İndeksler: segmentte sık kullanılanlar (`city`, tarih kolonları, `loyalty_tier`, `favorite_category`). Model: `canReceive(Channel)`, `age()`. Factory.

- **Dosyalar:** migration, `app/Models/Customer.php`, `app/Enums/Channel.php`, factory, unit test
- **Bağımlı:** 2.9
- **Not:** Testler Postgres'e zorlandı, `make test-backend` ile çalışır; `campaign_test` yalnızca yeni `pgdata` hacminde oluşur.
- **Kabul:** `canReceive` unit testleri (izin yok / iletişim bilgisi yok / izinli) yeşil.

### 3.2 Seeder

- **İş:** 5.000 kullanıcı, Faker `tr_TR`, sabit seed (deterministik). Dağılımlar her alan için anlamlı ve birbiriyle tutarlı (ör. `first_purchase_at ≤ last_purchase_at`, `orders_count = 0` ise satın alma tarihleri boş, `avg_order_value = total_spent / orders_count`): ağırlıklı ~15 şehir (İstanbul ~%30); hiç alışveriş yapmamış ~%15; son 30 günde sepete ekleyip son 30 günde satın almamış ~%12; sadakat seviyeleri piramit; SMS izni ~%70, email izni ~%80; ~%3 boş `first_name`; ~%10 `en`. Toplu insert (1.000'lik chunk).
- **Dosyalar:** `database/seeders/CustomerSeeder.php`, `DatabaseSeeder.php`
- **Bağımlı:** 3.1
- **Not:** Entrypoint "tablo boşsa seed" kontrolü `customers` tablosuna bakar (`SEED_CHECK_TABLE`); ad değişirse env güncellenir.
- **Kabul:** `make fresh` iki kez → `SELECT count(*), md5(string_agg(email, ',' ORDER BY id))` aynı; dağılım sorgusu hedeflere ±%3 yakın.

### 3.3 `Campaign` ve durum makinesi

- **İş:** Tablo: `brief`, `status`, `segment_rule` (jsonb), `unsupported_criteria`, `audience_counts`, zaman damgaları. `CampaignStatus`: `draft → audience_ready → content_ready → approved → sending → completed` (+ `failed`). Geçişler model metodlarında (`defineAudience()`, `setContent()`, `approve()`, `startSending()`, `complete()`); geçersiz geçiş `InvalidCampaignTransition` fırlatır (Rich Domain Model).
- **Dosyalar:** migration, `app/Models/Campaign.php`, `app/Enums/CampaignStatus.php`, unit test
- **Bağımlı:** 3.1
- **Kabul:** Tüm izinli ve yasak geçiş çiftleri için test (data provider) yeşil.

### 3.4 `campaign_messages`

- **İş:** `campaign_id`, `channel`, `subject` (nullable), `body`, `source` (`llm`/`human`); unique (`campaign_id`, `channel`).
- **Dosyalar:** migration, `app/Models/CampaignMessage.php`, test
- **Bağımlı:** 3.3
- **Kabul:** Aynı kanal için ikinci insert unique hatası verir (integration test).

### 3.5 `deliveries`

- **İş:** `campaign_id`, `customer_id`, `channel`, `status` (`pending`, `sending`, `sent`, `failed`, `skipped`), `attempts`, `next_attempt_at`, `claimed_at`, `last_error`, `idempotency_key` (unique), `provider_message_id`, `sent_at`. Unique (`campaign_id`, `customer_id`, `channel`); indeks (`campaign_id`, `channel`, `status`). Domain metodları: `markSent`, `markFailed`, `scheduleRetry`, `skip`.
- **Dosyalar:** migration, `app/Models/Delivery.php`, `app/Enums/DeliveryStatus.php`, testler
- **Bağımlı:** 3.3
- **Kabul:** Unit: terminal durumdan geçiş reddedilir. Integration: aynı üçlü ikinci kez eklenemez.

---

## Faz 4: Segment kuralı (LLM'siz) · G2

### 4.1 `FieldRegistry`

- **İş:** Alan → kolon, tip, izinli operatörler, değer kısıtları, Türkçe etiket. 3.1'deki segmentlenebilir tüm alanlar kayıtlıdır (iletişim bilgileri ve isim hariç). Tipler: `enum` (`city`, `gender`, `language`, `platform`, `loyalty_tier`, `favorite_category`; şehir listesi DB'den, cache'li), `number` (`age`, `orders_count`, `total_spent`, `avg_order_value`, `cart_items_count`, `cart_value`), `date` (`registered_at`, `last_login_at`, `first_purchase_at`, `last_purchase_at`, `last_cart_add_at`). Operatörler: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt`, `lte`, `between`, `within_last_days`, `not_within_last_days` (null dahil), `is_null`, `not_null`. Doğrulayıcı, derleyici, prompt ve JSON şeması bu tek kaynaktan beslenir (DRY). Hash map, arama O(1).
- **Dosyalar:** `app/Segment/FieldRegistry.php`, `FieldDefinition.php`, `Operator.php`, unit test
- **Bağımlı:** 3.2
- **Kabul:** Unit: her tip için izinli/yasak operatör tablosu.

### 4.2 `SegmentRule` ve doğrulayıcı

- **İş:** `SegmentRuleParser::parse(array): SegmentRule|ValidationErrors` ("doğrulama yerine ayrıştır": `SegmentRule` geçersiz olarak var olamaz). Koşullar AND ile bağlanır (K6). Kontroller: yapı, `version`, 1–10 koşul, bilinen alan, tipe uygun operatör, değer tipi ve aralığı (gün 1–365, `in` ≤50 öğe, şehir listede), `between` sırası. Hatalar yol bazlı (`conditions[1].value: ...`); hem onarım prompt'u hem UI kullanır.
- **Dosyalar:** `app/Segment/SegmentRule.php`, `Condition.php`, `SegmentRuleParser.php`, `ValidationErrors.php`, unit test
- **Bağımlı:** 4.1
- **Kabul:** ≥20 geçersiz girdi vakası doğru yol ve mesajla reddedilir; örnek brief'in kuralı geçer.

### 4.3 `SegmentQueryCompiler`

- **İş:** `SegmentRule` → `customers` üzerinde Builder; kolon adı yalnızca registry'den, değerler binding ile; tarih koşulları `now()`'a göre (testte `travelTo`). Kanal izin filtresi ayrı metod.
- **Dosyalar:** `app/Segment/SegmentQueryCompiler.php`, integration test
- **Bağımlı:** 4.2
- **Kabul:** Integration: el ile kurulmuş küçük fixture'da örnek brief kuralı (`city = İstanbul` ∧ `last_cart_add_at within_last_days 30` ∧ `last_purchase_at not_within_last_days 30`) ve her tipten en az bir başka kural (ör. `loyalty_tier in [gold, platinum]` ∧ `age between 25–40`) beklenen ID kümesini döner; `toSql()` çıktısında kural değerleri yok, yalnızca `?`.

### 4.4 `AudienceService`

- **İş:** Kural → kanal başına sayılar (`COUNT(*) FILTER`, tek sorgu, tek tarama) + toplam benzersiz + 5 örnek kişi + kanal başına her kişiselleştirme değişkeninin (`first_name`, `last_name`, `city`) en uzun değeri (K13; `char_length` kod noktası sayar ve grapheme sayısından küçük olamaz, yani güvenli tarafta kalır).
- **Dosyalar:** `app/Segment/AudienceService.php`, `AudienceSummary.php`, integration test
- **Bağımlı:** 4.3
- **Kabul:** Integration: izinsiz kullanıcı ilgili kanal sayısına girmez; sorgu sayısı 2 (`DB::getQueryLog`).

---

## Faz 5: LLM katmanı (en ağır kalem) · G2–G3

### 5.1 `LlmClient` arayüzü ve fake

- **İş:** `LlmClient::complete(LlmRequest): LlmResponse`, `stream(LlmRequest): iterable<LlmChunk>`. DTO'lar: system, messages, JSON şeması, `maxTokens`; yanıt: metin, usage, `stopReason`. `FakeLlmClient`: sıralı senaryo kuyruğu (geçersiz → geçerli gibi), stream'i parçalara böler, hata da üretebilir. Container binding: `auto` / `claude` / `fake`.
- **Dosyalar:** `app/Llm/LlmClient.php`, `LlmRequest.php`, `LlmResponse.php`, `LlmChunk.php`, `FakeLlmClient.php`, `app/Providers/LlmServiceProvider.php`, unit test
- **Bağımlı:** 2.3
- **Not:** `config/llm.php` yalnızca çözümler; bilinmeyen `LLM_DRIVER` değerinde (örn. `claud`) ve `claude` + boş key birleşiminde binding hata fırlatır.
- **Kabul:** Unit: senaryo sırası, stream parçaları birleşince tam metin, binding doğru sürücüyü seçer.

### 5.2 `ClaudeLlmClient`: tek seferlik çağrı

- **İş:** Docs first: `claude-api` skill (model ID, structured output veya tool use biçimi, header'lar, `stop_reason`, hata kodları). Laravel Http; timeout; 429/5xx/529'da `retry-after` dikkate alınarak en fazla 3 deneme; tipli exception'lar: `LlmUnavailable`, `LlmTruncated` (`max_tokens`), `LlmRefused`, `LlmBadResponse`. Key hiçbir log'a yazılmaz.
- **Dosyalar:** `app/Llm/ClaudeLlmClient.php`, `app/Llm/Exceptions/*`, test
- **Bağımlı:** 5.1
- **Kabul:** `Http::fake` testleri: başarı, 429 → retry → başarı, 3× 529 → `LlmUnavailable`, `max_tokens` → `LlmTruncated`; istek gövdesinde şema ve model doğru. Log'da key yok.

### 5.3 Streaming ve SSE ayrıştırıcı

- **İş:** Saf `SseParser` (satır sınırında bölünmüş parçalar, `ping`, `error` olayı, çok satırlı `data`); `ClaudeLlmClient::stream` Guzzle stream'iyle delta'ları generator olarak verir.
- **Dosyalar:** `app/Llm/SseParser.php`, `ClaudeLlmClient.php`, `tests/Fixtures/claude-stream.txt`, test
- **Bağımlı:** 5.2
- **Kabul:** Unit: fixture rastgele noktalarından bölününce de aynı olay dizisi; `error` olayı exception'a döner.

### 5.4 LLM çağrı kaydı

- **İş:** `llm_calls` tablosu (amaç, prompt versiyonu, model, token'lar, gecikme, deneme no, sonuç, doğrulama hataları, ham çıktı; brief ve şablon PII içermez). `RecordingLlmClient` decorator: istemcilere dokunmadan kayıt (OCP).
- **Dosyalar:** migration, `app/Models/LlmCall.php`, `app/Llm/RecordingLlmClient.php`, test
- **Bağımlı:** 5.1
- **Kabul:** Integration: başarılı ve hatalı çağrı birer satır; gecikme > 0.

### 5.5 Segment prompt'u v1

- **İş:** Rol; registry'den üretilen alan/operatör tablosu; şehir listesi; bugünün tarihi; kurallar: yalnızca şemaya uygun JSON, eşleştiremediğin ifadeyi uydurma, `unsupported_criteria`'ya yaz (ör. "iPhone kullananlar"), her yorum için kısa `explanation`; farklı alan türlerini kapsayan 3 örnek (örnek brief'e aşırı uyum olmasın diye yalnızca biri sepet terk senaryosu). JSON şeması registry'den üretilir. Sabit system kısmı prompt cache'e uygun tutulur.
- **Dosyalar:** `app/Llm/Prompts/SegmentPrompt.php`, `resources/prompts/segment.v1.md`, snapshot test
- **Bağımlı:** 4.1, 5.1
- **Kabul:** Snapshot testi; şema test'te JSON Schema olarak geçerli.

### 5.6 `SegmentExtractor`

- **İş:** Brief doğrulama (10–1.000 karakter) → çağrı → JSON parse → `SegmentRuleParser` → hata varsa hatalar Claude'a geri verilip en fazla 2 onarım → `SegmentExtraction{rule, unsupported, explanation, attempts}` ya da tüm hataları taşıyan `SegmentExtractionFailed`. Brief'teki talimatlar (prompt injection) çıktıyı değiştirse bile doğrulamadan geçemez.
- **Dosyalar:** `app/Llm/SegmentExtractor.php`, `SegmentExtraction.php`, unit test
- **Bağımlı:** 4.2, 5.4, 5.5
- **Kabul:** Fake ile vakalar: geçerli; geçersiz → onarım → geçerli (2. istekte hata listesi var); 3× geçersiz → anlamlı hata; JSON olmayan çıktı; kesilmiş çıktı; API düşük. Hepsi yeşil.

### 5.7 Kişiselleştirme

- **İş:** Değişken registry'si (`{{first_name}}`, `{{last_name}}`, `{{city}}`, her biri fallback'li, ör. "Değerli müşterimiz"). `PersonalizationRenderer`: tek geçişte `preg_replace_callback`, O(n); bilinmeyen değişkenleri bulur; `worstCaseLength(template, maxLengths)` hesaplar. Uzunluk, kullanıcının gördüğü karakter sayısıdır (`grapheme_strlen`: Türkçe harf, emoji, birleşik karakter 1 sayılır).
- **Dosyalar:** `app/Content/Variables.php`, `PersonalizationRenderer.php`, unit test
- **Bağımlı:** 3.1
- **Kabul:** Unit: boş `first_name` → fallback; `{{unknown}}` tespit; `ş`, `ğ`, emoji (👍, 👨‍👩‍👧) içeren metinde uzunluk görünen karakter sayısıyla aynı.

### 5.8 `ContentValidator`

- **İş:** SMS ≤160 (K13'e göre en kötü durum render uzunluğu), konu ≤80, email gövdesi ≤2.000, boş değil, yalnızca bilinen değişkenler, bozuk `{{`. Limitler `config/delivery.php`'den. LLM çıktısı ve insan düzenlemesi aynı doğrulayıcıdan geçer (DRY). Yol bazlı hatalar.
- **Dosyalar:** `app/Content/MessageContent.php`, `ContentValidator.php`, unit test
- **Bağımlı:** 5.7
- **Kabul:** Unit: 160/161 ve 80/81 sınırları (görünen karakterle); değişken uzunluğu limiti aşırınca hata; Türkçe harf ve emoji içeren sınır vakaları.

### 5.9 İçerik prompt'u v1

- **İş:** Brief, ton, kitle özeti (sayılar ve kural açıklaması), kanal limitleri, kullanılabilir değişkenler, dil (Türkçe). Şema: `{sms:{body}, email:{subject, body}}`.
- **Dosyalar:** `app/Llm/Prompts/ContentPrompt.php`, `resources/prompts/content.v1.md`, snapshot test
- **Bağımlı:** 5.8
- **Kabul:** Snapshot testi.

### 5.10 `ContentGenerator`

- **İş:** Stream delta'larını olay olarak verir → sonda parse ve `ContentValidator` → geçersizse hatalarla tek seferlik (stream'siz) onarım, en fazla 2 → `final` (doğrulanmış içerik ve "onarıldı" bilgisi) ya da `error`. Tüketici bağlantıyı keserse stream iptal edilir (token israfı olmasın).
- **Dosyalar:** `app/Llm/ContentGenerator.php`, `ContentEvent.php`, unit test
- **Bağımlı:** 5.3, 5.9
- **Kabul:** Fake ile: delta'lar sırayla; 161 karakterlik SMS → onarım → geçerli `final`; iki onarım da başarısız → `error` ve hata listesi.

### 5.11 Geliştirme fake'i

- **İş:** Key yokken çalışan hazır yanıtlar: birkaç farklı senaryo (sepet terk, sadakat seviyesi, kategori, uzun süredir giriş yapmayan) anahtar kelimeyle seçilir, eşleşmeyen brief'lere genel bir yanıt; 20–40 ms aralıklı stream.
- **Dosyalar:** `app/Llm/Fake/DevScenarios.php`, `resources/llm-fixtures/*.json`
- **Bağımlı:** 5.6, 5.10
- **Kabul:** Key'siz `php artisan llm:eval --only=example` (5.12) geçer; stream gecikmesi gözle görülür.

### 5.12 `llm:eval`

- **İş:** 8–10 altın brief; registry'deki alan türlerinin hepsini kapsar, en az biri desteklenmeyen kriter içerir (beklenen alan/operatör/değer iddiaları, `unsupported_criteria`, içerik limitleri). Seçili sürücüyle çalışır; geçerlilik oranı, ortalama deneme ve gecikme raporlar. Fake ile CI'da, gerçek key ile istersen elle.
- **Dosyalar:** `app/Console/Commands/LlmEval.php`, `resources/llm-eval/briefs.json`, test
- **Bağımlı:** 5.11
- **Kabul:** Fake ile %100; key verirsen gerçek model raporu README'ye eklenir.

---

## Faz 6: Kampanya API'si · G3

### 6.1 Oluştur, listele, göster

- **İş:** `POST /api/campaigns {brief}`, `GET /api/campaigns` (sayfalı), `GET /api/campaigns/{id}` (kural, sayılar, mesajlar, durum). FormRequest + Resource.
- **Dosyalar:** controller, request, resource, route, feature test
- **Bağımlı:** 3.3
- **Not:** `ModelNotFoundException` → 404 eşlemesi `bootstrap/app.php`'ye eklenir.
- **Kabul:** Feature: kısa brief → 422 ve alan hatası; oluşturma → 201; liste sayfalı.

### 6.2 Kitle çıkarma

- **İş:** `POST /api/campaigns/{id}/audience` → `SegmentExtractor` + `AudienceService`; kural, desteklenmeyen kriterler ve sayılar saklanır; `audience_ready`. Kural elle düzenlenemez (bunun için endpoint yok); pazarlamacı brief'i değiştirip onaya kadar yeniden çalıştırabilir. LLM başarısız → 422 (hata listesi), LLM erişilemez → 503.
- **Dosyalar:** controller, action, feature test
- **Bağımlı:** 4.4, 5.6, 6.1
- **Kabul:** Fake ile 200 ve kanal sayıları; başarısız senaryoda 422 gövdesinde anlamlı mesaj; onaylanmış kampanyada 409.

### 6.3 İçerik stream endpoint'i

- **İş:** `POST /api/campaigns/{id}/content/stream` → SSE: `delta`, `final`, `error`; `final` sonrası `campaign_messages` (`source=llm`) ve `content_ready`. İstemci koparsa iptal.
- **Dosyalar:** controller, feature test
- **Bağımlı:** 5.10, 6.2
- **Not:** Yanıta `X-Accel-Buffering: no` eklenir (nginx `/api/` tamponu zaten kapalı).
- **Kabul:** Feature: yanıt `text/event-stream`, olay sırası delta… → final; DB'de iki mesaj.

### 6.4 Düzenleme, önizleme ve kurallar

- **İş:** `PUT /api/campaigns/{id}/content` (insan düzenlemesi; yalnızca üretilmiş mesajlar ve yalnızca onaydan önce, `content_ready` dışında 409; `ContentValidator`, 422 yol bazlı); `GET /api/campaigns/{id}/preview?customer_id=` (verilmezse örnek kişi; render edilmiş metin ve uzunluklar); `GET /api/content/rules` (limitler ve değişkenler; frontend kendi kopyasını tutmasın diye, DRY).
- **Dosyalar:** controller'lar, feature test
- **Bağımlı:** 6.3
- **Kabul:** Feature: 161 karakterlik SMS → 422 `sms.body`; onaylanmış kampanyada düzenleme → 409; önizlemede boş isimli kişi için fallback.

---

## Faz 7: Mock provider · G3

### 7.1 Servis iskeleti

- **İş:** `mock-provider/`: tek giriş noktalı sade PHP, `php -S` (`PHP_CLI_SERVER_WORKERS=16`). `POST /sms {to, body}`, `POST /email {to, subject, body}`; `Idempotency-Key` zorunlu; geçersiz alıcı → 400 (kalıcı hata). `GET /health`, `GET /stats` (kanal başına toplam, tekrar sayısı, saniyelik en yüksek hız), `DELETE /stats` (test sıfırlama). Compose'a eklenir.
- **Dosyalar:** `mock-provider/public/index.php`, `mock-provider/src/*`, `mock-provider/Dockerfile`, `docker-compose.yml`
- **Bağımlı:** 2.8
- **Not:** Compose servis adı `mock-provider` olmalı (`PROVIDER_URL` bunu bekler); `MOCK_*` env'leri `.env.example`'da hazır.
- **Kabul:** `curl` ile SMS gönder → 200 ve `id`; `/stats` 1 gösterir.

### 7.2 Idempotency

- **İş:** Aynı key → kaydedilmiş aynı yanıt, sayaca tekrar eklenmez (`SET NX`); aynı key farklı gövde → 422.
- **Dosyalar:** `mock-provider/src/IdempotencyStore.php`, test
- **Bağımlı:** 7.1
- **Not:** Testler projenin geneliyle aynı şekilde Pest ile yazılır. Mock provider'da Composer yok; testler backend imajındaki Pest ile çalışır (`mock-provider/phpunit.xml`, `tests/bootstrap.php`). `DELETE /stats` idempotency kayıtlarını da siler.
- **Kabul:** Aynı key ile 3 istek → `/stats` toplam 1, `duplicates_prevented` 2.

### 7.3 Hata üretimi ve kendi rate limit'i

- **İş:** `FaultPolicy` (rastgele kaynağı enjekte edilir): gecikme aralığı, 500, 429 (+`Retry-After`), timeout, "kabul edip 500". Oranlar env'den; deterministik test için `X-Mock-Fault` header'ı. Kanal başına >100/sn → 429 (saniyelik Redis `INCR`).
- **Dosyalar:** `mock-provider/src/FaultPolicy.php`, `RateLimiter.php`, test
- **Bağımlı:** 7.2
- **Not:** Mock provider testleri (Pest, bkz. 7.2) `make test-mock` hedefiyle `make test`'e eklenir.
- **Kabul:** `make test` mock provider testlerini de çalıştırır. Pest: sabit rastgele kaynakla beklenen dağılım; `X-Mock-Fault: 429` → 429 ve `Retry-After`; "kabul edip 500" sonrası aynı key ile tekrar → 200 ve kayıt 1.

---

## Faz 8: Gönderim hattı (ikinci ağır kalem) · G4

### 8.1 `MessageProvider` istemcisi

- **İş:** Arayüz + `HttpMessageProvider`: `send(payload, idempotencyKey): SendResult`. Sınıflandırma: 2xx → `Sent`; 429 → `RateLimited(retryAfter)`; 5xx, timeout, bağlantı hatası → `Transient`; diğer 4xx → `Permanent`. Bağlantı timeout'u 1 sn, toplam 3 sn.
- **Dosyalar:** `app/Delivery/MessageProvider.php`, `HttpMessageProvider.php`, `SendResult.php`, unit test
- **Bağımlı:** 7.1
- **Kabul:** `Http::fake` ile 4 sınıfın hepsi; header'da `Idempotency-Key`.

### 8.2 `RetryPolicy`

- **İş:** Saf sınıf: deneme n → `min(cap, base·2ⁿ)` aralığında full jitter; `RateLimited` → `max(retryAfter, backoff)`; maks. 5 deneme; `Permanent` → tekrar yok. Rastgele kaynağı enjekte edilir.
- **Dosyalar:** `app/Delivery/RetryPolicy.php`, `RetryDecision.php`, unit test
- **Bağımlı:** 8.1
- **Not:** `config/delivery.php`'deki taban (200 ms) ve tavan (10 sn) tahmindir, burada ayarlanır.
- **Kabul:** Unit: gecikme sınırları, `Retry-After` önceliği, 5. denemeden sonra `GiveUp`.

### 8.3 `SendDeliveryJob`

- **İş:** Delivery'yi yükle; terminal ise çık (idempotent); atomik claim (`UPDATE … SET status=sending, claimed_at=now() WHERE id=? AND (status=pending OR (status=sending AND claimed_at < now()-60s))`, etkilenen satır 1 değilse çık); izni gönderim anında tekrar kontrol et → `skipped`; render + uzunluk kontrolü; throttle (8.4); provider; sonuca göre `markSent`, `scheduleRetry` (gecikmeli `release`) ya da `markFailed`. Deneme sayısı DB'de, Laravel `tries` değil.
- **Dosyalar:** `app/Delivery/SendDeliveryJob.php`, integration test
- **Bağımlı:** 3.5, 5.7, 8.2
- **Kabul:** Integration (gerçek DB + fake provider): başarı; geçici → başarı; kalıcı → `failed`; 429 → gecikme ≥ `Retry-After`; aynı delivery için iki job → provider'a tek çağrı; izni sonradan kapanan → `skipped`.

### 8.4 Kanal rate limit'i

- **İş:** `ChannelRateLimiter`: `Redis::throttle("send:{channel}")->allow(N)->every(1)->block(...)`. Ayrı kuyruklar `sms`/`email`.
- **Dosyalar:** `app/Delivery/ChannelRateLimiter.php`, integration test
- **Bağımlı:** 8.3
- **Kabul:** Integration (gerçek Redis): 250 alım, saniye kovalarına göre her kova ≤100; iki kanal birbirini yavaşlatmaz.

### 8.5 Onay, snapshot ve dağıtım

- **İş:** `POST /api/campaigns/{id}/approve`: kampanya kilidi (`Cache::lock`), ön koşullar (içerik geçerli, kitle >0, `content_ready`), `approve()`. `StartCampaignJob`: kanal başına `INSERT INTO deliveries … SELECT … FROM customers WHERE <derlenmiş kural + izin> ON CONFLICT DO NOTHING` (set-based, tek round-trip, PHP'ye 5.000 satır çekilmez); idempotency key = hash(kampanya, kişi, kanal); `lazyById(1000)` ile job dağıtımı, bellek sabit; `sending`. Tekrar onay → 200 ve mevcut durum, yeni satır yok.
- **Dosyalar:** controller, `app/Delivery/StartCampaignJob.php`, `AudienceSnapshotter.php`, testler
- **Bağımlı:** 4.3, 6.4, 8.3
- **Kabul:** Integration: snapshot sayısı = `AudienceService` sayısı; çift ve eşzamanlı onay → satır ve job sayısı değişmez (`Queue::fake` sayımı); kitle 0 → 422.

### 8.6 Kampanya tamamlanması

- **İş:** Terminal her delivery sonrası koşullu güncelleme: `UPDATE campaigns SET status=completed WHERE id=? AND status=sending AND NOT EXISTS (pending/sending)`; yarış durumunda güvenli.
- **Dosyalar:** `app/Models/Campaign.php` (veya `CampaignProgress`), integration test
- **Bağımlı:** 8.5
- **Kabul:** Integration: son delivery bitince `completed`; eksik varken değişmez.

### 8.7 Worker servisleri

- **İş:** `worker-sms`/`worker-email`: `queue:work redis --queue=<kanal> --sleep=0 --max-time=3600`, replica `WORKERS_PER_CHANNEL` (varsayılan 8). Graceful shutdown (`stop_grace_period`).
- **Dosyalar:** `docker-compose.yml`
- **Bağımlı:** 8.4
- **Not:** Servisler 2.8'de var; burada komut/replica ince ayarı yapılır. 16 worker ve `app` aynı Postgres'e bağlanır, `max_connections=100` izlenir. Healthcheck `queue:work`'ün PID 1 olmasına bakar, komut değişirse güncellenir.
- **Kabul:** `docker compose ps` → 16 worker; `docker compose logs worker-sms` işlenen job'ları gösterir.

### 8.8 Uçtan uca smoke script'i

- **İş:** `make smoke`: API ile kampanya (fake LLM) → kitle → içerik → onay → `completed` bekle → kontroller: provider'da tekrar 0; `sent` = provider toplamı; `sent+failed+skipped` = delivery sayısı; saniyelik en yüksek hız ≤100/kanal. Hata oranları açıkken.
- **Dosyalar:** `scripts/smoke.mjs`, `Makefile`
- **Bağımlı:** 8.6, 8.7, 7.3
- **Kabul:** 3 ardışık koşuda yeşil; çıktı tablosu rapora eklenir.

---

## Faz 9: Takip · G4

### 9.1 İstatistik endpoint'i

- **İş:** `GET /api/campaigns/{id}/stats`: tek `GROUP BY channel, status` (indeksli), son 5 sn gönderim hızı, toplam yeniden deneme, son 10 hata.
- **Dosyalar:** `app/Delivery/CampaignStats.php`, controller, feature test
- **Bağımlı:** 8.6
- **Kabul:** Feature: fixture sayıları ile birebir.

### 9.2 İstatistik SSE'si

- **İş:** `GET /api/campaigns/{id}/stats/stream`: 1 sn'de bir, yalnızca değişince (hash karşılaştırma) snapshot; 15 sn heartbeat; `retry:`; terminal durumda veya en fazla 10 dk sonra kapanır.
- **Dosyalar:** controller, feature test
- **Bağımlı:** 9.1
- **Kabul:** Feature: tamamlanmış kampanyada tek snapshot ve kapanış.

### 9.3 `/api/metrics`

- **İş:** Prometheus text: `deliveries_total{channel,status}`, `llm_calls_total{purpose,outcome}`, `llm_latency_ms_avg`, `queue_depth{queue}`, `campaigns{status}`. Sorgu anında hesaplanır (ek sayaç durumu yok, KISS).
- **Dosyalar:** controller, feature test
- **Bağımlı:** 9.1, 5.4
- **Kabul:** Feature: format satırları; `curl localhost:8080/api/metrics` okunur çıktı.

### 9.4 Health genişletme

- **İş:** Mock provider (`/health`, 1 sn timeout), kuyruk derinlikleri, LLM modu (`fake`/`claude`, API çağrısı yapmadan). DB/Redis düşükse 503, provider düşükse `degraded` ve 200.
- **Dosyalar:** `HealthController.php`, test
- **Bağımlı:** 2.5, 7.1
- **Kabul:** Feature: üç durum (sağlıklı, degraded, down).

### 9.5 Yapılandırılmış log

- **İş:** stderr'e JSON log; `campaign_id`, `delivery_id`, `attempt`, `llm_call_id` bağlamı; key ve kişisel veri log'a yazılmaz.
- **Dosyalar:** `config/logging.php`, ilgili sınıflar
- **Bağımlı:** 8.3
- **Kabul:** `docker compose logs worker-sms` JSON satırları; log'da `sk-ant` ve telefon numarası yok (grep).

---

## Faz 10: Frontend · G5

Performans ilkeleri (gerektiği yerde): lazy route, stream delta'larını `requestAnimationFrame` ile toplu çizme, büyük snapshot'larda `shallowRef`, bileşen kapanınca `AbortController`/`EventSource` kapatma, düzenlemede debounce.

### 10.1 API katmanı

- **İş:** `client.ts` (fetch sarmalayıcı, tipli `ApiError`), `types.ts`, `streamSse(url, body, onEvent, signal)` (POST SSE ayrıştırıcı), `useEventSource` composable'ı (kapanışta temizlik).
- **Dosyalar:** `frontend/src/api/*`, `frontend/src/composables/useEventSource.ts`, Vitest
- **Bağımlı:** 2.6, 6.1
- **Kabul:** Vitest: bölünmüş SSE parçaları doğru olaylara dönüşür; abort'ta okuma durur; 422 → alan hataları.

### 10.2 Kabuk, router ve liste

- **İş:** Rotalar: `/campaigns`, `/campaigns/new`, `/campaigns/:id` (adım duruma göre), `/campaigns/:id/dashboard`. Pinia `campaign` store'u. Liste sayfası (durum rozeti).
- **Dosyalar:** `router/index.ts`, `stores/campaign.ts`, `views/CampaignList.vue`, test
- **Bağımlı:** 10.1
- **Kabul:** Vitest; tarayıcıda liste görünür.

### 10.3 Brief ve kitle adımı

- **İş:** Karakter sayaçlı textarea, "Kitleyi çıkar", yükleniyor durumu; kural Türkçe çiplerle; desteklenmeyen kriter uyarısı; SMS, email ve toplam sayılar; hata ve tekrar dene.
- **Dosyalar:** `views/CampaignComposer.vue`, `components/BriefStep.vue`, `components/AudienceCard.vue`, test
- **Bağımlı:** 10.2, 6.2
- **Kabul:** Vitest (başarı, 422, 503); tarayıcıda örnek brief → sayılar.
- **Uygulama notu:** Frontend limit ve etiket kopyası tutmasın diye `GET /api/content/rules` yanıtına `brief` limitleri ve `segment.fields/operators` (Türkçe çip kalıbı `Operator::template()`) eklendi (`ContentRulesController`, `Operator.php`, feature test); `AudienceCard` sayıları `longest_values`/`sample` ile birlikte okunur. Backend'de brief güncelleme endpoint'i yok: kayıtlı kampanyada brief salt okunur, farklı brief için yeni kampanya açılır.

### 10.4 İçerik stream adımı

- **İş:** SMS kartı (telefon görünümü) ve email kartı; kısmi JSON ayrıştırma (K12, docs-first ile kütüphane seçimi); iptal butonu; `final` gelince metin doğrulanmış içerikle değişir, "onarıldı" bilgisi gösterilir.
- **Dosyalar:** `components/ContentStream.vue`, `components/SmsPreview.vue`, `components/EmailPreview.vue`, test
- **Bağımlı:** 10.3, 6.3
- **Kabul:** Vitest (sahte stream ile kademeli metin); tarayıcıda metin akarak gelir.
- **Uygulama notu:** Kısmi JSON için `partial-json` (`STR | OBJ` bayrakları, kare başına bir ayrıştırma) seçildi; K12 `DECISIONS.md`'ye bu görevde yazıldı (ilk görev 6.3'te yazılmamıştı). Vite dev sunucusuna `/api` proxy'si eklendi (`npm run dev`).

### 10.5 Düzenleme ve önizleme

- **İş:** Düzenlenebilir alanlar (yalnızca SMS ve email metni; kural salt-okur; onaydan sonra kilitli); `/api/content/rules`'tan limitlerle canlı sayaç (en kötü durum, `Intl.Segmenter` ile görünen karakter; backend'deki sayımla aynı sonuç); değişken ekleme butonları; debounce ile `PUT`; backend hataları alanın altında; örnek kişi seçerek önizleme.
- **Dosyalar:** `components/ContentEditor.vue`, `components/CharCounter.vue`, test
- **Bağımlı:** 10.4, 6.4
- **Kabul:** Vitest: 161. karakterde sayaç kırmızı ve onay kapalı; emoji içeren metinde sayaç backend ile aynı; backend 422 mesajı görünür.

### 10.6 Onay

- **İş:** Kanal sayılarını gösteren onay modalı; tıklayınca buton kapanır (çift tıklamaya karşı); onaydan sonra dashboard'a yönlendirme.
- **Dosyalar:** `components/ApproveDialog.vue`, test
- **Bağımlı:** 10.5, 8.5
- **Kabul:** Vitest: çift tıklama → tek istek.

### 10.7 Dashboard

- **İş:** Kanal başına ilerleme çubuğu (gönderildi, başarısız, atlandı, bekliyor), hız, yeniden deneme sayısı, son hatalar tablosu, durum rozeti; SSE ile güncellenir, kopunca yeniden bağlanır, bitince kapanır.
- **Dosyalar:** `views/CampaignDashboard.vue`, `components/ChannelProgress.vue`, test
- **Bağımlı:** 10.1, 9.2
- **Kabul:** Vitest; tarayıcıda gönderim sırasında sayılar canlı artar ve `completed`'da durur.

### 10.8 Playwright e2e

- **İş:** Çekirdek akış: brief → kitle → stream → düzenle → onayla → dashboard `completed`; fake LLM ve mock provider ile. `make e2e`; ekran görüntüleri `e2e/artifacts/`.
- **Dosyalar:** `frontend/e2e/core-flow.spec.ts`, `playwright.config.ts`, `Makefile`
- **Bağımlı:** 10.7
- **Kabul:** `make e2e` yeşil; ekran görüntüleri incelenir.
- **Uygulama notu:** Test host'ta Node ile çalışır (`make e2e` yığını `up -d --build --wait` ile güncel kodla kaldırır, Chromium'u kurar); ekran görüntüleri `frontend/e2e/artifacts/` (gitignore'da).

### 10.9 Arayüz dili (Türkçe/İngilizce) · K23

- **İş:** `vue-i18n` (Composition API); başlıkta diğer dili kendi adıyla sunan tek buton; seçim `localStorage`'da, kayıt yoksa tarayıcı dili (Türkçe → `tr`, diğerleri `en`); `<html lang>` seçime uyar. Mesajlar `en.ts` (ana kaynak) ve `tr.ts` (`typeof en` ile tip denetimli); tüm görünümler, durum rozetleri, kanal adları ve saat biçimi çevrilir. API hata mesajları, model içeriği ve kural çiplerinin etiketleri (backend zaten Türkçe gönderir) olduğu gibi gösterilir.
- **Dosyalar:** `frontend/src/i18n/*`, `components/LocaleSwitcher.vue`, mevcut bileşenler, `test-setup.ts`, `e2e/language.spec.ts`, Vitest
- **Bağımlı:** 10.8
- **Kabul:** Vitest: iki dilde aynı anahtar ve `{parametre}` kümesi, dil algılama ve kalıcılık, butonla Türkçe↔İngilizce geçişi; e2e: geçiş ve yenilemeden sonra kalıcılık.

---

## Faz 11: Teslim · G6

### 11.1 README

- **İş:** Kurulum (`.env.example` → `.env`, key isteğe bağlı), mermaid mimari diyagramı, akış, endpoint tablosu, test komutları (Windows için `docker compose` eşdeğerleriyle), `llm:eval` sonucu, yapılmayanlar. Varsayımlar ve kapsam dışı: kimlik doğrulama yok (K22), içerik Türkçe, yasal SMS metni (İYS, çıkış ifadesi) yok, kampanyalar arası frekans sınırı yok, seed 5.000 kişi.
- **Dosyalar:** `README.md`
- **Bağımlı:** 10.8
- **Not:** README'deki _TBD_ bölümler doldurulur; Docker imaj etiketleri (`node:24-alpine`, `nginx:stable-alpine`) sabitlenir; Boost için `backend/.env` içinde `APP_ENV=local` gerektiği yazılır.
- **Kabul:** Okuyarak onay.

### 11.2 DECISIONS kontrolü

- **İş:** Bölüm 2'deki K1–K22 ve uygulamada eklenen kararlar `DECISIONS.md`'de tam mı; reddedilen alternatifler kısa.
- **Dosyalar:** `DECISIONS.md`
- **Bağımlı:** 11.1
- **Kabul:** Okuyarak onay.

### 11.3 Son inceleme

- **İş:** `/security-review` ve tüm repo için `/code-review high`; yalnızca bug/güvenlik düzeltmeleri.
- **Dosyalar:** bulgulara göre
- **Bağımlı:** 11.2
- **Kabul:** Bulgu listesi ve kararlar raporda.

### 11.4 Temiz klon doğrulaması

- **İş:** Geçici klasöre temiz klon → `.env` kopyala → `docker compose up` → `make test`, `make smoke`, `make e2e`; süreler not edilir.
- **Dosyalar:** -
- **Bağımlı:** 11.3
- **Kabul:** Üçü de yeşil; README'deki adımlar birebir yeterli.

### 11.5 Session export'ları

- **İş:** Tüm export'lar `ai-sessions/`'a; `scrub-sessions.mjs`; `--check` 0.
- **Dosyalar:** `ai-sessions/*`
- **Bağımlı:** 11.4
- **Kabul:** `--check` 0; her fazın en az bir `phase-<N>_<konu>.md` dosyası var.

---

## Doğrulama (uçtan uca)

1. `docker compose up` (key'siz) → `http://localhost:8080` → örnek brief → kanal sayıları → içerik akarak gelir → SMS'i düzenle (161. karakterde engel) → onayla → dashboard `completed`.
2. `make test` (Pest unit + integration, Vitest), `make smoke` (tekrar 0, hız ≤100/sn, sayılar tutarlı), `make e2e`.
3. `curl localhost:8080/api/health`, `curl localhost:8080/api/metrics`.
4. İsteğe bağlı: `.env`'e gerçek key → `php artisan llm:eval` raporu.

---

## İsteğe Bağlı Kısımlar

Çekirdek bittikten sonra, bu sırayla:

1. **CI pipeline:** GitHub Actions'ta `make lint` + `make test` (servis container'larıyla). En ucuz ve en görünür bonus; Faz 2'den sonra zaman olursa öne alınabilir.
2. **Zamanlanmış gönderim:** `campaigns.scheduled_at`; scheduler her dakika vadesi gelenleri `StartCampaignJob`'a verir.
3. **A/B varyantları:** kanal başına 2 içerik; atama `hash(customer_id) mod 100` ile deterministik, O(1); dashboard'da varyant kırılımı.
4. **Kullanıcının diline göre içerik:** `customers.language`; prompt dil başına içerik üretir; render'da dil seçilir.
5. **Diğer:** elle "başarısızları tekrar dene", kampanya iptali, circuit breaker, segment kuralı düzenleyici, iç içe AND/OR kuralları.
