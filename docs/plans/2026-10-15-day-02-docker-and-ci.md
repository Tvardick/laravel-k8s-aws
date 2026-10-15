# День 2: Docker, docker-compose та CI — план реалізації

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> The author implements all tasks personally (learning project). Claude explains and reviews.

**Goal:** Laravel API працює в Docker-контейнері з PostgreSQL, має probes `/health` та `/ready`,
а кожен pull request проходить CI: Pint, Pest на PostgreSQL та `docker build`.

**Architecture:** Один образ `notes-api` (multi-stage: Composer-етап + runtime-етап на
`serversideup/php:8.5-fpm-nginx`, non-root, порт 8080). `docker-compose.yml` у корені
репозиторію запускає три сервіси: `db` (PostgreSQL), `migrate` (одноразові міграції) та
`app`. Ця схема повторює майбутній Kubernetes: StatefulSet, Job та Deployment.
GitHub Actions перевіряє код та збирання образу на кожен pull request.

**Tech Stack:** Docker, Docker Compose, `serversideup/php:8.5` (`cli` та `fpm-nginx`),
PostgreSQL 17, GitHub Actions, Pint, Pest 5, Laravel 13.

**Spec:** `docs/specs/2026-10-14-laravel-k8s-aws-design.md` (секції 4.1, 4.6, 8, 10 — день 2).
Терміни: `docs/specs/glossary.md`, секції 5 та 11.

## Global Constraints

- Застосунок живе в `app/`. Команди `php artisan`, `composer` та `pest` запускаються з `app/`.
- Базові образи: `serversideup/php:8.5-cli` (build) та `serversideup/php:8.5-fpm-nginx`
  (runtime). Версія PHP задана одним `ARG PHP_VERSION=8.5`.
- Контейнер працює як `www-data` (uid 33), не як `root`. Порт: `8080`.
- В образі немає `.env`, `APP_KEY`, `tests/`, `vendor` з dev-залежностями.
- Міграції не запускаються під час старту `app`. Їх запускає окремий сервіс `migrate`.
- `/health` не звертається до БД, сесій або кешу. `/ready` перевіряє БД.
- Логи йдуть у stderr контейнера (`LOG_CHANNEL=stderr`), а не у файл.
- Секрети compose (`APP_KEY`, `DB_PASSWORD`) лежать у кореневому `.env`. Він у `.gitignore`.
- CI має мінімальні права: `permissions: contents: read`.
- Робота дня йде в гілці `day-02-docker`. У `main` зміни потрапляють лише через pull request.

## Review Focus

1. БД впала → `/health` повертає 200, `/ready` повертає 503. Kubernetes не перезапускає Pod
   без потреби. Тест у Task 1: `health stays ok when the database is down` та
   `ready returns 503 when the database is down`.
2. Laravel 13 за замовчуванням має `SESSION_DRIVER=database`. Якщо `/health` проходить через
   групу middleware `web`, він створює сесію в БД і падає разом з БД. Тест у Task 1:
   `health does not start a session`.
3. `/ready` не показує текст помилки БД назовні (host, user, SQL). Тест у Task 1:
   `ready does not leak the error message`.
4. Тести дня 1 (нечисловий ID, `title` > 255) реально проходять на PostgreSQL, а не лише на
   SQLite. Перевірка в Task 4: job `app` у CI запускає Pest на PostgreSQL.
5. В образі немає секретів і тестів, а процес не має root-прав. Перевірка в Task 2, Step 5.

---

### Task 1: Probes `/health` та `/ready` (TDD)

**Files:**
- Create: `app/tests/Feature/ProbeTest.php`
- Create: `app/app/Http/Controllers/ProbeController.php`
- Create: `app/routes/probes.php`
- Modify: `app/bootstrap/app.php` (параметр `then:` у `withRouting`)

**Interfaces:**
- Produces (для Docker healthcheck та Kubernetes probes, дні 3-4):
  - `GET /health` → 200 `{"status":"ok"}`. Без БД, без сесії, без cookies.
  - `GET /ready` → 200 `{"status":"ok"}`, якщо `select 1` у БД працює.
    Інакше → 503 `{"status":"unavailable"}`.

- [ ] **Step 1: Закомітьте нотатки дня 1 та створіть гілку**

```bash
cd ~/laravel-k8s-aws
git add docs/interview-notes.md
git commit -m "docs: add day 1 interview notes (Q1-Q3)"
git push
git switch -c day-02-docker
```

- [ ] **Step 2: Напишіть тести**

`app/tests/Feature/ProbeTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

// Simulates a database that is down. The message contains details that must not leak.
function breakDatabase(): void
{
    DB::shouldReceive('select')
        ->andThrow(new RuntimeException('SQLSTATE[08006] connection to host db failed for user notes'));
}

it('returns ok from health', function () {
    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('health stays ok when the database is down', function () {
    breakDatabase();

    $this->get('/health')->assertOk();
});

it('health does not start a session', function () {
    $response = $this->get('/health');

    expect($response->headers->getCookies())->toBeEmpty();
});

it('returns ok from ready when the database works', function () {
    $this->get('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('ready returns 503 when the database is down', function () {
    breakDatabase();

    $this->get('/ready')->assertStatus(503);
});

it('ready does not leak the error message', function () {
    breakDatabase();

    $this->get('/ready')->assertExactJson(['status' => 'unavailable']);
});
```

Чому mock, а не "зламаний" config БД: трейт `RefreshDatabase` відкриває транзакцію на
початку тесту і закриває її в кінці. Якщо тест змінює `database.default`, `RefreshDatabase`
у кінці тесту звертається до зламаного з'єднання, і наступні тести падають.
`DB::shouldReceive` підміняє лише фасад `DB`, а `RefreshDatabase` працює з реальним
з'єднанням.

- [ ] **Step 3: Запустіть тести та переконайтеся, що вони падають**

```bash
cd ~/laravel-k8s-aws/app
./vendor/bin/pest tests/Feature/ProbeTest.php
```

Очікуваний результат: FAIL, маршрути повертають 404.

- [ ] **Step 4: Створіть контролер**

`app/app/Http/Controllers/ProbeController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeController extends Controller
{
    // Liveness: the process answers. No database, no session, no cache.
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    // Readiness: the app can serve real requests, so the database must answer.
    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            // Details go to the log only. The response must not expose hosts or credentials.
            Log::warning('Readiness check failed', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
```

- [ ] **Step 5: Створіть маршрути без middleware**

`app/routes/probes.php`:

```php
<?php

use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [ProbeController::class, 'health']);
Route::get('/ready', [ProbeController::class, 'ready']);
```

У `app/bootstrap/app.php` додайте `use Illuminate\Support\Facades\Route;` та параметр `then:`
у `withRouting`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Probes skip the "web" group: no session, cookies or CSRF, so no hidden DB calls.
        then: function () {
            Route::middleware([])->group(base_path('routes/probes.php'));
        },
    )
```

Причина: маршрути з `routes/web.php` проходять групу `web`. Вона стартує сесію, а сесія в
Laravel 13 за замовчуванням живе в БД. Тоді liveness probe падає разом з БД, і Kubernetes
перезапускає всі Pods без користі.

- [ ] **Step 6: Запустіть тести та переконайтеся, що вони проходять**

```bash
./vendor/bin/pest
```

Очікуваний результат: `Tests: 19 passed` (13 з дня 1 + 6 нових).

- [ ] **Step 7: Закомітьте**

```bash
cd ~/laravel-k8s-aws
git add app
git commit -m "feat(app): add /health and /ready probes"
```

---

### Task 2: Dockerfile (multi-stage, non-root)

**Files:**
- Create: `app/Dockerfile`
- Create: `app/.dockerignore`

**Interfaces:**
- Consumes: `/health` з Task 1 (для ручної перевірки).
- Produces: образ `notes-api:local`, порт 8080, користувач `www-data`. Конфігурація лише
  через змінні середовища (`APP_KEY`, `DB_*`, `LOG_CHANNEL`, `SESSION_DRIVER`, `CACHE_STORE`).

- [ ] **Step 1: Створіть `.dockerignore`**

`app/.dockerignore`:

```
# Secrets and local state
.env
.env.*
database/database.sqlite

# Installed inside the image
vendor
node_modules

# Not needed at runtime
tests
.phpunit.cache
.phpunit.result.cache
Dockerfile
.dockerignore

# Runtime files, keep the directories (.gitignore files)
storage/logs/*
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
!storage/**/.gitignore
```

Причина: без `.dockerignore` команда `COPY . .` копіює ваш локальний `.env` з `APP_KEY` в
образ. Будь-хто з доступом до registry зможе його прочитати.

- [ ] **Step 2: Створіть Dockerfile**

`app/Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5

# Stage 1: install production dependencies with Composer.
FROM serversideup/php:${PHP_VERSION}-cli AS vendor

# Dependency files first: this layer stays cached until composer.lock changes.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
    && composer check-platform-reqs --no-dev

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Stage 2: runtime image with nginx + php-fpm. Composer cache and build files stay behind.
FROM serversideup/php:${PHP_VERSION}-fpm-nginx AS runtime

ENV PHP_OPCACHE_ENABLE=1

COPY --chown=www-data:www-data --from=vendor /var/www/html /var/www/html

# The base image already runs as www-data on port 8080. Explicit for readers.
USER www-data
EXPOSE 8080
```

Пояснення до двох рішень:
- `COPY composer.json composer.lock` стоїть перед `COPY . .`. Якщо ви змінили лише код,
  Docker бере шар з `composer install` з кешу. Збирання триває секунди, а не хвилини.
- `composer check-platform-reqs` падає під час build, якщо `composer.lock` потребує інших
  PHP-розширень або версії PHP, ніж є в образі. Краще побачити це в build, ніж у production.

- [ ] **Step 3: Зберіть образ**

```bash
cd ~/laravel-k8s-aws/app
docker build -t notes-api:local .
```

Очікуваний результат: `naming to docker.io/library/notes-api:local done`.

- [ ] **Step 4: Перевірте кеш шарів**

Змініть будь-який коментар у `app/Http/Controllers/ProbeController.php` і зберіть ще раз:

```bash
docker build -t notes-api:local .
```

Очікуваний результат: крок `RUN composer install ...` має позначку `CACHED`. Поверніть
коментар назад.

- [ ] **Step 5: Перевірте безпеку образу**

```bash
docker run --rm notes-api:local whoami
docker run --rm notes-api:local sh -c 'ls -a /var/www/html | grep -E "^\.env$|^tests$" || echo "no .env, no tests"'
docker run --rm notes-api:local sh -c 'test -d vendor/pestphp && echo "DEV DEPS FOUND" || echo "no dev deps"'
docker image ls notes-api:local
```

Образ `serversideup/php` друкує банер (версії, користувач, OPcache) на кожен `docker run`.
Результат команди — останній рядок виводу.

Очікуваний результат:
- `www-data`
- `no .env, no tests`
- `no dev deps`
- розмір образу: ~850MB (перевірено 2026-10-15). Базовий образ на Debian великий. Варіант
  `8.5-fpm-nginx-alpine` менший. Порівняння — необов'язковий експеримент, якщо лишиться час.

- [ ] **Step 6: Перевірте `/health` у контейнері**

```bash
docker run --rm -d --name notes-probe -p 8080:8080 \
  -e APP_KEY="$(php artisan key:generate --show)" \
  -e LOG_CHANNEL=stderr -e SESSION_DRIVER=array -e CACHE_STORE=array \
  notes-api:local
sleep 5
curl -s localhost:8080/health
curl -s -o /dev/null -w '%{http_code}\n' localhost:8080/ready
docker logs notes-probe 2>&1 | tail -5
docker stop notes-probe
```

Очікуваний результат:
- `/health` → `{"status":"ok"}`;
- `/ready` → `503`, бо БД ще немає;
- у `docker logs` є рядок `Readiness check failed`. Логи йдуть у stderr, тому Docker їх бачить.

- [ ] **Step 7: Закомітьте**

```bash
cd ~/laravel-k8s-aws
git add app/Dockerfile app/.dockerignore
git commit -m "build(app): add multi-stage non-root Dockerfile"
```

---

### Task 3: docker-compose з PostgreSQL та міграціями

**Files:**
- Create: `docker-compose.yml` (корінь репозиторію)
- Create: `.env.example` (корінь репозиторію)
- Create: `.env` (корінь, не комітиться)

**Interfaces:**
- Consumes: образ з Task 2, probes з Task 1.
- Produces: `docker compose up -d` піднімає API на `http://localhost:8080`. Імена та змінні
  (`DB_HOST=db`, `DB_DATABASE=notes`, `DB_USERNAME=notes`) повторюються в Helm chart (день 5).

- [ ] **Step 1: Створіть `.env.example`**

`~/laravel-k8s-aws/.env.example`:

```
# Copy to .env and fill in. .env is ignored by git.
# APP_KEY: run "cd app && php artisan key:generate --show"
APP_KEY=
DB_PASSWORD=
```

- [ ] **Step 2: Створіть `.env` з реальними значеннями**

```bash
cd ~/laravel-k8s-aws
cp .env.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=$(cd app && php artisan key:generate --show)|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" .env
git status --short .env
```

Очікуваний результат: `git status` нічого не показує для `.env`. Кореневий `.gitignore`
його блокує.

- [ ] **Step 3: Створіть `docker-compose.yml`**

`~/laravel-k8s-aws/docker-compose.yml`:

```yaml
# Local stack. The same roles appear in Kubernetes later:
# db -> StatefulSet, migrate -> Job, app -> Deployment.

x-app-env: &app-env
  APP_KEY: ${APP_KEY:?set APP_KEY in .env}
  APP_ENV: local
  APP_DEBUG: "true"
  LOG_CHANNEL: stderr
  SESSION_DRIVER: array
  CACHE_STORE: array
  QUEUE_CONNECTION: sync
  DB_CONNECTION: pgsql
  DB_HOST: db
  DB_PORT: "5432"
  DB_DATABASE: notes
  DB_USERNAME: notes
  DB_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD in .env}

services:
  db:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: notes
      POSTGRES_USER: notes
      POSTGRES_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD in .env}
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U notes -d notes"]
      interval: 5s
      timeout: 3s
      retries: 10

  migrate:
    build: ./app
    image: notes-api:local
    command: ["php", "artisan", "migrate", "--force"]
    environment: *app-env
    restart: "no"
    depends_on:
      db:
        condition: service_healthy

  app:
    image: notes-api:local
    environment: *app-env
    ports:
      - "8080:8080"
    depends_on:
      migrate:
        condition: service_completed_successfully

volumes:
  pgdata:
```

Пояснення:
- `migrate` запускається один раз і завершується. `app` стартує лише після успішних
  міграцій. У Kubernetes цю роль виконає Job з ArgoCD PreSync hook.
- Порт БД (5432) не опубліковано назовні. До БД має доступ лише мережа compose.
- `${APP_KEY:?...}` зупиняє compose з помилкою, якщо змінна порожня.

- [ ] **Step 4: Запустіть стек**

```bash
docker compose up -d --build
docker compose ps -a
docker compose logs migrate
```

Очікуваний результат:
- `migrate` має статус `Exited (0)`;
- у логах `migrate` є рядок `create_notes_table ... DONE`;
- `app` має статус `Up (healthy)` через 10-30 секунд.

- [ ] **Step 5: Перевірте API на PostgreSQL**

```bash
curl -s localhost:8080/ready
curl -s -X POST localhost:8080/api/notes -d 'title=From Docker'
curl -s localhost:8080/api/notes
curl -s -o /dev/null -w '%{http_code}\n' localhost:8080/api/notes/abc
```

Очікуваний результат:
- `{"status":"ok"}`;
- JSON з `"title":"From Docker"`;
- список з `data`, `links` та `meta`;
- `404`. На PostgreSQL без `whereNumber` тут був би `500`.

- [ ] **Step 6: Навчальний інцидент: БД впала**

```bash
docker compose stop db
curl -s -w ' %{http_code}\n' localhost:8080/health
curl -s -w ' %{http_code}\n' localhost:8080/ready
docker compose logs app --tail 5
docker compose start db
sleep 5
curl -s -w ' %{http_code}\n' localhost:8080/ready
```

Очікуваний результат:
- `/health` → 200;
- `/ready` → 503, у логах `app` є `Readiness check failed`;
- після `start db`: `/ready` → 200.

Запишіть результат одним реченням. Він знадобиться в день 3 для питання про
liveness vs readiness.

- [ ] **Step 7: Перевірте, що дані переживають перезапуск**

```bash
docker compose down
docker compose up -d
sleep 10
curl -s localhost:8080/api/notes | grep -o '"title":"From Docker"'
```

Очікуваний результат: `"title":"From Docker"`. Дані живуть у volume `pgdata`.
Команда `docker compose down -v` видалить volume разом з даними.

- [ ] **Step 8: Закомітьте**

```bash
cd ~/laravel-k8s-aws
git add docker-compose.yml .env.example
git status --short | grep -E '(^|/)\.env$' && echo "STOP: .env staged" || echo "clean"
git commit -m "build: add docker-compose with PostgreSQL and migration service"
```

---

### Task 4: CI на pull request (GitHub Actions)

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: файли, які виправить Pint (якщо він знайде проблеми)

**Interfaces:**
- Consumes: Dockerfile з Task 2, тести з Task 1 та дня 1.
- Produces: два status checks на кожен pull request: `app` та `docker`. День 6 додасть
  окремий `release.yml` для push в ECR.

- [ ] **Step 1: Перевірте стиль коду локально**

```bash
cd ~/laravel-k8s-aws/app
./vendor/bin/pint --test
```

Очікуваний результат: Pint знаходить проблеми у 4 файлах дня 1: `routes/api.php`,
`app/Models/Note.php`, `app/Http/Controllers/NoteController.php` та міграції `create_notes_table`
(порядок `use`, невикористані імпорти, відступи). Виправте їх автоматично і закомітьте:

```bash
./vendor/bin/pint
git -C ~/laravel-k8s-aws add app
git -C ~/laravel-k8s-aws commit -m "style(app): apply Pint"
```

- [ ] **Step 2: Створіть workflow**

`~/laravel-k8s-aws/.github/workflows/ci.yml`:

```yaml
name: CI

on:
  pull_request:

# Least privilege for the GITHUB_TOKEN: CI only reads the code.
permissions:
  contents: read

# A new push to the same PR cancels the old run.
concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: true

jobs:
  app:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: app

    # Tests run on PostgreSQL, the same engine as in Docker and RDS.
    services:
      postgres:
        image: postgres:17-alpine
        env:
          POSTGRES_DB: notes_test
          POSTGRES_USER: notes
          POSTGRES_PASSWORD: notes
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U notes -d notes_test"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    # Real env vars win over the <env> values in phpunit.xml.
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: notes_test
      DB_USERNAME: notes
      DB_PASSWORD: notes

    steps:
      - uses: actions/checkout@v5

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"
          extensions: pdo_pgsql
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment
        run: cp .env.example .env && php artisan key:generate

      - name: Code style (Pint)
        run: vendor/bin/pint --test

      - name: Tests (Pest on PostgreSQL)
        run: vendor/bin/pest

  docker:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5

      - uses: docker/setup-buildx-action@v3

      - name: Build image (no push)
        uses: docker/build-push-action@v6
        with:
          context: app
          push: false
          tags: notes-api:ci
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

Пароль `notes` тут не секрет. Ця БД живе лише всередині CI-job кілька хвилин.

- [ ] **Step 3: Закомітьте та відкрийте pull request**

```bash
cd ~/laravel-k8s-aws
git add .github/workflows/ci.yml
git commit -m "ci: add pull request checks (Pint, Pest on PostgreSQL, docker build)"
git push -u origin day-02-docker
gh pr create --fill --base main
```

- [ ] **Step 4: Дочекайтеся результату CI**

```bash
gh pr checks --watch
```

Очікуваний результат: `app` та `docker` мають статус `pass`.

Якщо `app` впав на тестах, які проходять локально, це різниця SQLite та PostgreSQL.
Відкрийте лог (`gh run view --log-failed`) і надішліть мені помилку. Саме для таких
випадків тести в CI йдуть на PostgreSQL.

- [ ] **Step 5: Захистіть гілку `main`**

GitHub → репозиторій → Settings → Rules → Rulesets → New branch ruleset:
- Ruleset name: `protect-main`
- Enforcement status: Active
- Target branches: Include default branch
- Rules: "Require a pull request before merging" (Required approvals: 0) та
  "Require status checks to pass" → додайте `app` та `docker`
- "Block force pushes": увімкнено

Причина: прямий `git push` у `main` тепер неможливий, і код без зеленого CI не потрапить у
`main`. З дня 6 merge в `main` запускає деплой. Тому цей захист важливий.

- [ ] **Step 6: Зробіть merge**

```bash
gh pr merge --merge --delete-branch
git switch main
git pull
```

Очікуваний результат: `git log --oneline -5` показує merge commit з `day-02-docker`.

---

### Task 5: Теорія (не більше 1.5 години, після практики)

**Files:**
- Modify: `docs/interview-notes.md`

**Interfaces:**
- Consumes: формат з spec, секція 10 (пояснення від Claude → ваші 3-5 речень → один review).

Порядок: спершу борг дня 1, потім Docker. Обмеження: 25 хвилин на питання з дня 1 та
10 хвилин на питання з Docker. Ви щойно бачили Docker на практиці.

- [ ] **Step 1: Виправте Q1 (5 хвилин)**

Приберіть першу половину відповіді (що таке IAM). Виправте фразу "IAM Role замінює IAM User".
Role не має постійних credentials. Її тимчасово беруть (assume) людина, сервіс або CI.

- [ ] **Step 2: Q5, Q6, Q9 з дня 1 (по 25 хвилин)**

Для кожного питання напишіть у сесії Claude: "Поясни Q5 у форматі A". Потім напишіть
відповідь у `docs/interview-notes.md` і попросіть review.

- [ ] **Step 3: Додайте розділ дня 2 та дайте відповіді (по 10 хвилин)**

Додайте в кінець `docs/interview-notes.md`:

```markdown
## Day 2: Docker

1. Why does the Dockerfile copy composer.json and composer.lock before the rest of the code?
   What did you see in Task 2, Step 4?
2. Why does the container run as www-data and not as root? How did you check it?
```

- [ ] **Step 4: Закомітьте через pull request**

`main` тепер захищений, тому нотатки теж йдуть через гілку:

```bash
cd ~/laravel-k8s-aws
git switch -c day-02-notes
git add docs/interview-notes.md
git commit -m "docs: add day 2 interview notes"
git push -u origin day-02-notes
gh pr create --fill --base main
gh pr checks --watch
gh pr merge --merge --delete-branch
git switch main && git pull
```

---

## Результат дня 2

- [ ] `./vendor/bin/pest` у `app/` показує 19 passed.
- [ ] `docker compose up -d` піднімає `db`, `migrate` (Exited 0) та `app` (healthy).
- [ ] БД зупинена → `/health` 200, `/ready` 503.
- [ ] Образ працює як `www-data`, без `.env`, `tests` та dev-залежностей.
- [ ] Pull request показує зелені checks `app` та `docker`. `main` захищений ruleset `protect-main`.
- [ ] `docs/interview-notes.md` має Q1 (виправлено), Q5, Q6, Q9 та 2 питання про Docker.
