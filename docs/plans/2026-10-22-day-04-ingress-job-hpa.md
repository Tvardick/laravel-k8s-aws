# День 4: Ingress, Job для міграцій, HPA та rolling update — план реалізації

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> The author implements all tasks personally (learning project). Claude explains and reviews.

**Goal:** API відповідає на `http://notes.localhost` через Traefik. Міграції запускає Job.
HPA додає pods під навантаженням. Rolling update проходить без помилок для клієнтів.

**Architecture:** Traefik встановлюється Helm-чартом у namespace `traefik` як Service типу
`NodePort` (30080/30443). Порти вже відкриті в `k8s/kind-config.yaml` з дня 3. Ingress
`notes-api` направляє host `notes.localhost` на Service `notes-api`. Job `notes-api-migrate`
замінює ручний `php artisan migrate`. metrics-server дає HPA дані про CPU. Образ отримує
`STOPSIGNAL SIGTERM`, а Deployment — `preStop`, щоб pods завершувалися швидко і без втрати
запитів.

**Tech Stack:** Helm 4.3.0, Traefik chart 42.1.1 (Traefik v3.7.16), metrics-server chart
3.14.0 (metrics-server 0.9.0), Kubernetes 1.37 (kind 0.33), busybox 1.37.

**Spec:** `docs/specs/2026-10-14-laravel-k8s-aws-design.md` (секції 4.1, 4.2, 10 — день 4).
Терміни: `docs/specs/glossary.md`, секція 6.

**Перевірено:** Claude виконав кожну команду цього плану 2026-10-21/22 в ізольованому кластері
kind (окремий `KUBECONFIG`, host-порти 18080/18443 замість 80/443). Ваш кластер `notes` не
змінювався. Очікувані результати нижче взято з цих запусків. Виняток: у Task 4, Step 3 Claude
розгортав новий образ з окремим тегом, а не з тим самим `notes-api:local`. Тому Step 3 має
самоперевірку: чи pods справді отримали новий образ.

## Global Constraints

- Кластер: `kind-notes` з дня 3. Не перестворюйте його: дані PostgreSQL живуть у PVC.
- Traefik: namespace `traefik`, release `traefik`, NodePort `30080` (HTTP) та `30443`
  (HTTPS). Ці числа мають збігатися з `k8s/kind-config.yaml`.
- Host API: `notes.localhost`, тільки HTTP. TLS не входить у день 4.
- Версії чартів зафіксовані: Traefik `42.1.1`, metrics-server `3.14.0`. Без `--version`
  Helm бере найновішу версію, і результат може відрізнятися від плану.
- `--kubelet-insecure-tls` для metrics-server — тільки для kind. В EKS цей прапорець не
  потрібен.
- Образ: `notes-api:local`. Після кожного `docker build` виконайте `kind load` та
  `kubectl rollout restart`. Тег не змінюється, тому `kubectl apply` сам pods не оновить.
- У Deployment немає поля `replicas`. Кількістю pods керує HPA (`minReplicas: 2`).
- Drills з помилками працюють у namespace `drills`. Наприкінці Task 5 його треба видалити.
- Ваш shell — zsh. Аргументи з `[*]`, `{...}` або `$` беріть в одинарні лапки.
- Робота дня йде в гілці `day-04-ingress-hpa`. У `main` зміни потрапляють лише через PR.
- Теорія: 2 складених питання у форматі A, по 50 хвилин (spec, секція 10).

## Review Focus

1. HPA створено раніше, ніж контейнер отримав `requests.cpu` → `TARGETS` показує
   `<unknown>`, а `describe hpa` показує `missing request for cpu`. HPA рахує відсоток від
   request. Перевірка в Task 3, Steps 3-4.
2. Deployment має `replicas: 2`, а HPA вже додав pods → кожен `kubectl apply` скидає
   кількість pods до 2. Рішення — прибрати поле. Одноразовий ефект: replicas стає 1, потім
   HPA повертає 2. Перевірка в Task 3, Step 4.
3. Новий образ з тим самим тегом → `kubectl apply` нічого не змінює, pods працюють зі
   старим образом. Потрібен `kubectl rollout restart`. Перевірка в Task 4, Step 3.
4. Швидке завершення (SIGTERM) без `preStop` → під час rolling update Traefik повертає
   клієнтам `502`. Перевірка в Task 4, Steps 3-4.
5. Job незмінний (immutable). `kubectl apply` зі зміненим `template` падає з
   `field is immutable`. Повторний запуск = видалити Job і створити знову. Перевірка в
   Task 2, Step 4.

---

### Task 1: Helm, Traefik та Ingress

**Files:**
- Create: `k8s/traefik-values.yaml`
- Create: `k8s/manifests/23-app-ingress.yaml`

**Interfaces:**
- Consumes: кластер `kind-notes` і порти `127.0.0.1:80/443` → NodePort `30080/30443`
  (день 3, Task 1), Service `notes-api` з портом `http` (день 3, Task 3).
- Produces (для Task 3, 4 та днів 5-9):
  - IngressClass `traefik` (default);
  - `http://notes.localhost` → Service `notes-api`.

- [ ] **Step 1: Створіть гілку та закомітьте план**

Спершу змерджіть PR дня 3 (#3). Гілка дня 4 має починатися з `main`, де вже є маніфести
дня 3.

У робочому дереві вже є незакомічені зміни spec (Theory backlog для дня 4) та glossary
(терміни днів 3-4). Ваш локальний `main` має іншу версію spec, тому `git switch main`
відмовиться перемикатися. `git stash` тимчасово ховає зміну, а `git stash pop` повертає її
вже на новій гілці. Glossary однаковий у `main` і `day-03-k8s`, тому git переносить його
без stash.

```bash
cd ~/laravel-k8s-aws
git stash push -- docs/specs/2026-10-14-laravel-k8s-aws-design.md
git switch main && git pull
git switch -c day-04-ingress-hpa
git stash pop
git add docs/plans/2026-10-22-day-04-ingress-job-hpa.md docs/specs/2026-10-14-laravel-k8s-aws-design.md \
  docs/specs/glossary.md
git commit -m "docs: add day 4 plan, theory backlog and glossary terms for days 3-4"
```

- [ ] **Step 2: Встановіть Helm**

```bash
sudo pacman -S helm
helm version
```

Очікуваний результат: `Version:"v4.3.0"`.

Helm — це менеджер пакетів для Kubernetes. Чарт — це набір шаблонів маніфестів. Helm
підставляє у шаблони ваші значення (values) і застосовує результат у кластер. Сьогодні ви
тільки встановлюєте чужі чарти. Власний чарт ви напишете в дні 5.

- [ ] **Step 3: Створіть values для Traefik**

`k8s/traefik-values.yaml`:

```yaml
# Traefik for the local kind cluster. kind-config.yaml maps 127.0.0.1:80/443 to these NodePorts.
service:
  spec:
    type: NodePort
ports:
  web:
    nodePort: 30080
  websecure:
    nodePort: 30443
```

Чому `NodePort`: за замовчуванням чарт створює Service типу `LoadBalancer`. У kind немає
хмари, яка створить балансувальник, тому такий Service залишиться в `Pending`. NodePort
відкриває порт на node, а kind уже публікує цей порт на ваш `127.0.0.1`.

- [ ] **Step 4: Встановіть Traefik**

```bash
helm repo add traefik https://traefik.github.io/charts
helm repo update
helm install traefik traefik/traefik --version 42.1.1 \
  -n traefik --create-namespace -f k8s/traefik-values.yaml --wait --timeout 3m
kubectl -n traefik get pods,svc
kubectl get ingressclass
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/
```

Очікуваний результат:
- `helm install` друкує `STATUS: deployed`;
- pod `traefik-...` має `READY 1/1`;
- Service `traefik` має тип `NodePort` і порти `80:30080/TCP,443:30443/TCP`;
- IngressClass `traefik (default)`;
- `curl` друкує `404`. Відповідає Traefik: він працює, але ще не має жодного маршруту.

- [ ] **Step 5: Створіть Ingress**

`k8s/manifests/23-app-ingress.yaml`:

```yaml
# Traefik routes http://notes.localhost to the Service notes-api.
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: notes-api
  namespace: notes
spec:
  ingressClassName: traefik
  rules:
    - host: notes.localhost
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: notes-api
                port:
                  name: http
```

```bash
kubectl apply -f k8s/manifests/23-app-ingress.yaml
kubectl -n notes get ingress
curl -s http://notes.localhost/health; echo
curl -s -H 'Accept: application/json' http://notes.localhost/api/notes; echo
```

Очікуваний результат:
- Ingress `notes-api`, `CLASS traefik`, `HOSTS notes.localhost`;
- `/health` → `{"status":"ok"}`;
- `/api/notes` → JSON зі списком. У ньому є ваша нотатка з дня 3 (`"id":1`).

`notes.localhost` не потребує запису в `/etc/hosts`. curl сам направляє будь-яке ім'я
`*.localhost` на loopback.

`port.name: http` — це ім'я порту Service `notes-api`. Якщо номер порту зміниться, Ingress
змінювати не треба.

- [ ] **Step 6: Подивіться, куди Traefik надсилає запити**

```bash
for i in $(seq 1 20); do curl -s -o /dev/null http://notes.localhost/health; done
kubectl -n notes get svc notes-api
kubectl -n notes get pods -l app=notes-api -o wide
kubectl -n traefik exec deploy/traefik -- netstat -tn | grep ':8080 '
```

Очікуваний результат: у колонці адрес `netstat` є IP-адреси pods `notes-api` з портом
`8080`. Адреси `CLUSTER-IP` Service `notes-api` там немає.

Це матеріал для теорії (Q1). Traefik читає EndpointSlices і з'єднується з pods напряму. Він
не використовує ClusterIP і kube-proxy. Service для Traefik — це тільки список адрес pods.

- [ ] **Step 7: Закомітьте**

```bash
git add k8s/traefik-values.yaml k8s/manifests/23-app-ingress.yaml
git commit -m "feat(k8s): add Traefik values and Ingress for notes-api"
```

---

### Task 2: Job для міграцій

**Files:**
- Create: `k8s/manifests/30-migrate-job.yaml`

**Interfaces:**
- Consumes: образ `notes-api:local`, ConfigMap `notes-api`, Secret `notes-secrets`
  (день 3).
- Produces (для дня 5: Helm hook, дня 7: ArgoCD PreSync): Job `notes-api-migrate`,
  container `migrate`, команда `php artisan migrate --force`.

- [ ] **Step 1: Створіть Job**

`k8s/manifests/30-migrate-job.yaml`:

```yaml
# Runs migrations once. A Job is immutable: to run it again, delete it first.
apiVersion: batch/v1
kind: Job
metadata:
  name: notes-api-migrate
  namespace: notes
spec:
  backoffLimit: 4
  template:
    metadata:
      labels:
        app: notes-api-migrate
    spec:
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 33
        runAsGroup: 33
      containers:
        - name: migrate
          image: notes-api:local
          imagePullPolicy: IfNotPresent
          command: ["php", "artisan", "migrate", "--force"]
          envFrom:
            - configMapRef:
                name: notes-api
            - secretRef:
                name: notes-secrets
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              memory: 256Mi
```

Пояснення до полів:
- `command` замінює і `ENTRYPOINT`, і `CMD` образу. Скрипти старту nginx та php-fpm не
  запускаються. Job потрібен тільки `php`.
- `restartPolicy: Never` — після помилки Job створює **новий** pod, а не перезапускає
  контейнер. Тому кожна спроба має окремі логи.
- `backoffLimit: 4` — після 4 невдалих спроб Job отримує статус `Failed`. Наприклад, якщо БД
  недоступна.
- `resources` — ті самі значення, що й у Deployment (Task 3). Без них Job конкурує за CPU з
  іншими pods без жодних гарантій.

- [ ] **Step 2: Запустіть Job**

```bash
kubectl apply -f k8s/manifests/30-migrate-job.yaml
kubectl -n notes wait --for=condition=Complete job/notes-api-migrate --timeout=120s
kubectl -n notes get job,pods -l app=notes-api-migrate
kubectl -n notes logs job/notes-api-migrate
```

Очікуваний результат:
- `job.batch/notes-api-migrate condition met`;
- Job має `STATUS Complete`, `COMPLETIONS 1/1`;
- pod `notes-api-migrate-...` має `STATUS Completed`;
- лог: `INFO  Nothing to migrate.`

`Nothing to migrate` — це правильний результат. У дні 3 ви вже запустили міграції вручну.
На порожній БД Job виконує всі 4 міграції, і кожна має статус `DONE`. Claude перевірив це
на порожній схемі.

- [ ] **Step 3: Перевірте, що повторний apply нічого не запускає**

```bash
kubectl apply -f k8s/manifests/
```

Очікуваний результат: усі ресурси `unchanged`, серед них `job.batch/notes-api-migrate`.
Завершений Job залишається в кластері. Новий pod міграції не з'являється.

- [ ] **Step 4: Перевірте, що Job незмінний**

```bash
sed 's/memory: 256Mi/memory: 300Mi/' k8s/manifests/30-migrate-job.yaml \
  | kubectl apply -f - 2>&1 | tail -c 120; echo
```

Очікуваний результат: помилка, яка закінчується на `field is immutable`. Файл у репозиторії
не змінився: `sed` друкує змінену копію в pipe.

Чому так: `template` Job описує pod, який уже відпрацював. Kubernetes забороняє його
змінювати. Щоб запустити міграції з новим образом, Job треба видалити і створити знову:

```bash
kubectl -n notes delete job notes-api-migrate
kubectl apply -f k8s/manifests/30-migrate-job.yaml
kubectl -n notes wait --for=condition=Complete job/notes-api-migrate --timeout=120s
```

Цей ручний цикл «видалити → створити» у дні 5 автоматизує Helm hook, а в дні 7 — ArgoCD
PreSync hook.

Відомий розрив: `kubectl apply -f k8s/manifests/` не чекає на Job. Pods нової версії
можуть стартувати раніше, ніж міграції завершаться. Hooks у днях 5 і 7 закривають цей
розрив: вони запускають міграції **до** оновлення Deployment.

- [ ] **Step 5: Закомітьте**

```bash
git add k8s/manifests/30-migrate-job.yaml
git commit -m "feat(k8s): add migration Job"
```

---

### Task 3: Requests/limits, metrics-server та HPA

**Files:**
- Create: `k8s/metrics-server-values.yaml`
- Create: `k8s/manifests/24-app-hpa.yaml`
- Modify: `k8s/manifests/21-app-deployment.yaml`

**Interfaces:**
- Consumes: Deployment `notes-api` (день 3).
- Produces (для Task 4 та дня 8: моніторинг):
  - контейнер `app`: requests `cpu: 100m`, `memory: 128Mi`, limit `memory: 256Mi`;
  - HPA `notes-api`: `minReplicas: 2`, `maxReplicas: 5`, CPU `70%`;
  - `kubectl top` працює.

- [ ] **Step 1: Встановіть metrics-server**

`k8s/metrics-server-values.yaml`:

```yaml
# kind: kubelet serves a self-signed certificate, metrics-server cannot verify it.
# Local cluster only. EKS does not need this flag.
args:
  - --kubelet-insecure-tls
```

```bash
helm repo add metrics-server https://kubernetes-sigs.github.io/metrics-server/
helm repo update
helm install metrics-server metrics-server/metrics-server --version 3.14.0 \
  -n kube-system -f k8s/metrics-server-values.yaml --wait --timeout 3m
```

Зачекайте 1 хвилину: metrics-server збирає перші дані.

```bash
kubectl top nodes
kubectl -n notes top pods
```

Очікуваний результат: кожен pod `notes-api` використовує приблизно `5m` CPU та `45-50Mi`
пам'яті. `postgres-0` — приблизно `12m` та `25Mi`.

Ці числа — основа для requests у Step 3.

- [ ] **Step 2: Створіть HPA**

`k8s/manifests/24-app-hpa.yaml`:

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: notes-api
  namespace: notes
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: notes-api
  minReplicas: 2
  maxReplicas: 5
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
```

```bash
kubectl apply -f k8s/manifests/24-app-hpa.yaml
sleep 30
kubectl -n notes get hpa
kubectl -n notes describe hpa notes-api | sed -n '/Events/,$p'
```

Очікуваний результат: `TARGETS` показує `cpu: <unknown>/70%`. Events мають
`FailedGetResourceMetric` з текстом `missing request for cpu in container app`. Це
навмисна помилка. Наступний крок її пояснює.

- [ ] **Step 3: Додайте requests та limits**

`averageUtilization: 70` означає 70% від `requests.cpu`. Контейнер не має requests, тому HPA
не має від чого рахувати відсоток.

Зміна в `k8s/manifests/21-app-deployment.yaml`:

```diff
           ports:
             - name: http
               containerPort: 8080
+          resources:
+            requests:
+              cpu: 100m
+              memory: 128Mi
+            limits:
+              memory: 256Mi
```

Чому такі значення:
- `requests.cpu: 100m` — у 20 разів більше за споживання в спокої. HPA додає pods, коли
  середнє споживання перевищує 70m.
- `requests.memory: 128Mi` — у 2,5 рази більше за споживання в спокої. Під навантаженням
  pod займає ~70Mi.
- `limits.memory: 256Mi` — захист node від витоку пам'яті. Якщо контейнер перевищить ліміт,
  ядро вбиває процес (`OOMKilled`, Task 5).
- Без `limits.cpu`. Ліміт CPU не вбиває контейнер, а гальмує його (throttling). Під
  навантаженням це збільшує час відповіді. Поширена практика — задавати CPU request без
  CPU limit.

- [ ] **Step 4: Приберіть `replicas` з Deployment**

Зміна в `k8s/manifests/21-app-deployment.yaml`:

```diff
 spec:
-  replicas: 2
   selector:
```

Чому: якщо поле залишити, кожен `kubectl apply` повертає кількість pods до 2. HPA додав
pods під навантаженням, а `apply` їх прибирає. Тепер кількістю pods керує тільки HPA.

```bash
kubectl apply -f k8s/manifests/21-app-deployment.yaml
kubectl -n notes get deploy notes-api -o jsonpath='{.spec.replicas}{"\n"}'
kubectl -n notes rollout status deploy/notes-api --timeout=200s
sleep 60
kubectl -n notes get hpa
kubectl -n notes get deploy notes-api -o jsonpath='{.spec.replicas}{"\n"}'
```

Очікуваний результат:
- перший `jsonpath` друкує `1`. `kubectl apply` побачив, що поле зникло з файлу, і видалив
  його. Kubernetes підставив значення за замовчуванням: 1;
- через хвилину HPA показує `cpu: 5%/70%` (або близько), `REPLICAS 2`;
- другий `jsonpath` друкує `2`. HPA підняв кількість pods до `minReplicas`.

Падіння до 1 pod відбувається тільки один раз, під час цього переходу. Далі `apply` поле
`replicas` не чіпає.

- [ ] **Step 5: Дайте навантаження та спостерігайте за HPA**

Термінал 2:

```bash
kubectl -n notes get hpa notes-api -w
```

Термінал 1:

```bash
kubectl -n notes run load --image=busybox:1.37 --restart=Never -- sh -c \
  'for i in 1 2 3 4 5 6 7 8; do (while true; do wget -q -O /dev/null http://notes-api/api/notes; done) & done; wait'
```

8 паралельних циклів надсилають запити на Service `notes-api` усередині кластера.

Очікуваний результат у терміналі 2:
- через 20-40 секунд `TARGETS` зростає вище за `700%/70%`;
- `REPLICAS` зростає до 4, а потім до 5 протягом 1-2 хвилин;
- `TARGETS` залишається вище за 70%, бо `maxReplicas: 5` не дає додати більше pods.

```bash
kubectl -n notes top pods
kubectl -n notes describe hpa notes-api | grep SuccessfulRescale
```

Очікуваний результат: 5 pods `notes-api`, кожен використовує ~900-1000m CPU та ~65-70Mi.
Events: `New size: 4` та `New size: 5`, reason `above target`.

- [ ] **Step 6: Зупиніть навантаження та спостерігайте за зменшенням**

```bash
kubectl -n notes delete pod load --now
```

Очікуваний результат у терміналі 2:
- через 30-60 секунд `TARGETS` падає до ~5%;
- `REPLICAS` залишається 5 ще приблизно 5 хвилин;
- потім `REPLICAS` стає 2. Event: `New size: 2; reason: All metrics below target`.

Чому HPA чекає 5 хвилин: за замовчуванням він зменшує кількість pods тільки після 5 хвилин
низького навантаження (stabilization window). Так HPA не прибирає pods при кожному
короткому спаді трафіку.

Поки HPA чекає, можна почати Task 4, Step 1. Task 4, Step 2 потребує знову 2 pods.

- [ ] **Step 7: Закомітьте**

```bash
git add k8s/metrics-server-values.yaml k8s/manifests/21-app-deployment.yaml k8s/manifests/24-app-hpa.yaml
git commit -m "feat(k8s): add resources, metrics-server values and HPA"
```

---

### Task 4: Rolling update та коректне завершення pod

**Files:**
- Modify: `app/Dockerfile`
- Modify: `k8s/manifests/21-app-deployment.yaml`

**Interfaces:**
- Consumes: Ingress `http://notes.localhost` (Task 1), Deployment без `replicas` (Task 3).
- Produces (для дня 5: Helm chart, дня 9: EKS):
  - образ із `STOPSIGNAL SIGTERM`;
  - контейнер `app` з `lifecycle.preStop.sleep.seconds: 5`;
  - видалення pod триває ~10 секунд з `exitCode 0` замість 30 секунд з `137`.

Цей task закриває питання з дня 3: чому видалення pod триває 30 секунд.

- [ ] **Step 1: Знайдіть сигнал зупинки образу**

```bash
docker image inspect notes-api:local --format '{{.Config.StopSignal}}'
```

Очікуваний результат: `SIGQUIT`.

Поправка до пояснення з дня 3. Kubelet не надсилає SIGTERM сам. Kubelet просить container
runtime (containerd) зупинити контейнер. Containerd надсилає сигнал, який задано в образі
через `STOPSIGNAL`. Якщо образ його не задає, це SIGTERM.

Базовий образ `serversideup/php:*-fpm-nginx` задає `STOPSIGNAL SIGQUIT`. Для nginx та
php-fpm SIGQUIT означає «завершити поточні запити і вийти». Ймовірно, тому автори образу
обрали його. Але PID 1 у контейнері — це `/init` (s6-overlay), а не nginx. `/init` не
завершує роботу за SIGQUIT. Через 30 секунд kubelet надсилає SIGKILL → `exitCode 137`.

Claude перевірив це в ізольованому кластері:
- образ із `SIGQUIT`: `delete` триває 30,9 с, `exitCode 137`;
- той самий образ із `STOPSIGNAL SIGTERM`: `delete` триває 4,1 с, `exitCode 0`.

- [ ] **Step 2: Виміряйте rolling update з поточним образом**

Зачекайте, поки HPA поверне 2 pods (Task 3, Step 6):

```bash
kubectl -n notes get pods -l app=notes-api
```

Термінал 1 (клієнт надсилає запити через Ingress 60 секунд і рахує коди відповідей):

```bash
end=$((SECONDS+60)); while [ $SECONDS -lt $end ]; do
  curl -s -o /dev/null -m 3 -w '%{http_code}\n' -H 'Accept: application/json' http://notes.localhost/api/notes
done | sort | uniq -c
```

Термінал 2 (одразу після запуску терміналу 1):

```bash
kubectl -n notes rollout restart deploy/notes-api
kubectl -n notes get pods -l app=notes-api -w
```

Очікуваний результат:
- термінал 1: тільки код `200` (~1500 запитів за 60 секунд);
- термінал 2: нові pods стають `1/1` за ~13 секунд, а старі мають `Terminating` ще
  ~30 секунд.

Помилок немає, бо старий pod ігнорує SIGQUIT і продовжує обслуговувати запити. Ціна —
кожен старий pod займає ресурси ще 30 секунд, а потім SIGKILL обриває запити, які він
обробляє в цей момент.

- [ ] **Step 3: Змініть сигнал зупинки на SIGTERM**

Зміна в `app/Dockerfile`:

```diff
 # The base image already runs as www-data on port 8080. Explicit for readers.
 USER www-data
 EXPOSE 8080
+
+# The base image sets SIGQUIT, but its init (s6-overlay, PID 1) ignores it: Kubernetes
+# then waits terminationGracePeriodSeconds (30 s) and sends SIGKILL. SIGTERM stops it in ~3 s.
+STOPSIGNAL SIGTERM
```

Зберіть образ, завантажте в кластер і розгорніть його:

```bash
docker build -t notes-api:local app
docker image inspect notes-api:local --format '{{.Config.StopSignal}}'
kind load docker-image notes-api:local --name notes
kubectl -n notes rollout restart deploy/notes-api
kubectl -n notes rollout status deploy/notes-api --timeout=200s
```

Очікуваний результат: `inspect` друкує `SIGTERM`, rollout завершується успішно.

Перевірте, що pods працюють саме з новим образом:

```bash
docker image inspect notes-api:local --format '{{.Id}}'
kubectl -n notes get pods -l app=notes-api \
  -o jsonpath='{range .items[*]}{.status.containerStatuses[0].imageID}{"\n"}{end}'
```

Очікуваний результат: hash `sha256:...` у кожного pod збігається з `Id` локального образу.
Якщо не збігається, pod працює зі старим образом. Повторіть `kind load` та
`rollout restart`.

Чому `rollout restart`: тег `notes-api:local` не змінився, тому маніфест теж не змінився.
`kubectl apply` не бачить різниці і не створює нових pods. `rollout restart` додає в
template анотацію з часом. Template змінився → Deployment створює нові pods, і вони беруть
новий образ.

Тепер повторіть вимір зі Step 2: термінал 1 і `rollout restart` у терміналі 2.

Очікуваний результат:
- термінал 1: переважно `200`, але є кілька десятків відповідей `502` (у перевірці Claude
  було 50 та 66);
- термінал 2: старі pods зникають майже одразу після `Terminating`.

Чому `502`: після SIGTERM pod завершується за ~3 секунди. Але Traefik дізнається про це з
EndpointSlice із затримкою. Кілька запитів він ще надсилає на pod, який вже закрив порт.
Traefik повертає клієнту `502 Bad Gateway`. Ця затримка — та сама паралельність з дня 3:
видалення з endpoints і сигнал зупинки відбуваються одночасно, а не по черзі.

- [ ] **Step 4: Додайте preStop**

Зміна в `k8s/manifests/21-app-deployment.yaml`:

```diff
             - secretRef:
                 name: notes-secrets
+          # Wait until Traefik and kube-proxy remove the pod from endpoints, then stop.
+          lifecycle:
+            preStop:
+              sleep:
+                seconds: 5
           livenessProbe:
```

`preStop.sleep` — вбудована дія Kubernetes. Їй не потрібна команда `sleep` в образі.

```bash
kubectl apply -f k8s/manifests/21-app-deployment.yaml
kubectl -n notes rollout status deploy/notes-api --timeout=200s
```

`apply` теж запускає rolling update, бо template змінився. Під час цього оновлення можуть
бути `502`: старі pods ще не мають `preStop`.

Повторіть вимір зі Step 2 ще раз.

Очікуваний результат:
- термінал 1: тільки `200`;
- термінал 2: старі pods зникають приблизно через 9 секунд після `Terminating`.

Послідовність тепер така:
1. API server записує `deletionTimestamp`.
2. Паралельно: EndpointSlice прибирає pod, а kubelet запускає `preStop` (5 секунд).
3. За ці 5 секунд Traefik отримує оновлений список pods і більше не надсилає туди запити.
4. Containerd надсилає SIGTERM. Pod завершується за ~3 секунди з `exitCode 0`.

Примітка: Kubernetes 1.37 має поле `lifecycle.stopSignal`. Воно дозволяє задати сигнал у
маніфесті без зміни образу. Але це alpha-функція, і в kind вона вимкнена. API server мовчки
видаляє поле з маніфесту. Тому виправлення зроблене в Dockerfile. Воно працює однаково в
kind та EKS.

- [ ] **Step 5: Повторіть drill з дня 3**

```bash
P=$(kubectl -n notes get pods -l app=notes-api -o name | head -1)
kubectl -n notes get $P -w \
  -o jsonpath='{.status.containerStatuses[0].state}{"\n"}' &
time kubectl -n notes delete $P
kill %1
```

Очікуваний результат: `exitCode` дорівнює `0`, а `kubectl delete` триває ~10 секунд (5 с
`preStop` + ~3 с завершення + ~1 с видалення об'єкта). У дні 3 було 31 секунда та `137`.

- [ ] **Step 6: Закомітьте**

```bash
git add app/Dockerfile k8s/manifests/21-app-deployment.yaml
git commit -m "fix(k8s): stop pods with SIGTERM and drain endpoints in preStop"
```

---

### Task 5: Drills: CrashLoopBackOff та OOMKilled

**Files:** немає. Цей task дає матеріал для теорії (Q2).

**Interfaces:**
- Consumes: образ `notes-api:local`.
- Produces: нічого. Namespace `drills` видаляється в кінці task.

- [ ] **Step 1: Запустіть два зламані pods**

```bash
kubectl create namespace drills
kubectl -n drills run crash --image=busybox:1.37 -- \
  sh -c 'echo "booting"; echo "FATAL: DB_HOST is not set" >&2; exit 1'
kubectl apply -f - <<'EOF'
apiVersion: v1
kind: Pod
metadata:
  name: oom
  namespace: drills
spec:
  securityContext: {runAsNonRoot: true, runAsUser: 33, runAsGroup: 33}
  containers:
    - name: app
      image: notes-api:local
      imagePullPolicy: IfNotPresent
      command: ["php", "-d", "memory_limit=-1", "-r", "$a = str_repeat('x', 200 * 1024 * 1024); sleep(60);"]
      resources:
        requests: {memory: 64Mi}
        limits: {memory: 64Mi}
EOF
```

Що роблять ці pods:
- `crash` імітує застосунок, який падає під час старту через помилку конфігурації;
- `oom` створює рядок на 200 MB при ліміті 64Mi.

- [ ] **Step 2: Спостерігайте за станом**

```bash
kubectl -n drills get pods -w
```

Через 4-5 хвилин зупиніть watch (`Ctrl+C`).

Очікуваний результат: `RESTARTS` зростає в обох pods. `STATUS` чергується:
- `crash`: `Running` → `Error` → `CrashLoopBackOff`;
- `oom`: `Running` → `OOMKilled` → `CrashLoopBackOff`.

Відразу після падіння `STATUS` показує причину завершення (`Error`, `OOMKilled`).
`CrashLoopBackOff` з'являється пізніше, коли kubelet оновлює стан на «чекаю перед
наступним стартом». Паузи між стартами ростуть: 10 с, 20 с, 40 с... до 5 хвилин.

- [ ] **Step 3: Знайдіть причину для `crash`**

```bash
kubectl -n drills describe pod crash | sed -n '/Last State/,/Finished/p'
kubectl -n drills logs crash
kubectl -n drills get events --field-selector involvedObject.name=crash \
  -o custom-columns=REASON:.reason,MESSAGE:.message --no-headers | sort -u
```

Очікуваний результат:
- `Last State: Terminated`, `Reason: Error`, `Exit Code: 1`;
- лог: `booting` та `FATAL: DB_HOST is not set`;
- event `BackOff` з текстом `Back-off restarting failed container`.

Примітка про `kubectl logs --previous`. Флаг показує лог попереднього контейнера. Він працює,
коли `STATUS` дорівнює `CrashLoopBackOff`. Одразу після падіння (`STATUS Error`) поточний
контейнер уже мертвий, і `--previous` може повернути `unable to retrieve container logs`.
Тоді використайте `kubectl logs` без флага.

- [ ] **Step 4: Знайдіть причину для `oom`**

```bash
kubectl -n drills describe pod oom | sed -n '/Last State/,/Finished/p'
kubectl -n drills logs oom
```

Очікуваний результат:
- `Last State: Terminated`, `Reason: OOMKilled`, `Exit Code: 137`;
- лог порожній.

Порівняйте три випадки з `exit code` 137 і подібних:

| Випадок | Reason | Exit Code | Хто вбив процес |
|---|---|---|---|
| День 3: `delete` pod з `SIGQUIT` | `Error` | 137 | Kubelet: SIGKILL після grace period |
| Task 5: `oom` | `OOMKilled` | 137 | Ядро Linux: процес перевищив memory limit |
| Task 5: `crash` | `Error` | 1 | Застосунок сам вийшов з помилкою |

Exit code 137 тільки каже, що процес отримав SIGKILL (128 + 9). Хто його надіслав, показує
поле `Reason`. Логи при OOMKilled порожні або обірвані: ядро вбиває процес миттєво, без
шансу щось записати.

- [ ] **Step 5: Видаліть drills**

```bash
kubectl delete namespace drills
kubectl get namespaces
```

Очікуваний результат: namespace `drills` зникає зі списку. Видалення namespace видаляє всі
ресурси в ньому.

---

### Task 6: Теорія та pull request

**Files:**
- Modify: `docs/interview-notes.md`

- [ ] **Step 1: Додайте розділ дня 4**

Додайте в кінець `docs/interview-notes.md`:

```markdown
## Day 4: Ingress, autoscaling, debugging

1. How does a request from `curl http://notes.localhost/api/notes` reach a notes-api pod?
   Name every hop and the Service type on each hop (Task 1, Step 6).
   When do you need a Service of type LoadBalancer? Why is Traefik a NodePort in kind?
2. A pod is in CrashLoopBackOff. Describe your debugging steps and commands.
   How do you tell OOMKilled from an application crash and from SIGKILL after the grace
   period (Task 5, day 3)? What is the difference between a memory request and a
   memory limit?
```

- [ ] **Step 2: Дайте відповіді**

- Q1-Q2: формат A, по 50 хвилин. Напишіть у сесії Claude: "Поясни Q1 дня 4 у форматі A".
- Відповідь — 3-5 речень, які ви скажете вголос за 1 хвилину. Деталі — у підрозділ
  «Deeper», без ліміту часу.

Після кожної відповіді попросіть review. Інші теми дня 4 вже є в «Theory backlog» (spec,
секція 10).

- [ ] **Step 3: Закомітьте та відкрийте pull request**

```bash
cd ~/laravel-k8s-aws
git add docs/interview-notes.md
git commit -m "docs: add day 4 interview notes (ingress, autoscaling, debugging)"
git push -u origin day-04-ingress-hpa
gh pr create --fill --base main
gh pr checks --watch
gh pr merge --merge --delete-branch
git switch main && git pull
```

CI збирає образ зі зміненим `app/Dockerfile`. Маніфести CI ще не перевіряє. Це зробить
`helm lint` у дні 5.

---

## Результат дня 4

- [ ] `curl http://notes.localhost/api/notes` повертає список нотаток через Traefik.
- [ ] Job `notes-api-migrate` має `Complete`, а лог показує результат міграцій.
- [ ] `kubectl top pods` працює. HPA має `TARGETS` у відсотках, а не `<unknown>`.
- [ ] Під навантаженням HPA додав pods до 5, а через ~5 хвилин після навантаження
      повернув 2.
- [ ] Rolling update під навантаженням: тільки `200`. Видалення pod: ~10 секунд,
      `exitCode 0`.
- [ ] Ви бачили `CrashLoopBackOff` та `OOMKilled` і знайшли причину кожного. Namespace
      `drills` видалено.
- [ ] `docs/interview-notes.md` має розділ "Day 4" з 2 відповідями.
- [ ] PR дня 4 змерджений, гілку видалено.

Кластер не видаляйте. Дні 5-8 працюють у ньому.
