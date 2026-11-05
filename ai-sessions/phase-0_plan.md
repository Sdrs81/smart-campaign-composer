❯ Merhaba, seninle yeni bir projeye başlıyoruz. Kodlamaya geçmeden önce
dikkatli bir planlama yapmamız gerekiyor. Bu aşamada kod yazma; sadece planı
hazırla.

Girdiler

Önce şu dosyaları tamamen oku:

- Proje case'i: @"docs/Smart Campaign Composer Case.md"
- Önceki kişisel projemden bulgular (fikir almak için):
  @docs/claude-structure.md ve @docs/claude-setup-findings.md

Bulgu dosyalarına harfiyen uymak ya da kopyalamak zorunda değilsin. İstersen
birleştir, ayır ya da görmezden gel; bu projenin gereksinimlerine uyanı al.

Case ile ilgili notlarım

- "Sen tasarla" gibi bana bırakılmış kısımlarda 2-3 opsiyon sun, en uygun
  olduğunu düşündüğünü kısa bir gerekçeyle belirt.
- Backend PHP olmalı.
- Her şeyi yetiştirmek zorunda değiliz. Öncelik, case'de belirtildiği üzere
  çekirdek akış.
- İsteğe bağlı kısımları planın en sonuna ekle, aşırı detaylandırma.
- Case'de eksik, belirsiz veya çelişkili bir nokta görürsen sessizce varsayım
  yapma ve planı yarıda kesip soru sorma. Bunları planın sonunda "Açık
  Sorular" başlığında topla, her biri için varsayılan önerini de yaz.

Genel mühendislik ilkelerim

Bunlar her göreve mekanik olarak eklenecek maddeler değil; projede ve ilgili
adımda gerçekten ihtiyaç olduğu yerde uygulanacak yönergeler. Önemli bir
tasarım kararında hangi ilkeye dayandığını kısaca belirt.

- Temiz kod: DRY, KISS, YAGNI, yerinde ve "neden"i açıklayan yorum satırları.
- Mimari: Rich Domain Model, temel SOLID ilkeleri.
- Hata yönetimi: Defensive Programming (girdi doğrulama, erken hata, anlamlı
  hata mesajları, loglama).
- Performans: JS/Frontend tarafında güncel ve uygun pratikler.
- Test: Önemli tekil birimlerde Unit test; veritabanı, mesaj kuyruğu gibi dış
  bileşenlere temas eden noktalarda Integration test.
- Algoritma karmaşıklığı ve veri yapıları: Proje ve ilgili adım için en uygun
  olanı seç, önemli seçimlerde gerekçelendir.

Faz 1: Kurulum (planın ilk fazı)

İlk iş, uygulamayı daha hızlı, kaliteli ve tutarlı ilerletecek Claude
araçlarını hazırlamak olmalı:

- CLAUDE.md
- hooks
- skills
- rules
- agents
- MCP'ler
- plugin'ler
- permission/izin ayarları

Her bir araç için bu proje özelinde gerekli olup olmadığına karar ver.
Gerekli değilse neden atlandığını tek satırla yaz. Hedefimiz, Claude
araçlarıyla en az komut ve soruyla kesintiye uğramadan çalışabileceğimiz
akıcı, güvenli ve verimli bir iş akışı.

İstediğim çıktı: Uygulama planı

Case'i seninle birlikte gerçekleştireceğiz. Bunun için ana fazlara, fazların
içinde de küçük görevlere bölünmüş bir plan istiyorum. Uyguladığımız her
adımı tek tek kontrol edip doğrulayacağım, bu yüzden:

- Görevleri küçük ve odaklı tut. Tek görevde geniş kapsam veya çok sayıda
  dosya değişikliği olmasın.
- Her görev için şunları yaz: ID (örn. 2.3), kısa başlık, yapılacak iş,
  etkilenecek dosyalar/alanlar, varsa bağımlı olduğu görevler ve benim nasıl
  doğrulayacağım (kabul kriteri).
- Faz sırası: Faz 1 Kurulum → çekirdek akış fazları → (en sonda) isteğe bağlı
  kısımlar.
- Planın başında 5-10 satırlık kısa bir mimari/teknoloji özeti ver, ardından
  fazlar ve görevler gelsin.
- Planın sonuna "Açık Sorular" ve "İsteğe Bağlı Kısımlar" bölümlerini ekle.

Planı docs/plan.md dosyasına yaz. Ben onaylamadan uygulamaya (kod veya araç
kurulumuna) geçme.
⎿  Read docs\Smart Campaign Composer Case.md (123 lines)
⎿  Read docs\claude-structure.md (168 lines)
⎿  Read docs\claude-setup-findings.md (112 lines)

Searched for 1 pattern (ctrl+o to expand)

● Updated plan
⎿  /plan to preview

● Plan hazır. Plan modu yalnızca plan dosyasına yazmama izin veriyor; onay
verirsen Görev 1.1'de içeriği docs/plan.md'ye kopyalayacağım.

● User approved Claude's plan
⎿  Plan saved to: ~\.claude\plans\merhaba-seninle-yeni-bir-cheerful-lerdorf.md
· /plan to edit
Smart Campaign Composer: Uygulama Planı

     Tarih: 2026-11-04 · Durum: taslak, onay bekliyor · Hedef dosya:
     docs/plan.md (plan modu yalnızca bu dosyaya yazmaya izin veriyor; onaydan
     sonra Görev 1.1'de docs/plan.md'ye kopyalanır)

     0. Bağlam

     Insider One case'i: pazarlamacı doğal dille bir brief yazar; Claude
     brief'ten segment kuralı ve SMS/email içeriği üretir; insan önizler,
     düzenler ve onaylar; mesajlar kuyruk üzerinden mock provider'a gider;
     dashboard durumu canlı gösterir. Değerlendirmede en ağır kalem LLM
     entegrasyonu (%25): Claude çıktısının yapılandırılmış, backend'de
     doğrulanmış ve gerçek key olmadan test edilebilir olması. Teslim süresi 7
     gün; öncelik çekirdek akışın sağlam olması. Repo şu an boş (yalnızca
     docs/).

     1. Mimari ve teknoloji özeti

     - Backend: Laravel 12 (PHP 8.4), yalnızca JSON API, FrankenPHP container.
     Feature klasörleri: app/Segment, app/Llm, app/Content, app/Delivery;
     davranış zengin modellerde (Campaign::approve(), Delivery::markSent()).
     - Veri ve kuyruk: PostgreSQL 17 (kullanıcılar, kampanyalar, gönderimler,
     LLM çağrı kayıtları); Redis 7 (Laravel queue, kanal başına ayrı sms ve
     email kuyruğu, global rate limiter, kilitler).
     - LLM: LlmClient arayüzü. ClaudeLlmClient (Messages API, JSON şemalı çıktı,
     streaming) ve FakeLlmClient (testler ve key'siz geliştirme). Key yoksa
     sistem otomatik olarak fake ile açılır.
     - Güvenilirlik zinciri: LLM JSON → şema ve iş kuralı doğrulaması → hata
     varsa hatalar Claude'a geri verilip en fazla 2 onarım denemesi → tipli
     değer nesnesi (SegmentRule, MessageContent). LLM çıktısı asla SQL olmaz:
     kural, izinli alan/operatör kayıt defterinden geçip binding'li query
     builder'a çevrilir.
     - Gönderim: Onayda kitle deliveries tablosuna tek bir INSERT … SELECT ile
     dondurulur (unique: kampanya + kişi + kanal) → mesaj başına job → Redis
     throttle (kanal başına ≤100/sn) → mock provider (Idempotency-Key ile).
     Retry: hata sınıfına göre üstel backoff + jitter, 429'da Retry-After.
     - Mock provider: Ayrı container, sade PHP + Redis. Gecikme, 500, 429,
     timeout ve "kabul edip 500 dön" hatalarını ayarlanabilir oranlarla üretir;
     tekrar eden mesajları sayar.
     - Canlı akış: İçerik üretimi SSE (POST + fetch stream), dashboard SSE
     (EventSource, değişince snapshot). /api/health, /api/metrics (Prometheus
     text).
     - Frontend: Vue 3 + TypeScript + Vite + Pinia + Vue Router + Tailwind;
     nginx statik servis ve /api reverse proxy.
     - Test ve kurulum: Pest (unit + gerçek Postgres/Redis'e karşı integration),
     Vitest, Playwright e2e (fake LLM). docker compose up (migrate + seed
     otomatik), make test (docker içinde, host bağımlılığı yok).

     backend/  frontend/  mock-provider/  docker/  scripts/  ai-sessions/  docs/
     docker-compose.yml  Makefile  .env.example  README.md  DECISIONS.md
     CLAUDE.md  .claude/  .mcp.json

     2. Tasarım kararları ("sen tasarla" kısımları)

     İlk tercih öneridir; parantezde dayandığı ilke. Her biri ilgili görevde
     DECISIONS.md'ye yazılır.

     #: D1
     Konu: PHP framework
     Opsiyonlar: Laravel 12 · Symfony · Slim
     Öneri ve gerekçe: Queue, Redis throttle, migration, seeder, Pest ve SSE
     hazır;
       Boost MCP ile önceki projedeki akış tekrar kullanılır (KISS).
     ────────────────────────────────────────
     #: D2
     Konu: Veritabanı
     Opsiyonlar: PostgreSQL · MySQL · SQLite
     Öneri ve gerekçe: COUNT(*) FILTER, ON CONFLICT DO NOTHING, JSONB; tek
     sorguda
       kanal bazlı sayım ve idempotent snapshot.
     ────────────────────────────────────────
     #: D3
     Konu: Kuyruk
     Opsiyonlar: Redis (Laravel queue) · RabbitMQ · DB queue
     Öneri ve gerekçe: Tek bileşen kuyruk + global rate limiter + kilit işini
     görür;
       RabbitMQ bu ölçekte ek operasyon yükü (YAGNI).
     ────────────────────────────────────────
     #: D4
     Konu: Davranış verisi
     Opsiyonlar: Kullanıcı üzerinde özet kolonlar (last_cart_add_at,
       last_purchase_at, orders_count, total_spent) · olay tabloları + EXISTS
     Öneri ve gerekçe: Olay toplama hattı kapsam dışı; segment koşulları
     indeksli
       kolon karşılaştırmasına iner, derleyici basit kalır (KISS). Olay tablosu
       geçiş yolu DECISIONS'ta not edilir.
     ────────────────────────────────────────
     #: D5
     Konu: Segment kural dili
     Opsiyonlar: Düz AND listesi + in/not_in · iç içe AND/OR ağacı · JSON Logic
     Öneri ve gerekçe: Örnek brief ve tipik istekleri karşılar; doğrulama ve
     prompt
       kısa kalır (YAGNI). Ağaca genişleme kolay, şema versiyonlu.
     ────────────────────────────────────────
     #: D6
     Konu: Yapılandırılmış çıktı
     Opsiyonlar: Structured outputs (JSON schema) · zorunlu tool use
     (tool_choice) ·
       yalnızca prompt
     Öneri ve gerekçe: Şemaya uyumu modelde garanti eder, onarım turunu azaltır.

       Backend doğrulaması yine zorunlu (iş kuralları şemaya sığmaz). Güncel API

       Görev 5.2'de claude-api skill ile doğrulanır; desteklenmiyorsa zorunlu
     tool
       use.
     ────────────────────────────────────────
     #: D7
     Konu: Claude istemcisi
     Opsiyonlar: Laravel HTTP client, kendi arayüzümüzün arkasında · resmi PHP
     SDK
     Öneri ve gerekçe: Bağımlılık az, Http::fake ile test kolay, SSE ayrıştırıcı

       küçük. Arayüz sayesinde SDK'ya geçiş tek sınıf (DIP). SDK durumu 5.2'de
       kontrol edilir.
     ────────────────────────────────────────
     #: D8
     Konu: İçerik stream'i
     Opsiyonlar: SSE, POST + fetch ReadableStream · WebSocket (Reverb) · polling
     Öneri ve gerekçe: Tek yönlü akış için yeterli; yan etkili işlem GET olmaz.
       Backend ham JSON delta'larını iletir, frontend kısmi JSON'u ayrıştırıp
       alanları gösterir, son ve doğrulanmış sonuç final olayıyla gelir. Kısmi
       ayrıştırma her karede tüm tampon üzerinden O(n²) ama n < 3 KB.
     ────────────────────────────────────────
     #: D9
     Konu: Dashboard canlılığı
     Opsiyonlar: SSE (EventSource, 1 sn, yalnızca değişince) · 2 sn polling ·
     Reverb
     Öneri ve gerekçe: Otomatik yeniden bağlanma; ek container yok. Snapshot tek

       GROUP BY sorgusundan gelir.
     ────────────────────────────────────────
     #: D10
     Konu: Rate limit (100/sn/kanal)
     Opsiyonlar: Kanal başına kuyruk + Redis::throttle(...)->block() · Lua token

       bucket · saniyelik batch dispatcher
     Öneri ve gerekçe: Worker sayısından bağımsız global sınır, atomik Redis
       işlemleri O(1). block ile beklenir, deneme sayacı yanmaz. Sabit pencere
       sınırda kısa patlamaya izin verebilir; ölçümle doğrulanır, gerekirse
     token
       bucket.
     ────────────────────────────────────────
     #: D11
     Konu: Retry stratejisi
     Opsiyonlar: Hata sınıfına göre: 429 → Retry-After; 5xx/timeout → üstel
     backoff
       + full jitter (maks. 5 deneme); diğer 4xx → kalıcı hata · sabit aralık ·
       circuit breaker eklemek
     Öneri ve gerekçe: Gereksiz tekrarı ve senkron dalgalanmayı önler.
     RetryPolicy
       saf sınıf, unit test edilir (SRP). Circuit breaker isteğe bağlı.
     ────────────────────────────────────────
     #: D12
     Konu: Çift gönderim önleme
     Opsiyonlar: DB unique + atomik claim (pending→sending koşullu UPDATE) +
       provider Idempotency-Key · Redis set · yalnızca ShouldBeUnique job
     Öneri ve gerekçe: Üç katman: aynı satır iki kez oluşmaz, iki worker aynı
     satırı
       alamaz, timeout sonrası tekrar provider'da tekilleşir (Defensive).
     sending'de
        takılan satır süre aşımında yeniden alınabilir; idempotency key bunu
     güvenli
        kılar.
     ────────────────────────────────────────
     #: D13
     Konu: Mock provider
     Opsiyonlar: Ayrı container, sade PHP + Redis · ana uygulamada route · başka
     dil
     Öneri ve gerekçe: Gerçek ağ hataları ve timeout'lar oluşur, ana uygulamadan

       bağımsız sayaçlar tekrarı kanıtlar (SRP).
     ────────────────────────────────────────
     #: D14
     Konu: Web sunucusu
     Opsiyonlar: FrankenPHP (klasik mod, Octane yok) · nginx + php-fpm · artisan

       serve
     Öneri ve gerekçe: Tek container, stream'de tampon ayarı gerekmez. Octane
     worker
       modunun state sızıntısı riskine girilmez (KISS).
     ────────────────────────────────────────
     #: D15
     Konu: Test ortamı
     Opsiyonlar: Integration testler docker'daki Postgres/Redis'e karşı (ayrı
     test
       DB) · SQLite in-memory
     Öneri ve gerekçe: FILTER, ON CONFLICT, throttle gerçek bileşende test
     edilir.
       Unit testler bağımlılıksız ve hızlı.
     ────────────────────────────────────────
     #: D16
     Konu: Kişiselleştirme uzunluğu
     Opsiyonlar: Kitlenin gerçek maks. değişken uzunluğuyla en kötü durum ·
     şablon
       uzunluğu · sabit bütçe
     Öneri ve gerekçe: 160 limiti alıcıya giden metinde garanti edilir; tek
       MAX(char_length()) sorgusu. Gönderimde yeniden kontrol edilir
     (Defensive).
     ────────────────────────────────────────
     #: D17
     Konu: Claude araç hook'ları
     Opsiyonlar: Node .mjs · bash + jq
     Öneri ve gerekçe: Windows/Git Bash farkı ve jq bağımlılığı yok, JSON doğal,

       node:test ile test edilir.

     3. Çalışma akışı

     - Dal: faz başına bir dal (phase-N/<ad>). main'e commit yok (hook
     engeller).
     - Görev döngüsü: /task N.M → Claude: önce ilgili rule ve dokümanlar (Docs
     first) → uygulama → test → lint → rapor (değişen dosyalar, kabul kriteri
     kanıtı: komut + çıktı, sorulan doküman sorguları). Sen doğrularsın, "onay"
     dersen Claude commit atar ve plan.md'deki kutuyu işaretler.
     - Faz sonu: /finish-phase → make test + make lint, review-pr, /code-review
     (genelde medium; Faz 5 ve 8 için high), en fazla 2 tur, reddedilen bulgu
     listesi → özet → onayla push (ask) ve PR. Merge sende.
     - Kararlar: Görevde verilen her önemli karar aynı commit'te DECISIONS.md'ye
     girer.
     - Session export: Her oturum sonunda /export →
     ai-sessions/YYYY-MM-DD-<konu>.md; commit'ten önce node
     scripts/scrub-sessions.mjs --check.
     - Takvim (yaklaşık): G1 Faz 1–2 · G2 Faz 3–4 · G3 Faz 5 · G4 Faz 6–7 · G5
     Faz 8–9 · G6 Faz 10 · G7 Faz 11 ve tampon.

     Görev formatı: İş · Dosyalar · Bağımlı · Kabul (senin doğrulama yöntemin).

     ---

     Faz 1: Kurulum (Claude araçları)

     Önceki projeden alınanlar: tam settings.json baştan, test script'li
     fail-closed guard hook, iş akışı skill'leri ilk günden, inceleme döngüsünde
     tur sınırı, hook kanıtı için log satırı. Bu projeye göre değişenler: görev
     bazlı ilerleme olduğu için tek task skill'i (önceki phase, api-endpoint ve
     frontend-feature yerine), hook'lar Node ile, .env.example deny dışında.

     Araç kararları

     Araç: CLAUDE.md
     Karar: Var (~500 token)
     Neden: Komutlar, mimari değişmezler, çalışma kuralları her oturumda lazım.
     ────────────────────────────────────────
     Araç: Rules
     Karar: clean-code, backend, frontend (paths: ile)
     Neden: Senin ilkelerin ve stack kuralları yalnızca kod işinde yüklenir.
     ────────────────────────────────────────
     Araç: Skills
     Karar: task, review-pr, finish-phase
     Neden: Görev döngüsü, salt-okur inceleme ve faz kapanışı. Docs-first adımı
     task
       içinde zorunlu.
     ────────────────────────────────────────
     Araç: Agents
     Karar: test-runner (haiku)
     Neden: Test çıktısını ana bağlamdan uzak tutar.
     ────────────────────────────────────────
     Araç: Hooks
     Karar: guard.mjs (PreToolUse), format.mjs (PostToolUse)
     Neden: Tehlikeli komut ve sır engeli; otomatik format.
     ────────────────────────────────────────
     Araç: settings.json
     Karar: Tam liste bir kerede
     Neden: İzin sorularını baştan keser; Claude kendisi düzenleyemez, sen
       uygularsın.
     ────────────────────────────────────────
     Araç: MCP
     Karar: context7 (Faz 1), laravel-boost (Görev 2.2)
     Neden: Vue, Pinia, Tailwind, Vitest, Playwright dokümanları; Laravel
     dokümanı,
       DB şeması.
     ────────────────────────────────────────
     Araç: Plugin
     Karar: security-guidance
     Neden: Commit/push sonrası güvenlik bulgusu; önceki projede gerçek bulgular

       yakaladı.
     ────────────────────────────────────────
     Araç: Built-in
     Karar: claude-api skill, /code-review, /security-review, /doctor,
       /fewer-permission-prompts
     Neden: Claude API dokümanı, inceleme, sağlık kontrolü, izin listesini
     kullanıma
       göre genişletme.
     ────────────────────────────────────────
     Araç: Atlananlar
     Karar: phase skill
     Neden: Her görevi sen doğruluyorsun; gözetimsiz faz koşusu gerekmiyor.
     ────────────────────────────────────────
     Araç:
     Karar: api-endpoint, frontend-feature skill'leri
     Neden: Docs-first task'ta, konvansiyonlar rule'larda; tekrar olur (DRY).
     ────────────────────────────────────────
     Araç:
     Karar: browser-check skill, Playwright MCP
     Neden: make e2e script'i tekrarlanabilir ve testlerin parçası.
     ────────────────────────────────────────
     Araç:
     Karar: GitHub MCP, DB MCP
     Neden: gh CLI ve Boost yeterli.
     ────────────────────────────────────────
     Araç:
     Karar: Global ~/.claude/CLAUDE.md, output style, statusline
     Neden: Bu projeye katkısı yok.

     1.1 Repo başlangıcı

     - İş: .gitignore (vendor, node_modules, .env, build çıktıları,
     .claude/logs), .gitattributes (* text=auto eol=lf: Windows'ta CRLF'li shell
     script'ler container'da bozulmasın), planı docs/plan.md'ye kopyala, main'e
     ilk commit, phase-1/setup dalı.
     - Dosyalar: .gitignore, .gitattributes, docs/plan.md
     - Bağımlı: -
     - Kabul: git log --oneline 1 commit; git branch --show-current →
     phase-1/setup; git check-attr eol -- x.sh → lf.

     1.2 settings.json (sen uygularsın)

     - İş: Claude tam içeriği hazırlar, sen .claude/settings.json'a koyarsın.
       - defaultMode: acceptEdits.
       - allow: make test*|lint*|up|ps|logs*|fresh|smoke|e2e; docker compose
     ps|logs|build|up -d|exec|run --rm; php artisan
     test|route:list|migrate:status|config:show|llm:eval;
     vendor/bin/pest|pint|phpstan; npm run lint|test|type-check|build; npx
     playwright test; node scripts/<tam ad>.mjs (joker yok); git
     status|diff|log|branch|show|add|commit|switch|checkout -b; gh pr
     create|view|list; ls; curl http://localhost:*; context7 ve boost araçları.
       - ask: git push, composer require, npm install <paket> (yeni bağımlılık),
     docker compose down -v.
       - deny: .env, backend/.env, frontend/.env, mock-provider/.env için
     Read/Edit/Write (.env.* joker yok, .env.example yazılabilsin); **/*.pem,
     **/*credentials* okuma; git clean, git reset --hard, docker system prune.
       - Hook kaydı, enabledPlugins: security-guidance.
     - Dosyalar: .claude/settings.json
     - Bağımlı: 1.1
     - Kabul: /permissions listesi beklenenle aynı; Claude'un .env okuma
     denemesi reddedilir; .env.example oluşturma sorusuz geçer; git status
     sorusuz çalışır.

     1.3 Guard hook ve test script'i

     - İş: guard.mjs (Bash ve PowerShell). Engeller: özyinelemeli silme; main'e
     veya main'deyken push, force push, main'de commit; shell üzerinden .env
     erişimi (cat, type, Get-Content, cp vb.; "aracı değil etkiyi engelle");
     docker compose down -v, docker volume rm, docker system prune; staged
     diff'te sk-ant- deseni varken git commit. Riskli komutta ayrıştıramadığı
     yapıda ($(, backtick, satır devamı, tırnak) fail-closed. Her engelde
     .claude/logs/hooks.log'a satır yazar.
     - Dosyalar: .claude/hooks/guard.mjs, scripts/test-guard.mjs
     - Bağımlı: 1.2
     - Kabul: node scripts/test-guard.mjs tümü PASS (≥30 vaka, engellenecek ve
     geçecek, iki shell); canlı deneme: git push origin main engellenir, log'da
     satır görünür. (Classifier self-modification diye reddederse Claude test
     edilmiş dosyayı verir, sen koyarsın.)

     1.4 Format hook'u

     - İş: format.mjs: .php → Pint, .vue/.ts/.js/.css/.json/.md → Prettier. Araç
     henüz yoksa sessizce atlar, hiç engellemez, log satırı yazar.
     - Dosyalar: .claude/hooks/format.mjs
     - Bağımlı: 1.2
     - Kabul: Bozuk girintili bir .md yazılınca düzelir; hooks.log'da satır var.

     1.5 CLAUDE.md

     - İş: Stack; komutlar; dev ortamı (host'ta PHP 8.4, Composer, Node, Docker;
     Windows'ta make); mimari değişmezler (LLM çıktısı her zaman doğrulanır ve
     asla SQL olmaz; izin kontrolü hem kitlede hem gönderimde; çift gönderim
     katmanları; limitler tek kaynaktan); çalışma kuralları (/task, docs-first,
     tek görev, onaysız commit yok, reddedilen araçta dur ve raporla, başka
     araca geçme, karar → DECISIONS.md, session export).
     - Dosyalar: CLAUDE.md
     - Bağımlı: 1.1
     - Kabul: /context'te ≤ ~600 token; içerik okunarak onaylanır.

     1.6 Rules

     - İş: clean-code.md (DRY/KISS/YAGNI, "neden" yorumları, isimlendirme, magic
     number yok, boş catch yok, erken dönüş, anlamlı hata mesajı); backend.md
     (FormRequest, API Resource, ince controller, davranış modelde veya servis
     sınıfında, env() yalnızca config'de, tipli exception → tek JSON hata
     formatı, Unit/Feature test yerleşimi, nötr fixture); frontend.md (<script
     setup lang="ts">, any yok, Pinia, composable'larda cleanup, lazy route,
     shallowRef, erişilebilirlik temeli).
     - Dosyalar: .claude/rules/*.md
     - Bağımlı: 1.5
     - Kabul: Her dosyada doğru paths:; toplam ~1.5K token; içerik okunarak
     onaylanır.

     1.7 task skill'i

     - İş: Argüman görev ID'si. Adımlar: 0) plan.md'den görevi ve bağımlılıkları
     oku, ilgili rule dosyalarını önceden oku; 1) Docs first: dokunulan her
     kütüphane için bir context7/Boost sorgusu, LLM görevlerinde claude-api
     skill; ya da gerekçe; 2) en küçük uygulama; 3) testler (test-runner); 4)
     lint/type-check ana oturumda; 5) gerekiyorsa DECISIONS.md; 6) rapor:
     dosyalar, kabul kriteri kanıtı, doküman sorguları, açık konular; commit'ten
     önce dur.
     - Dosyalar: .claude/skills/task/SKILL.md
     - Bağımlı: 1.5, 1.6
     - Kabul: /task 1.13 deneme koşusunda tüm adımlar raporda görünür.

     1.8 review-pr ve finish-phase skill'leri

     - İş: review-pr: salt-okur (Write/Edit yasak); git diff main...HEAD + git
     diff HEAD + izlenmeyen dosyalar; kontrol listesi: proje değişmezleri, sır
     yok, nötr fixture, test var mı. finish-phase: test + lint → review-pr →
     /code-review (seviye fazdan) → yalnızca bug/güvenlik/kural ihlali
     düzeltilir, en fazla 2 tur, reddedilenler listesi → özet (dosyalar,
     kontroller, düzeltmeler, PR başlığı) → onayla push + gh pr create.
     - Dosyalar: .claude/skills/review-pr/SKILL.md,
     .claude/skills/finish-phase/SKILL.md
     - Bağımlı: 1.7
     - Kabul: review-pr commit'lenmemiş değişiklikte boş diff vermez (1.13'te
     denenir).

     1.9 test-runner agent'ı

     - İş: model: haiku, araçlar Bash, Read, Grep, Glob; make test-backend /
     make test-frontend / filtreli Pest çalıştırır, yalnızca sonucu ve hata
     veren testleri döner.
     - Dosyalar: .claude/agents/test-runner.md
     - Bağımlı: 1.5
     - Kabul: Faz 2'deki ilk testte özet ≤15 satır.

     1.10 MCP: context7

     - İş: .mcp.json'a context7 (remote HTTP). Boost Laravel kurulunca (2.2)
     eklenir.
     - Dosyalar: .mcp.json
     - Bağımlı: 1.2
     - Kabul: /mcp → context7 connected; "vue router lazy loading" sorgusu sonuç
     döner.

     1.11 Session export temizliği

     - İş: ai-sessions/README.md (isim formatı, export adımı);
     scrub-sessions.mjs: API key desenleri, e-posta, telefon, Windows kullanıcı
     yolu maskelenir; --check temiz değilse non-zero döner.
     - Dosyalar: ai-sessions/README.md, scripts/scrub-sessions.mjs
     - Bağımlı: 1.1
     - Kabul: Sahte key ve e-posta içeren örnek dosya maskelenir; --check önce
     1, sonra 0 döner.

     1.12 Plugin ve sağlık kontrolü

     - İş: security-guidance etkin mi kontrol; /doctor çalıştır; bulgular
     Fix/Defer/Disagree diye ayrılır (durma kuralı: güvenlik ve izin dışı
     öneriler ertelenir).
     - Dosyalar: - (gerekirse CLAUDE.md)
     - Bağımlı: 1.2–1.10
     - Kabul: /plugin listesinde etkin; /doctor çıktısı ve karar listesi
     raporda.

     1.13 Deneme koşusu

     - İş: Küçük bir değişiklikle (README taslağı) task → commit → finish-phase
     → PR döngüsünü baştan sona çalıştır, bozulanı düzelt.
     - Dosyalar: README.md (taslak) + düzeltmeler
     - Bağımlı: 1.1–1.12
     - Kabul: Faz 1 PR'ı açık; döngüde çıkan sorunlar ve düzeltmeleri PR
     açıklamasında.

     ---

     Faz 2: İskelet ve altyapı

     2.1 Laravel iskeleti

     - İş: backend/'e Laravel 12, Pest, Pint, Larastan (seviye 6). Örnek
     dosyalar temizlenir.
     - Dosyalar: backend/** (scaffold), backend/phpstan.neon
     - Bağımlı: 1.13
     - Kabul: vendor/bin/pest, vendor/bin/pint --test, vendor/bin/phpstan yeşil.

     2.2 Laravel Boost MCP

     - İş: laravel/boost (dev), .mcp.json'a ekle.
     - Dosyalar: backend/composer.json, .mcp.json
     - Bağımlı: 2.1
     - Kabul: /mcp → laravel-boost connected; search-docs "queue rate limiting"
     sonuç döner.

     2.3 Ortam ve config

     - İş: Kökte .env.example: ANTHROPIC_API_KEY=, ANTHROPIC_MODEL,
     LLM_DRIVER=auto|claude|fake, DB/Redis, PROVIDER_URL,
     SEND_RATE_PER_SECOND=100, WORKERS_PER_CHANNEL, mock hata oranları.
     config/llm.php, config/delivery.php (limitlerin tek kaynağı). auto: key
     boşsa fake ve uyarı log'u.
     - Dosyalar: .env.example, backend/config/llm.php,
     backend/config/delivery.php
     - Bağımlı: 2.1
     - Kabul: Key boşken php artisan config:show llm → driver fake; unit test
     aynı şeyi doğrular.

     2.4 Backend imajı

     - İş: FrankenPHP + PHP 8.4 (pgsql, redis, pcntl). Entrypoint: DB'yi bekle →
     migrate --force → tablo boşsa seed. Aynı imaj worker olarak da çalışır.
     - Dosyalar: backend/Dockerfile, docker/backend/entrypoint.sh
     - Bağımlı: 2.1
     - Kabul: docker compose build app başarılı.

     2.5 Health endpoint (temel)

     - İş: GET /api/health: DB ve Redis ping, LLM sürücü adı; biri düşükse 503.
     Tek JSON hata formatı ({error:{code,message,details}}) ve exception
     eşlemesi burada kurulur.
     - Dosyalar: backend/routes/api.php,
     backend/app/Http/Controllers/HealthController.php,
     backend/bootstrap/app.php, test
     - Bağımlı: 2.3
     - Kabul: Feature test: sağlıklıyken 200, Redis düşük simülasyonunda 503 ve
     hata gövdesi.

     2.6 Frontend iskeleti

     - İş: Vue 3 + TS + Vite + Pinia + Router + Tailwind + Vitest + ESLint +
     Prettier.
     - Dosyalar: frontend/** (scaffold)
     - Bağımlı: 1.13
     - Kabul: npm run build, lint, type-check, test yeşil.

     2.7 Frontend imajı ve nginx

     - İş: Çok aşamalı build → nginx. /api proxy; SSE için proxy_buffering off,
     uzun proxy_read_timeout; SPA fallback.
     - Dosyalar: frontend/Dockerfile, docker/nginx/default.conf
     - Bağımlı: 2.6
     - Kabul: http://localhost:8080 uygulamayı, http://localhost:8080/api/health
     JSON'u döner.

     2.8 docker-compose.yml

     - İş: postgres (init script'i test DB'sini de açar), redis, app,
     worker-sms, worker-email (replica sayısı env'den), web; healthcheck'ler,
     depends_on: service_healthy. Mock provider 7.1'de eklenir.
     - Dosyalar: docker-compose.yml, docker/postgres/init.sql
     - Bağımlı: 2.4, 2.5, 2.7
     - Kabul: Temiz durumda docker compose up -d → docker compose ps tümü
     healthy.

     2.9 Makefile

     - İş: up, down, fresh (volume'suz yeniden migrate+seed), logs, test (=
     test-backend + test-frontend, docker içinde), lint, smoke (8.8), e2e
     (10.8).
     - Dosyalar: Makefile
     - Bağımlı: 2.8
     - Kabul: make test temiz klonda yeşil; make lint yeşil.

     2.10 README ve DECISIONS iskeleti

     - İş: README başlıkları (kurulum, mimari, akış, testler, eksikler);
     DECISIONS.md'ye D1, D2, D3, D14, D15, D17.
     - Dosyalar: README.md, DECISIONS.md
     - Bağımlı: 2.8
     - Kabul: Okuyarak onay.

     ---

     Faz 3: Veri modeli ve seed

     3.1 customers tablosu ve modeli

     - İş: Alanlar: first_name (nullable, fallback testi için), last_name,
     email, phone (E.164), city, language (tr/en), birth_date, last_cart_add_at,
     last_purchase_at, orders_count, total_spent, sms_opt_in, email_opt_in.
     İndeksler: city, last_cart_add_at, last_purchase_at. Model:
     canReceive(Channel). Factory.
     - Dosyalar: migration, app/Models/Customer.php, app/Enums/Channel.php,
     factory, unit test
     - Bağımlı: 2.9
     - Kabul: canReceive unit testleri (izin yok / iletişim bilgisi yok /
     izinli) yeşil.

     3.2 Seeder

     - İş: 5.000 kullanıcı, Faker tr_TR, sabit seed (deterministik). Dağılımlar:
     ağırlıklı şehirler (İstanbul ~%30); son 30 günde sepete ekleyen ~%25,
     bunların yarısı satın almamış; SMS izni ~%70, email izni ~%80; ~%3 boş
     first_name; ~%10 en. Toplu insert (1.000'lik chunk).
     - Dosyalar: database/seeders/CustomerSeeder.php, DatabaseSeeder.php
     - Bağımlı: 3.1
     - Kabul: make fresh iki kez → SELECT count(*), md5(string_agg(email, ','
     ORDER BY id)) aynı; dağılım sorgusu hedeflere ±%3 yakın.

     3.3 Campaign ve durum makinesi

     - İş: Tablo: brief, status, segment_rule (jsonb), unsupported_criteria,
     audience_counts, zaman damgaları. CampaignStatus: draft → audience_ready →
     content_ready → approved → sending → completed (+ failed). Geçişler model
     metodlarında (defineAudience(), setContent(), approve(), startSending(),
     complete()); geçersiz geçiş InvalidCampaignTransition fırlatır (Rich Domain
     Model).
     - Dosyalar: migration, app/Models/Campaign.php,
     app/Enums/CampaignStatus.php, unit test
     - Bağımlı: 3.1
     - Kabul: Tüm izinli ve yasak geçiş çiftleri için test (data provider)
     yeşil.

     3.4 campaign_messages

     - İş: campaign_id, channel, subject (nullable), body, source (llm/human);
     unique (campaign_id, channel).
     - Dosyalar: migration, app/Models/CampaignMessage.php, test
     - Bağımlı: 3.3
     - Kabul: Aynı kanal için ikinci insert unique hatası verir (integration
     test).

     3.5 deliveries

     - İş: campaign_id, customer_id, channel, status (pending, sending, sent,
     failed, skipped), attempts, next_attempt_at, claimed_at, last_error,
     idempotency_key (unique), provider_message_id, sent_at. Unique
     (campaign_id, customer_id, channel); indeks (campaign_id, channel, status).
     Domain metodları: markSent, markFailed, scheduleRetry, skip.
     - Dosyalar: migration, app/Models/Delivery.php,
     app/Enums/DeliveryStatus.php, testler
     - Bağımlı: 3.3
     - Kabul: Unit: terminal durumdan geçiş reddedilir. Integration: aynı üçlü
     ikinci kez eklenemez.

     ---

     Faz 4: Segment kuralı (LLM'siz)

     4.1 FieldRegistry

     - İş: Alan → kolon, tip, izinli operatörler, değer kısıtları, Türkçe
     etiket. Tipler: enum (city, language; şehir listesi DB'den, cache'li),
     number (orders_count, total_spent, age), date (last_cart_add_at,
     last_purchase_at). Operatörler: eq, neq, in, not_in, gt, gte, lt, lte,
     between, within_last_days, not_within_last_days (null dahil), is_null,
     not_null. Doğrulayıcı, derleyici, prompt ve JSON şeması bu tek kaynaktan
     beslenir (DRY). Hash map, arama O(1).
     - Dosyalar: app/Segment/FieldRegistry.php, FieldDefinition.php,
     Operator.php, unit test
     - Bağımlı: 3.2
     - Kabul: Unit: her tip için izinli/yasak operatör tablosu.

     4.2 SegmentRule ve doğrulayıcı

     - İş: SegmentRuleParser::parse(array): SegmentRule|ValidationErrors
     ("doğrulama yerine ayrıştır": SegmentRule geçersiz olarak var olamaz).
     Kontroller: yapı, version, 1–10 koşul, bilinen alan, tipe uygun operatör,
     değer tipi ve aralığı (gün 1–365, in ≤50 öğe, şehir listede), between
     sırası. Hatalar yol bazlı (conditions[1].value: ...); hem onarım prompt'u
     hem UI kullanır.
     - Dosyalar: app/Segment/SegmentRule.php, Condition.php,
     SegmentRuleParser.php, ValidationErrors.php, unit test
     - Bağımlı: 4.1
     - Kabul: ≥20 geçersiz girdi vakası doğru yol ve mesajla reddedilir; örnek
     brief'in kuralı geçer.

     4.3 SegmentQueryCompiler

     - İş: SegmentRule → customers üzerinde Builder; kolon adı yalnızca
     registry'den, değerler binding ile; tarih koşulları now()'a göre (testte
     travelTo). Kanal izin filtresi ayrı metod.
     - Dosyalar: app/Segment/SegmentQueryCompiler.php, integration test
     - Bağımlı: 4.2
     - Kabul: Integration: el ile kurulmuş 10 kişilik fixture'da örnek brief
     kuralı beklenen ID kümesini döner; toSql() çıktısında kural değerleri yok,
     yalnızca ?.

     4.4 AudienceService

     - İş: Kural → kanal başına sayılar (COUNT(*) FILTER, tek sorgu, tek tarama)
     + toplam benzersiz + 5 örnek kişi + kanal başına en uzun first_name/city
     (D16 için).
     - Dosyalar: app/Segment/AudienceService.php, AudienceSummary.php,
     integration test
     - Bağımlı: 4.3
     - Kabul: Integration: izinsiz kullanıcı ilgili kanal sayısına girmez; sorgu
     sayısı 2 (DB::getQueryLog).

     ---

     Faz 5: LLM katmanı (en ağır kalem)

     5.1 LlmClient arayüzü ve fake

     - İş: LlmClient::complete(LlmRequest): LlmResponse, stream(LlmRequest):
     iterable<LlmChunk>. DTO'lar: system, messages, JSON şeması, maxTokens;
     yanıt: metin, usage, stopReason. FakeLlmClient: sıralı senaryo kuyruğu
     (geçersiz → geçerli gibi), stream'i parçalara böler, hata da üretebilir.
     Container binding: auto / claude / fake.
     - Dosyalar: app/Llm/LlmClient.php, LlmRequest.php, LlmResponse.php,
     LlmChunk.php, FakeLlmClient.php, app/Providers/LlmServiceProvider.php, unit
     test
     - Bağımlı: 2.3
     - Kabul: Unit: senaryo sırası, stream parçaları birleşince tam metin,
     binding doğru sürücüyü seçer.

     5.2 ClaudeLlmClient: tek seferlik çağrı

     - İş: Docs first: claude-api skill (model ID, structured output veya tool
     use biçimi, header'lar, stop_reason, hata kodları). Laravel Http; timeout;
     429/5xx/529'da retry-after dikkate alınarak en fazla 3 deneme; tipli
     exception'lar: LlmUnavailable, LlmTruncated (max_tokens), LlmRefused,
     LlmBadResponse. Key hiçbir log'a yazılmaz.
     - Dosyalar: app/Llm/ClaudeLlmClient.php, app/Llm/Exceptions/*, test
     - Bağımlı: 5.1
     - Kabul: Http::fake testleri: başarı, 429 → retry → başarı, 3× 529 →
     LlmUnavailable, max_tokens → LlmTruncated; istek gövdesinde şema ve model
     doğru. Log'da key yok.

     5.3 Streaming ve SSE ayrıştırıcı

     - İş: Saf SseParser (satır sınırında bölünmüş parçalar, ping, error olayı,
     çok satırlı data); ClaudeLlmClient::stream Guzzle stream'iyle delta'ları
     generator olarak verir.
     - Dosyalar: app/Llm/SseParser.php, ClaudeLlmClient.php,
     tests/Fixtures/claude-stream.txt, test
     - Bağımlı: 5.2
     - Kabul: Unit: fixture rastgele noktalarından bölününce de aynı olay
     dizisi; error olayı exception'a döner.

     5.4 LLM çağrı kaydı

     - İş: llm_calls tablosu (amaç, prompt versiyonu, model, token'lar, gecikme,
     deneme no, sonuç, doğrulama hataları, ham çıktı; brief ve şablon PII
     içermez). RecordingLlmClient decorator: istemcilere dokunmadan kayıt (OCP).
     - Dosyalar: migration, app/Models/LlmCall.php,
     app/Llm/RecordingLlmClient.php, test
     - Bağımlı: 5.1
     - Kabul: Integration: başarılı ve hatalı çağrı birer satır; gecikme > 0.

     5.5 Segment prompt'u v1

     - İş: Rol; registry'den üretilen alan/operatör tablosu; şehir listesi;
     bugünün tarihi; kurallar: yalnızca şemaya uygun JSON, eşleştiremediğin
     ifadeyi uydurma, unsupported_criteria'ya yaz (ör. "iPhone kullananlar"),
     her yorum için kısa explanation; 3 örnek. JSON şeması registry'den
     üretilir. Sabit system kısmı prompt cache'e uygun tutulur.
     - Dosyalar: app/Llm/Prompts/SegmentPrompt.php,
     resources/prompts/segment.v1.md, snapshot test
     - Bağımlı: 4.1, 5.1
     - Kabul: Snapshot testi; şema test'te JSON Schema olarak geçerli.

     5.6 SegmentExtractor

     - İş: Brief doğrulama (10–1.000 karakter) → çağrı → JSON parse →
     SegmentRuleParser → hata varsa hatalar Claude'a geri verilip en fazla 2
     onarım → SegmentExtraction{rule, unsupported, explanation, attempts} ya da
     tüm hataları taşıyan SegmentExtractionFailed. Brief'teki talimatlar (prompt
     injection) çıktıyı değiştirse bile doğrulamadan geçemez.
     - Dosyalar: app/Llm/SegmentExtractor.php, SegmentExtraction.php, unit test
     - Bağımlı: 4.2, 5.4, 5.5
     - Kabul: Fake ile vakalar: geçerli; geçersiz → onarım → geçerli (2. istekte
     hata listesi var); 3× geçersiz → anlamlı hata; JSON olmayan çıktı;
     kesilmiş çıktı; API düşük. Hepsi yeşil.

     5.7 Kişiselleştirme

     - İş: Değişken registry'si ({{first_name}}, {{last_name}}, {{city}}, her
     biri fallback'li, ör. "Değerli müşterimiz"). PersonalizationRenderer: tek
     geçişte preg_replace_callback, O(n); bilinmeyen değişkenleri bulur;
     worstCaseLength(template, maxLengths) hesaplar. mb_* ile Türkçe güvenli.
     - Dosyalar: app/Content/Variables.php, PersonalizationRenderer.php, unit
     test
     - Bağımlı: 3.1
     - Kabul: Unit: boş first_name → fallback; {{unknown}} tespit; ş, ğ içeren
     metinde uzunluk karakter bazında doğru.

     5.8 ContentValidator

     - İş: SMS ≤160 (D16'ya göre en kötü durum render uzunluğu), konu ≤80, email
     gövdesi ≤2.000, boş değil, yalnızca bilinen değişkenler, bozuk {{.
     Limitler config/delivery.php'den. LLM çıktısı ve insan düzenlemesi aynı
     doğrulayıcıdan geçer (DRY). Yol bazlı hatalar.
     - Dosyalar: app/Content/MessageContent.php, ContentValidator.php, unit test
     - Bağımlı: 5.7
     - Kabul: Unit: 160/161 ve 80/81 sınırları; değişken uzunluğu limiti
     aşırınca hata; çok baytlı karakterler.

     5.9 İçerik prompt'u v1

     - İş: Brief, ton, kitle özeti (sayılar ve kural açıklaması), kanal
     limitleri, kullanılabilir değişkenler, dil (Türkçe). Şema: {sms:{body},
     email:{subject, body}}.
     - Dosyalar: app/Llm/Prompts/ContentPrompt.php,
     resources/prompts/content.v1.md, snapshot test
     - Bağımlı: 5.8
     - Kabul: Snapshot testi.

     5.10 ContentGenerator

     - İş: Stream delta'larını olay olarak verir → sonda parse ve
     ContentValidator → geçersizse hatalarla tek seferlik (stream'siz) onarım,
     en fazla 2 → final (doğrulanmış içerik ve "onarıldı" bilgisi) ya da error.
     Tüketici bağlantıyı keserse stream iptal edilir (token israfı olmasın).
     - Dosyalar: app/Llm/ContentGenerator.php, ContentEvent.php, unit test
     - Bağımlı: 5.3, 5.9
     - Kabul: Fake ile: delta'lar sırayla; 161 karakterlik SMS → onarım →
     geçerli final; iki onarım da başarısız → error ve hata listesi.

     5.11 Geliştirme fake'i

     - İş: Key yokken çalışan hazır yanıtlar: örnek brief için kural ve içerik,
     diğer brief'ler için genel bir yanıt; 20–40 ms aralıklı stream.
     - Dosyalar: app/Llm/Fake/DevScenarios.php, resources/llm-fixtures/*.json
     - Bağımlı: 5.6, 5.10
     - Kabul: Key'siz php artisan llm:eval --only=example (5.12) geçer; stream
     gecikmesi gözle görülür.

     5.12 llm:eval

     - İş: 8–10 altın brief (beklenen alan/operatör/değer iddiaları ve içerik
     limitleri). Seçili sürücüyle çalışır; geçerlilik oranı, ortalama deneme ve
     gecikme raporlar. Fake ile CI'da, gerçek key ile istersen elle.
     - Dosyalar: app/Console/Commands/LlmEval.php,
     resources/llm-eval/briefs.json, test
     - Bağımlı: 5.11
     - Kabul: Fake ile %100; key verirsen gerçek model raporu README'ye eklenir.

     ---

     Faz 6: Kampanya API'si

     6.1 Oluştur, listele, göster

     - İş: POST /api/campaigns {brief}, GET /api/campaigns (sayfalı), GET
     /api/campaigns/{id} (kural, sayılar, mesajlar, durum). FormRequest +
     Resource.
     - Dosyalar: controller, request, resource, route, feature test
     - Bağımlı: 3.3
     - Kabul: Feature: kısa brief → 422 ve alan hatası; oluşturma → 201; liste
     sayfalı.

     6.2 Kitle çıkarma

     - İş: POST /api/campaigns/{id}/audience → SegmentExtractor +
     AudienceService; kural, desteklenmeyen kriterler ve sayılar saklanır;
     audience_ready. Onaya kadar tekrar çalıştırılabilir. LLM başarısız → 422
     (hata listesi), LLM erişilemez → 503.
     - Dosyalar: controller, action, feature test
     - Bağımlı: 4.4, 5.6, 6.1
     - Kabul: Fake ile 200 ve kanal sayıları; başarısız senaryoda 422 gövdesinde
     anlamlı mesaj; onaylanmış kampanyada 409.

     6.3 İçerik stream endpoint'i

     - İş: POST /api/campaigns/{id}/content/stream → SSE: delta, final, error;
     final sonrası campaign_messages (source=llm) ve content_ready. İstemci
     koparsa iptal.
     - Dosyalar: controller, feature test
     - Bağımlı: 5.10, 6.2
     - Kabul: Feature: yanıt text/event-stream, olay sırası delta… → final;
     DB'de iki mesaj.

     6.4 Düzenleme, önizleme ve kurallar

     - İş: PUT /api/campaigns/{id}/content (insan düzenlemesi, ContentValidator,
     422 yol bazlı); GET /api/campaigns/{id}/preview?customer_id= (verilmezse
     örnek kişi; render edilmiş metin ve uzunluklar); GET /api/content/rules
     (limitler ve değişkenler; frontend kendi kopyasını tutmasın diye, DRY).
     - Dosyalar: controller'lar, feature test
     - Bağımlı: 6.3
     - Kabul: Feature: 161 karakterlik SMS → 422 sms.body; önizlemede boş isimli
     kişi için fallback.

     ---

     Faz 7: Mock provider

     7.1 Servis iskeleti

     - İş: mock-provider/: tek giriş noktalı sade PHP, php -S
     (PHP_CLI_SERVER_WORKERS=16). POST /sms {to, body}, POST /email {to,
     subject, body}; Idempotency-Key zorunlu; geçersiz alıcı → 400 (kalıcı
     hata). GET /health, GET /stats (kanal başına toplam, tekrar sayısı,
     saniyelik en yüksek hız), DELETE /stats (test sıfırlama). Compose'a
     eklenir.
     - Dosyalar: mock-provider/public/index.php, mock-provider/src/*,
     mock-provider/Dockerfile, docker-compose.yml
     - Bağımlı: 2.8
     - Kabul: curl ile SMS gönder → 200 ve id; /stats 1 gösterir.

     7.2 Idempotency

     - İş: Aynı key → kaydedilmiş aynı yanıt, sayaca tekrar eklenmez (SET NX);
     aynı key farklı gövde → 422.
     - Dosyalar: mock-provider/src/IdempotencyStore.php, test
     - Bağımlı: 7.1
     - Kabul: Aynı key ile 3 istek → /stats toplam 1, duplicates_prevented 2.

     7.3 Hata üretimi ve kendi rate limit'i

     - İş: FaultPolicy (rastgele kaynağı enjekte edilir): gecikme aralığı, 500,
     429 (+Retry-After), timeout, "kabul edip 500". Oranlar env'den;
     deterministik test için X-Mock-Fault header'ı. Kanal başına >100/sn → 429
     (saniyelik Redis INCR).
     - Dosyalar: mock-provider/src/FaultPolicy.php, RateLimiter.php, test
     - Bağımlı: 7.2
     - Kabul: PHPUnit: sabit rastgele kaynakla beklenen dağılım; X-Mock-Fault:
     429 → 429 ve Retry-After; "kabul edip 500" sonrası aynı key ile tekrar →
     200 ve kayıt 1.

     ---

     Faz 8: Gönderim hattı (ikinci ağır kalem)

     8.1 MessageProvider istemcisi

     - İş: Arayüz + HttpMessageProvider: send(payload, idempotencyKey):
     SendResult. Sınıflandırma: 2xx → Sent; 429 → RateLimited(retryAfter); 5xx,
     timeout, bağlantı hatası → Transient; diğer 4xx → Permanent. Bağlantı
     timeout'u 1 sn, toplam 3 sn.
     - Dosyalar: app/Delivery/MessageProvider.php, HttpMessageProvider.php,
     SendResult.php, unit test
     - Bağımlı: 7.1
     - Kabul: Http::fake ile 4 sınıfın hepsi; header'da Idempotency-Key.

     8.2 RetryPolicy

     - İş: Saf sınıf: deneme n → min(cap, base·2ⁿ) aralığında full jitter;
     RateLimited → max(retryAfter, backoff); maks. 5 deneme; Permanent → tekrar
     yok. Rastgele kaynağı enjekte edilir.
     - Dosyalar: app/Delivery/RetryPolicy.php, RetryDecision.php, unit test
     - Bağımlı: 8.1
     - Kabul: Unit: gecikme sınırları, Retry-After önceliği, 5. denemeden sonra
     GiveUp.

     8.3 SendDeliveryJob

     - İş: Delivery'yi yükle; terminal ise çık (idempotent); atomik claim
     (UPDATE … SET status=sending, claimed_at=now() WHERE id=? AND
     (status=pending OR (status=sending AND claimed_at < now()-60s)), etkilenen
     satır 1 değilse çık); izni gönderim anında tekrar kontrol et → skipped;
     render + uzunluk kontrolü; throttle (8.4); provider; sonuca göre markSent,
     scheduleRetry (gecikmeli release) ya da markFailed. Deneme sayısı DB'de,
     Laravel tries değil.
     - Dosyalar: app/Delivery/SendDeliveryJob.php, integration test
     - Bağımlı: 3.5, 5.7, 8.2
     - Kabul: Integration (gerçek DB + fake provider): başarı; geçici → başarı;
     kalıcı → failed; 429 → gecikme ≥ Retry-After; aynı delivery için iki job →
     provider'a tek çağrı; izni sonradan kapanan → skipped.

     8.4 Kanal rate limit'i

     - İş: ChannelRateLimiter:
     Redis::throttle("send:{channel}")->allow(N)->every(1)->block(...). Ayrı
     kuyruklar sms/email.
     - Dosyalar: app/Delivery/ChannelRateLimiter.php, integration test
     - Bağımlı: 8.3
     - Kabul: Integration (gerçek Redis): 250 alım, saniye kovalarına göre her
     kova ≤100; iki kanal birbirini yavaşlatmaz.

     8.5 Onay, snapshot ve dağıtım

     - İş: POST /api/campaigns/{id}/approve: kampanya kilidi (Cache::lock), ön
     koşullar (içerik geçerli, kitle >0, content_ready), approve().
     StartCampaignJob: kanal başına INSERT INTO deliveries … SELECT … FROM
     customers WHERE <derlenmiş kural + izin> ON CONFLICT DO NOTHING (set-based,
     tek round-trip, PHP'ye 5.000 satır çekilmez); idempotency key =
     hash(kampanya, kişi, kanal); lazyById(1000) ile job dağıtımı, bellek sabit;
     sending. Tekrar onay → 200 ve mevcut durum, yeni satır yok.
     - Dosyalar: controller, app/Delivery/StartCampaignJob.php,
     AudienceSnapshotter.php, testler
     - Bağımlı: 4.3, 6.4, 8.3
     - Kabul: Integration: snapshot sayısı = AudienceService sayısı; çift ve
     eşzamanlı onay → satır ve job sayısı değişmez (Queue::fake sayımı); kitle 0
     → 422.

     8.6 Kampanya tamamlanması

     - İş: Terminal her delivery sonrası koşullu güncelleme: UPDATE campaigns
     SET status=completed WHERE id=? AND status=sending AND NOT EXISTS
     (pending/sending); yarış durumunda güvenli.
     - Dosyalar: app/Models/Campaign.php (veya CampaignProgress), integration
     test
     - Bağımlı: 8.5
     - Kabul: Integration: son delivery bitince completed; eksik varken
     değişmez.

     8.7 Worker servisleri

     - İş: worker-sms/worker-email: queue:work redis --queue=<kanal> --sleep=0
     --max-time=3600, replica WORKERS_PER_CHANNEL (varsayılan 8). Graceful
     shutdown (stop_grace_period).
     - Dosyalar: docker-compose.yml
     - Bağımlı: 8.4
     - Kabul: docker compose ps → 16 worker; docker compose logs worker-sms
     işlenen job'ları gösterir.

     8.8 Uçtan uca smoke script'i

     - İş: make smoke: API ile kampanya (fake LLM) → kitle → içerik → onay →
     completed bekle → kontroller: provider'da tekrar 0; sent = provider
     toplamı; sent+failed+skipped = delivery sayısı; saniyelik en yüksek hız
     ≤100/kanal. Hata oranları açıkken.
     - Dosyalar: scripts/smoke.mjs, Makefile
     - Bağımlı: 8.6, 8.7, 7.3
     - Kabul: 3 ardışık koşuda yeşil; çıktı tablosu rapora eklenir.

     ---

     Faz 9: Takip

     9.1 İstatistik endpoint'i

     - İş: GET /api/campaigns/{id}/stats: tek GROUP BY channel, status
     (indeksli), son 5 sn gönderim hızı, toplam yeniden deneme, son 10 hata.
     - Dosyalar: app/Delivery/CampaignStats.php, controller, feature test
     - Bağımlı: 8.6
     - Kabul: Feature: fixture sayıları ile birebir.

     9.2 İstatistik SSE'si

     - İş: GET /api/campaigns/{id}/stats/stream: 1 sn'de bir, yalnızca değişince
     (hash karşılaştırma) snapshot; 15 sn heartbeat; retry:; terminal durumda
     veya en fazla 10 dk sonra kapanır.
     - Dosyalar: controller, feature test
     - Bağımlı: 9.1
     - Kabul: Feature: tamamlanmış kampanyada tek snapshot ve kapanış.

     9.3 /api/metrics

     - İş: Prometheus text: deliveries_total{channel,status},
     llm_calls_total{purpose,outcome}, llm_latency_ms_avg, queue_depth{queue},
     campaigns{status}. Sorgu anında hesaplanır (ek sayaç durumu yok, KISS).
     - Dosyalar: controller, feature test
     - Bağımlı: 9.1, 5.4
     - Kabul: Feature: format satırları; curl localhost:8080/api/metrics okunur
     çıktı.

     9.4 Health genişletme

     - İş: Mock provider (/health, 1 sn timeout), kuyruk derinlikleri, LLM modu
     (fake/claude, API çağrısı yapmadan). DB/Redis düşükse 503, provider düşükse
     degraded ve 200.
     - Dosyalar: HealthController.php, test
     - Bağımlı: 2.5, 7.1
     - Kabul: Feature: üç durum (sağlıklı, degraded, down).

     9.5 Yapılandırılmış log

     - İş: stderr'e JSON log; campaign_id, delivery_id, attempt, llm_call_id
     bağlamı; key ve kişisel veri log'a yazılmaz.
     - Dosyalar: config/logging.php, ilgili sınıflar
     - Bağımlı: 8.3
     - Kabul: docker compose logs worker-sms JSON satırları; log'da sk-ant ve
     telefon numarası yok (grep).

     ---

     Faz 10: Frontend

     Performans ilkeleri (gerektiği yerde): lazy route, stream delta'larını
     requestAnimationFrame ile toplu çizme, büyük snapshot'larda shallowRef,
     bileşen kapanınca AbortController/EventSource kapatma, düzenlemede
     debounce.

     10.1 API katmanı

     - İş: client.ts (fetch sarmalayıcı, tipli ApiError), types.ts,
     streamSse(url, body, onEvent, signal) (POST SSE ayrıştırıcı),
     useEventSource composable'ı (kapanışta temizlik).
     - Dosyalar: frontend/src/api/*, frontend/src/composables/useEventSource.ts,
     Vitest
     - Bağımlı: 2.6, 6.1
     - Kabul: Vitest: bölünmüş SSE parçaları doğru olaylara dönüşür; abort'ta
     okuma durur; 422 → alan hataları.

     10.2 Kabuk, router ve liste

     - İş: Rotalar: /campaigns, /campaigns/new, /campaigns/:id (adım duruma
     göre), /campaigns/:id/dashboard. Pinia campaign store'u. Liste sayfası
     (durum rozeti).
     - Dosyalar: router/index.ts, stores/campaign.ts, views/CampaignList.vue,
     test
     - Bağımlı: 10.1
     - Kabul: Vitest; tarayıcıda liste görünür.

     10.3 Brief ve kitle adımı

     - İş: Karakter sayaçlı textarea, "Kitleyi çıkar", yükleniyor durumu; kural
     Türkçe çiplerle; desteklenmeyen kriter uyarısı; SMS, email ve toplam
     sayılar; hata ve tekrar dene.
     - Dosyalar: views/CampaignComposer.vue, components/BriefStep.vue,
     components/AudienceCard.vue, test
     - Bağımlı: 10.2, 6.2
     - Kabul: Vitest (başarı, 422, 503); tarayıcıda örnek brief → sayılar.

     10.4 İçerik stream adımı

     - İş: SMS kartı (telefon görünümü) ve email kartı; kısmi JSON ayrıştırma
     (D8, docs-first ile kütüphane seçimi); iptal butonu; final gelince metin
     doğrulanmış içerikle değişir, "onarıldı" bilgisi gösterilir.
     - Dosyalar: components/ContentStream.vue, components/SmsPreview.vue,
     components/EmailPreview.vue, test
     - Bağımlı: 10.3, 6.3
     - Kabul: Vitest (sahte stream ile kademeli metin); tarayıcıda metin akarak
     gelir.

     10.5 Düzenleme ve önizleme

     - İş: Düzenlenebilir alanlar; /api/content/rules'tan limitlerle canlı sayaç
     (en kötü durum); değişken ekleme butonları; debounce ile PUT; backend
     hataları alanın altında; örnek kişi seçerek önizleme.
     - Dosyalar: components/ContentEditor.vue, components/CharCounter.vue, test
     - Bağımlı: 10.4, 6.4
     - Kabul: Vitest: 161. karakterde sayaç kırmızı ve onay kapalı; backend 422
     mesajı görünür.

     10.6 Onay

     - İş: Kanal sayılarını gösteren onay modalı; tıklayınca buton kapanır (çift
     tıklamaya karşı); onaydan sonra dashboard'a yönlendirme.
     - Dosyalar: components/ApproveDialog.vue, test
     - Bağımlı: 10.5, 8.5
     - Kabul: Vitest: çift tıklama → tek istek.

     10.7 Dashboard

     - İş: Kanal başına ilerleme çubuğu (gönderildi, başarısız, atlandı,
     bekliyor), hız, yeniden deneme sayısı, son hatalar tablosu, durum rozeti;
     SSE ile güncellenir, kopunca yeniden bağlanır, bitince kapanır.
     - Dosyalar: views/CampaignDashboard.vue, components/ChannelProgress.vue,
     test
     - Bağımlı: 10.1, 9.2
     - Kabul: Vitest; tarayıcıda gönderim sırasında sayılar canlı artar ve
     completed'da durur.

     10.8 Playwright e2e

     - İş: Çekirdek akış: brief → kitle → stream → düzenle → onayla → dashboard
     completed; fake LLM ve mock provider ile. make e2e; ekran görüntüleri
     e2e/artifacts/.
     - Dosyalar: frontend/e2e/core-flow.spec.ts, playwright.config.ts, Makefile
     - Bağımlı: 10.7
     - Kabul: make e2e yeşil; ekran görüntüleri incelenir.

     ---

     Faz 11: Teslim

     11.1 README

     - İş: Kurulum (.env.example → .env, key isteğe bağlı), mermaid mimari
     diyagramı, akış, endpoint tablosu, test komutları, llm:eval sonucu,
     yapılmayanlar.
     - Dosyalar: README.md
     - Bağımlı: 10.8
     - Kabul: Okuyarak onay.

     11.2 DECISIONS kontrolü

     - İş: D1–D17 ve görevlerde çıkan kararlar tam mı; reddedilen alternatifler
     kısa.
     - Dosyalar: DECISIONS.md
     - Bağımlı: 11.1
     - Kabul: Okuyarak onay.

     11.3 Son inceleme

     - İş: /security-review ve tüm repo için /code-review high; yalnızca
     bug/güvenlik düzeltmeleri.
     - Dosyalar: bulgulara göre
     - Bağımlı: 11.2
     - Kabul: Bulgu listesi ve kararlar raporda.

     11.4 Temiz klon doğrulaması

     - İş: Geçici klasöre temiz klon → .env kopyala → docker compose up → make
     test, make smoke, make e2e; süreler not edilir.
     - Dosyalar: -
     - Bağımlı: 11.3
     - Kabul: Üçü de yeşil; README'deki adımlar birebir yeterli.

     11.5 Session export'ları

     - İş: Tüm export'lar ai-sessions/'a; scrub-sessions.mjs; --check 0.
     - Dosyalar: ai-sessions/*
     - Bağımlı: 11.4
     - Kabul: --check 0; dosya listesi tarih sırasıyla.

     ---

     Doğrulama (uçtan uca)

     1. docker compose up (key'siz) → http://localhost:8080 → örnek brief →
     kanal sayıları → içerik akarak gelir → SMS'i düzenle (161. karakterde
     engel) → onayla → dashboard completed.
     2. make test (Pest unit + integration, Vitest), make smoke (tekrar 0, hız
     ≤100/sn, sayılar tutarlı), make e2e.
     3. curl localhost:8080/api/health, curl localhost:8080/api/metrics.
     4. İsteğe bağlı: .env'e gerçek key → php artisan llm:eval raporu.

     ---

     Açık Sorular

     Her birinde varsayılan önerim parantezde; aksini söylemezsen bununla
     ilerlerim.

     1. SMS 160 neyi sayıyor? Türkçe karakterler GSM-7'de yok; operatörde
     UCS-2'ye düşer ve segment 70 karaktere iner. (Varsayılan: 160 Unicode
     karakter, mb_strlen; GSM/UCS-2 ayrımı DECISIONS'ta bilinen sadeleştirme
     olarak yazılır.)
     2. Limit şablona mı, kişiye giden metne mi? (Varsayılan: kişiye giden
     metin; kitlenin en uzun değişken değeriyle en kötü durum, D16.)
     3. "Alışveriş yapmayan" ne demek? Son 30 günde hiç almamış / hiç almamış /
     sepete ekledikten sonra almamış. (Varsayılan: son 30 günde satın alma yok;
     explanation alanında kullanıcıya gösterilir.)
     4. Email gövdesi: format ve uzunluk belirtilmemiş. (Varsayılan: düz metin,
     en fazla 2.000 karakter; HTML yok.)
     5. Kimlik doğrulama / çoklu kullanıcı. (Varsayılan: yok, tek pazarlamacı,
     yerel demo; README'de belirtilir.)
     6. "Aynı mesaj" kapsamı: farklı kampanyalar aynı kişiye gidebilir mi?
     (Varsayılan: tekillik kampanya + kişi + kanal; kampanyalar arası frekans
     sınırı kapsam dışı.)
     7. İzin onaydan sonra değişirse? (Varsayılan: gönderim anında tekrar
     kontrol, skipped.)
     8. Pazarlamacı segment kuralını elle düzenleyebilir mi? Case yalnızca mesaj
     düzenlemeyi anlatıyor. (Varsayılan: kural salt-okur; brief değiştirilip
     yeniden üretilir.)
     9. Rate limit kimin sınırı? (Varsayılan: bizim giden trafiğimizin kanal
     başına global sınırı; mock provider da aynı sınırı uygular ve aşımda 429
     döner.)
     10. Model seçimi. (Varsayılan: ANTHROPIC_MODEL=claude-sonnet-5-5, env ile
     değiştirilebilir; maliyet için Haiku 5.5 seçeneği DECISIONS'ta.)
     11. İçerik dili. (Varsayılan: Türkçe; kullanıcı diline göre içerik bonus.)
     12. Yasal metin (İYS, "RET yaz" gibi çıkış ifadesi) SMS'e eklenmeli mi?
     (Varsayılan: hayır, mock ortam; README'de not.)
     13. Seed büyüklüğü. (Varsayılan: 5.000.)
     14. Kalıcı başarısız gönderimler. (Varsayılan: failed olarak kalır ve
     dashboard'da görünür; elle "tekrar dene" isteğe bağlı.)
     15. Windows'ta make. Değerlendirici büyük olasılıkla macOS/Linux kullanır.
     (Varsayılan: Makefile ana yol; README'de Windows için docker compose run
     eşdeğerleri.)

     İsteğe Bağlı Kısımlar

     Çekirdek bittikten sonra, bu sırayla:

     1. CI pipeline: GitHub Actions'ta make lint + make test (servis
     container'larıyla). En ucuz ve en görünür bonus; Faz 2'den sonra zaman
     olursa öne alınabilir.
     2. Zamanlanmış gönderim: campaigns.scheduled_at; scheduler her dakika
     vadesi gelenleri StartCampaignJob'a verir.
     3. A/B varyantları: kanal başına 2 içerik; atama hash(customer_id) mod 100
     ile deterministik, O(1); dashboard'da varyant kırılımı.
     4. Kullanıcının diline göre içerik: customers.language; prompt dil başına
     içerik üretir; render'da dil seçilir.
     5. Diğer: elle "başarısızları tekrar dene", kampanya iptali, circuit
     breaker, segment kuralı düzenleyici, iç içe AND/OR kuralları.

● Plan onaylandı. Önce istediğin çıktıyı (docs/plan.md) oluşturuyorum; Görev
1.1'e (commit, dal) senin "başla" demenle geçeceğim.

● Bash(cp "C:/Users/<user>/.claude/plans/merhaba-seninle-yeni-bir-cheerful-lerdor
f.md" "c:/Users/<user>/Desktop/case/smart-campaign-composer/docs/plan.md"
&& head -3 "c:…)
⎿  1 file changed (binary, mode only or too large to show)

Read 1 file (ctrl+o to expand)

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
1 # Smart Campaign Composer: Uygulama Planı
2  
 3 -Tarih: 2026-11-04 · Durum: taslak, onay bekliyor · Hedef dosya: -`docs/plan.md` (plan modu yalnızca bu dosyaya yazmaya izin veriy
-or; onaydan sonra Görev 1.1'de `docs/plan.md`'ye kopyalanır)  
 3 +Tarih: 2026-11-04 · Durum: onaylandı (2026-11-04) · Açık Sorular
+'daki varsayılanlar geçerli, aksi söylenene kadar  
 4  
 5 ## 0. Bağlam
6

● Plan docs/plan.md dosyasında. Kod yazmadım, araç da kurmadım.

Genel yapı

- Teknoloji: Laravel 12 ve PostgreSQL. Kuyruk ve rate limit için Redis
  kullanılıyor. Claude'a bir arayüz üzerinden bağlanılıyor: gerçek istemci ya
  da fake. API key yoksa uygulama kendiliğinden fake ile açılıyor. Mock
  provider ayrı bir container. Frontend Vue 3, canlı akış SSE ile.
- Fazlar: 11 faz ve yaklaşık 70 küçük görev var. Her görevin ID'si, yapılacak
  işi, dosyaları, bağımlılıkları ve kabul kriteri yazılı.
- Ağırlık: En çok iş Faz 5'te (LLM güvenilirliği) ve Faz 8'de (gönderim
  hattı). Bunlar değerlendirmede en ağır iki kalem.

Senin karar vermen gereken noktalar

- Tasarım seçenekleri: "Sen tasarla" denen kısımlar için bölüm 2'de 17 karar
  var. Her birinde seçenekler, önerim ve hangi ilkeye dayandığı yazılı.
- Kurulum fazı: Önceki projedeki tek görevlik araçlar (phase, api-endpoint,
  frontend-feature) yerine tek bir task skill'i öneriyorum, çünkü her görevi
  sen doğrulayacaksın. Hook'ları bash ve jq yerine Node ile yazmayı
  öneriyorum. Ayrıca .env.\* desenini deny listesine koymuyorum; yoksa Claude
  .env.example dosyasını yazamaz.
- Açık sorular: 15 tane var. En önemlileri:
  - SMS 160 sınırı: Türkçe karakterler mesajı UCS-2'ye düşürüyor, bu da sınırı
    fiilen 70 karaktere indiriyor.
  - "Alışveriş yapmayan"ın anlamı: Ne kastedildiği net değil.
  - Email gövdesi: Uzunluk sınırı belirtilmemiş.

  Hepsinin yanına varsayılan önerimi yazdım. Aksini söylemezsen o
  varsayılanlarla ilerleyeceğim.

Plan dosyası git'e henüz eklenmedi. Görev 1.1'de ilk commit'e girecek: main'e
ilk commit, ardından phase-1/setup dalı. "Başla" dersen oradan başlarım.

❯ Tasarım seçenekleri başlığı: Öneriler uygun

Kurulum fazı başlığı: öneriler uygun, sadece .env dosyaına doğrudan erişim
olmamalı. .env.example'a erişilebilmeli

Açık sorular bölümü:
1- Kullanıcının gördüğü karakter sayısı 160.
2- Kişiye giden metne
3- "Son 30 günde sepetine ürün eklemiş ama herhnagi bir alışveriş yapmamış".
Ayrıca bu bizim temel case'imiz değil, bir örnek unutma. Dolayısıyla veri
tabanı alanlarını çok dar tutma
4- Varsayılan
5- Varsayılan
6- Farkklı kampanyalar aynı kişiye gidebilir
7- Varsayılan
8- Sadece çıkan mesaj düzenlenebilsin gönderilmeden önce
9- Varsayılan
10- Varsayılan
11- Varsayılan
12- Varsayılan
13- Varsayılan
14- Varsayılan
15- Varsayılan

- Ek olarak temel çekirdek akışı 5 günde bitirebilmeliyim. Her aşama sonrası
  bana değişen/eklenen dosyalarda neler yaptığımızı belirtmeli özetleyerek (git
  aşamlasına başlamadan hemen önce)

Plana gerekli değişiklikleri ekle, herhangi bir commit yapma.

● Geri bildirimleri plana işliyorum; commit yok.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
1 # Smart Campaign Composer: Uygulama Planı
2  
 3 -Tarih: 2026-11-04 · Durum: onaylandı (2026-11-04) · Açık Sorular
-'daki varsayılanlar geçerli, aksi söylenene kadar  
 3 +Tarih: 2026-11-04 · Durum: onaylandı, geri bildirim işlendi (202
+6-11-04) · Açık sorular yanıtlandı (bkz. "Kararlaşan Sorular")  
 4  
 5 ## 0. Bağlam
6

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
32 | D1 | PHP framework | **Laravel 12** · Symfony
· Slim  
 | Queue, Redis throttle, migration, seede
r, Pest ve SSE hazır; Boost MCP ile önceki projedeki akış tekra
r kullanılır (KISS).

                                                           |
      33  | D2  | Veritabanı                   | **PostgreSQL** · MySQL ·
           SQLite
                                | `COUNT(*) FILTER`, `ON CONFLICT DO NOTH
          ING`, JSONB; tek sorguda kanal bazlı sayım ve idempotent snapsh
          ot.

                                                           |
      34  | D3  | Kuyruk                       | **Redis (Laravel queue)*
          * · RabbitMQ · DB queue
                                | Tek bileşen kuyruk + global rate limite
          r + kilit işini görür; RabbitMQ bu ölçekte ek operasyon yükü (Y
          AGNI).

                                                           |
      35 -| D4  | Davranış verisi              | **Kullanıcı üzerinde öze
         -t kolonlar** (`last_cart_add_at`, `last_purchase_at`, `orders_c
         -ount`, `total_spent`) · olay tabloları + `EXISTS` | Olay toplam
         -a hattı kapsam dışı; segment koşulları indeksli kolon karşılaşt
         -ırmasına iner, derleyici basit kalır (KISS). Olay tablosu geçiş
         - yolu DECISIONS'ta not edilir.
         -
         -               |
      35 +| D4  | Davranış verisi              | **Kullanıcı üzerinde öze
         +t kolonlar** (`last_cart_add_at`, `last_purchase_at`, `orders_c
         +ount`, `total_spent`) · olay tabloları + `EXISTS` | Olay toplam
         +a hattı kapsam dışı; segment koşulları indeksli kolon karşılaşt
         +ırmasına iner, derleyici basit kalır (KISS). Örnek brief yalnız
         +ca bir örnek olduğu için kolon seti geniş tutulur (demografi, a
         +lışveriş, sepet, etkileşim, sadakat; bkz. 3.1). Yeni alan = kol
         +on + registry kaydı. Olay tablosu geçiş yolu DECISIONS'ta not e
         +dilir.
         +                                                      |
      36  | D5  | Segment kural dili           | **Düz AND listesi + `in`
          /`not_in`** · iç içe AND/OR ağacı · JSON Logic
                                 | Örnek brief ve tipik istekleri karşıla
          r; doğrulama ve prompt kısa kalır (YAGNI). Ağaca genişleme kola
          y, şema versiyonlu.

                                                            |
      37  | D6  | Yapılandırılmış çıktı        | **Structured outputs (JS
          ON schema)** · zorunlu tool use (`tool_choice`) · yalnızca prom
          pt                    | Şemaya uyumu modelde garanti eder, onar
          ım turunu azaltır. Backend doğrulaması yine zorunlu (iş kuralla
          rı şemaya sığmaz). Güncel API Görev 5.2'de `claude-api` skill i
          le doğrulanır; desteklenmiyorsa zorunlu tool use.
                                                                 |
      38  | D7  | Claude istemcisi             | **Laravel HTTP client, k
          endi arayüzümüzün arkasında** · resmi PHP SDK
                                 | Bağımlılık az, `Http::fake` ile test k
          olay, SSE ayrıştırıcı küçük. Arayüz sayesinde SDK'ya geçiş tek
          sınıf (DIP). SDK durumu 5.2'de kontrol edilir.

                                                            |

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
44 | D13 | Mock provider | **Ayrı container, sade P
HP + Redis** · ana uygulamada route · başka dil  
 | Gerçek ağ hataları ve timeout'lar oluş
ur, ana uygulamadan bağımsız sayaçlar tekrarı kanıtlar (SRP).

                                                            |
      45  | D14 | Web sunucusu                 | **FrankenPHP (klasik mod
          , Octane yok)** · nginx + php-fpm · `artisan serve`
                                  | Tek container, stream'de tampon ayarı
           gerekmez. Octane worker modunun state sızıntısı riskine girilm
          ez (KISS).

                                                            |
      46  | D15 | Test ortamı                  | **Integration testler do
          cker'daki Postgres/Redis'e karşı (ayrı test DB)** · SQLite in-m
          emory                  | `FILTER`, `ON CONFLICT`, throttle gerç
          ek bileşende test edilir. Unit testler bağımlılıksız ve hızlı.


                                                            |
      47 -| D16 | Kişiselleştirme uzunluğu     | **Kitlenin gerçek maks.
         -değişken uzunluğuyla en kötü durum** · şablon uzunluğu · sabit
         -bütçe                  | 160 limiti alıcıya giden metinde garan
         -ti edilir; tek `MAX(char_length())` sorgusu. Gönderimde yeniden
         - kontrol edilir (Defensive).
         -
         -                                                |
      47 +| D16 | Kişiselleştirme uzunluğu     | **Kitlenin gerçek maks.
         +değişken uzunluğuyla en kötü durum** · şablon uzunluğu · sabit
         +bütçe                  | 160 limiti alıcıya giden metinde, kull
         +anıcının gördüğü karakter sayısıyla (grapheme: emoji ve birleşi
         +k harf 1 sayılır; backend `grapheme_strlen`, frontend `Intl.Seg
         +menter`) garanti edilir; tek `MAX(char_length())` sorgusu. Gönd
         +erimde yeniden kontrol edilir (Defensive).
         +
         +                                                              |
      48  | D17 | Claude araç hook'ları        | **Node `.mjs`** · bash +
           `jq`
                                 | Windows/Git Bash farkı ve `jq` bağımlı
          lığı yok, JSON doğal, `node:test` ile test edilir.


                                                           |
      49
      50  ## 3. Çalışma akışı

● Şimdi çalışma akışı, takvim ve özet kuralı:

● Update(docs\plan.md)
⎿  Added 3 lines, removed 2 lines
50 ## 3. Çalışma akışı
51  
 52 - **Dal:** faz başına bir dal (`phase-N/<ad>`). `main`'e commit
yok (hook engeller).
53 -- **Görev döngüsü:** `/task N.M` → Claude: önce ilgili rule ve
-dokümanlar (Docs first) → uygulama → test → lint → rapor (değiş
-en dosyalar, kabul kriteri kanıtı: komut + çıktı, sorulan doküm
-an sorguları). Sen doğrularsın, "onay" dersen Claude commit ata
-r ve `plan.md`'deki kutuyu işaretler.  
 54 -- **Faz sonu:** `/finish-phase` → `make test` + `make lint`, `r
         -eview-pr`, `/code-review` (genelde `medium`; Faz 5 ve 8 için `h
         -igh`), en fazla 2 tur, reddedilen bulgu listesi → özet → onayla - push (`ask`) ve PR. Merge sende.  
 53 +- **Görev döngüsü:** `/task N.M` → Claude: önce ilgili rule ve
+dokümanlar (Docs first) → uygulama → test → lint → rapor. Sen d
+oğrularsın, "onay" dersen Claude commit atar ve `plan.md`'deki
+kutuyu işaretler.  
 54 +- **Git öncesi özet (zorunlu):** Her görevin raporu ve her fazı
+n kapanışı, herhangi bir git komutundan (`add`, `commit`, `push
         +`, PR) hemen önce, değişen/eklenen her dosya için bir özet tabl
+osu içerir: `dosya | yeni/değişti | ne yapıldı | neden`. Ardınd
+an kabul kriteri kanıtı (komut + çıktı) ve doküman sorguları ge
+lir. Faz kapanışındaki özet fazın tüm dosyalarını kapsar.  
 55 +- **Faz sonu:** `/finish-phase` → `make test` + `make lint`, `r
         +eview-pr`, `/code-review` (genelde `medium`; Faz 5 ve 8 için `h
         +igh`), en fazla 2 tur, reddedilen bulgu listesi → dosya bazlı f
+az özeti → onayla push (`ask`) ve PR. Merge sende.  
 56 - **Kararlar:** Görevde verilen her önemli karar aynı commit'te
`DECISIONS.md`'ye girer.
57 - **Session export:** Her oturum sonunda `/export` → `ai-sessio
          ns/YYYY-MM-DD-<konu>.md`; commit'ten önce `node scripts/scrub-s
          essions.mjs --check`.
58 - **Takvim (yaklaşık):** G1 Faz 1–2 · G2 Faz 3–4 · G3 Faz 5 · G
4 Faz 6–7 · G5 Faz 8–9 · G6 Faz 10 · G7 Faz 11 ve tampon.

● Update(docs\plan.md)
⎿  Added 13 lines, removed 1 line
55 - **Faz sonu:** `/finish-phase` → `make test` + `make lint`, `r
          eview-pr`, `/code-review` (genelde `medium`; Faz 5 ve 8 için `h
          igh`), en fazla 2 tur, reddedilen bulgu listesi → dosya bazlı f
az özeti → onayla push (`ask`) ve PR. Merge sende.
56 - **Kararlar:** Görevde verilen her önemli karar aynı commit'te
`DECISIONS.md`'ye girer.
57 - **Session export:** Her oturum sonunda `/export` → `ai-sessio
          ns/YYYY-MM-DD-<konu>.md`; commit'ten önce `node scripts/scrub-s
          essions.mjs --check`.
58 -- **Takvim (yaklaşık):** G1 Faz 1–2 · G2 Faz 3–4 · G3 Faz 5 · G
-4 Faz 6–7 · G5 Faz 8–9 · G6 Faz 10 · G7 Faz 11 ve tampon.  
 58 +- **Takvim (çekirdek akış 5 günde):**  
 59  
 60 + | Gün | Fazlar | Gün sonu ö
+lçütü  
 + |  
 61 + | --- | ---------------------------------------- | ----------
+---------------------------------------------------------------
+------ |  
 62 + | G1 | Faz 1, Faz 2 | `docker co
         +mpose up` sağlıklı, `make test` yeşil, Claude araçları çalışıyo
+r |  
 63 + | G2 | Faz 3, Faz 4, Faz 5.1–5.4 | Seed ve se
+gment derleyicisi çalışıyor, LLM istemcisi testli  
 + |  
 64 + | G3 | Faz 5.5–5.11, Faz 6, Faz 7 | Brief → ku
+ral → sayılar → stream içerik API'den çalışıyor; mock provider
+ayakta |  
 65 + | G4 | Faz 8, Faz 9 | `make smok
         +e` yeşil, stats/health/metrics hazır  
 + |  
 66 + | G5 | Faz 10 | Çekirdek a
+kış tarayıcıda uçtan uca, `make e2e` yeşil  
 + |  
 67 + | G6 | Faz 11 | Teslim pak
+eti  
 + |  
 68 + | G7 | Tampon, İsteğe Bağlı Kısımlar (CI önce) |  
 +  
 + |  
 69 +  
 70 +- **Gerideysek önce kesilecekler** (çekirdek akışı bozmayanlar, + README'de "yapılmadı" olarak yazılır): 5.12 `llm:eval` (altın
+brief testleri fake ile unit testte kalır), 9.5 JSON log, 2.1'd
+e Larastan, 10.8 Playwright (yerine `make smoke` + elle tarayıc
+ı kontrolü), 1.13 deneme koşusu (Faz 2'nin ilk görevinde yapılı
+r).  
 71 +  
 72 Görev formatı: **İş** · **Dosyalar** · **Bağımlı** · **Kabul**
(senin doğrulama yöntemin).
73  
 74 ---

● Update(docs\plan.md)
⎿  Added 2 lines, removed 1 line
107 - `defaultMode: acceptEdits`.
108 - `allow`: `make test*|lint*|up|ps|logs*|fresh|smoke|e2e`; `
           docker compose ps|logs|build|up -d|exec|run --rm`; `php artisa
           n test|route:list|migrate:status|config:show|llm:eval`; `vendo
           r/bin/pest|pint|phpstan`; `npm run lint|test|type-check|build`
; `npx playwright test`; `node scripts/<tam ad>.mjs` (joker yo
k); `git status|diff|log|branch|show|add|commit|switch|checkou
           t -b`; `gh pr create|view|list`; `ls`; `curl http://localhost:
           *`; context7 ve boost araçları.
109 - `ask`: `git push`, `composer require`, `npm install <paket
           >` (yeni bağımlılık), `docker compose down -v`.
110 - - `deny`: `.env`, `backend/.env`, `frontend/.env`, `mock-pro
          -vider/.env` için Read/Edit/Write (`.env.*` joker yok, `.env.ex
          -ample` yazılabilsin); `**/*.pem`, `**/*credentials*` okuma; `g
          -it clean`, `git reset --hard`, `docker system prune`.  
 110 + - `deny`: her dizinde gerçek env dosyaları için Read/Edit/Wr
+ite: `**/.env`, `**/.env.local`, `**/.env.testing`, `**/.env.p
          +roduction`, `**/.env.*.local`. `.env.*` jokeri kullanılmaz, çü
+nkü deny allow'u ezer ve `.env.example` da engellenirdi; `.env
          +.example` serbestçe okunur ve yazılır. Ayrıca `**/*.pem`, `**/
          +*credentials*` okuma; `git clean`, `git reset --hard`, `docker
          + system prune`.  
 111 + - Container içinden erişim de kapalı: `docker compose exec …
          + cat .env`, `printenv`, `env` gibi komutlar guard hook'ta enge
+llenir (1.3).  
 112 - Hook kaydı, `enabledPlugins: security-guidance`.
113 - **Dosyalar:** `.claude/settings.json`
114 - **Bağımlı:** 1.1

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
112 - Hook kaydı, `enabledPlugins: security-guidance`.
113 - **Dosyalar:** `.claude/settings.json`
114 - **Bağımlı:** 1.1
115 -- **Kabul:** `/permissions` listesi beklenenle aynı; Claude'un - `.env` okuma denemesi reddedilir; `.env.example` oluşturma so
-rusuz geçer; `git status` sorusuz çalışır.  
 115 +- **Kabul:** `/permissions` listesi beklenenle aynı; Claude'un + `.env` ve `backend/.env` için Read, Edit ve Write denemeleri
+reddedilir; `.env.example` okuma ve düzenleme sorusuz geçer; `
          +git status` sorusuz çalışır.  
 116  
 117 ### 1.3 Guard hook ve test script'i
118 - **İş:** `guard.mjs` (Bash ve PowerShell). Engeller: özyinele
meli silme; `main`'e veya `main`'deyken push, force push, `mai
           n`'de commit; shell üzerinden `.env` erişimi (`cat`, `type`, `
           Get-Content`, `cp` vb.; "aracı değil etkiyi engelle"); `docker
            compose down -v`, `docker volume rm`, `docker system prune`;
staged diff'te `sk-ant-` deseni varken `git commit`. Riskli ko
mutta ayrıştıramadığı yapıda (`$(`, backtick, satır devamı, tı
rnak) fail-closed. Her engelde `.claude/logs/hooks.log`'a satı
r yazar.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
115 - **Kabul:** `/permissions` listesi beklenenle aynı; Claude'un
`.env` ve `backend/.env` için Read, Edit ve Write denemeleri
reddedilir; `.env.example` okuma ve düzenleme sorusuz geçer; `
           git status` sorusuz çalışır.
116  
 117 ### 1.3 Guard hook ve test script'i
118 -- **İş:** `guard.mjs` (Bash ve PowerShell). Engeller: özyinele
-meli silme; `main`'e veya `main`'deyken push, force push, `mai
          -n`'de commit; shell üzerinden `.env` erişimi (`cat`, `type`, `
          -Get-Content`, `cp` vb.; "aracı değil etkiyi engelle"); `docker
          - compose down -v`, `docker volume rm`, `docker system prune`;
-staged diff'te `sk-ant-` deseni varken `git commit`. Riskli ko
-mutta ayrıştıramadığı yapıda (`$(`, backtick, satır devamı, tı
-rnak) fail-closed. Her engelde `.claude/logs/hooks.log`'a satı
-r yazar.  
 118 +- **İş:** `guard.mjs` (Bash ve PowerShell). Engeller: özyinele
+meli silme; `main`'e veya `main`'deyken push, force push, `mai
          +n`'de commit; shell veya container üzerinden gerçek env dosyal
+arına erişim (`cat`, `type`, `Get-Content`, `cp`, `grep`, `sou
          +rce`, `docker compose exec … cat .env`, container'da `env`/`pr
          +intenv`; "aracı değil etkiyi engelle"), yalnızca `.env.example
          +` serbest; `docker compose down -v`, `docker volume rm`, `dock
          +er system prune`; staged diff'te `sk-ant-` deseni varken `git 
          +commit`. Riskli komutta ayrıştıramadığı yapıda (`$(`, backtick
+, satır devamı, tırnak) fail-closed. Her engelde `.claude/logs
          +/hooks.log`'a satır yazar.  
 119 - **Dosyalar:** `.claude/hooks/guard.mjs`, `scripts/test-guard
           .mjs`
120 - **Bağımlı:** 1.2
121 - **Kabul:** `node scripts/test-guard.mjs` tümü PASS (≥30 vaka
, engellenecek ve geçecek, iki shell); canlı deneme: `git push
            origin main` engellenir, log'da satır görünür. (Classifier se
lf-modification diye reddederse Claude test edilmiş dosyayı ve
rir, sen koyarsın.)

● Update(docs\plan.md) ⎿  Added 1 line, removed 1 line
118 - **İş:** `guard.mjs` (Bash ve PowerShell). Engeller: özyinele
meli silme; `main`'e veya `main`'deyken push, force push, `mai
           n`'de commit; shell veya container üzerinden gerçek env dosyal
arına erişim (`cat`, `type`, `Get-Content`, `cp`, `grep`, `sou
           rce`, `docker compose exec … cat .env`, container'da `env`/`pr
           intenv`; "aracı değil etkiyi engelle"), yalnızca `.env.example
           ` serbest; `docker compose down -v`, `docker volume rm`, `dock
           er system prune`; staged diff'te `sk-ant-` deseni varken `git 
           commit`. Riskli komutta ayrıştıramadığı yapıda (`$(`, backtick
, satır devamı, tırnak) fail-closed. Her engelde `.claude/logs
           /hooks.log`'a satır yazar.
119 - **Dosyalar:** `.claude/hooks/guard.mjs`, `scripts/test-guard
           .mjs`
120 - **Bağımlı:** 1.2
121 -- **Kabul:** `node scripts/test-guard.mjs` tümü PASS (≥30 vaka
-, engellenecek ve geçecek, iki shell); canlı deneme: `git push
          - origin main` engellenir, log'da satır görünür. (Classifier se
-lf-modification diye reddederse Claude test edilmiş dosyayı ve
-rir, sen koyarsın.)  
 121 +- **Kabul:** `node scripts/test-guard.mjs` tümü PASS (≥30 vaka
+, engellenecek ve geçecek, iki shell; `cat .env` ve `cat backe
          +nd/.env` engellenir, `cat .env.example` geçer); canlı deneme: +`git push origin main` engellenir, log'da satır görünür. (Clas
+sifier self-modification diye reddederse Claude test edilmiş d
+osyayı verir, sen koyarsın.)  
 122  
 123 ### 1.4 Format hook'u
124 - **İş:** `format.mjs`: `.php` → Pint, `.vue/.ts/.js/.css/.jso
           n/.md` → Prettier. Araç henüz yoksa sessizce atlar, hiç engell
emez, log satırı yazar.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
127 - **Kabul:** Bozuk girintili bir `.md` yazılınca düzelir; `hoo
           ks.log`'da satır var.
128  
 129 ### 1.5 `CLAUDE.md`
130 -- **İş:** Stack; komutlar; dev ortamı (host'ta PHP 8.4, Compos
-er, Node, Docker; Windows'ta `make`); mimari değişmezler (LLM
-çıktısı her zaman doğrulanır ve asla SQL olmaz; izin kontrolü
-hem kitlede hem gönderimde; çift gönderim katmanları; limitler - tek kaynaktan); çalışma kuralları (`/task`, docs-first, tek g
-örev, onaysız commit yok, reddedilen araçta dur ve raporla, ba
-şka araca geçme, karar → `DECISIONS.md`, session export).  
 130 +- **İş:** Stack; komutlar; dev ortamı (host'ta PHP 8.4, Compos
+er, Node, Docker; Windows'ta `make`); mimari değişmezler (LLM
+çıktısı her zaman doğrulanır ve asla SQL olmaz; izin kontrolü
+hem kitlede hem gönderimde; çift gönderim katmanları; limitler + tek kaynaktan; `.env`'e doğrudan erişim yok, yalnızca `.env.e
          +xample`); çalışma kuralları (`/task`, docs-first, tek görev, h
+er git adımından önce dosya bazlı özet, onaysız commit yok, re
+ddedilen araçta dur ve raporla, başka araca geçme, karar → `DE
          +CISIONS.md`, session export).  
 131 - **Dosyalar:** `CLAUDE.md`
132 - **Bağımlı:** 1.1
133 - **Kabul:** `/context`'te ≤ ~600 token; içerik okunarak onayl
anır.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
139 - **Kabul:** Her dosyada doğru `paths:`; toplam ~1.5K token; i
çerik okunarak onaylanır.
140  
 141 ### 1.7 `task` skill'i
142 -- **İş:** Argüman görev ID'si. Adımlar: 0) `plan.md`'den görev
-i ve bağımlılıkları oku, ilgili rule dosyalarını önceden oku;
-1) Docs first: dokunulan her kütüphane için bir context7/Boost - sorgusu, LLM görevlerinde `claude-api` skill; ya da gerekçe;
-2) en küçük uygulama; 3) testler (`test-runner`); 4) lint/type
--check ana oturumda; 5) gerekiyorsa `DECISIONS.md`; 6) rapor:
-dosyalar, kabul kriteri kanıtı, doküman sorguları, açık konula
-r; commit'ten önce dur.  
 142 +- **İş:** Argüman görev ID'si. Adımlar: 0) `plan.md`'den görev
+i ve bağımlılıkları oku, ilgili rule dosyalarını önceden oku;
+1) Docs first: dokunulan her kütüphane için bir context7/Boost + sorgusu, LLM görevlerinde `claude-api` skill; ya da gerekçe;
+2) en küçük uygulama; 3) testler (`test-runner`); 4) lint/type
+-check ana oturumda; 5) gerekiyorsa `DECISIONS.md`; 6) rapor:
+dosya bazlı özet tablosu (`dosya | yeni/değişti | ne yapıldı |
          + neden`), kabul kriteri kanıtı, doküman sorguları, açık konula
+r; hiçbir git komutu çalıştırmadan dur.  
 143 - **Dosyalar:** `.claude/skills/task/SKILL.md`
144 - **Bağımlı:** 1.5, 1.6
145 - **Kabul:** `/task 1.13` deneme koşusunda tüm adımlar raporda
görünür.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
145 - **Kabul:** `/task 1.13` deneme koşusunda tüm adımlar raporda
görünür.
146  
 147 ### 1.8 `review-pr` ve `finish-phase` skill'leri
148 -- **İş:** `review-pr`: salt-okur (`Write`/`Edit` yasak); `git 
          -diff main...HEAD` + `git diff HEAD` + izlenmeyen dosyalar; kon
-trol listesi: proje değişmezleri, sır yok, nötr fixture, test
-var mı. `finish-phase`: test + lint → `review-pr` → `/code-rev
          -iew` (seviye fazdan) → yalnızca bug/güvenlik/kural ihlali düze
-ltilir, en fazla 2 tur, reddedilenler listesi → özet (dosyalar
-, kontroller, düzeltmeler, PR başlığı) → onayla push + `gh pr 
          -create`.  
 148 +- **İş:** `review-pr`: salt-okur (`Write`/`Edit` yasak); `git 
          +diff main...HEAD` + `git diff HEAD` + izlenmeyen dosyalar; kon
+trol listesi: proje değişmezleri, sır yok, nötr fixture, test
+var mı. `finish-phase`: test + lint → `review-pr` → `/code-rev
          +iew` (seviye fazdan) → yalnızca bug/güvenlik/kural ihlali düze
+ltilir, en fazla 2 tur, reddedilenler listesi → fazın tüm dosy
+aları için dosya bazlı özet tablosu, kontroller, düzeltmeler,
+PR başlığı → onayla push + `gh pr create`.  
 149 - **Dosyalar:** `.claude/skills/review-pr/SKILL.md`, `.claude/
           skills/finish-phase/SKILL.md`
150 - **Bağımlı:** 1.7
151 - **Kabul:** `review-pr` commit'lenmemiş değişiklikte boş diff
vermez (1.13'te denenir).

● Şimdi veri modeli, segment alanları ve karakter sayımı:

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
203 - **Kabul:** Key boşken `php artisan config:show llm` → driver
`fake`; unit test aynı şeyi doğrular.
204  
 205 ### 2.4 Backend imajı
206 -- **İş:** FrankenPHP + PHP 8.4 (pgsql, redis, pcntl). Entrypoi
-nt: DB'yi bekle → `migrate --force` → tablo boşsa seed. Aynı i
-maj worker olarak da çalışır.  
 206 +- **İş:** FrankenPHP + PHP 8.4 (pgsql, redis, pcntl, intl: `gr
          +apheme_strlen` için). Entrypoint: DB'yi bekle → `migrate --for
          +ce` → tablo boşsa seed. Aynı imaj worker olarak da çalışır.  
 207 - **Dosyalar:** `backend/Dockerfile`, `docker/backend/entrypoi
           nt.sh`
208 - **Bağımlı:** 2.1
209 - **Kabul:** `docker compose build app` başarılı.

● Update(docs\plan.md)
⎿  Added 9 lines, removed 1 line
249 ## Faz 3: Veri modeli ve seed
250  
 251 ### 3.1 `customers` tablosu ve modeli
252 -- **İş:** Alanlar: `first_name` (nullable, fallback testi için
-), `last_name`, `email`, `phone` (E.164), `city`, `language` ( -`tr`/`en`), `birth_date`, `last_cart_add_at`, `last_purchase_a
          -t`, `orders_count`, `total_spent`, `sms_opt_in`, `email_opt_in
          -`. İndeksler: `city`, `last_cart_add_at`, `last_purchase_at`.
-Model: `canReceive(Channel)`. Factory.  
 252 +- **İş:** Örnek brief yalnızca bir örnek; alanlar farklı brief + türlerini karşılayacak genişlikte:  
 253 + - **Kimlik ve iletişim:** `first_name` (nullable, fallback t
+esti için), `last_name`, `email`, `phone` (E.164).  
 254 + - **Demografi:** `city`, `gender`, `birth_date` (yaş türetil
+ir), `language` (`tr`/`en`).  
 255 + - **Hesap ve etkileşim:** `registered_at`, `last_login_at`, +`platform` (`ios`/`android`/`web`), `loyalty_tier` (`bronze`/`
          +silver`/`gold`/`platinum`).  
 256 + - **Alışveriş:** `orders_count`, `total_spent`, `avg_order_v
          +alue`, `first_purchase_at`, `last_purchase_at`, `favorite_cate
          +gory` (elektronik, moda, ev, kozmetik, spor, kitap, market…).
257 + - **Sepet:** `last_cart_add_at`, `cart_items_count`, `cart_v
          +alue`.  
 258 + - **İzinler:** `sms_opt_in`, `email_opt_in`.  
 259 +  
 260 + İndeksler: segmentte sık kullanılanlar (`city`, tarih kolonl
+arı, `loyalty_tier`, `favorite_category`). Model: `canReceive(
          +Channel)`, `age()`. Factory.  
 261 - **Dosyalar:** migration, `app/Models/Customer.php`, `app/Enu
           ms/Channel.php`, factory, unit test
262 - **Bağımlı:** 2.9
263 - **Kabul:** `canReceive` unit testleri (izin yok / iletişim b
ilgisi yok / izinli) yeşil.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
263 - **Kabul:** `canReceive` unit testleri (izin yok / iletişim b
ilgisi yok / izinli) yeşil.
264  
 265 ### 3.2 Seeder
266 -- **İş:** 5.000 kullanıcı, Faker `tr_TR`, sabit seed (determin
-istik). Dağılımlar: ağırlıklı şehirler (İstanbul ~%30); son 30 - günde sepete ekleyen ~%25, bunların yarısı satın almamış; SMS - izni ~%70, email izni ~%80; ~%3 boş `first_name`; ~%10 `en`.
-Toplu insert (1.000'lik chunk).  
 266 +- **İş:** 5.000 kullanıcı, Faker `tr_TR`, sabit seed (determin
+istik). Dağılımlar her alan için anlamlı ve birbiriyle tutarlı + (ör. `first_purchase_at ≤ last_purchase_at`, `orders_count = 
          +0` ise satın alma tarihleri boş, `avg_order_value = total_spen
          +t / orders_count`): ağırlıklı ~15 şehir (İstanbul ~%30); hiç a
+lışveriş yapmamış ~%15; son 30 günde sepete ekleyip son 30 gün
+de satın almamış ~%12; sadakat seviyeleri piramit; SMS izni ~%
+70, email izni ~%80; ~%3 boş `first_name`; ~%10 `en`. Toplu in
+sert (1.000'lik chunk).  
 267 - **Dosyalar:** `database/seeders/CustomerSeeder.php`, `Databa
           seSeeder.php`
268 - **Bağımlı:** 3.1
269 - **Kabul:** `make fresh` iki kez → `SELECT count(*), md5(stri
           ng_agg(email, ',' ORDER BY id))` aynı; dağılım sorgusu hedefle
re ±%3 yakın.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
291 ## Faz 4: Segment kuralı (LLM'siz)
292  
 293 ### 4.1 `FieldRegistry`
294 -- **İş:** Alan → kolon, tip, izinli operatörler, değer kısıtla
-rı, Türkçe etiket. Tipler: `enum` (`city`, `language`; şehir l
-istesi DB'den, cache'li), `number` (`orders_count`, `total_spe
          -nt`, `age`), `date` (`last_cart_add_at`, `last_purchase_at`).
-Operatörler: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt`, `
          -lte`, `between`, `within_last_days`, `not_within_last_days` (n
-ull dahil), `is_null`, `not_null`. Doğrulayıcı, derleyici, pro
-mpt ve JSON şeması bu tek kaynaktan beslenir (DRY). Hash map,
-arama O(1).  
 294 +- **İş:** Alan → kolon, tip, izinli operatörler, değer kısıtla
+rı, Türkçe etiket. 3.1'deki segmentlenebilir tüm alanlar kayıt
+lıdır (iletişim bilgileri ve isim hariç). Tipler: `enum` (`cit
          +y`, `gender`, `language`, `platform`, `loyalty_tier`, `favorit
          +e_category`; şehir listesi DB'den, cache'li), `number` (`age`, + `orders_count`, `total_spent`, `avg_order_value`, `cart_items
          +_count`, `cart_value`), `date` (`registered_at`, `last_login_a
          +t`, `first_purchase_at`, `last_purchase_at`, `last_cart_add_at
          +`). Operatörler: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt
          +`, `lte`, `between`, `within_last_days`, `not_within_last_days
          +` (null dahil), `is_null`, `not_null`. Doğrulayıcı, derleyici, + prompt ve JSON şeması bu tek kaynaktan beslenir (DRY). Hash m
+ap, arama O(1).  
 295 - **Dosyalar:** `app/Segment/FieldRegistry.php`, `FieldDefinit
           ion.php`, `Operator.php`, unit test
296 - **Bağımlı:** 3.2
297 - **Kabul:** Unit: her tip için izinli/yasak operatör tablosu.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
306 - **İş:** `SegmentRule` → `customers` üzerinde Builder; kolon
adı yalnızca registry'den, değerler binding ile; tarih koşulla
rı `now()`'a göre (testte `travelTo`). Kanal izin filtresi ayr
ı metod.
307 - **Dosyalar:** `app/Segment/SegmentQueryCompiler.php`, integr
ation test
308 - **Bağımlı:** 4.2
309 -- **Kabul:** Integration: el ile kurulmuş 10 kişilik fixture'd
-a örnek brief kuralı beklenen ID kümesini döner; `toSql()` çık
-tısında kural değerleri yok, yalnızca `?`.  
 309 +- **Kabul:** Integration: el ile kurulmuş küçük fixture'da örn
+ek brief kuralı (`city = İstanbul` ∧ `last_cart_add_at within_
          +last_days 30` ∧ `last_purchase_at not_within_last_days 30`) ve + her tipten en az bir başka kural (ör. `loyalty_tier in [gold,
          + platinum]` ∧ `age between 25–40`) beklenen ID kümesini döner; + `toSql()` çıktısında kural değerleri yok, yalnızca `?`.  
 310  
 311 ### 4.4 `AudienceService`
312 - **İş:** Kural → kanal başına sayılar (`COUNT(*) FILTER`, tek
sorgu, tek tarama) + toplam benzersiz + 5 örnek kişi + kanal
başına en uzun `first_name`/`city` (D16 için).

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
343 - **Kabul:** Integration: başarılı ve hatalı çağrı birer satır
; gecikme > 0.
344  
 345 ### 5.5 Segment prompt'u v1
346 -- **İş:** Rol; registry'den üretilen alan/operatör tablosu; şe
-hir listesi; bugünün tarihi; kurallar: yalnızca şemaya uygun J
-SON, eşleştiremediğin ifadeyi uydurma, `unsupported_criteria`'
-ya yaz (ör. "iPhone kullananlar"), her yorum için kısa `explan
          -ation`; 3 örnek. JSON şeması registry'den üretilir. Sabit syst
-em kısmı prompt cache'e uygun tutulur.  
 346 +- **İş:** Rol; registry'den üretilen alan/operatör tablosu; şe
+hir listesi; bugünün tarihi; kurallar: yalnızca şemaya uygun J
+SON, eşleştiremediğin ifadeyi uydurma, `unsupported_criteria`'
+ya yaz (ör. "iPhone kullananlar"), her yorum için kısa `explan
          +ation`; farklı alan türlerini kapsayan 3 örnek (örnek brief'e
+aşırı uyum olmasın diye yalnızca biri sepet terk senaryosu). J
+SON şeması registry'den üretilir. Sabit system kısmı prompt ca
+che'e uygun tutulur.  
 347 - **Dosyalar:** `app/Llm/Prompts/SegmentPrompt.php`, `resource
           s/prompts/segment.v1.md`, snapshot test
348 - **Bağımlı:** 4.1, 5.1
349 - **Kabul:** Snapshot testi; şema test'te JSON Schema olarak g
eçerli.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
355 - **Kabul:** Fake ile vakalar: geçerli; geçersiz → onarım → ge
çerli (2. istekte hata listesi var); 3× geçersiz → anlamlı hat
a; JSON olmayan çıktı; kesilmiş çıktı; API düşük. Hepsi yeşil.
356  
 357 ### 5.7 Kişiselleştirme
358 -- **İş:** Değişken registry'si (`{{first_name}}`, `{{last_name
          -}}`, `{{city}}`, her biri fallback'li, ör. "Değerli müşterimiz
-"). `PersonalizationRenderer`: tek geçişte `preg_replace_callb
          -ack`, O(n); bilinmeyen değişkenleri bulur; `worstCaseLength(te
          -mplate, maxLengths)` hesaplar. `mb_*` ile Türkçe güvenli.  
 358 +- **İş:** Değişken registry'si (`{{first_name}}`, `{{last_name
          +}}`, `{{city}}`, her biri fallback'li, ör. "Değerli müşterimiz
+"). `PersonalizationRenderer`: tek geçişte `preg_replace_callb
          +ack`, O(n); bilinmeyen değişkenleri bulur; `worstCaseLength(te
          +mplate, maxLengths)` hesaplar. Uzunluk, kullanıcının gördüğü k
+arakter sayısıdır (`grapheme_strlen`: Türkçe harf, emoji, birl
+eşik karakter 1 sayılır).  
 359 - **Dosyalar:** `app/Content/Variables.php`, `PersonalizationR
           enderer.php`, unit test
360 - **Bağımlı:** 3.1
361 - **Kabul:** Unit: boş `first_name` → fallback; `{{unknown}}`
tespit; `ş`, `ğ` içeren metinde uzunluk karakter bazında doğru
.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
358 - **İş:** Değişken registry'si (`{{first_name}}`, `{{last_name
           }}`, `{{city}}`, her biri fallback'li, ör. "Değerli müşterimiz
"). `PersonalizationRenderer`: tek geçişte `preg_replace_callb
           ack`, O(n); bilinmeyen değişkenleri bulur; `worstCaseLength(te
           mplate, maxLengths)` hesaplar. Uzunluk, kullanıcının gördüğü k
arakter sayısıdır (`grapheme_strlen`: Türkçe harf, emoji, birl
eşik karakter 1 sayılır).
359 - **Dosyalar:** `app/Content/Variables.php`, `PersonalizationR
           enderer.php`, unit test
360 - **Bağımlı:** 3.1
361 -- **Kabul:** Unit: boş `first_name` → fallback; `{{unknown}}`
-tespit; `ş`, `ğ` içeren metinde uzunluk karakter bazında doğru
-.  
 361 +- **Kabul:** Unit: boş `first_name` → fallback; `{{unknown}}`
+tespit; `ş`, `ğ`, emoji (👍, 👨‍👩‍👧) içeren metinde uzunluk görüne
+n karakter sayısıyla aynı.  
 362  
 363 ### 5.8 `ContentValidator`
364 - **İş:** SMS ≤160 (D16'ya göre en kötü durum render uzunluğu)
, konu ≤80, email gövdesi ≤2.000, boş değil, yalnızca bilinen
değişkenler, bozuk `{{`. Limitler `config/delivery.php`'den. L
LM çıktısı ve insan düzenlemesi aynı doğrulayıcıdan geçer (DRY
). Yol bazlı hatalar.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
364 - **İş:** SMS ≤160 (D16'ya göre en kötü durum render uzunluğu)
, konu ≤80, email gövdesi ≤2.000, boş değil, yalnızca bilinen
değişkenler, bozuk `{{`. Limitler `config/delivery.php`'den. L
LM çıktısı ve insan düzenlemesi aynı doğrulayıcıdan geçer (DRY
). Yol bazlı hatalar.
365 - **Dosyalar:** `app/Content/MessageContent.php`, `ContentVali
           dator.php`, unit test
366 - **Bağımlı:** 5.7
367 -- **Kabul:** Unit: 160/161 ve 80/81 sınırları; değişken uzunlu
-ğu limiti aşırınca hata; çok baytlı karakterler.  
 367 +- **Kabul:** Unit: 160/161 ve 80/81 sınırları (görünen karakte
+rle); değişken uzunluğu limiti aşırınca hata; Türkçe harf ve e
+moji içeren sınır vakaları.  
 368  
 369 ### 5.9 İçerik prompt'u v1
370 - **İş:** Brief, ton, kitle özeti (sayılar ve kural açıklaması
), kanal limitleri, kullanılabilir değişkenler, dil (Türkçe).
Şema: `{sms:{body}, email:{subject, body}}`.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
379 - **Kabul:** Fake ile: delta'lar sırayla; 161 karakterlik SMS
→ onarım → geçerli `final`; iki onarım da başarısız → `error`
ve hata listesi.
380  
 381 ### 5.11 Geliştirme fake'i
382 -- **İş:** Key yokken çalışan hazır yanıtlar: örnek brief için
-kural ve içerik, diğer brief'ler için genel bir yanıt; 20–40 m
-s aralıklı stream.  
 382 +- **İş:** Key yokken çalışan hazır yanıtlar: birkaç farklı sen
+aryo (sepet terk, sadakat seviyesi, kategori, uzun süredir gir
+iş yapmayan) anahtar kelimeyle seçilir, eşleşmeyen brief'lere
+genel bir yanıt; 20–40 ms aralıklı stream.  
 383 - **Dosyalar:** `app/Llm/Fake/DevScenarios.php`, `resources/ll
           m-fixtures/*.json`
384 - **Bağımlı:** 5.6, 5.10
385 - **Kabul:** Key'siz `php artisan llm:eval --only=example` (5. 12) geçer; stream gecikmesi gözle görülür.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
385 - **Kabul:** Key'siz `php artisan llm:eval --only=example` (5. 12) geçer; stream gecikmesi gözle görülür.
386  
 387 ### 5.12 `llm:eval`
388 -- **İş:** 8–10 altın brief (beklenen alan/operatör/değer iddia
-ları ve içerik limitleri). Seçili sürücüyle çalışır; geçerlili
-k oranı, ortalama deneme ve gecikme raporlar. Fake ile CI'da,
-gerçek key ile istersen elle.  
 388 +- **İş:** 8–10 altın brief; registry'deki alan türlerinin heps
+ini kapsar, en az biri desteklenmeyen kriter içerir (beklenen
+alan/operatör/değer iddiaları, `unsupported_criteria`, içerik
+limitleri). Seçili sürücüyle çalışır; geçerlilik oranı, ortala
+ma deneme ve gecikme raporlar. Fake ile CI'da, gerçek key ile
+istersen elle.  
 389 - **Dosyalar:** `app/Console/Commands/LlmEval.php`, `resources
           /llm-eval/briefs.json`, test
390 - **Bağımlı:** 5.11
391 - **Kabul:** Fake ile %100; key verirsen gerçek model raporu R
EADME'ye eklenir.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
401 - **Kabul:** Feature: kısa brief → 422 ve alan hatası; oluştur
ma → 201; liste sayfalı.
402  
 403 ### 6.2 Kitle çıkarma
404 -- **İş:** `POST /api/campaigns/{id}/audience` → `SegmentExtrac
          -tor` + `AudienceService`; kural, desteklenmeyen kriterler ve s
-ayılar saklanır; `audience_ready`. Onaya kadar tekrar çalıştır
-ılabilir. LLM başarısız → 422 (hata listesi), LLM erişilemez → - 503.  
 404 +- **İş:** `POST /api/campaigns/{id}/audience` → `SegmentExtrac
          +tor` + `AudienceService`; kural, desteklenmeyen kriterler ve s
+ayılar saklanır; `audience_ready`. Kural elle düzenlenemez (bu
+nun için endpoint yok); pazarlamacı brief'i değiştirip onaya k
+adar yeniden çalıştırabilir. LLM başarısız → 422 (hata listesi
+), LLM erişilemez → 503.  
 405 - **Dosyalar:** controller, action, feature test
406 - **Bağımlı:** 4.4, 5.6, 6.1
407 - **Kabul:** Fake ile 200 ve kanal sayıları; başarısız senaryo
da 422 gövdesinde anlamlı mesaj; onaylanmış kampanyada 409.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
413 - **Kabul:** Feature: yanıt `text/event-stream`, olay sırası d
elta… → final; DB'de iki mesaj.
414  
 415 ### 6.4 Düzenleme, önizleme ve kurallar
416 -- **İş:** `PUT /api/campaigns/{id}/content` (insan düzenlemesi
-, `ContentValidator`, 422 yol bazlı); `GET /api/campaigns/{id}
          -/preview?customer_id=` (verilmezse örnek kişi; render edilmiş
-metin ve uzunluklar); `GET /api/content/rules` (limitler ve de
-ğişkenler; frontend kendi kopyasını tutmasın diye, DRY).  
 416 +- **İş:** `PUT /api/campaigns/{id}/content` (insan düzenlemesi
+; yalnızca üretilmiş mesajlar ve yalnızca onaydan önce, `conte
          +nt_ready` dışında 409; `ContentValidator`, 422 yol bazlı); `GE
          +T /api/campaigns/{id}/preview?customer_id=` (verilmezse örnek
+kişi; render edilmiş metin ve uzunluklar); `GET /api/content/r
          +ules` (limitler ve değişkenler; frontend kendi kopyasını tutma
+sın diye, DRY).  
 417 - **Dosyalar:** controller'lar, feature test
418 - **Bağımlı:** 6.3
419 - **Kabul:** Feature: 161 karakterlik SMS → 422 `sms.body`; ön
izlemede boş isimli kişi için fallback.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
416 - **İş:** `PUT /api/campaigns/{id}/content` (insan düzenlemesi
; yalnızca üretilmiş mesajlar ve yalnızca onaydan önce, `conte
           nt_ready` dışında 409; `ContentValidator`, 422 yol bazlı); `GE
           T /api/campaigns/{id}/preview?customer_id=` (verilmezse örnek
kişi; render edilmiş metin ve uzunluklar); `GET /api/content/r
           ules` (limitler ve değişkenler; frontend kendi kopyasını tutma
sın diye, DRY).
417 - **Dosyalar:** controller'lar, feature test
418 - **Bağımlı:** 6.3
419 -- **Kabul:** Feature: 161 karakterlik SMS → 422 `sms.body`; ön
-izlemede boş isimli kişi için fallback.  
 419 +- **Kabul:** Feature: 161 karakterlik SMS → 422 `sms.body`; on
+aylanmış kampanyada düzenleme → 409; önizlemede boş isimli kiş
+i için fallback.  
 420  
 421 ---
422

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
557 - **Kabul:** Vitest (sahte stream ile kademeli metin); tarayıc
ıda metin akarak gelir.
558  
 559 ### 10.5 Düzenleme ve önizleme
560 -- **İş:** Düzenlenebilir alanlar; `/api/content/rules`'tan lim
-itlerle canlı sayaç (en kötü durum); değişken ekleme butonları
-; debounce ile `PUT`; backend hataları alanın altında; örnek k
-işi seçerek önizleme.  
 560 +- **İş:** Düzenlenebilir alanlar (yalnızca SMS ve email metni; + kural salt-okur; onaydan sonra kilitli); `/api/content/rules`
+'tan limitlerle canlı sayaç (en kötü durum, `Intl.Segmenter` i
+le görünen karakter; backend'deki sayımla aynı sonuç); değişke
+n ekleme butonları; debounce ile `PUT`; backend hataları alanı
+n altında; örnek kişi seçerek önizleme.  
 561 - **Dosyalar:** `components/ContentEditor.vue`, `components/Ch
           arCounter.vue`, test
562 - **Bağımlı:** 10.4, 6.4
563 - **Kabul:** Vitest: 161. karakterde sayaç kırmızı ve onay kap
alı; backend 422 mesajı görünür.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
560 - **İş:** Düzenlenebilir alanlar (yalnızca SMS ve email metni;
kural salt-okur; onaydan sonra kilitli); `/api/content/rules`
'tan limitlerle canlı sayaç (en kötü durum, `Intl.Segmenter` i
le görünen karakter; backend'deki sayımla aynı sonuç); değişke
n ekleme butonları; debounce ile `PUT`; backend hataları alanı
n altında; örnek kişi seçerek önizleme.
561 - **Dosyalar:** `components/ContentEditor.vue`, `components/Ch
           arCounter.vue`, test
562 - **Bağımlı:** 10.4, 6.4
563 -- **Kabul:** Vitest: 161. karakterde sayaç kırmızı ve onay kap
-alı; backend 422 mesajı görünür.  
 563 +- **Kabul:** Vitest: 161. karakterde sayaç kırmızı ve onay kap
+alı; emoji içeren metinde sayaç backend ile aynı; backend 422
+mesajı görünür.  
 564  
 565 ### 10.6 Onay
566 - **İş:** Kanal sayılarını gösteren onay modalı; tıklayınca bu
ton kapanır (çift tıklamaya karşı); onaydan sonra dashboard'a
yönlendirme.
● Son olarak faz başlıklarına gün hedefleri ve Açık Sorular bölümünün yanıtlarla
yeniden yazılması:

● Bash(cd "C:/Users/<user>/Desktop/case/smart-campaign-composer" && sed -i \
 -e 's/^## Faz 1: Kurulum (Claude araçları)$/## Faz 1: Kurulum (Claude
araçları) · G1/' \…)
⎿  Updated docs\plan.md (+11 -11)
73  
 74 ---
75  
 76 -## Faz 1: Kurulum (Claude araçları)  
 76 +## Faz 1: Kurulum (Claude araçları) · G1  
 77  
 78 Önceki projeden alınanlar: tam `settings.json` baştan, test scr
ipt'li fail-closed guard hook, iş akışı skill'leri ilk günden,
inceleme döngüsünde tur sınırı, hook kanıtı için log satırı. Bu
projeye göre değişenler: görev bazlı ilerleme olduğu için tek
`task` skill'i (önceki `phase`, `api-endpoint` ve `frontend-fea
          ture` yerine), hook'lar Node ile, `.env.example` deny dışında.
79  
 ...
182  
 183 ---
184  
 185 -## Faz 2: İskelet ve altyapı  
 185 +## Faz 2: İskelet ve altyapı · G1  
 186  
 187 ### 2.1 Laravel iskeleti
188 - **İş:** `backend/`'e Laravel 12, Pest, Pint, Larastan (seviy
e 6). Örnek dosyalar temizlenir.
...
246  
 247 ---
248  
 249 -## Faz 3: Veri modeli ve seed  
 249 +## Faz 3: Veri modeli ve seed · G2  
 250  
 251 ### 3.1 `customers` tablosu ve modeli
252 - **İş:** Örnek brief yalnızca bir örnek; alanlar farklı brief
türlerini karşılayacak genişlikte:
...
288  
 289 ---
290  
 291 -## Faz 4: Segment kuralı (LLM'siz)  
 291 +## Faz 4: Segment kuralı (LLM'siz) · G2  
 292  
 293 ### 4.1 `FieldRegistry`
294 - **İş:** Alan → kolon, tip, izinli operatörler, değer kısıtla
rı, Türkçe etiket. 3.1'deki segmentlenebilir tüm alanlar kayıt
lıdır (iletişim bilgileri ve isim hariç). Tipler: `enum` (`cit
           y`, `gender`, `language`, `platform`, `loyalty_tier`, `favorit
           e_category`; şehir listesi DB'den, cache'li), `number` (`age`,
`orders_count`, `total_spent`, `avg_order_value`, `cart_items
           _count`, `cart_value`), `date` (`registered_at`, `last_login_a
           t`, `first_purchase_at`, `last_purchase_at`, `last_cart_add_at
           `). Operatörler: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt
           `, `lte`, `between`, `within_last_days`, `not_within_last_days
           ` (null dahil), `is_null`, `not_null`. Doğrulayıcı, derleyici,
prompt ve JSON şeması bu tek kaynaktan beslenir (DRY). Hash m
ap, arama O(1).
...
316  
 317 ---
318  
 319 -## Faz 5: LLM katmanı (en ağır kalem)  
 319 +## Faz 5: LLM katmanı (en ağır kalem) · G2–G3  
 320  
 321 ### 5.1 `LlmClient` arayüzü ve fake
322 - **İş:** `LlmClient::complete(LlmRequest): LlmResponse`, `str
           eam(LlmRequest): iterable<LlmChunk>`. DTO'lar: system, message
s, JSON şeması, `maxTokens`; yanıt: metin, usage, `stopReason`
. `FakeLlmClient`: sıralı senaryo kuyruğu (geçersiz → geçerli
gibi), stream'i parçalara böler, hata da üretebilir. Container
binding: `auto` / `claude` / `fake`.
… 48 more lines (ctrl+o to expand)

Read 1 file (ctrl+o to expand)

● Update(docs\plan.md)
⎿  Added 20 lines, removed 17 lines
625  
 626 ---
627  
 628 -## Açık Sorular  
 628 +## Kararlaşan Sorular  
 629  
 630 -Her birinde varsayılan önerim parantezde; aksini söylemezsen b
-ununla ilerlerim.  
 630 +2026-11-04'te yanıtlandı. Her biri ilgili görevde `DECISIONS.m
          +d`'ye yazılır.  
 631  
 632 -1. **SMS 160 neyi sayıyor?** Türkçe karakterler GSM-7'de yok;
-operatörde UCS-2'ye düşer ve segment 70 karaktere iner. (Varsa
-yılan: 160 Unicode karakter, `mb_strlen`; GSM/UCS-2 ayrımı DEC
-ISIONS'ta bilinen sadeleştirme olarak yazılır.)  
 633 -2. **Limit şablona mı, kişiye giden metne mi?** (Varsayılan: k
-işiye giden metin; kitlenin en uzun değişken değeriyle en kötü - durum, D16.)  
 634 -3. **"Alışveriş yapmayan" ne demek?** Son 30 günde hiç almamış - / hiç almamış / sepete ekledikten sonra almamış. (Varsayılan: - son 30 günde satın alma yok; `explanation` alanında kullanıcı
-ya gösterilir.)  
 635 -4. **Email gövdesi:** format ve uzunluk belirtilmemiş. (Varsay
-ılan: düz metin, en fazla 2.000 karakter; HTML yok.)  
 636 -5. **Kimlik doğrulama / çoklu kullanıcı.** (Varsayılan: yok, t
-ek pazarlamacı, yerel demo; README'de belirtilir.)  
 637 -6. **"Aynı mesaj" kapsamı:** farklı kampanyalar aynı kişiye gi
-debilir mi? (Varsayılan: tekillik kampanya + kişi + kanal; kam
-panyalar arası frekans sınırı kapsam dışı.)  
 638 -7. **İzin onaydan sonra değişirse?** (Varsayılan: gönderim anı
-nda tekrar kontrol, `skipped`.)  
 639 -8. **Pazarlamacı segment kuralını elle düzenleyebilir mi?** Ca
-se yalnızca mesaj düzenlemeyi anlatıyor. (Varsayılan: kural sa
-lt-okur; brief değiştirilip yeniden üretilir.)  
 640 -9. **Rate limit kimin sınırı?** (Varsayılan: bizim giden trafi
-ğimizin kanal başına global sınırı; mock provider da aynı sını
-rı uygular ve aşımda 429 döner.)  
 641 -10. **Model seçimi.** (Varsayılan: `ANTHROPIC_MODEL=claude-son
          -net-5-5`, env ile değiştirilebilir; maliyet için Haiku 5.5 seç
-eneği DECISIONS'ta.)  
 642 -11. **İçerik dili.** (Varsayılan: Türkçe; kullanıcı diline gör
-e içerik bonus.)  
 643 -12. **Yasal metin (İYS, "RET yaz" gibi çıkış ifadesi) SMS'e ek
-lenmeli mi?** (Varsayılan: hayır, mock ortam; README'de not.)
644 -13. **Seed büyüklüğü.** (Varsayılan: 5.000.)  
 645 -14. **Kalıcı başarısız gönderimler.** (Varsayılan: `failed` ol
-arak kalır ve dashboard'da görünür; elle "tekrar dene" isteğe
-bağlı.)  
 646 -15. **Windows'ta `make`.** Değerlendirici büyük olasılıkla mac
-OS/Linux kullanır. (Varsayılan: Makefile ana yol; README'de Wi
-ndows için `docker compose run` eşdeğerleri.)  
 632 +1. **SMS 160 neyi sayıyor?** Kullanıcının gördüğü karakter say
+ısı: 160. Grapheme sayımı (backend `grapheme_strlen`, frontend + `Intl.Segmenter`). Operatör tarafındaki GSM-7/UCS-2 segment f
+arkı DECISIONS'ta bilinen sadeleştirme olarak not edilir. (5.7
+, 5.8, 10.5)  
 633 +2. **Limit şablona mı, kişiye giden metne mi?** Kişiye giden m
+etne; kitlenin en uzun değişken değeriyle en kötü durum (D16).
634 +3. **Örnek brief'in anlamı:** "Son 30 günde sepetine ürün ekle
+miş ama son 30 günde hiç alışveriş yapmamış" → `last_cart_add_
          +at within_last_days 30` ∧ `last_purchase_at not_within_last_da
          +ys 30` (hiç almamış olanlar dahil). Bu yalnızca bir örnek; ver
+i modeli ve segment alanları geniş tutulur (3.1, 4.1), prompt
+örnekleri ve eval brief'leri farklı alan türlerini kapsar (5.5
+, 5.12).  
 635 +4. **Email gövdesi:** düz metin, en fazla 2.000 karakter, HTML + yok.  
 636 +5. **Kimlik doğrulama:** yok; tek pazarlamacı, yerel demo; REA
+DME'de belirtilir.  
 637 +6. **"Aynı mesaj" kapsamı:** tekillik kampanya + kişi + kanal. + Farklı kampanyalar aynı kişiye gidebilir; frekans sınırı kaps
+am dışı.  
 638 +7. **İzin onaydan sonra değişirse:** gönderim anında tekrar ko
+ntrol, `skipped`.  
 639 +8. **Ne düzenlenebilir?** Yalnızca üretilen SMS ve email metni
+, yalnızca gönderimden (onaydan) önce. Segment kuralı salt-oku
+r; brief değiştirilip yeniden üretilir. (6.2, 6.4, 10.5)  
 640 +9. **Rate limit:** giden trafiğin kanal başına global sınırı;
+mock provider da aynı sınırı uygular ve aşımda 429 döner.  
 641 +10. **Model:** `ANTHROPIC_MODEL=claude-sonnet-5-5`, env ile de
+ğiştirilebilir; maliyet için Haiku 5.5 seçeneği DECISIONS'ta.
642 +11. **İçerik dili:** Türkçe; kullanıcı diline göre içerik bonu
+s.  
 643 +12. **Yasal metin (İYS, çıkış ifadesi):** eklenmez, mock ortam
+; README'de not.  
 644 +13. **Seed büyüklüğü:** 5.000.  
 645 +14. **Kalıcı başarısız gönderimler:** `failed` olarak kalır ve + dashboard'da görünür; elle "tekrar dene" isteğe bağlı.  
 646 +15. **Windows'ta `make`:** Makefile ana yol; README'de Windows + için `docker compose` eşdeğerleri.  
 647 +16. **`.env` erişimi:** Claude gerçek env dosyalarına hiçbir y
+oldan (araç, shell, container) erişemez; `.env.example` serbes
+t. (1.2, 1.3)  
 648 +17. **Süre:** çekirdek akış (Faz 1–10) 5 günde; G6 teslim, G7
+tampon ve bonus. (Bölüm 3)  
 649 +18. **Raporlama:** her görev ve faz sonunda, git adımından hem
+en önce dosya bazlı özet. (Bölüm 3, 1.7, 1.8)  
 650  
 651 ## İsteğe Bağlı Kısımlar
652

Searched for 1 pattern (ctrl+o to expand)

● Geri bildirimlerin docs/plan.md dosyasına işlendi. Commit ya da başka bir git
işlemi yapmadım.

┌──────────────┬───────────────────────┬──────────────────────────────────┐
│ Dosya │ Durum │ Ne değişti │
├──────────────┼───────────────────────┼──────────────────────────────────┤
│ docs/plan.md │ yeni (git'e │ Onaylanan plan ve senin │
│ │ eklenmedi) │ yanıtların │
└──────────────┴───────────────────────┴──────────────────────────────────┘

Plandaki değişiklikler

- .env erişimi: Claude gerçek env dosyalarına hiçbir yoldan erişemeyecek:
  dosya araçları, shell ve container içi komutlar engelli. .env.example
  serbest, testler bunu iki yönden kontrol ediyor (1.2, 1.3). .env.\* jokerini
  kullanmadım; o desen .env.example'ı da engellerdi.
- 160 karakter: Kişiye giden metinde, kullanıcının gördüğü karakter sayılıyor.
  Backend grapheme_strlen, frontend Intl.Segmenter kullanıyor; böylece iki
  tarafın sayacı aynı sonucu veriyor ve emoji 1 karakter sayılıyor (D16, 5.7,
  5.8, 10.5).
- Örnek brief: "Son 30 günde sepete ekleyip son 30 günde hiç alışveriş
  yapmamış" diye tanımlandı. Veri modelini genişlettim: demografi, giriş ve
  platform, sadakat seviyesi, kategori, sepet değeri, ortalama sipariş tutarı
  (3.1). Bu alanların hepsi segmentlenebilir (4.1). Prompt örnekleri ve test
  brief'leri yalnızca sepet senaryosuna dayanmıyor (5.5, 5.11, 5.12).
- Düzenleme: Yalnızca üretilen SMS ve email metni düzenlenebilir, o da onaydan
  önce. Onaydan sonra düzenleme 409 döner; segment kuralını düzenleyen bir
  endpoint yok (6.2, 6.4, 10.5).
- 5 günlük takvim: Faz 1–10 ilk 5 güne sığıyor. Her günün bir gün sonu ölçütü
  var ve faz başlıklarına gün hedefi eklendi. 6. gün teslime, 7. gün tampona
  ve bonuslara ayrıldı. Geride kalırsak önce kesilecekler: llm:eval, JSON log,
  Larastan, Playwright ve deneme koşusu (Bölüm 3).
- Özet kuralı: Her görevde ve her faz sonunda, herhangi bir git komutundan
  hemen önce dosya bazlı bir özet tablosu vereceğim (dosya | yeni/değişti | ne
  yapıldı | neden). Bu kural CLAUDE.md, task ve finish-phase adımlarına da
  yazıldı.
- Açık sorular: Bölümün adı "Kararlaşan Sorular" oldu. Yanıtların ve bu
  turdaki üç yeni karar (16–18) orada.

Görev 1.1'e "başla" dediğinde geçerim. O görev main'e ilk commit'i atacak, bu
yüzden commit adımında yine onayını bekleyeceğim.

❯ 1) "Tasarım kararları ("sen tasarla" kısımları)" ve "Kararlaşan Sorular"
başlıkları
-> projenin geri kalanında seçtiğimiz tasarılar eklenmiş mi kontrol et.

- ayrıca bu iki başlığı ortak bir çatıda topla, DECISIONS.md için önemli
  olanları kalsın sadece (case dosyasında doğrudan bahsedilen, mimari açıdan
  önemli, proje akışını kolaylaştıran kısımlar.)

Read 1 file (ctrl+o to expand)

● Kararların görevlere işlenmediği eksikler belirlendi: D3, D5, D13, D16, Soru
10, Sorular 5/11/12/15 ve 2.10/11.2 atıfları. Şimdi iki bölümü tek bir
"Kararlar" bölümünde birleştirip bu eksikleri gidereceğim.

Made 1 scratchpad edit +56 (ctrl+o to expand)

● Bash(cd "c:/Users/<user>/Desktop/case/smart-campaign-composer" && python -
"C:/Users/<user>/AppData/Local/Temp/claude/76579d91b6923705/b2dfd4d9-fda8-4
402-bf85-46e0e6f7…)
⎿  Updated docs\plan.md (+55 -46)
23 docker-compose.yml Makefile .env.example README.md DECISION
S.md CLAUDE.md .claude/ .mcp.json
24 ```      25  
      26 -## 2. Tasarım kararları ("sen tasarla" kısımları)              
      27 -                                                               
      28 -İlk tercih öneridir; parantezde dayandığı ilke. Her biri ilgili
         - görevde`DECISIONS.md`'ye yazılır.                            
      29 -                                                               
      30 -| #   | Konu                         | Opsiyonlar              
         -                                                               
         -                      | Öneri ve gerekçe                       
         -                                                               
         -                                                               
         -                                                               
         -                                                |              
      31 -| --- | ---------------------------- | ------------------------
         ----------------------------------------------------------------
         ---------------------- | ---------------------------------------
         ----------------------------------------------------------------
         ----------------------------------------------------------------
         ----------------------------------------------------------------
         ------------------------------------------------ |              
      32 -| D1  | PHP framework                | **Laravel 12** · Symfony
         - · Slim                                                        
         -                      | Queue, Redis throttle, migration, seede
         -r, Pest ve SSE hazır; Boost MCP ile önceki projedeki akış tekra
         -r kullanılır (KISS).                                           
         -                                                               
         -                                                 |             
      33 -| D2  | Veritabanı                   | **PostgreSQL** · MySQL ·
         - SQLite                                                        
         -                      | `COUNT(_) FILTER`, `ON CONFLICT DO NOTH
-ING`, JSONB; tek sorguda kanal bazlı sayım ve idempotent snapsh
-ot.  
 -  
 - |  
 34 -| D3 | Kuyruk | \*\*Redis (Laravel queue)_ -_ · RabbitMQ · DB queue  
 - | Tek bileşen kuyruk + global rate limite
-r + kilit işini görür; RabbitMQ bu ölçekte ek operasyon yükü (Y
-AGNI).  
 -  
 - |  
 35 -| D4 | Davranış verisi | **Kullanıcı üzerinde öze
-t kolonlar** (`last_cart_add_at`, `last_purchase_at`, `orders_c
         -ount`, `total_spent`) · olay tabloları + `EXISTS` | Olay toplam
-a hattı kapsam dışı; segment koşulları indeksli kolon karşılaşt
-ırmasına iner, derleyici basit kalır (KISS). Örnek brief yalnız
-ca bir örnek olduğu için kolon seti geniş tutulur (demografi, a
-lışveriş, sepet, etkileşim, sadakat; bkz. 3.1). Yeni alan = kol
-on + registry kaydı. Olay tablosu geçiş yolu DECISIONS'ta not e
-dilir.  
 - |  
 36 -| D5 | Segment kural dili | **Düz AND listesi + `in`
-/`not_in`** · iç içe AND/OR ağacı · JSON Logic  
 - | Örnek brief ve tipik istekleri karşıla
-r; doğrulama ve prompt kısa kalır (YAGNI). Ağaca genişleme kola
-y, şema versiyonlu.  
 -  
 - |  
 37 -| D6 | Yapılandırılmış çıktı | **Structured outputs (JS
-ON schema)** · zorunlu tool use (`tool_choice`) · yalnızca prom
-pt | Şemaya uyumu modelde garanti eder, onar
-ım turunu azaltır. Backend doğrulaması yine zorunlu (iş kuralla
-rı şemaya sığmaz). Güncel API Görev 5.2'de `claude-api` skill i
-le doğrulanır; desteklenmiyorsa zorunlu tool use.  
 - |  
 38 -| D7 | Claude istemcisi | **Laravel HTTP client, k
-endi arayüzümüzün arkasında** · resmi PHP SDK  
 - | Bağımlılık az, `Http::fake` ile test k
-olay, SSE ayrıştırıcı küçük. Arayüz sayesinde SDK'ya geçiş tek
-sınıf (DIP). SDK durumu 5.2'de kontrol edilir.  
 -  
 - |  
 39 -| D8 | İçerik stream'i | **SSE, POST + `fetch` Re
-adableStream** · WebSocket (Reverb) · polling  
 - | Tek yönlü akış için yeterli; yan etkil
-i işlem GET olmaz. Backend ham JSON delta'larını iletir, fronte
-nd kısmi JSON'u ayrıştırıp alanları gösterir, son ve doğrulanmı
-ş sonuç `final` olayıyla gelir. Kısmi ayrıştırma her karede tüm - tampon üzerinden O(n²) ama n < 3 KB. |  
 40 -| D9 | Dashboard canlılığı | **SSE (`EventSource`, 1
-sn, yalnızca değişince)** · 2 sn polling · Reverb  
 - | Otomatik yeniden bağlanma; ek container - yok. Snapshot tek `GROUP BY` sorgusundan gelir.  
 -  
 -  
 - |  
 41 -| D10 | Rate limit (100/sn/kanal) | **Kanal başına kuyruk + -`Redis::throttle(...)->block()`** · Lua token bucket · saniyeli
-k batch dispatcher | Worker sayısından bağımsız global sını
-r, atomik Redis işlemleri O(1). `block` ile beklenir, deneme sa
-yacı yanmaz. Sabit pencere sınırda kısa patlamaya izin verebili
-r; ölçümle doğrulanır, gerekirse token bucket.  
 - |  
 42 -| D11 | Retry stratejisi | **Hata sınıfına göre: 42
-9 → `Retry-After`; 5xx/timeout → üstel backoff + full jitter (m
-aks. 5 deneme); diğer 4xx → kalıcı hata** · sabit aralık · circ
-uit breaker eklemek | Gereksiz tekrarı ve senkron dalgalanmayı
-önler. `RetryPolicy` saf sınıf, unit test edilir (SRP). Circuit - breaker isteğe bağlı.  
 -  
 - |  
 43 -| D12 | Çift gönderim önleme | **DB unique + atomik cla
-im (`pending→sending` koşullu UPDATE) + provider `Idempotency-K
         -ey`** · Redis set · yalnızca `ShouldBeUnique` job | Üç katman:
-aynı satır iki kez oluşmaz, iki worker aynı satırı alamaz, time
-out sonrası tekrar provider'da tekilleşir (Defensive). `sending
         -`'de takılan satır süre aşımında yeniden alınabilir; idempotenc
-y key bunu güvenli kılar.  
 - |  
 44 -| D13 | Mock provider | **Ayrı container, sade P
-HP + Redis** · ana uygulamada route · başka dil  
 - | Gerçek ağ hataları ve timeout'lar oluş
-ur, ana uygulamadan bağımsız sayaçlar tekrarı kanıtlar (SRP).  
 -  
 -  
 - |  
 45 -| D14 | Web sunucusu | **FrankenPHP (klasik mod
-, Octane yok)** · nginx + php-fpm · `artisan serve`  
 - | Tek container, stream'de tampon ayarı - gerekmez. Octane worker modunun state sızıntısı riskine girilm
-ez (KISS).  
 -  
 - |  
 46 -| D15 | Test ortamı | **Integration testler do
-cker'daki Postgres/Redis'e karşı (ayrı test DB)** · SQLite in-m
-emory | `FILTER`, `ON CONFLICT`, throttle gerç
-ek bileşende test edilir. Unit testler bağımlılıksız ve hızlı. -  
 -  
 - |  
 47 -| D16 | Kişiselleştirme uzunluğu | **Kitlenin gerçek maks.
-değişken uzunluğuyla en kötü durum** · şablon uzunluğu · sabit
-bütçe | 160 limiti alıcıya giden metinde, kull
-anıcının gördüğü karakter sayısıyla (grapheme: emoji ve birleşi
-k harf 1 sayılır; backend `grapheme_strlen`, frontend `Intl.Seg
         -menter`) garanti edilir; tek `MAX(char_length())` sorgusu. Gönd
-erimde yeniden kontrol edilir (Defensive).  
 -  
 - |
48 -| D17 | Claude araç hook'ları | **Node `.mjs`** · bash + - `jq`  
 - | Windows/Git Bash farkı ve `jq` bağımlı
-lığı yok, JSON doğal, `node:test` ile test edilir.  
 -  
 -  
 - |  
 26 +## 2. Kararlar  
 27 +  
 28 +Yalnızca `DECISIONS.md`'ye girecek kararlar: case'te doğrudan g
+eçenler, mimariyi belirleyenler ve proje akışını kolaylaştıranl
+ar. Tasarım seçenekleri ve yanıtlanan sorular burada birleşti.
+Her karar, "Görevler" sütunundaki ilk görevin commit'inde `DECI
         +SIONS.md`'ye yazılır; aynı sütun kararın plana nerede işlendiği
+ni gösterir. Küçük varsayımlar (seed boyutu, içerik dili, yasal + metin, Windows'ta `make`) ilgili görevde ve README'de (11.1);
+süreç kuralları (süre, raporlama, `.env` erişimi, Claude araçla
+rı) Bölüm 3'te ve Faz 1'de.  
 29 +  
 30 +### Altyapı  
 31 +  
 32 +| # | Karar  
 + | Gerekçe + (ilke)  
 + | Alternatifl
+er | Görevler |  
 33 +| --- | -------------------------------------------------------
+----------------------------------------------------- | -------
+---------------------------------------------------------------
+------------------------------------------------- | -----------
+---------------------- | ------------- |  
 34 +| K1 | Laravel 12, FrankenPHP klasik mod (Octane yok)  
 + | Queue,
+Redis throttle, migration, seeder, Pest, SSE hazır; tek contain
+er, stream'de tampon ayarı yok; Octane'in state sızıntısı riski + yok (KISS) | Symfony, Slim; nginx + php-fpm | 2.1, 2.4  
 + |  
 35 +| K2 | PostgreSQL  
 + | `COUNT( +_) FILTER`ile tek sorguda kanal sayımı,`ON CONFLICT DO NOTHIN
+G`ile idempotent snapshot, JSONB                  | MySQL, SQL
         +ite                     | 2.8, 4.4, 8.5 |                      
      36 +| K3  | Redis: kuyruk + global rate limiter + kilit            
         +                                                      | Tek bil
         +eşen üç işi görür; bu ölçekte RabbitMQ ek operasyon yükü (YAGNI
         +)                                                 | RabbitMQ, D
         +B queue                | 2.3, 2.8, 8.4 |                       
      37 +| K4  | Unit testler bağımlılıksız; integration testler docker'
         +daki Postgres/Redis'e karşı (ayrı test DB);`make test` docker 
         +içinde | Gerçek bileşen davranışı (`FILTER`, `ON CONFLICT`, thr
+ottle) test edilir; değerlendiricinin host'unda bağımlılık gere
+kmez | SQLite in-memory | 2.8, 2.9 |  
 38 +  
 39 +### Kitle  
 … 73 more lines (ctrl+o to expand)

● Şimdi görevlerdeki eksikleri ve eski D-numaralı atıfları düzeltiyorum:

Read 1 file, ran 1 shell command (ctrl+o to expand)

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
1 # Smart Campaign Composer: Uygulama Planı
2  
 3 -Tarih: 2026-11-04 · Durum: onaylandı, geri bildirim işlendi (202
-6-11-04) · Açık sorular yanıtlandı (bkz. "Kararlaşan Sorular")  
 3 +Tarih: 2026-11-04, güncelleme 2026-11-05 · Durum: onaylandı · Ta
+sarım seçenekleri ve yanıtlanan sorular Bölüm 2'de (K1–K22)  
 4  
 5 ## 0. Bağlam
6

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
85 - **Görev döngüsü:** `/task N.M` → Claude: önce ilgili rule ve
dokümanlar (Docs first) → uygulama → test → lint → rapor. Sen d
oğrularsın, "onay" dersen Claude commit atar ve `plan.md`'deki
kutuyu işaretler.
86 - **Git öncesi özet (zorunlu):** Her görevin raporu ve her fazı
n kapanışı, herhangi bir git komutundan (`add`, `commit`, `push
          `, PR) hemen önce, değişen/eklenen her dosya için bir özet tabl
osu içerir: `dosya | yeni/değişti | ne yapıldı | neden`. Ardınd
an kabul kriteri kanıtı (komut + çıktı) ve doküman sorguları ge
lir. Faz kapanışındaki özet fazın tüm dosyalarını kapsar.
87 - **Faz sonu:** `/finish-phase` → `make test` + `make lint`, `r
          eview-pr`, `/code-review` (genelde `medium`; Faz 5 ve 8 için `h
          igh`), en fazla 2 tur, reddedilen bulgu listesi → dosya bazlı f
az özeti → onayla push (`ask`) ve PR. Merge sende.
88 -- **Kararlar:** Görevde verilen her önemli karar aynı commit'te - `DECISIONS.md`'ye girer.  
 88 +- **Kararlar:** Bölüm 2'deki her karar, "Görevler" sütunundaki
+ilk görevin commit'inde `DECISIONS.md`'ye girer. Uygulama sıras
+ında çıkan yeni bir önemli karar önce Bölüm 2'ye eklenir, sonra + aynı şekilde yazılır.  
 89 - **Session export:** Her oturum sonunda `/export` → `ai-sessio
          ns/YYYY-MM-DD-<konu>.md`; commit'ten önce `node scripts/scrub-s
          essions.mjs --check`.
90 - **Takvim (çekirdek akış 5 günde):**
91

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
171 - **Kabul:** Her dosyada doğru `paths:`; toplam ~1.5K token; i
çerik okunarak onaylanır.
172  
 173 ### 1.7 `task` skill'i
174 -- **İş:** Argüman görev ID'si. Adımlar: 0) `plan.md`'den görev
-i ve bağımlılıkları oku, ilgili rule dosyalarını önceden oku;
-1) Docs first: dokunulan her kütüphane için bir context7/Boost - sorgusu, LLM görevlerinde `claude-api` skill; ya da gerekçe;
-2) en küçük uygulama; 3) testler (`test-runner`); 4) lint/type
--check ana oturumda; 5) gerekiyorsa `DECISIONS.md`; 6) rapor:
-dosya bazlı özet tablosu (`dosya | yeni/değişti | ne yapıldı |
          - neden`), kabul kriteri kanıtı, doküman sorguları, açık konula
-r; hiçbir git komutu çalıştırmadan dur.  
 174 +- **İş:** Argüman görev ID'si. Adımlar: 0) `plan.md`'den görev
+i ve bağımlılıkları oku, ilgili rule dosyalarını önceden oku;
+1) Docs first: dokunulan her kütüphane için bir context7/Boost + sorgusu, LLM görevlerinde `claude-api` skill; ya da gerekçe;
+2) en küçük uygulama; 3) testler (`test-runner`); 4) lint/type
+-check ana oturumda; 5) görev Bölüm 2'de bir kararın ilk görev
+i ise o kararı `DECISIONS.md`'ye yaz; 6) rapor: dosya bazlı öz
+et tablosu (`dosya | yeni/değişti | ne yapıldı | neden`), kabu
+l kriteri kanıtı, doküman sorguları, açık konular; hiçbir git
+komutu çalıştırmadan dur.  
 175 - **Dosyalar:** `.claude/skills/task/SKILL.md`
176 - **Bağımlı:** 1.5, 1.6
177 - **Kabul:** `/task 1.13` deneme koşusunda tüm adımlar raporda
görünür.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
117 | Rules | `clean-code`, `backend`, `frontend` (
`paths:` ile) | Senin ilkelerin ve stack kuralları yal
nızca kod işinde yüklenir.  
 |
118 | Skills | `task`, `review-pr`, `finish-phase`  
 | Görev döngüsü, salt-okur inceleme ve f
az kapanışı. Docs-first adımı `task` içinde zorunlu.  
 |
119 | Agents | `test-runner` (haiku)  
 | Test çıktısını ana bağlamdan uzak tuta
r.  
 |
120 -| Hooks | `guard.mjs` (PreToolUse), `format.mjs
          -` (PostToolUse) | Tehlikeli komut ve sır engeli; otomati
-k format.  
 - |  
 120 +| Hooks | `guard.mjs` (PreToolUse), `format.mjs
          +` (PostToolUse) | Tehlikeli komut ve sır engeli; otomati
+k format. Node ile: `jq` ve Windows/Git Bash farkı yok, `node:
          +test` ile test edilir. |  
 121 | `settings.json` | Tam liste bir kerede  
 | İzin sorularını baştan keser; Claude k
endisi düzenleyemez, sen uygularsın.  
 |
122 | MCP | `context7` (Faz 1), `laravel-boost` (
Görev 2.2) | Vue, Pinia, Tailwind, Vitest, Playwrig
ht dokümanları; Laravel dokümanı, DB şeması.  
 |
123 | Plugin | `security-guidance`  
 | Commit/push sonrası güvenlik bulgusu;
önceki projede gerçek bulgular yakaladı.  
 |

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
229 - **Kabul:** `/mcp` → laravel-boost connected; `search-docs "q
           ueue rate limiting"` sonuç döner.
230  
 231 ### 2.3 Ortam ve config
232 -- **İş:** Kökte `.env.example`: `ANTHROPIC_API_KEY=`, `ANTHROP
          -IC_MODEL`, `LLM_DRIVER=auto|claude|fake`, DB/Redis, `PROVIDER_
          -URL`, `SEND_RATE_PER_SECOND=100`, `WORKERS_PER_CHANNEL`, mock
-hata oranları. `config/llm.php`, `config/delivery.php` (limitl
-erin tek kaynağı). `auto`: key boşsa fake ve uyarı log'u.  
 232 +- **İş:** Kökte `.env.example`: `ANTHROPIC_API_KEY=`, `ANTHROP
          +IC_MODEL=claude-sonnet-5-5`, `LLM_DRIVER=auto|claude|fake`, DB
+/Redis, `QUEUE_CONNECTION=redis`, `PROVIDER_URL`, `SEND_RATE_P
          +ER_SECOND=100`, `WORKERS_PER_CHANNEL`, mock hata oranları. `co
          +nfig/llm.php`, `config/delivery.php` (limitlerin tek kaynağı). + `auto`: key boşsa fake ve uyarı log'u.  
 233 - **Dosyalar:** `.env.example`, `backend/config/llm.php`, `bac
           kend/config/delivery.php`
234 - **Bağımlı:** 2.1
235 - **Kabul:** Key boşken `php artisan config:show llm` → driver
`fake`; unit test aynı şeyi doğrular.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
271 - **Kabul:** `make test` temiz klonda yeşil; `make lint` yeşil
.
272  
 273 ### 2.10 README ve DECISIONS iskeleti
274 -- **İş:** README başlıkları (kurulum, mimari, akış, testler, e
-ksikler); `DECISIONS.md`'ye D1, D2, D3, D14, D15, D17.  
 274 +- **İş:** README başlıkları (kurulum, mimari, akış, testler, e
+ksikler); `DECISIONS.md` iskeleti ve şablonu (karar, gerekçe,
+alternatifler); K1–K4 bu görevde yazılır.  
 275 - **Dosyalar:** `README.md`, `DECISIONS.md`
276 - **Bağımlı:** 2.8
277 - **Kabul:** Okuyarak onay.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
329 - **Kabul:** Unit: her tip için izinli/yasak operatör tablosu.
330  
 331 ### 4.2 `SegmentRule` ve doğrulayıcı
332 -- **İş:** `SegmentRuleParser::parse(array): SegmentRule|Valida
          -tionErrors` ("doğrulama yerine ayrıştır": `SegmentRule` geçers
-iz olarak var olamaz). Kontroller: yapı, `version`, 1–10 koşul
-, bilinen alan, tipe uygun operatör, değer tipi ve aralığı (gü
-n 1–365, `in` ≤50 öğe, şehir listede), `between` sırası. Hatal
-ar yol bazlı (`conditions[1].value: ...`); hem onarım prompt'u - hem UI kullanır.  
 332 +- **İş:** `SegmentRuleParser::parse(array): SegmentRule|Valida
          +tionErrors` ("doğrulama yerine ayrıştır": `SegmentRule` geçers
+iz olarak var olamaz). Koşullar AND ile bağlanır (K6). Kontrol
+ler: yapı, `version`, 1–10 koşul, bilinen alan, tipe uygun ope
+ratör, değer tipi ve aralığı (gün 1–365, `in` ≤50 öğe, şehir l
+istede), `between` sırası. Hatalar yol bazlı (`conditions[1].v
          +alue: ...`); hem onarım prompt'u hem UI kullanır.  
 333 - **Dosyalar:** `app/Segment/SegmentRule.php`, `Condition.php`
, `SegmentRuleParser.php`, `ValidationErrors.php`, unit test
334 - **Bağımlı:** 4.1
335 - **Kabul:** ≥20 geçersiz girdi vakası doğru yol ve mesajla re
ddedilir; örnek brief'in kuralı geçer.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
341 - **Kabul:** Integration: el ile kurulmuş küçük fixture'da örn
ek brief kuralı (`city = İstanbul` ∧ `last_cart_add_at within_
           last_days 30` ∧ `last_purchase_at not_within_last_days 30`) ve
her tipten en az bir başka kural (ör. `loyalty_tier in [gold,
            platinum]` ∧ `age between 25–40`) beklenen ID kümesini döner;
`toSql()` çıktısında kural değerleri yok, yalnızca `?`.
342  
 343 ### 4.4 `AudienceService`
344 -- **İş:** Kural → kanal başına sayılar (`COUNT(*) FILTER`, tek - sorgu, tek tarama) + toplam benzersiz + 5 örnek kişi + kanal
-başına en uzun `first_name`/`city` (D16 için).  
 344 +- **İş:** Kural → kanal başına sayılar (`COUNT(*) FILTER`, tek + sorgu, tek tarama) + toplam benzersiz + 5 örnek kişi + kanal
+başına her kişiselleştirme değişkeninin (`first_name`, `last_n
          +ame`, `city`) en uzun değeri (K13; `char_length` kod noktası s
+ayar ve grapheme sayısından küçük olamaz, yani güvenli tarafta + kalır).  
 345 - **Dosyalar:** `app/Segment/AudienceService.php`, `AudienceSu
           mmary.php`, integration test
346 - **Bağımlı:** 4.3
347 - **Kabul:** Integration: izinsiz kullanıcı ilgili kanal sayıs
ına girmez; sorgu sayısı 2 (`DB::getQueryLog`).

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
393 - **Kabul:** Unit: boş `first_name` → fallback; `{{unknown}}`
tespit; `ş`, `ğ`, emoji (👍, 👨‍👩‍👧) içeren metinde uzunluk görüne
n karakter sayısıyla aynı.
394  
 395 ### 5.8 `ContentValidator`
396 -- **İş:** SMS ≤160 (D16'ya göre en kötü durum render uzunluğu)
-, konu ≤80, email gövdesi ≤2.000, boş değil, yalnızca bilinen
-değişkenler, bozuk `{{`. Limitler `config/delivery.php`'den. L
-LM çıktısı ve insan düzenlemesi aynı doğrulayıcıdan geçer (DRY
-). Yol bazlı hatalar.  
 396 +- **İş:** SMS ≤160 (K13'e göre en kötü durum render uzunluğu), + konu ≤80, email gövdesi ≤2.000, boş değil, yalnızca bilinen d
+eğişkenler, bozuk `{{`. Limitler `config/delivery.php`'den. LL
+M çıktısı ve insan düzenlemesi aynı doğrulayıcıdan geçer (DRY)
+. Yol bazlı hatalar.  
 397 - **Dosyalar:** `app/Content/MessageContent.php`, `ContentVali
           dator.php`, unit test
398 - **Bağımlı:** 5.7
399 - **Kabul:** Unit: 160/161 ve 80/81 sınırları (görünen karakte
rle); değişken uzunluğu limiti aşırınca hata; Türkçe harf ve e
moji içeren sınır vakaları.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
583 - **Kabul:** Vitest (başarı, 422, 503); tarayıcıda örnek brief
→ sayılar.
584  
 585 ### 10.4 İçerik stream adımı
586 -- **İş:** SMS kartı (telefon görünümü) ve email kartı; kısmi J
-SON ayrıştırma (D8, docs-first ile kütüphane seçimi); iptal bu
-tonu; `final` gelince metin doğrulanmış içerikle değişir, "ona
-rıldı" bilgisi gösterilir.  
 586 +- **İş:** SMS kartı (telefon görünümü) ve email kartı; kısmi J
+SON ayrıştırma (K12, docs-first ile kütüphane seçimi); iptal b
+utonu; `final` gelince metin doğrulanmış içerikle değişir, "on
+arıldı" bilgisi gösterilir.  
 587 - **Dosyalar:** `components/ContentStream.vue`, `components/Sm
           sPreview.vue`, `components/EmailPreview.vue`, test
588 - **Bağımlı:** 10.3, 6.3
589 - **Kabul:** Vitest (sahte stream ile kademeli metin); tarayıc
ıda metin akarak gelir.

● Update(docs\plan.md)
⎿  Added 2 lines, removed 1 line
470 - **İş:** `FaultPolicy` (rastgele kaynağı enjekte edilir): gec
ikme aralığı, 500, 429 (+`Retry-After`), timeout, "kabul edip
500". Oranlar env'den; deterministik test için `X-Mock-Fault`
header'ı. Kanal başına >100/sn → 429 (saniyelik Redis `INCR`).
471 - **Dosyalar:** `mock-provider/src/FaultPolicy.php`, `RateLimi
           ter.php`, test
472 - **Bağımlı:** 7.2
473 -- **Kabul:** PHPUnit: sabit rastgele kaynakla beklenen dağılım
-; `X-Mock-Fault: 429` → 429 ve `Retry-After`; "kabul edip 500" - sonrası aynı key ile tekrar → 200 ve kayıt 1.  
 473 +- **Not:** Mock provider testleri `make test-mock` hedefiyle `
          +make test`'e eklenir.  
 474 +- **Kabul:** `make test` mock provider testlerini de çalıştırı
+r. PHPUnit: sabit rastgele kaynakla beklenen dağılım; `X-Mock-
          +Fault: 429` → 429 ve `Retry-After`; "kabul edip 500" sonrası a
+ynı key ile tekrar → 200 ve kayıt 1.  
 475  
 476 ---
477

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
618 ## Faz 11: Teslim · G6
619  
 620 ### 11.1 README
621 -- **İş:** Kurulum (`.env.example` → `.env`, key isteğe bağlı), - mermaid mimari diyagramı, akış, endpoint tablosu, test komutl
-arı, `llm:eval` sonucu, yapılmayanlar.  
 621 +- **İş:** Kurulum (`.env.example` → `.env`, key isteğe bağlı), + mermaid mimari diyagramı, akış, endpoint tablosu, test komutl
+arı (Windows için `docker compose` eşdeğerleriyle), `llm:eval` + sonucu, yapılmayanlar. Varsayımlar ve kapsam dışı: kimlik doğ
+rulama yok (K22), içerik Türkçe, yasal SMS metni (İYS, çıkış i
+fadesi) yok, kampanyalar arası frekans sınırı yok, seed 5.000
+kişi.  
 622 - **Dosyalar:** `README.md`
623 - **Bağımlı:** 10.8
624 - **Kabul:** Okuyarak onay.
● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
624 - **Kabul:** Okuyarak onay.
625  
 626 ### 11.2 DECISIONS kontrolü
627 -- **İş:** D1–D17 ve görevlerde çıkan kararlar tam mı; reddedil
-en alternatifler kısa.  
 627 +- **İş:** Bölüm 2'deki K1–K22 ve uygulamada eklenen kararlar `
          +DECISIONS.md`'de tam mı; reddedilen alternatifler kısa.  
 628 - **Dosyalar:** `DECISIONS.md`
629 - **Bağımlı:** 11.1
630 - **Kabul:** Okuyarak onay.

● Son kontrol: eski atıf kalmadı mı ve K-kararlarının tamamı en az bir görevde
geçiyor mu.

Ran 1 shell command (ctrl+o to expand)

● İki bölüm docs/plan.md içinde "Bölüm 2. Kararlar" başlığı altında birleşti.
Görevlerdeki eksikleri de tamamladım. Commit atmadım; git durumu değişmedi (??
docs/).

Kontrolün sonucu. Seçilen tasarımların çoğu görevlere zaten işlenmişti. Eksik
bulduklarımı tamamladım:

┌─────────────────┬─────────────────┬────────────────────────────────────┐
│ Karar │ Eksik │ Düzeltme │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ Redis kuyruk │ 2.3'te kuyruk │ QUEUE_CONNECTION=redis eklendi │
│ (K3) │ ayarı yoktu │ │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ Model seçimi │ 2.3'te │ ANTHROPIC_MODEL=claude-sonnet-5-5 │
│ (K9) │ varsayılan │ eklendi │
│ │ model yoktu │ │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ │ 4.2'de │ │
│ Segment dili │ koşulların AND │ Eklendi │
│ (K6) │ ile bağlandığı │ │
│ │ yazmıyordu │ │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ Kişiselleştirme │ 4.4 yalnızca │ last_name dahil tüm değişkenler │
│ uzunluğu (K13) │ first_name/city │ sayılıyor │
│ │ sayıyordu │ │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ Mock provider │ Testleri make │ │
│ (K20) │ test'e dahil │ 7.3'e make test-mock eklendi │
│ │ değildi │ │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ │ README │ Kimlik doğrulama yok, Türkçe │
│ Kapsam │ görevinde │ içerik, yasal SMS metni yok, │
│ varsayımları │ (11.1) yoktu │ frekans sınırı yok, Windows için │
│ │ │ docker compose eşdeğerleri eklendi │
├─────────────────┼─────────────────┼────────────────────────────────────┤
│ │ 2.10, 4.4, 5.8, │ │
│ Eski │ 10.4 ve │ K-numaralarına çevrildi │
│ D-numaraları │ 11.2'deki │ │
│ │ atıflar │ │
└─────────────────┴─────────────────┴────────────────────────────────────┘

Yeni Bölüm 2. 22 karar var (K1–K22) ve altı gruba ayrıldı: Altyapı, Kitle,
LLM, İçerik ve onay, Gönderim, Takip ve kapsam. Her satırda karar, gerekçe ve
dayandığı ilke, alternatifler ve "Görevler" sütunu var. "Görevler" sütunu
kararın plana nerede işlendiğini gösteriyor. Karar, o sütundaki ilk görevin
commit'inde DECISIONS.md'ye yazılacak; bu kural Bölüm 3'e ve task skill'ine de
eklendi.

- Eklenenler: Eski tabloda açıkça yer almayan ama case'te doğrudan geçen
  kararlar kendi satırını aldı:
  - LLM çıktısının SQL olmaması (K7)
  - izin kontrolünün iki noktada yapılması (K8)
  - LLM güvenilirlik zinciri (K11)
  - kişiselleştirme (K14)
  - insan onayı akışı (K15)
  - metrics ve health (K21)
  - kimlik doğrulama yok (K22)
- Birleşenler: Yanıtladığın sorular ilgili karara katıldı. Örneğin 160
  karakter sayımı ve email limiti K13'te, örnek brief yorumu K5'te, "farklı
  kampanyalar aynı kişiye gidebilir" K19'da.
- Çıkarılanlar: Bu maddeler DECISIONS.md'ye girmiyor, ama planda yerlerinde
  duruyorlar:
  - Seed boyutu, içerik dili, yasal metin ve Windows'ta make: 3.2'de ve README
    görevinde (11.1).
  - .env erişimi, 5 günlük takvim, raporlama kuralı ve Node hook'ları: Bölüm
    3'te ve Faz 1'de. Node hook'larının gerekçesi Faz 1'deki araç tablosuna
    taşındı.

"Kararlaşan Sorular" bölümü kaldırıldı, Açık Sorular da artık yok. İsteğe
Bağlı Kısımlar planın sonunda olduğu gibi duruyor.

❯ - Son haliyle AI sessions klasörünü oluştur ve bu sohbeti export et plan
adıyla.

- bu haliyle yeni branch aç, commitle ve PR yap. (Plandaki yapıya benzer
  olarak)

● Önce repoda bir uzak sunucu (remote) tanımlı mı ve gh oturumu açık mı, ona
bakıyorum.

Ran 1 shell command (ctrl+o to expand)

● Bu arada bir "Initial commit" ve bir README.md eklenmiş. Ne içerdiğine ve
main'in uzakta olup olmadığına bakıyorum:

Ran 1 shell command (ctrl+o to expand)

● Ana dal uzakta zaten var ve yalnızca "Initial commit" içeriyor; docs/ altında
şimdilik sadece plan.md kaldı. /export komutunu ben çalıştıramadığım için,
dal, plan ve PR adımlarını önce yapıp export'u en sonda sana bırakacağım,
sonra ikinci commit olarak ekleyeceğim.

Ran 1 shell command (ctrl+o to expand)

● Write(ai-sessions\README.md)
⎿  Wrote 7 lines to ai-sessions\README.md
1 # AI sessions
2
3 Case boyunca Claude ile yapılan çalışma oturumlarının export'ları
.
4
5 - **Kaynak:** Claude Code `/export` komutu.
6 - **İsim:** `YYYY-MM-DD-<konu>.md` (ör. `2026-11-05-plan.md`).
7 - **Temizlik:** Commit'ten önce API key, e-posta, telefon ve kişi
sel dosya yolları maskelenir. Faz 1'den itibaren bu kontrol `scri
        pts/scrub-sessions.mjs --check` ile yapılır.

● Update(docs\plan.md)
⎿  Added 4 lines, removed 4 lines
129 | | Global `~/.claude/CLAUDE.md`, output
style, statusline | Bu projeye katkısı yok.

                     |
      130
      131  ### 1.1 Repo başlangıcı
      132 -- **İş:** `.gitignore` (vendor, node_modules, `.env`, build çı
          -ktıları, `.claude/logs`), `.gitattributes` (`* text=auto eol=l
          -f`: Windows'ta CRLF'li shell script'ler container'da bozulması
          -n), planı `docs/plan.md`'ye kopyala, `main`'e ilk commit, `pha
          -se-1/setup` dalı.
      133 -- **Dosyalar:** `.gitignore`, `.gitattributes`, `docs/plan.md`
      134 -- **Bağımlı:** -
      135 -- **Kabul:** `git log --oneline` 1 commit; `git branch --show-
          -current` → `phase-1/setup`; `git check-attr eol -- x.sh` → `lf
          -`.
      132 +- **İş:** Faz 0 PR'ı merge edildikten sonra güncel `main`'den
          +`phase-1/setup` dalı; `.gitignore` (vendor, node_modules, `.en
          +v`, build çıktıları, `.claude/logs`), `.gitattributes` (`* tex
          +t=auto eol=lf`: Windows'ta CRLF'li shell script'ler container'
          +da bozulmasın).
      133 +- **Dosyalar:** `.gitignore`, `.gitattributes`
      134 +- **Bağımlı:** Faz 0
      135 +- **Kabul:** `git branch --show-current` → `phase-1/setup`; `g
          +it log` Faz 0 merge commit'ini içerir; `git check-attr eol --
          +x.sh` → `lf`.
      136
      137  ### 1.2 `settings.json` (sen uygularsın)
      138  - **İş:** Claude tam içeriği hazırlar, sen `.claude/settings.j
           son`'a koyarsın.

● Update(docs\plan.md)
⎿  Added 1 line, removed 1 line
195 - **Kabul:** `/mcp` → context7 connected; "vue router lazy loa
ding" sorgusu sonuç döner.
196  
 197 ### 1.11 Session export temizliği
198 -- **İş:** `ai-sessions/README.md` (isim formatı, export adımı)
-; `scrub-sessions.mjs`: API key desenleri, e-posta, telefon, W
-indows kullanıcı yolu maskelenir; `--check` temiz değilse non-
-zero döner.  
 198 +- **İş:** `scrub-sessions.mjs`: API key desenleri, e-posta, te
+lefon, Windows kullanıcı yolu maskelenir; `--check` temiz deği
+lse non-zero döner. `ai-sessions/README.md` (Faz 0'da açıldı)
+bu adımla güncellenir; Faz 0 export'u da script'ten geçirilir.
199 - **Dosyalar:** `ai-sessions/README.md`, `scripts/scrub-sessio
           ns.mjs`
200 - **Bağımlı:** 1.1
201 - **Kabul:** Sahte key ve e-posta içeren örnek dosya maskeleni
r; `--check` önce 1, sonra 0 döner.

● Update(docs\plan.md)
⎿  Added 6 lines
105  
 106 ---
107  
 108 +## Faz 0: Plan · tamamlandı (2026-11-05)  
 109 +  
 110 +`docs/plan.md` ve `ai-sessions/` (planlama oturumunun export'u
+), `phase-0/plan` dalı ve PR'ı. Kurulum araçları henüz olmadığ
+ı için görev formatı ve `finish-phase` dışında, elle yapıldı.
111 +  
 112 +---  
 113 +  
 114 ## Faz 1: Kurulum (Claude araçları) · G1
115  
 116 Önceki projeden alınanlar: tam `settings.json` baştan, test sc
ript'li fail-closed guard hook, iş akışı skill'leri ilk günden
, inceleme döngüsünde tur sınırı, hook kanıtı için log satırı.
Bu projeye göre değişenler: görev bazlı ilerleme olduğu için
tek `task` skill'i (önceki `phase`, `api-endpoint` ve `fronten
           d-feature` yerine), hook'lar Node ile, `.env.example` deny dış
ında.

● Plan ve klasör README'si dosya tablosuna eklendi; secret kontrolünden sonra
commit, push ve PR açacağım.

Ran 1 shell command (ctrl+o to expand)

● Her iki eşleşme de gerçek bir sır değil, planın kendi metni: guard hook'un
aradığı sk-ant- deseni ve boş ANTHROPIC_API_KEY= satırı. CRLF uyarılarını ise
.gitattributes 1.1'de çözeceğim; şimdi commit ve push yapıyorum.

Ran 2 shell commands (ctrl+o to expand)

● PR #1'i açtım: https://github.com/Sdrs81/smart-campaign-composer/pull/1

- Dal: phase-0/plan, main'den açıldı.
- Commit: Tek commit (895deff), içinde docs/plan.md ve ai-sessions/README.md
  var. Değişen dosyalarda sır taramasının iki eşleşmesi de planın kendi metni,
  gerçek sır yok.
- Plan güncellemesi: Plana tamamlanmış "Faz 0: Plan" bölümünü ekledim.
  1.1'deki "ilk commit, planı kopyala" adımlarını kaldırdım; artık Faz 0 merge
  edildikten sonra phase-1/setup dalını açıyor. 1.11'e Faz 0 export'unun da
  temizlenmesini ekledim.

Senden tek adım: /export komutunu ben çalıştıramıyorum. Şunu çalıştır:

/export ai-sessions/2026-11-05-plan.md

VS Code eklentisinde /export yoksa iki yol var. Terminalde claude --continue
ile bu oturumu açıp aynı komutu çalıştırabilirsin. Ya da panoya kopyalanan
export'u o dosyaya yapıştırabilirsin.

Dosya oluşunca haber ver. API key, e-posta ve C:\Users\… yollarını maskeleyip
sana göstereceğim, sonra aynı PR'a ikinci commit olarak ekleyeceğim. Export en
sona kaldığı için bu oturumun tamamını, PR adımları dahil, içerecek.
