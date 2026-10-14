# День 1: AWS-акаунт та Notes API — план реалізації

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> Tasks 1-3 are manual (browser, credit card, personal accounts). Only the author can do them.

**Goal:** Безпечний AWS-акаунт з доступом через CLI, публічний GitHub-репозиторій та
Laravel API `notes` (CRUD) з зеленими Pest-тестами.

**Architecture:** AWS-частина в день 1 — лише акаунт, IAM та Budgets. Ресурси не
створюються. Laravel-застосунок живе в `app/` у корені репозиторію. Сьогодні тести працюють
на SQLite in-memory. PostgreSQL з'явиться в день 2 через docker-compose.

**Tech Stack:** AWS (IAM, Budgets), AWS CLI v2, GitHub + `gh`, PHP 8.5, Laravel (остання
версія з `composer create-project`), Pest.

**Spec:** `docs/specs/2026-10-14-laravel-k8s-aws-design.md` (секції 4.1, 5, 10 — день 1).
Терміни: `docs/specs/glossary.md`.

## Global Constraints

- Регіон AWS: `eu-central-1` (Франкфурт).
- AWS CLI profile: `pet`.
- Root-акаунт: MFA увімкнено, для щоденної роботи не використовується.
- AWS Budgets: alerts на $10 та $20.
- AWS access keys ніколи не потрапляють у Git (лише `~/.aws/credentials`).
- Ресурс API: `notes`, поля `title` (string, max 255, required) та `body` (text, nullable).
- Усі відповіді `/api/*` — JSON, навіть без заголовка `Accept: application/json`.
- Застосунок живе в `app/`. Команди `php artisan`, `composer` та `pest` запускаються з `app/`.

## Review Focus

1. Запит без `Accept: application/json` (наприклад, `curl` без заголовків) → JSON 422, а не
   302-redirect на `/`. Тест у Task 5: `returns json errors without an Accept header`.
2. Нечисловий ID (`/api/notes/abc`) → 404. На PostgreSQL без обмеження маршруту це дасть 500
   (`invalid input syntax for type bigint`). Тест у Task 5: `returns 404 for a non-numeric id`.
3. `title` довше за 255 символів → 422. Без валідації PostgreSQL відповість 500
   (`value too long`). Тест у Task 5: `rejects a title longer than 255 characters`.
4. PATCH лише з `body` не стирає `title`. Тест у Task 5: `updates only the given fields`.
5. Велика кількість нотаток → список з пагінацією, а не всі рядки одразу. Тест у Task 5:
   `lists notes with pagination`.

---

### Task 1: AWS-акаунт та безпека root (вручну)

**Files:** немає (лише консоль AWS).

**Interfaces:**
- Produces: AWS-акаунт з Account ID, email-alerts від Budgets.

- [ ] **Step 1: Зареєструйте акаунт**

Відкрийте https://aws.amazon.com/ і натисніть "Create an AWS Account".
Для root використовуйте особистий email, а не робочий. Цей акаунт лишиться у вас після
зміни роботи.

Під час вибору плану прочитайте умови "Free plan" та "Paid plan".
- Якщо ви обрали Free plan, запишіть, які сервіси він обмежує.
- Якщо EKS недоступний на Free plan, в день 9 ви перейдете на Paid plan. Кредити
  при цьому лишаються.

- [ ] **Step 2: Увімкніть MFA для root**

Консоль → правий верхній кут (ім'я акаунту) → Security credentials → Multi-factor
authentication (MFA) → Assign MFA device → Authenticator app.

Очікуваний результат: у списку MFA є один пристрій.

- [ ] **Step 3: Дозвольте IAM-користувачам бачити Billing**

Консоль → Account → "IAM user and role access to Billing information" → Edit → Activate.

Причина: без цього IAM-користувач не бачить витрати, і вам доведеться входити як root.

- [ ] **Step 4: Створіть бюджет**

Консоль → Billing and Cost Management → Budgets → Create budget → "Use a template" →
"Monthly cost budget".
- Budget name: `pet-monthly`
- Budgeted amount: `20`
- Email recipients: ваш email

Після створення відкрийте бюджет → Alerts → Edit. Налаштуйте три alerts:

| Threshold | Trigger    |
|-----------|------------|
| 50% ($10) | Actual     |
| 100% ($20)| Actual     |
| 100% ($20)| Forecasted |

Очікуваний результат: бюджет `pet-monthly` має 3 alerts.

- [ ] **Step 5: Вийдіть з root**

Далі ви працюєте лише як IAM-користувач (Task 2).

---

### Task 2: IAM-користувач та AWS CLI (вручну)

**Files:**
- Create: `~/.aws/config`, `~/.aws/credentials` (поза репозиторієм)
- Modify: `~/.zshrc`

**Interfaces:**
- Consumes: AWS-акаунт з Task 1.
- Produces: CLI profile `pet`. Команда `aws sts get-caller-identity --profile pet` повертає ARN
  `arn:aws:iam::<account-id>:user/tvard-admin`.

- [ ] **Step 1: Створіть групу та користувача**

Увійдіть як root востаннє. IAM → User groups → Create group:
- Group name: `admins`
- Permissions: `AdministratorAccess`

IAM → Users → Create user:
- User name: `tvard-admin`
- "Provide user access to the AWS Management Console": так, custom password
- Add to group: `admins`

Запишіть "Console sign-in URL" (`https://<account-id>.signin.aws.amazon.com/console`).

Примітка для співбесіди: `AdministratorAccess` порушує least privilege. Для одного
адміністратора pet project це прийнятно. У команді права дають через ролі, а постійних
IAM users з ключами уникають.

- [ ] **Step 2: Увійдіть як `tvard-admin` та увімкніть MFA**

Увійдіть через Console sign-in URL. Security credentials → Assign MFA device.

- [ ] **Step 3: Створіть access key для CLI**

Security credentials → Access keys → Create access key → "Command Line Interface (CLI)".
Скопіюйте Access key ID та Secret access key. Secret показується лише один раз.

**Не вставляйте ключі в чати, нотатки або файли репозиторію.** Якщо ключ потрапив у
публічне місце, одразу зробіть його Deactivate та Delete.

- [ ] **Step 4: Встановіть AWS CLI**

```bash
sudo pacman -S aws-cli-v2
aws --version
```

Очікуваний результат: `aws-cli/2.x.x ...`

- [ ] **Step 5: Налаштуйте profile `pet`**

```bash
aws configure --profile pet
# AWS Access Key ID: <ваш key id>
# AWS Secret Access Key: <ваш secret>
# Default region name: eu-central-1
# Default output format: json
chmod 600 ~/.aws/credentials
echo 'export AWS_PROFILE=pet' >> ~/.zshrc
source ~/.zshrc
```

- [ ] **Step 6: Перевірте доступ**

```bash
aws sts get-caller-identity
```

Очікуваний результат:

```json
{
    "UserId": "AIDA...",
    "Account": "<12 цифр>",
    "Arn": "arn:aws:iam::<12 цифр>:user/tvard-admin"
}
```

Якщо в `Arn` є `:root`, ви створили ключ для root. Видаліть цей ключ і повторіть Step 3 як
`tvard-admin`.

---

### Task 3: Публічний GitHub-репозиторій (вручну)

**Files:**
- Create: `README.md`

**Interfaces:**
- Consumes: локальний репозиторій `~/laravel-k8s-aws` (2 коміти зі spec та глосарієм).
- Produces: `origin` → `github.com/Tvardick/laravel-k8s-aws`, гілка `main`.

- [ ] **Step 1: Встановіть та авторизуйте `gh`**

```bash
sudo pacman -S github-cli
gh auth login
# GitHub.com → HTTPS → Login with a web browser
gh auth status
```

Очікуваний результат: `Logged in to github.com account Tvardick`.

- [ ] **Step 2: Створіть README**

`~/laravel-k8s-aws/README.md`:

```markdown
# laravel-k8s-aws

A Laravel API deployed to AWS EKS with Terraform, Helm, ArgoCD and GitHub Actions.

Work in progress. Design: [docs/specs/2026-10-14-laravel-k8s-aws-design.md](docs/specs/2026-10-14-laravel-k8s-aws-design.md)

## Stack

- App: Laravel API + PostgreSQL
- Infrastructure: AWS (VPC, ECR, EKS, RDS) with Terraform
- Kubernetes: Helm, Traefik, ArgoCD, kube-prometheus-stack
- CI/CD: GitHub Actions with OIDC (no static AWS keys)
```

- [ ] **Step 3: Перевірте, що в репозиторії немає секретів**

```bash
cd ~/laravel-k8s-aws
git status --short
grep -rIl 'AKIA' . --exclude-dir=.git || echo "no AWS keys found"
```

Очікуваний результат: `no AWS keys found`.

- [ ] **Step 4: Закомітьте та опублікуйте**

```bash
git add README.md
git commit -m "docs: add README"
gh repo create laravel-k8s-aws --public --source=. --remote=origin --push
```

Очікуваний результат: репозиторій відкривається на `https://github.com/Tvardick/laravel-k8s-aws`.

---

### Task 4: Laravel skeleton, API routing та Pest

**Files:**
- Create: `app/` (Laravel skeleton через `composer create-project`)
- Create: `app/routes/api.php`
- Modify: `app/bootstrap/app.php`
- Modify: `app/tests/Pest.php` (створює `pest --init`)
- Delete: `app/tests/Feature/ExampleTest.php`

**Interfaces:**
- Produces: маршрути з `app/routes/api.php` мають префікс `/api`. Усі помилки для `api/*`
  повертаються як JSON. `./vendor/bin/pest` запускає тести з `RefreshDatabase` у `tests/Feature`.

- [ ] **Step 1: Встановіть та увімкніть SQLite для PHP**

```bash
sudo pacman -S php-sqlite
sudo sed -i 's/^;extension=pdo_sqlite/extension=pdo_sqlite/; s/^;extension=sqlite3/extension=sqlite3/' /etc/php/php.ini
php -m | grep -i sqlite
```

Очікуваний результат: `pdo_sqlite` та `sqlite3`.

- [ ] **Step 2: Створіть Laravel-проєкт**

```bash
cd ~/laravel-k8s-aws
composer create-project laravel/laravel app
cd app
php artisan --version
```

Очікуваний результат: `Laravel Framework 1x.x.x`. Post-install скрипт створює
`database/database.sqlite` та виконує міграції без помилок.

- [ ] **Step 3: Підключіть API-маршрути без Sanctum**

Ми не використовуємо `php artisan install:api`, бо він встановлює Sanctum. Автентифікація
в цьому проєкті не потрібна (YAGNI).

Створіть `app/routes/api.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
```

У `app/bootstrap/app.php` додайте рядок `api:` у `withRouting` та правило JSON у
`withExceptions`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients (curl, other services) often skip the Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
```

Якщо ваш згенерований файл має додаткові рядки, лишіть їх. Змініть лише `api:` та
`withExceptions`.

- [ ] **Step 4: Встановіть Pest**

```bash
composer remove phpunit/phpunit --dev
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/pest --init
rm tests/Feature/ExampleTest.php
```

- [ ] **Step 5: Увімкніть `RefreshDatabase` для Feature-тестів**

Замініть вміст `app/tests/Pest.php`:

```php
<?php

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 6: Перевірте, що тести використовують SQLite в пам'яті**

```bash
grep -E 'DB_CONNECTION|DB_DATABASE' phpunit.xml
```

Очікуваний результат:

```
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Якщо ці рядки закоментовані (`<!-- -->`), розкоментуйте їх.

- [ ] **Step 7: Запустіть тести**

```bash
./vendor/bin/pest
```

Очікуваний результат: `Tests: 1 passed` (лишився `tests/Unit/ExampleTest.php`).

- [ ] **Step 8: Закомітьте**

```bash
cd ~/laravel-k8s-aws
git add app
git status --short | grep -E '\.env$|vendor/' && echo "STOP: secrets or vendor staged" || echo "clean"
git commit -m "feat(app): add Laravel skeleton with API routing and Pest"
```

Очікуваний результат: `clean`. Laravel `.gitignore` у `app/` блокує `.env` та `vendor/`.

---

### Task 5: Notes CRUD API (TDD)

**Files:**
- Create: `app/tests/Feature/NoteApiTest.php`
- Create: `app/app/Models/Note.php`, `app/database/migrations/*_create_notes_table.php`,
  `app/database/factories/NoteFactory.php` (через `make:model -mf`)
- Create: `app/app/Http/Controllers/NoteController.php`
- Create: `app/app/Http/Requests/StoreNoteRequest.php`, `app/app/Http/Requests/UpdateNoteRequest.php`
- Create: `app/app/Http/Resources/NoteResource.php`
- Modify: `app/routes/api.php`

**Interfaces:**
- Consumes: `/api` routing та JSON-помилки з Task 4.
- Produces (для дня 2 та далі):
  - `GET /api/notes` → 200, `{data: [...], links, meta}`, 15 на сторінку, нові спочатку
  - `POST /api/notes` → 201, `{data: {id, title, body, created_at, updated_at}}`
  - `GET /api/notes/{id}` → 200 або 404
  - `PATCH|PUT /api/notes/{id}` → 200
  - `DELETE /api/notes/{id}` → 204
  - Помилки валідації → 422, `{message, errors: {field: [...]}}`
  - Модель `App\Models\Note`, таблиця `notes`, фабрика `Note::factory()`

- [ ] **Step 1: Напишіть тести**

`app/tests/Feature/NoteApiTest.php`:

```php
<?php

use App\Models\Note;

it('lists notes with pagination', function () {
    Note::factory()->count(20)->create();

    $this->getJson('/api/notes')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20);
});

it('creates a note', function () {
    $this->postJson('/api/notes', ['title' => 'First', 'body' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'First')
        ->assertJsonPath('data.body', 'Hello');

    $this->assertDatabaseHas('notes', ['title' => 'First', 'body' => 'Hello']);
});

it('creates a note without body', function () {
    $this->postJson('/api/notes', ['title' => 'Only title'])
        ->assertCreated()
        ->assertJsonPath('data.body', null);
});

it('rejects a note without title', function () {
    $this->postJson('/api/notes', ['body' => 'No title'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('rejects a title longer than 255 characters', function () {
    $this->postJson('/api/notes', ['title' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('returns json errors without an Accept header', function () {
    $this->post('/api/notes', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json');
});

it('shows a note', function () {
    $note = Note::factory()->create(['title' => 'Shown']);

    $this->getJson("/api/notes/{$note->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $note->id)
        ->assertJsonPath('data.title', 'Shown');
});

it('returns 404 for a missing note', function () {
    $this->get('/api/notes/999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('returns 404 for a non-numeric id', function () {
    $this->get('/api/notes/abc')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('updates only the given fields', function () {
    $note = Note::factory()->create(['title' => 'Old', 'body' => 'Old body']);

    $this->patchJson("/api/notes/{$note->id}", ['body' => 'New body'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Old')
        ->assertJsonPath('data.body', 'New body');
});

it('rejects an empty title on update', function () {
    $note = Note::factory()->create();

    $this->patchJson("/api/notes/{$note->id}", ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('deletes a note', function () {
    $note = Note::factory()->create();

    $this->deleteJson("/api/notes/{$note->id}")->assertNoContent();

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});
```

- [ ] **Step 2: Запустіть тести та переконайтеся, що вони падають**

```bash
cd ~/laravel-k8s-aws/app
./vendor/bin/pest tests/Feature/NoteApiTest.php
```

Очікуваний результат: FAIL з `Class "App\Models\Note" not found` (або 404 для маршрутів).

- [ ] **Step 3: Створіть модель, міграцію та фабрику**

```bash
php artisan make:model Note -mf
```

Міграція `database/migrations/*_create_notes_table.php`, метод `up()`:

```php
Schema::create('notes', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body')->nullable();
    $table->timestamps();
});
```

`app/Models/Note.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'body'];
}
```

`database/factories/NoteFactory.php`, метод `definition()`:

```php
public function definition(): array
{
    return [
        'title' => fake()->sentence(3),
        'body' => fake()->paragraph(),
    ];
}
```

- [ ] **Step 4: Створіть Form Requests**

```bash
php artisan make:request StoreNoteRequest
php artisan make:request UpdateNoteRequest
```

`app/Http/Requests/StoreNoteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ];
    }
}
```

`app/Http/Requests/UpdateNoteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // "sometimes": validate a field only when the client sends it (PATCH semantics).
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 5: Створіть API Resource**

```bash
php artisan make:resource NoteResource
```

`app/Http/Resources/NoteResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 6: Створіть контролер**

```bash
php artisan make:controller NoteController --api
```

`app/Http/Controllers/NoteController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NoteResource::collection(Note::latest('id')->paginate(15));
    }

    public function store(StoreNoteRequest $request): NoteResource
    {
        // JsonResource returns 201 for a model that was just created.
        return new NoteResource(Note::create($request->validated()));
    }

    public function show(Note $note): NoteResource
    {
        return new NoteResource($note);
    }

    public function update(UpdateNoteRequest $request, Note $note): NoteResource
    {
        $note->update($request->validated());

        return new NoteResource($note);
    }

    public function destroy(Note $note): Response
    {
        $note->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Зареєструйте маршрути**

`app/routes/api.php`:

```php
<?php

use App\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

// Numeric ids only: on PostgreSQL a non-numeric id would fail in SQL with 500 instead of 404.
Route::apiResource('notes', NoteController::class)->where(['note' => '[0-9]+']);
```

```bash
php artisan route:list --path=api
```

Очікуваний результат: 5 маршрутів `api/notes` (index, store, show, update, destroy).

- [ ] **Step 8: Запустіть тести та переконайтеся, що вони проходять**

```bash
./vendor/bin/pest
```

Очікуваний результат: `Tests: 13 passed` (12 у `NoteApiTest` + 1 у `Unit/ExampleTest`).

Якщо `returns json errors without an Accept header` падає з 302, перевірте `withExceptions`
у `bootstrap/app.php` (Task 4, Step 3).

- [ ] **Step 9: Перевірте вручну через curl**

```bash
php artisan migrate
php artisan serve --port=8080 &
sleep 2
curl -s -X POST localhost:8080/api/notes -d 'title=From curl'
curl -s localhost:8080/api/notes
curl -s -X POST localhost:8080/api/notes -d 'body=no title'
kill %1
```

Очікуваний результат:
- перший запит повертає JSON з `"title":"From curl"`;
- другий повертає список з `data`, `links` та `meta`;
- третій повертає JSON з `errors.title`, а не HTML.

- [ ] **Step 10: Закомітьте та опублікуйте**

```bash
cd ~/laravel-k8s-aws
git add app
git commit -m "feat(app): add notes CRUD API with Pest tests"
git push
```

---

### Task 6: Теорія дня 1 — IAM (1 година, вручну)

**Files:**
- Create: `docs/interview-notes.md`

**Interfaces:**
- Produces: файл, у який ви додаєте відповіді щодня.

- [ ] **Step 1: Створіть файл з питаннями**

`docs/interview-notes.md`:

```markdown
# Interview notes

Answers in my own words. One section per day.

## Day 1: IAM

1. What is the difference between an IAM user, an IAM group and an IAM role?
2. What is an IAM policy? What are Effect, Action, Resource and Condition?
3. What happens if one policy says Allow and another says Deny for the same action?
4. What is the principle of least privilege? Give an example from this project.
5. Why must you not use the root account for daily work?
6. Where do you keep AWS keys? What do you do if a key leaked to a public repository?
7. What is the difference between long-term access keys and temporary credentials (STS)?
8. Why does CI (GitHub Actions) use an IAM role with OIDC instead of access keys?
```

- [ ] **Step 2: Напишіть відповіді своїми словами**

Джерела:
- https://docs.aws.amazon.com/IAM/latest/UserGuide/introduction.html
- https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_evaluation-logic.html
- `docs/specs/glossary.md`, секція 2

Пишіть коротко, 2-4 речення на питання. Ви можете писати українською. Якщо ви хочете
тренувати англійську для співбесід, пишіть англійською.

- [ ] **Step 3: Закомітьте та опублікуйте**

```bash
cd ~/laravel-k8s-aws
git add docs/interview-notes.md
git commit -m "docs: add day 1 interview notes (IAM)"
git push
```

---

## Результат дня 1

- [ ] `aws sts get-caller-identity` показує `user/tvard-admin`, а не root.
- [ ] Бюджет `pet-monthly` має 3 alerts.
- [ ] `https://github.com/Tvardick/laravel-k8s-aws` публічний і має spec, глосарій, README та `app/`.
- [ ] `./vendor/bin/pest` у `app/` показує 13 passed.
- [ ] `docs/interview-notes.md` має відповіді на 8 питань про IAM.
