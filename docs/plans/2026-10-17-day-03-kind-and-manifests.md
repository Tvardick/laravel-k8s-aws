# День 3: кластер kind та raw manifests — план реалізації

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> The author implements all tasks personally (learning project). Claude explains and reviews.

**Goal:** Laravel API та PostgreSQL працюють у локальному кластері `kind`, а API відповідає
через `kubectl port-forward`.

**Architecture:** Один кластер `kind` з назвою `notes` (один node). У namespace `notes`
працюють PostgreSQL (StatefulSet + headless Service + PVC) та API (Deployment з 2 replicas +
ConfigMap + Secret + Service ClusterIP). Образ `notes-api:local` з дня 2 потрапляє в кластер
через `kind load`, а не через registry. Маніфести лежать у `k8s/manifests/`. У день 5 вони
стануть Helm chart.

**Tech Stack:** kind 0.33, kubectl 1.37, Kubernetes 1.37 (node image kind), PostgreSQL 17,
образ `notes-api:local` (день 2).

**Spec:** `docs/specs/2026-10-14-laravel-k8s-aws-design.md` (секції 3, 4.1, 4.2, 10 — день 3).
Терміни: `docs/specs/glossary.md`, секція 6.

**Перевірено:** Claude виконав кожну команду цього плану 2026-10-17 у двох ізольованих
кластерах (kind 0.33.0, kubectl 1.37.1, Docker 29.9). Secret створювався з вашого реального
`.env`. Очікувані результати нижче взято з цих запусків.

## Global Constraints

- Кластер: назва `notes`, context `kind-notes`, конфігурація `k8s/kind-config.yaml`.
- Порти 80 та 443 на `127.0.0.1` зарезервовані для Traefik (день 4). Їх можна задати лише
  під час створення кластера. Якщо ви видалите кластер, дані PVC зникнуть.
- Усі ресурси застосунку живуть у namespace `notes`.
- Образ: `notes-api:local`, `imagePullPolicy: IfNotPresent`. Не використовуйте тег `latest`:
  для нього Kubernetes за замовчуванням ставить `Always` і отримує `ErrImagePull`.
- Контейнер API працює як uid 33 (`www-data`) з `runAsNonRoot: true`.
- Secret `notes-secrets` має ключі `APP_KEY` та `DB_PASSWORD`. Значення беруться з кореневого
  `.env`. Secret ніколи не потрапляє в git.
- У кластері `APP_ENV=production`, `APP_DEBUG=false`.
- Міграції в день 3 запускаються вручну (`kubectl exec`). Job для міграцій буде в дні 4.
- `kubectl port-forward` використовує локальний порт `8090`. Порт 8080 зайнятий compose
  дня 2, порт 8081 зайнятий іншим проєктом.
- Ваш shell — zsh. Аргументи з `[*]` або `{...}` (jsonpath, custom-columns) беріть у
  одинарні лапки. Інакше zsh видає `no matches found`.
- Робота дня йде в гілці `day-03-k8s`. У `main` зміни потрапляють лише через pull request.

## Review Focus

1. БД зупинена → pods API мають `READY 0/1`, але `RESTARTS` залишається 0 навіть через
   3 хвилини. Liveness не залежить від БД. Перевірка в Task 4, Step 2.
2. `runAsNonRoot: true` з `USER www-data` в образі → `CreateContainerConfigError`. Kubelet
   не може перевірити ім'я користувача, йому потрібен числовий uid. Перевірка в Task 3,
   Steps 4-5.
3. Відомий розрив: до міграцій pods мають `READY 1/1`, а `GET /api/notes` повертає 500.
   `/ready` перевіряє з'єднання з БД, а не схему. Job у дні 4 закриває цей розрив.
   Перевірка в Task 3, Step 7.
4. Дані переживають видалення pod `postgres-0` та `scale 0 → 1`, бо живуть у PVC
   `data-postgres-0`. Перевірка в Task 4, Steps 2 та 4.
5. Secret не потрапляє в git: його створює команда з pipe, а не файл у репозиторії.
   `git status` після Task 2 не показує нових файлів із секретами. Перевірка в Task 2,
   Step 5.

---

### Task 1: Інструменти та кластер kind

**Files:**
- Create: `k8s/kind-config.yaml`

**Interfaces:**
- Produces (для всіх наступних task та днів 4-8):
  - кластер `notes`, context `kind-notes`;
  - host `127.0.0.1:80` → NodePort `30080`, host `127.0.0.1:443` → NodePort `30443`
    (Traefik, день 4);
  - StorageClass `standard` (provisioner `rancher.io/local-path`, reclaim policy `Delete`).

- [ ] **Step 1: Створіть гілку та закомітьте план**

`main` захищений, тому план дня 3 теж іде через гілку.

```bash
cd ~/laravel-k8s-aws
git switch main && git pull
git switch -c day-03-k8s
git add docs/plans/2026-10-17-day-03-kind-and-manifests.md
git commit -m "docs: add day 3 plan (kind, raw manifests)"
```

- [ ] **Step 2: Встановіть kind та kubectl**

```bash
sudo pacman -S kind kubectl
kind version
kubectl version --client
```

Очікуваний результат: `kind v0.33.0`, `Client Version: v1.37.1`.

Необов'язково: автодоповнення для zsh. Додайте рядок у `~/.zshrc`:

```bash
source <(kubectl completion zsh)
```

- [ ] **Step 3: Створіть конфігурацію кластера**

`k8s/kind-config.yaml`:

```yaml
# Local cluster for days 3-8. Recreate = all data in PVCs is lost.
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
name: notes
nodes:
  - role: control-plane
    # Day 4: Traefik listens on these NodePorts. Port mappings can only be set at cluster creation.
    extraPortMappings:
      - containerPort: 30080
        hostPort: 80
        listenAddress: "127.0.0.1"
        protocol: TCP
      - containerPort: 30443
        hostPort: 443
        listenAddress: "127.0.0.1"
        protocol: TCP
```

Чому `extraPortMappings` вже зараз: node kind — це Docker-контейнер. Docker публікує порти
контейнера лише під час його створення. Без цих рядків у дні 4 вам треба буде перестворити
кластер, і ви втратите дані PostgreSQL.

- [ ] **Step 4: Створіть кластер**

```bash
cd ~/laravel-k8s-aws
kind create cluster --config k8s/kind-config.yaml
kubectl config current-context
kubectl get nodes
kubectl get storageclass
```

Очікуваний результат:
- context `kind-notes`;
- node `notes-control-plane` має статус `Ready`, версія `v1.37.0`;
- StorageClass `standard (default)` з provisioner `rancher.io/local-path`.

`kind create cluster` записує доступ до кластера в `~/.kube/config` і перемикає context на
`kind-notes`.

- [ ] **Step 5: Закомітьте**

```bash
git add k8s/kind-config.yaml
git commit -m "build(k8s): add kind cluster config with ports for ingress"
```

---

### Task 2: Namespace, Secret та PostgreSQL

**Files:**
- Create: `k8s/manifests/00-namespace.yaml`
- Create: `k8s/manifests/10-postgres-service.yaml`
- Create: `k8s/manifests/11-postgres-statefulset.yaml`

**Interfaces:**
- Consumes: кластер `kind-notes` (Task 1), `APP_KEY` та `DB_PASSWORD` з кореневого `.env`
  (день 2).
- Produces (для Task 3):
  - namespace `notes`;
  - Secret `notes-secrets` з ключами `APP_KEY` та `DB_PASSWORD`;
  - БД доступна в кластері як `postgres:5432`, база `notes`, користувач `notes`.

Чому числа в назвах файлів: `kubectl apply -f k8s/manifests/` обробляє файли за абеткою.
Namespace має існувати раніше за ресурси в ньому.

- [ ] **Step 1: Створіть namespace**

`k8s/manifests/00-namespace.yaml`:

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: notes
```

```bash
kubectl apply -f k8s/manifests/00-namespace.yaml
```

- [ ] **Step 2: Створіть Secret з кореневого `.env`**

```bash
cd ~/laravel-k8s-aws
kubectl -n notes create secret generic notes-secrets \
  --from-literal=APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)" \
  --from-literal=DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)" \
  --dry-run=client -o yaml | kubectl apply -f -
```

Як це працює: `create ... --dry-run=client -o yaml` не створює Secret, а лише друкує YAML.
`kubectl apply -f -` читає цей YAML з pipe. YAML не потрапляє на диск і в git. Команду
можна запускати повторно: `apply` оновить Secret, а не впаде з `already exists`.

`cut -d= -f2-` бере все після першого `=`. Це важливо для `APP_KEY`: base64-значення може
мати `=` в кінці.

- [ ] **Step 3: Створіть headless Service для PostgreSQL**

`k8s/manifests/10-postgres-service.yaml`:

```yaml
# Headless Service (clusterIP: None): gives the StatefulSet pod a stable DNS name
# postgres-0.postgres.notes.svc.cluster.local. The app connects to "postgres".
apiVersion: v1
kind: Service
metadata:
  name: postgres
  namespace: notes
spec:
  clusterIP: None
  selector:
    app: postgres
  ports:
    - name: postgres
      port: 5432
      targetPort: 5432
```

- [ ] **Step 4: Створіть StatefulSet для PostgreSQL**

`k8s/manifests/11-postgres-statefulset.yaml`:

```yaml
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: postgres
  namespace: notes
spec:
  serviceName: postgres
  replicas: 1
  selector:
    matchLabels:
      app: postgres
  template:
    metadata:
      labels:
        app: postgres
    spec:
      containers:
        - name: postgres
          image: postgres:17-alpine
          ports:
            - name: postgres
              containerPort: 5432
          env:
            - name: POSTGRES_DB
              value: notes
            - name: POSTGRES_USER
              value: notes
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: notes-secrets
                  key: DB_PASSWORD
            # Subdirectory: initdb refuses a mount point that is not empty (lost+found).
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          readinessProbe:
            exec:
              command: ["pg_isready", "-U", "notes", "-d", "notes"]
            periodSeconds: 5
          volumeMounts:
            - name: data
              mountPath: /var/lib/postgresql/data
  # One PVC per pod (data-postgres-0). It survives pod deletion and StatefulSet deletion.
  volumeClaimTemplates:
    - metadata:
        name: data
      spec:
        accessModes: ["ReadWriteOnce"]
        resources:
          requests:
            storage: 1Gi
```

Контейнер PostgreSQL не має `runAsNonRoot`. Офіційний образ стартує як root, готує права на
каталог даних і потім сам перемикається на користувача `postgres`.

- [ ] **Step 5: Застосуйте та перевірте**

```bash
kubectl apply -f k8s/manifests/
kubectl -n notes rollout status statefulset/postgres --timeout=120s
kubectl -n notes get pods,pvc
kubectl -n notes exec postgres-0 -- psql -U notes -d notes -c 'select version()'
git status --short
```

Очікуваний результат:
- `postgres-0` має `READY 1/1`;
- PVC `data-postgres-0` має статус `Bound`, `1Gi`, StorageClass `standard`;
- `psql` друкує `PostgreSQL 17...`;
- `git status` показує лише `?? k8s/manifests/`. У цьому каталозі 3 YAML-файли, файлу із
  секретом немає.

Якщо `postgres-0` має статус `CreateContainerConfigError`, Secret не існує. Виконайте
Step 2 і зачекайте. Kubelet повторить спробу сам.

- [ ] **Step 6: Переконайтеся, що Secret — це не шифрування**

```bash
kubectl -n notes get secret notes-secrets -o jsonpath='{.data.DB_PASSWORD}' | base64 -d; echo
```

Очікуваний результат: ваш пароль з `.env` відкритим текстом. Secret зберігає значення в
base64, а не шифрує їх. Будь-хто з правом `get secrets` у namespace бачить пароль. Це
питання теорії (Task 5).

- [ ] **Step 7: Закомітьте**

```bash
git add k8s/manifests/
git commit -m "feat(k8s): add namespace and PostgreSQL StatefulSet"
```

---

### Task 3: API: ConfigMap, Deployment, Service

**Files:**
- Create: `k8s/manifests/20-app-configmap.yaml`
- Create: `k8s/manifests/21-app-deployment.yaml`
- Create: `k8s/manifests/22-app-service.yaml`

**Interfaces:**
- Consumes: Secret `notes-secrets`, Service `postgres` (Task 2), образ `notes-api:local`
  (день 2), `GET /health` та `GET /ready` (день 2).
- Produces (для дня 4: Ingress, Job, HPA; для дня 5: Helm):
  - Deployment `notes-api`, label `app: notes-api`, container `app`, port `http` (8080);
  - Service `notes-api`, тип `ClusterIP`, port `80` → `http`;
  - ConfigMap `notes-api` з несекретними налаштуваннями.

- [ ] **Step 1: Зберіть свіжий образ та завантажте його в кластер**

```bash
cd ~/laravel-k8s-aws
docker build -t notes-api:local app
kind load docker-image notes-api:local --name notes
docker exec notes-control-plane crictl images | grep notes-api
```

Очікуваний результат: `crictl` показує `docker.io/library/notes-api   local`.

Чому `kind load`: node kind — це окремий Docker-контейнер зі своїм containerd. Він не бачить
образи вашого Docker. `kind load` копіює образ у containerd node. Після кожного
`docker build` команду треба повторити.

- [ ] **Step 2: Створіть ConfigMap**

`k8s/manifests/20-app-configmap.yaml`:

```yaml
# Non-secret settings. Secrets (APP_KEY, DB_PASSWORD) live in the Secret notes-secrets.
apiVersion: v1
kind: ConfigMap
metadata:
  name: notes-api
  namespace: notes
data:
  APP_ENV: production
  APP_DEBUG: "false"
  LOG_CHANNEL: stderr
  SESSION_DRIVER: array
  CACHE_STORE: array
  QUEUE_CONNECTION: sync
  DB_CONNECTION: pgsql
  DB_HOST: postgres
  DB_PORT: "5432"
  DB_DATABASE: notes
  DB_USERNAME: notes
```

`DB_HOST: postgres` — це ім'я Service з Task 2. DNS кластера перетворює його на IP pod
`postgres-0`.

- [ ] **Step 3: Створіть Service для API**

`k8s/manifests/22-app-service.yaml`:

```yaml
apiVersion: v1
kind: Service
metadata:
  name: notes-api
  namespace: notes
spec:
  type: ClusterIP
  selector:
    app: notes-api
  ports:
    - name: http
      port: 80
      targetPort: http
```

`targetPort: http` — це ім'я порту контейнера з Deployment. Якщо порт контейнера зміниться,
Service змінювати не треба.

- [ ] **Step 4: Створіть Deployment (перша версія)**

`k8s/manifests/21-app-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: notes-api
  namespace: notes
spec:
  replicas: 2
  selector:
    matchLabels:
      app: notes-api
  template:
    metadata:
      labels:
        app: notes-api
    spec:
      securityContext:
        runAsNonRoot: true
      containers:
        - name: app
          image: notes-api:local
          imagePullPolicy: IfNotPresent
          ports:
            - name: http
              containerPort: 8080
          envFrom:
            - configMapRef:
                name: notes-api
            - secretRef:
                name: notes-secrets
          livenessProbe:
            httpGet:
              path: /health
              port: http
            periodSeconds: 10
            failureThreshold: 3
          readinessProbe:
            httpGet:
              path: /ready
              port: http
            periodSeconds: 5
            failureThreshold: 2
```

```bash
kubectl apply -f k8s/manifests/
kubectl -n notes get pods -l app=notes-api
```

Очікуваний результат: обидва pods мають статус `CreateContainerConfigError`. Це навмисна
помилка. Наступний крок пояснює її.

- [ ] **Step 5: Знайдіть причину та виправте**

```bash
kubectl -n notes get events --field-selector reason=Failed \
  -o custom-columns=MSG:.message --no-headers | sort -u
```

Очікуваний результат (по рядку на кожен pod):

```
Error: container has runAsNonRoot and image has non-numeric user (www-data), cannot verify user is non-root (pod: ...)
```

`kubectl describe pod <name>` для одного pod теж показує цю помилку в блоці Events. Але
`describe` з `-l` для кількох pods блок Events не друкує.

Kubelet бачить в образі `USER www-data` (`app/Dockerfile:23`). Ім'я користувача він не може
перевірити: uid для імені визначає `/etc/passwd` всередині образу. Тому kubelet відмовляється
запускати контейнер. Рішення — вказати числовий uid у маніфесті. Uid `www-data` в образі
дорівнює 33 (перевірка: `docker run --rm --entrypoint id notes-api:local www-data`).

Зміна в `k8s/manifests/21-app-deployment.yaml`:

```diff
       securityContext:
         runAsNonRoot: true
+        # Numeric uid of www-data: kubelet cannot check a user name from the image.
+        runAsUser: 33
+        runAsGroup: 33
```

```bash
kubectl apply -f k8s/manifests/21-app-deployment.yaml
kubectl -n notes rollout status deploy/notes-api --timeout=180s
kubectl -n notes get pods -l app=notes-api
```

Очікуваний результат: обидва pods мають `READY 1/1`, `RESTARTS 0`.

- [ ] **Step 6: Відкрийте API через port-forward**

Відкрийте другий термінал і залиште команду працювати:

```bash
kubectl -n notes port-forward svc/notes-api 8090:80
```

У першому терміналі:

```bash
curl -s localhost:8090/health; echo
curl -s localhost:8090/ready; echo
curl -s -H 'Accept: application/json' localhost:8090/api/notes; echo
```

Очікуваний результат:
- `/health` → `{"status":"ok"}`;
- `/ready` → `{"status":"ok"}`;
- `/api/notes` → 500 `{"message": "Server Error"}`.

- [ ] **Step 7: Знайдіть причину 500 у логах**

```bash
kubectl -n notes logs -l app=notes-api --tail=-1 --prefix | grep -m1 'does not exist'
```

Очікуваний результат: рядок з `[pod/notes-api-.../app]` та `relation "notes" does not exist`.

Чому `-l` та `--tail=-1`:
- `port-forward` та `logs deploy/...` обирають pod кожен по-своєму. Помилку міг записати
  інший pod. `-l app=notes-api` читає логи всіх pods, а `--prefix` показує, який pod
  записав рядок.
- З `-l` kubectl за замовчуванням показує лише 10 останніх рядків кожного pod. Laravel пише
  stack trace на десятки рядків, тому рядок з помилкою опиняється вище. `--tail=-1` показує
  весь лог. Таблиць ще немає. Це
відомий розрив з Review Focus, пункт 3: `/ready` перевіряє з'єднання з БД, але не схему.
`APP_DEBUG=false`, тому деталі помилки є лише в логах, а клієнт бачить загальний текст.

- [ ] **Step 8: Запустіть міграції вручну**

```bash
kubectl -n notes exec deploy/notes-api -- php artisan migrate --force
```

Очікуваний результат: `Creating migration table` та 4 міграції мають статус `DONE`, серед
них `2026_10_14_171724_create_notes_table`.

`--force` потрібен, бо `APP_ENV=production`. Без нього artisan питає підтвердження.
`exec deploy/notes-api` обирає один pod Deployment. У дні 4 цей крок замінить Job.

- [ ] **Step 9: Перевірте CRUD**

```bash
curl -s -H 'Accept: application/json' -X POST -d title=hello -d body=k8s localhost:8090/api/notes; echo
curl -s -H 'Accept: application/json' localhost:8090/api/notes; echo
```

Очікуваний результат: POST повертає 201 і нотатку з `"id":1`. GET повертає список з цією
нотаткою. Залиште її: Task 4 перевіряє, що вона переживе перезапуск БД.

- [ ] **Step 10: Закомітьте**

```bash
git add k8s/manifests/
git commit -m "feat(k8s): add notes-api Deployment, Service and ConfigMap"
```

---

### Task 4: Drills: як кластер реагує на збої

**Files:** немає. Цей task перевіряє поведінку і дає матеріал для теорії.

**Interfaces:**
- Consumes: усі ресурси з Task 2 та Task 3, нотатка з Task 3, Step 9.

- [ ] **Step 1: Зупиніть port-forward**

У другому терміналі натисніть `Ctrl+C`.

- [ ] **Step 2: БД впала: readiness проти liveness**

```bash
kubectl -n notes scale statefulset/postgres --replicas=0
sleep 180
kubectl -n notes get pods
kubectl -n notes get endpointslices -l kubernetes.io/service-name=notes-api \
  -o 'custom-columns=NAME:.metadata.name,READY:.endpoints[*].conditions.ready'
```

Очікуваний результат через 3 хвилини:
- обидва pods `notes-api` мають `READY 0/1`, `STATUS Running`, `RESTARTS 0`;
- `postgres-0` немає;
- EndpointSlice показує `false,false`: Service не надсилає трафік на жоден pod.

Чому треба чекати 3 хвилини: liveness перевіряє pod кожні 10 секунд. Якщо `/health`
залежав би від БД, kubelet перезапустив би контейнери вже через 30 секунд. `RESTARTS 0`
через 3 хвилини доводить, що liveness від БД не залежить.

Поверніть БД і перевірте дані:

```bash
kubectl -n notes scale statefulset/postgres --replicas=1
kubectl -n notes rollout status statefulset/postgres --timeout=120s
sleep 15
kubectl -n notes get pods
kubectl -n notes exec postgres-0 -- psql -U notes -d notes -tAc 'select count(*) from notes'
```

Очікуваний результат: усі pods мають `READY 1/1`. `count` дорівнює 1. Pod `postgres-0`
новий, але PVC `data-postgres-0` той самий, тому нотатка збереглася.

- [ ] **Step 3: Видаліть pod API, до якого підключений port-forward**

```bash
P=$(kubectl -n notes get pods -l app=notes-api -o name | head -1)
echo $P
kubectl -n notes port-forward $P 8090:8080 &
PF=$!
sleep 3
curl -s localhost:8090/health; echo
time kubectl -n notes delete $P
curl -s -m 5 localhost:8090/health || echo "connection failed"
kill $PF 2>/dev/null
kubectl -n notes get pods -l app=notes-api
```

Очікуваний результат:
- перший `curl` повертає `{"status":"ok"}`;
- port-forward друкує `error: lost connection to pod`, другий `curl` друкує
  `connection failed`;
- `kubectl delete` триває ~30 секунд;
- Deployment вже створив новий pod з іншою назвою. Знову 2 pods `READY 1/1`.

Два висновки для теорії:
- `port-forward` до Service підключається до одного pod. Він не балансує трафік і не
  перемикається на інший pod. Це інструмент для debug, а не для продакшн-трафіку.
- Видалення триває ~30 секунд, бо це значення `terminationGracePeriodSeconds` за
  замовчуванням. Kubelet надсилає SIGTERM, чекає 30 секунд і потім надсилає SIGKILL.
  Чому контейнер не завершився раніше, розберемо в дні 4 (rolling update).

- [ ] **Step 4: Видаліть pod PostgreSQL**

```bash
kubectl -n notes delete pod postgres-0
kubectl -n notes wait --for=condition=Ready pod/postgres-0 --timeout=90s
kubectl -n notes exec postgres-0 -- psql -U notes -d notes -tAc 'select count(*) from notes'
kubectl -n notes get pvc
```

Очікуваний результат:
- новий pod має ту саму назву `postgres-0` (у Deployment назва була б нова);
- `count` дорівнює 1;
- PVC `data-postgres-0` має той самий `VOLUME`, що й у Task 2.

Не видаляйте PVC. StorageClass `standard` має reclaim policy `Delete`: разом з PVC зникнуть
і дані.

---

### Task 5: Теорія та pull request

**Files:**
- Modify: `docs/interview-notes.md`

- [ ] **Step 1: Додайте розділ дня 3**

Додайте в кінець `docs/interview-notes.md`:

```markdown
## Day 3: Kubernetes basics

1. Describe the Pod lifecycle: phases, what happens on `kubectl delete pod`.
   Why did the delete take ~30 seconds in Task 4, Step 3?
2. When do you need a startupProbe in addition to liveness and readiness?
   What would happen in Task 4, Step 2 if /health checked the database?
3. Deployment vs StatefulSet: why does PostgreSQL run as a StatefulSet?
   What was different after you deleted the notes-api pod and the postgres-0 pod?
4. Is a Kubernetes Secret secure? What did you see in Task 2, Step 6?
   What do you use in production instead?
```

- [ ] **Step 2: Дайте відповіді**

- Q1-Q3: формат A, по 50 хвилин (ліміт змінено 2026-10-21, див. spec, розділ 10). Напишіть у сесії Claude: "Поясни Q1 дня 3 у форматі A".
- Q4: 10 хвилин, без пояснення. Ви бачили механізм у Task 2, Step 6.

Після кожної відповіді попросіть review.

- [ ] **Step 3: Закомітьте та відкрийте pull request**

```bash
cd ~/laravel-k8s-aws
git add docs/interview-notes.md
git commit -m "docs: add day 3 interview notes (Kubernetes basics)"
git push -u origin day-03-k8s
gh pr create --fill --base main
gh pr checks --watch
gh pr merge --merge --delete-branch
git switch main && git pull
```

`gh pr checks --watch` чекає, поки `app` та `docker` завершаться. Тільки після зелених
checks ruleset дозволяє merge. CI ще не перевіряє маніфести. Це зробить `helm lint` у дні 5.

---

## Результат дня 3

- [ ] `kubectl config current-context` показує `kind-notes`.
- [ ] `kubectl -n notes get pods` показує 2 pods `notes-api` та `postgres-0`, усі `1/1`,
      `RESTARTS 0`.
- [ ] Через `kubectl port-forward svc/notes-api 8090:80` API створює та повертає нотатки.
- [ ] БД зупинена 3 хвилини → pods API `0/1`, `RESTARTS 0`.
- [ ] Нотатка пережила `scale 0 → 1` та видалення `postgres-0`.
- [ ] `docs/interview-notes.md` має розділ "Day 3" з 4 відповідями.
- [ ] PR дня 3 змержений, гілку видалено.

Кластер не видаляйте. Дні 4-8 працюють у ньому.
