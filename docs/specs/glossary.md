# Глосарій до spec

Цей документ пояснює всі назви технологій та терміни зі spec
[2026-10-14-laravel-k8s-aws-design.md](2026-10-14-laravel-k8s-aws-design.md).

Кожен термін має три частини:

- **Що це** — коротке пояснення.
- **У проєкті** — для чого термін потрібен саме тут.
- **Аналогія** — схожа річ з вашого досвіду (VPS, Docker, Ansible, Traefik). Ця частина є
  не в кожного терміна.

---

## 0. Карта: ваш досвід → нові терміни

| Що ви вже робили                         | Що це в новому стеку                          |
|------------------------------------------|-----------------------------------------------|
| 5 VPS у хостера                          | EC2 / nodes у EKS                             |
| Ansible-playbook створює конфіг сервера  | Terraform створює інфраструктуру в AWS        |
| `docker-compose.yml` описує сервіси      | Kubernetes-маніфести / Helm chart             |
| `docker compose up -d`                   | `kubectl apply` / `helm install` / ArgoCD sync |
| Traefik як reverse proxy                 | Traefik як Ingress controller                 |
| WireGuard + закритий доступ до БД         | VPC + private subnets + Security Groups       |
| Bitbucket Pipelines                      | GitHub Actions                                |
| Docker Hub / власний registry            | ECR                                           |
| MySQL/PostgreSQL на VPS                  | RDS                                           |
| Ваш bash-скрипт для zero-downtime deploy | Rolling update у Deployment                   |
| Prometheus + exporters, налаштовані вручну | kube-prometheus-stack + ServiceMonitor      |
| `.env` на сервері                        | ConfigMap + Secret                            |

---

## 1. Загальні терміни

**DevOps pet project**
- Що це: особистий навчальний проєкт, який ви показуєте роботодавцю.
- У проєкті: цей репозиторій на GitHub.

**CRUD**
- Що це: чотири базові операції з даними: Create, Read, Update, Delete.
- У проєкті: API для ресурсу `notes` (нотатки).

**High availability (HA)**
- Що це: система працює далі, навіть якщо одна її частина впала.
- У проєкті: не реалізуємо. Це "out of scope".

**Region / Availability Zone (AZ)**
- Що це: Region — географічний регіон AWS, наприклад `eu-central-1` (Франкфурт). AZ —
  окремий дата-центр у межах регіону. Один регіон має 2-6 AZ.
- У проєкті: один регіон. Subnets розподілені між 2 AZ, бо EKS цього вимагає.

**Multi-region / Multi-AZ**
- Що це: копії системи в кількох регіонах або AZ. Якщо один дата-центр впав, працює інший.
- У проєкті: не використовуємо, щоб зберегти гроші.

---

## 2. AWS — базові сервіси

**AWS (Amazon Web Services)**
- Що це: хмарна платформа Amazon. Ви не купуєте сервер, а орендуєте ресурси погодинно
  через API.
- Аналогія: хостер VPS, але з сотнями сервісів, і все керується через API.

**Free tier / кредити**
- Що це: безкоштовний ліміт для нових акаунтів. Нові акаунти (з 2025 року) отримують
  кредити до $200.
- У проєкті: кредити мають покрити EKS у дні 9-10.

**Root account**
- Що це: головний користувач AWS-акаунту (email, з яким ви реєструвалися). Він має
  необмежені права, і їх не можна обмежити.
- У класичному акаунті: увімкнути MFA та більше не використовувати для щоденної роботи.
- У проєкті: root немає. Новий досвід AWS (з вересня 2026) прибрав root та IAM users.
  Ви входите через Builder ID і працюєте через role `AccountFullAccessRole` з
  тимчасовими credentials (`get-caller-identity` показує `assumed-role`).

**MFA (Multi-Factor Authentication)**
- Що це: другий фактор входу. Наприклад, код з Google Authenticator.
- У проєкті: обов'язково для Builder ID (єдиний вхід в акаунт).

**IAM (Identity and Access Management)**
- Що це: сервіс AWS, який керує доступом: хто може що робити з якими ресурсами.
- Складові:
  - **IAM user** — користувач з постійними даними для входу (пароль, access keys).
  - **IAM role** — набір прав, який можна тимчасово "взяти" (assume). Постійних ключів
    роль не має. Її беруть сервіси, CI або інші акаунти.
  - **IAM policy** — JSON-документ з правилами: `Allow`/`Deny`, `Action`, `Resource`.
- **Least privilege** — принцип: давати лише ті права, які реально потрібні.
- У проєкті: IAM users немає. Ви працюєте через role з Builder ID, GitHub Actions —
  через IAM role з OIDC (день 6).
- Аналогія: користувачі та групи в Linux + `sudoers`, але для API-викликів.

**IAM Identity Center**
- Що це: сучасний спосіб входу в AWS (SSO). Ви отримуєте тимчасові ключі замість постійних.
- У проєкті: альтернатива до IAM user. Це краща практика, але трохи складніша в налаштуванні.

**Access keys**
- Що це: пара `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` для доступу до API.
- У проєкті: лише локально у `~/.aws/credentials`. Ніколи не комітьте їх у Git.

**AWS CLI (`aws`)**
- Що це: консольна утиліта для роботи з AWS API.
- У проєкті: `aws sts get-caller-identity` показує, під яким користувачем ви працюєте.

**AWS Budgets**
- Що це: сервіс, який надсилає email, якщо витрати перейшли поріг.
- У проєкті: alerts на $10 та $20.

**AWS Billing**
- Що це: розділ консолі з рахунками та деталями витрат.
- У проєкті: перевірка в день 10, що нічого не працює.

**S3 (Simple Storage Service)**
- Що це: сховище файлів (об'єктів). Файли лежать у "bucket".
- **Versioning** — S3 зберігає старі версії кожного файла.
- У проєкті: bucket для Terraform state.

**ECR (Elastic Container Registry)**
- Що це: приватний Docker registry в AWS.
- У проєкті: тут лежать образи Laravel API. EKS бере образи звідси.
- Аналогія: Docker Hub, але приватний і всередині AWS.

**EC2 (Elastic Compute Cloud)**
- Що це: віртуальні сервери в AWS.
- У проєкті: прямо не використовуємо. Але nodes в EKS — це EC2-інстанси.
- Аналогія: ваш VPS.

**RDS (Relational Database Service)**
- Що це: керована БД (PostgreSQL, MySQL та інші). AWS сам робить бекапи, оновлення та
  моніторинг.
- `db.t4g.micro` — найменший і найдешевший тип інстансу RDS.
- У проєкті: БД для застосунку на EKS.
- Аналогія: PostgreSQL на окремому VPS, але адмініструє його AWS.

**EBS / gp3**
- Що це: EBS — диски для EC2. `gp3` — сучасний тип SSD-диска.
- У проєкті: на EKS PVC створює EBS-диск типу `gp3`.

**Secrets Manager**
- Що це: сервіс AWS для зберігання секретів (паролі, токени) з ротацією.
- У проєкті: лише описуємо в README як production-практику.

---

## 3. AWS — мережа

**VPC (Virtual Private Cloud)**
- Що це: ваша ізольована приватна мережа в AWS. Ви самі задаєте діапазон IP
  (наприклад, `10.0.0.0/16`).
- У проєкті: одна VPC для всього.
- Аналогія: локальна мережа офісу, яку ви налаштовуєте на MikroTik.

**Subnet (public / private)**
- Що це: частина VPC з меншим діапазоном IP. Кожна subnet живе в одній AZ.
  - **Public subnet** — має маршрут в інтернет через Internet Gateway. Тут живуть load
    balancers.
  - **Private subnet** — не має прямого доступу з інтернету. Тут живуть nodes та RDS.
- Аналогія: VLAN-и на MikroTik: одна "DMZ", інша "внутрішня".

**Route table**
- Що це: таблиця маршрутів для subnet. Вона вирішує, куди йде трафік.
- Аналогія: `/ip route` на MikroTik.

**Internet Gateway (IGW)**
- Що це: "двері" між VPC та інтернетом. Він працює в обидва боки і коштує $0.
- У проєкті: для public subnets.

**NAT Gateway**
- Що це: дає ресурсам у private subnets вихід в інтернет. Вхід з інтернету він не дає.
- Ціна: ~$0.045-0.05 на годину + плата за трафік. Це найчастіша "пастка" витрат.
- У проєкті: створюємо лише в `eks/` на дні 9-10. Nodes потребують NAT, щоб завантажити
  образи та зв'язатися з AWS API.
- Аналогія: masquerade (`srcnat`) на MikroTik.

**Security Group (SG)**
- Що це: firewall на рівні ресурсу (інстансу, RDS). Він **stateful**: якщо вхідний трафік
  дозволено, відповідь проходить автоматично. Він має лише правила `Allow`.
- У проєкті: SG для RDS дозволяє порт 5432 лише від nodes EKS.
- Аналогія: `ufw` на кожному сервері.

**NACL (Network Access Control List)**
- Що це: firewall на рівні subnet. Він **stateless**: правила для відповіді треба писати
  окремо. Він має правила `Allow` та `Deny`.
- У проєкті: не змінюємо, але це типове питання "SG vs NACL".
- Аналогія: firewall filter на MikroTik між VLAN-ами.

**Load Balancer (CLB / NLB / ALB)**
- Що це: розподіляє трафік між кількома цілями. AWS має три типи:
  - **CLB (Classic)** — застарілий тип.
  - **NLB (Network)** — рівень L4 (TCP/UDP), дуже швидкий.
  - **ALB (Application)** — рівень L7 (HTTP), вміє маршрутизувати за шляхом та host.
- У проєкті: Service типу `LoadBalancer` для Traefik створює load balancer автоматично.

**AWS Load Balancer Controller**
- Що це: компонент у Kubernetes, який створює ALB/NLB з Ingress або Service.
- У проєкті: не використовуємо. Описуємо як альтернативу.

---

## 4. Terraform

**Terraform**
- Що це: інструмент Infrastructure as Code (IaC). Ви описуєте бажану інфраструктуру в
  файлах `.tf`, і Terraform створює її через API хмари.
- Аналогія: Ansible, але для хмарних ресурсів. Ansible каже "зроби кроки". Terraform
  каже "ось бажаний результат" і сам вираховує різницю.

**Provider**
- Що це: плагін для конкретної платформи: `aws`, `kubernetes`, `helm` тощо.

**Resource**
- Що це: один об'єкт інфраструктури. Наприклад, `aws_s3_bucket` або `aws_vpc`.

**Module**
- Що це: група ресурсів, яку можна використати повторно з параметрами.
- **Root module** — папка, у якій ви запускаєте `terraform apply`.
- **terraform-aws-modules** — популярні готові модулі від спільноти (`vpc`, `eks`).
- Аналогія: Ansible role.

**Variables / Outputs / tfvars**
- Що це: `variable` — вхідний параметр. `output` — значення, яке модуль віддає назовні.
  Файл `*.tfvars` містить значення змінних.
- У проєкті: `*.tfvars` у `.gitignore`, бо там можуть бути секрети.
- Аналогія: `vars` / `defaults` в Ansible.

**State (`terraform.tfstate`)**
- Що це: JSON-файл, де Terraform пам'ятає, які ресурси він створив і з якими ID. Без state
  Terraform "не знає" про свої ресурси.
- State може містити паролі у відкритому вигляді. Тому state ніколи не комітять у Git.

**Backend**
- Що це: місце, де лежить state. Локально або віддалено (S3).
- У проєкті: S3 bucket.

**State locking / `use_lockfile`**
- Що це: блокування state, щоб дві людини не запустили `apply` одночасно і не зламали state.
- Раніше для цього потрібна була таблиця **DynamoDB**. З Terraform 1.10 S3 вміє блокувати
  сам (`use_lockfile = true`).

**`terraform_remote_state`**
- Що це: спосіб прочитати outputs з іншого state.
- У проєкті: `eks/` читає ID VPC та subnets з `base/`.

**Bootstrap (проблема "курки та яйця")**
- Що це: Terraform потребує S3 bucket для state, але сам bucket теж треба створити.
  Тому `bootstrap/` створює bucket і зберігає свій state локально.

**Команди**
- `terraform init` — завантажує providers та модулі, підключає backend.
- `terraform fmt` — форматує код. `-check` лише перевіряє формат.
- `terraform validate` — перевіряє синтаксис та типи.
- `terraform plan` — показує, що зміниться. Нічого не змінює.
- `terraform apply` — застосовує зміни.
- `terraform destroy` — видаляє всі ресурси цього root module.

---

## 5. Docker та застосунок

**Laravel / Artisan / Migrations**
- Laravel — PHP-фреймворк. Artisan — його CLI. Migrations — PHP-файли, які змінюють
  схему БД (`php artisan migrate`).

**Pint / Pest**
- **Pint** — форматер та лінтер коду Laravel.
- **Pest** — фреймворк для тестів PHP.
- У проєкті: обидва запускаються в CI на кожен pull request.

**php-fpm / nginx**
- **php-fpm** — процес, який виконує PHP-код.
- **nginx** — веб-сервер, який приймає HTTP і передає PHP-запити в php-fpm.

**serversideup/php**
- Що це: готові Docker-образи для PHP. Варіант `fpm-nginx` має nginx та php-fpm в одному
  контейнері.
- У проєкті: базовий образ для Dockerfile.

**s6-overlay / `/init`**
- Що це: init-система в образі `serversideup/php`. Процес `/init` працює як PID 1, запускає
  nginx та php-fpm і стежить за ними.
- У проєкті: `/init` не завершує роботу за сигналом SIGQUIT. Тому в дні 3 видалення pod
  тривало 30 секунд (див. STOPSIGNAL).

**STOPSIGNAL**
- Що це: інструкція Dockerfile. Вона задає сигнал, яким Docker або containerd зупиняє
  контейнер. Якщо інструкції немає, це SIGTERM.
- У проєкті: базовий образ задає `SIGQUIT`. `app/Dockerfile` перевизначає його на
  `STOPSIGNAL SIGTERM` (день 4). Pod завершується за ~3 секунди замість 30.
- Перевірка: `docker image inspect <image> --format '{{.Config.StopSignal}}'`.

**Multi-stage build**
- Що це: Dockerfile з кількома `FROM`. Перший етап встановлює залежності (composer).
  Фінальний етап копіює лише результат.
- Навіщо: фінальний образ менший і не має зайвих інструментів.

**Image layers**
- Що це: кожна інструкція Dockerfile створює шар. Docker кешує шари.
- Навіщо: якщо `composer install` стоїть перед `COPY . .`, зміна коду не перезапускає
  встановлення залежностей.

**Non-root container**
- Що це: процес у контейнері працює не від `root`.
- Навіщо: якщо атакувальник зламав застосунок, він не отримає root-права в контейнері.

**docker-compose**
- Що це: ви вже його знаєте. У проєкті він лише для локальної розробки.

**Image tag / commit SHA**
- Що це: тег — версія образу (`notes-api:a1b2c3d`). Commit SHA — унікальний ID коміту в Git.
- У проєкті: тег образу = SHA коміту. Тому завжди видно, який код працює в кластері.
  Тег `latest` не використовуємо.
- Локально (дні 3-8): тег `notes-api:local` не змінюється. Після `docker build` та
  `kind load` маніфест той самий, тому `kubectl apply` pods не оновлює. Потрібен
  `kubectl rollout restart`.

---

## 6. Kubernetes — основа

**Kubernetes (K8s)**
- Що це: система, яка запускає контейнери на кількох серверах і сама підтримує бажаний
  стан. Якщо контейнер впав, Kubernetes запускає новий.
- Аналогія: docker-compose, але для кластера з багатьох серверів, з автовідновленням.

**Cluster / Control plane / Node**
- **Cluster** — уся система Kubernetes.
- **Control plane** — "мозок" кластера: API server, scheduler, controller-manager, etcd
  (база стану).
- **Node** — сервер, на якому працюють контейнери.

**API server / etcd**
- **API server** — єдина точка входу в кластер. Він перевіряє запит (автентифікація,
  RBAC, валідація) і зберігає об'єкт в etcd. Жоден інший компонент не звертається до etcd
  напряму.
- **etcd** — key-value база, де зберігається стан усіх об'єктів кластера.
- У проєкті: кожна команда `kubectl` — це HTTP-запит до API server. Перевірка:
  `kubectl get pods -v=6`.

**Scheduler / Controller**
- **Scheduler** — вибирає node для нового pod (поле `spec.nodeName`) за ресурсами,
  affinity та taints.
- **Controller** — цикл, який порівнює бажаний стан з реальним і виправляє різницю.
  Приклади: ReplicaSet controller, Deployment controller, EndpointSlice controller.
- **Watch** — компоненти не надсилають дані один одному. Кожен стежить за API server і
  реагує на зміни. Це головний патерн Kubernetes.

**kubelet**
- Що це: агент на кожній node (systemd-сервіс, а не pod). Він бачить pods, призначені на
  його node, і просить container runtime їх запустити.
- Ще kubelet виконує probes, перезапускає контейнери, зупиняє їх при видаленні pod і
  звітує API server про стан (`status.phase`, `containerStatuses`).
- Перевірка в kind: `docker exec notes-control-plane systemctl status kubelet`.

**Container runtime / containerd / CRI**
- Що це: програма, яка реально запускає контейнери на node. Kubelet спілкується з нею
  через інтерфейс CRI.
- У проєкті: containerd всередині node kind. Він не бачить образи вашого Docker, тому
  потрібен `kind load docker-image`.

**CNI**
- Що це: мережевий плагін. Він дає кожному pod власну IP-адресу, незалежно від Service.
- У проєкті: `kindnet` у kind, діапазон pods `10.244.x.x`.

**kube-proxy**
- Що це: компонент на кожній node. Він пише правила iptables/nftables: ClusterIP Service →
  один з ready pods.
- У проєкті: обробляє `notes-api:80`. Traefik його оминає і йде на IP pods напряму.

**CoreDNS**
- Що це: DNS кластера. Ім'я Service (`notes-api`, `postgres`) перетворюється на IP.
- Повне ім'я: `<service>.<namespace>.svc.cluster.local`.

**kubectl / kubeconfig**
- **kubectl** — CLI для роботи з кластером.
- **kubeconfig** — файл з адресою кластера та даними доступу (`~/.kube/config`).
  У `.gitignore`.

**Manifest**
- Що це: YAML-файл, який описує об'єкт Kubernetes.
- `kubectl apply -f` створює або оновлює об'єкт з маніфесту.

**Namespace**
- Що це: логічна "папка" для об'єктів у кластері. Наприклад, `notes`, `argocd`,
  `monitoring`.

**kind**
- Що це: Kubernetes IN Docker. Інструмент, а не дистрибутив. Він запускає звичайний
  upstream Kubernetes (через `kubeadm`), де кожна node — це Docker-контейнер.
- У проєкті: локальний кластер `notes` для днів 3-8.
- Обмеження: NodePort доступний з хоста тільки через `extraPortMappings`, образи треба
  копіювати через `kind load`, Service типу LoadBalancer залишається в `Pending`, дані PVC
  зникають разом з кластером.

**k3s**
- Що це: окремий дистрибутив Kubernetes (SUSE/Rancher). Один бінарник, SQLite замість etcd
  за замовчуванням, Traefik та ServiceLB вбудовані. Працює на реальних серверах і підходить
  для production (edge, малі кластери).
- У проєкті: відхилений варіант (див. spec, секція 2).
- Різниця з kind: k3s — production-дистрибутив, kind — інструмент для тестів і розробки.

**Pod**
- Що це: найменша одиниця в Kubernetes. Він має один або кілька контейнерів зі спільною
  мережею (IP) та дисками.
- Pod — "смертний". Якщо він впав, Kubernetes створює новий з новим IP.
- Аналогія: один сервіс з `docker-compose.yml`, але з одним або кількома контейнерами.

**Pod phase / стан контейнера / колонка STATUS**
- **Phase** (`status.phase`) — грубий підсумок для всього pod. 5 значень: `Pending`,
  `Running`, `Succeeded`, `Failed`, `Unknown`.
- **Стан контейнера** (`status.containerStatuses[].state`) — `waiting`, `running` або
  `terminated`, кожен з полем `reason` (`ContainerCreating`, `CrashLoopBackOff`, `Error`,
  `OOMKilled`).
- **STATUS** у `kubectl get pods` — не поле API. kubectl обчислює його: `Terminating`, якщо
  є `deletionTimestamp`, інакше `reason` контейнера, інакше phase.
- `Pending` триває від запису в etcd до старту контейнерів: очікування scheduler,
  завантаження образу, init containers.

**Pod conditions**
- Що це: `PodScheduled`, `Initialized`, `ContainersReady`, `Ready`. Вони показують, на
  якому етапі pod, точніше за phase.

**Завершення pod: deletionTimestamp / grace period / preStop**
- `kubectl delete pod` → API server записує `deletionTimestamp`. Phase залишається
  `Running`, а kubectl показує `Terminating`.
- Паралельно: EndpointSlice controller прибирає pod з endpoints, а kubelet запускає
  `preStop` hook.
- Після `preStop` container runtime надсилає сигнал з `STOPSIGNAL` образу (за
  замовчуванням SIGTERM).
- **terminationGracePeriodSeconds** (за замовчуванням 30) — скільки kubelet чекає. Потім
  SIGKILL.
- **preStop** — дія перед сигналом зупинки. `lifecycle.preStop.sleep.seconds: 5` дає
  Traefik і kube-proxy час прибрати pod з маршрутів.
- У проєкті (день 4): без `preStop` rolling update давав `502`, з `preStop` — 0 помилок.

**Exit codes 137 / 143 / 1**
- `128 + N` означає, що процес вбито сигналом N. `137` = SIGKILL (9), `143` = SIGTERM (15).
- `1` — застосунок сам вийшов з помилкою.
- Хто надіслав SIGKILL, показує `Reason`: `OOMKilled` — ядро Linux (ліміт пам'яті),
  `Error` — kubelet після grace period.

**Labels / Selector**
- **Labels** — пари ключ-значення на об'єкті (`app: notes-api`).
- **Selector** — запит за labels. Deployment знаходить «свої» pods за selector, а Service
  знаходить pods для трафіку теж за selector. Service не знає про Deployment: зв'язок
  існує тільки через labels.

**Deployment / ReplicaSet**
- **Deployment** — описує, скільки копій (replicas) Pod має працювати і з яким образом.
  Він керує оновленнями.
- **ReplicaSet** — внутрішній об'єкт, який Deployment створює для кожної версії.
- У проєкті: Laravel API.
- Pods Deployment взаємозамінні: випадкова назва з hash, нова IP. Новий pod стартує, не
  чекаючи, поки старий зникне.

**Stateless-застосунок**
- Що це: pod не зберігає стану, тому його можна вбити будь-коли. Стан живе в зовнішніх
  сервісах:
  - кеш і сесії — Redis (в AWS: ElastiCache);
  - автентифікація REST API — токени (Sanctum у БД або JWT);
  - файли — S3, клієнт завантажує їх за presigned URL;
  - логи — stdout/stderr.
- Чого уникати: файли на диску pod, sticky sessions, PVC `ReadWriteOnce` на Deployment.
- У проєкті: `SESSION_DRIVER=array`, `CACHE_STORE=array`, `LOG_CHANNEL=stderr`. Детальніше —
  «Theory backlog» у spec.

**Rolling update / Rollback**
- **Rolling update** — Deployment замінює старі Pods на нові поступово. Старі Pods
  працюють, доки нові не стануть "ready".
- **Rollback** — повернення до попередньої версії.
- Аналогія: ваш bash-скрипт для zero-downtime deploy з Traefik, але вбудований.

**StatefulSet**
- Що це: як Deployment, але для сервісів зі станом (БД). Гарантії:
  - стабільна назва pod (`postgres-0`) і DNS-ім'я через headless Service;
  - окремий PVC для кожного pod з `volumeClaimTemplates`;
  - не більше одного pod з однією назвою одночасно: новий `postgres-0` стартує тільки
    після того, як старий зник. Тому два процеси PostgreSQL не пишуть в одні файли.
- Чого StatefulSet НЕ робить: реплікацію та failover. `replicas: 3` — це 3 незалежні БД.
- У проєкті: PostgreSQL у локальному кластері. В EKS — RDS.

**PV / PVC / StorageClass**
- **PersistentVolume (PV)** — реальний диск.
- **PersistentVolumeClaim (PVC)** — запит Pod-а: "мені потрібно 1Gi диска".
- **StorageClass** — правило, як створити диск. У kind — локальна папка, в EKS — EBS `gp3`.
- Аналогія: Docker volume.

**Access mode / Reclaim policy**
- **ReadWriteOnce (RWO)** — том підключається до однієї node. Так працює EBS.
- **ReadWriteMany (RWX)** — том підключається до багатьох nodes одночасно. Приклад: EFS.
- **Reclaim policy `Delete`** — якщо видалити PVC, PV і дані теж зникнуть. Так налаштовано
  StorageClass `standard` у kind.

**Service (ClusterIP / NodePort / LoadBalancer / headless)**
- Що це: стабільна адреса для групи Pods. Pods змінюють IP, а Service ні.
  - **ClusterIP** — віртуальна IP-адреса лише всередині кластера (за замовчуванням).
    kube-proxy розподіляє трафік між ready pods.
  - **NodePort** — відкриває порт (30000-32767) на кожній node. У проєкті: Traefik у kind
    (30080/30443).
  - **LoadBalancer** — хмара створює зовнішній load balancer. У kind залишається в
    `Pending`. У проєкті: Traefik в EKS.
  - **Headless** (`clusterIP: None`) — без віртуальної IP. DNS одразу повертає IP pods. З
    StatefulSet дає ім'я кожному pod: `postgres-0.postgres`.
- `port` — порт Service, `targetPort` — порт контейнера (можна за іменем, `http`).
- Аналогія: ім'я сервісу в мережі docker-compose (`db:5432`).

**EndpointSlice**
- Що це: список IP-адрес ready pods для Service. Його веде EndpointSlice controller за
  selector Service.
- У проєкті: Traefik читає EndpointSlices і надсилає запити на IP pods напряму.
  Перевірка: `kubectl -n notes get endpointslices`.

**Ingress / Ingress controller**
- **Ingress** — правила HTTP-маршрутизації: "host `api.local` → Service `notes-api`".
- **Ingress controller** — програма, яка реально виконує ці правила (Traefik).
- **IngressClass** — зв'язує Ingress з конкретним controller (`ingressClassName:
  traefik`).
- У проєкті: `http://notes.localhost` → Service `notes-api`.
- Аналогія: labels Traefik у docker-compose.

**Traefik**
- Що це: reverse proxy, ви його вже знаєте. У Kubernetes він працює як Ingress controller.

**ingress-nginx**
- Що це: найпопулярніший Ingress controller у минулому. Проєкт закрито (retired) у 2026
  році. Тому ми обираємо Traefik.

**Gateway API**
- Що це: новий стандарт Kubernetes, який замінює Ingress. Traefik його підтримує.
- У проєкті: не використовуємо, але корисно знати для співбесіди.

**ConfigMap / Secret**
- **ConfigMap** — несекретні налаштування (`APP_ENV`, `DB_HOST`).
- **Secret** — секретні дані (`DB_PASSWORD`). Вони закодовані в base64, а **не
  зашифровані**. Це типове питання на співбесіді.
- Захист Secret дають додаткові механізми: RBAC (`get secrets` у namespace), шифрування
  etcd (в EKS — KMS), зовнішній менеджер секретів.
- Право `create pods` у namespace фактично дає доступ до всіх Secrets цього namespace.
- Аналогія: `.env`, розділений на дві частини.

**External Secrets Operator / Secrets Store CSI Driver**
- **External Secrets Operator** — бере секрети з AWS Secrets Manager і створює з них
  Kubernetes Secret.
- **Secrets Store CSI Driver** — монтує секрет з AWS Secrets Manager у pod як файл.
- Обидва отримують доступ до AWS через IRSA або Pod Identity, а не через ключі.
- У проєкті: лише описуємо в README.

**Sealed Secrets / SOPS**
- Що це: способи зберігати секрети в git у зашифрованому вигляді. Потрібні для GitOps,
  бо base64 у git — це відкритий текст.

**Job**
- Що це: Pod, який виконує задачу один раз і завершується.
- `restartPolicy: Never` — після помилки Job створює новий pod. `backoffLimit` — скільки
  спроб, потім статус `Failed`.
- Template Job незмінний (immutable). Щоб запустити знову — видалити Job і створити.
- У проєкті: Job `notes-api-migrate` виконує `php artisan migrate --force` (день 4). У
  дні 5 його запускає Helm hook, у дні 7 — ArgoCD PreSync.

**Liveness / Readiness probe**
- **Liveness** — "чи процес живий?". Якщо ні, Kubernetes перезапускає контейнер.
- **Readiness** — "чи готовий приймати трафік?". Якщо ні, Service не шле трафік на Pod,
  але контейнер не перезапускається.
- У проєкті: `/health` для liveness, `/ready` для readiness.
- Liveness перезапускає контейнер після `periodSeconds × failureThreshold` (у проєкті
  10 × 3 = 30 секунд).
- Чому liveness не перевіряє БД: перезапуск лікує тільки проблеми всередині контейнера.
  Якщо БД впала, усі pods одночасно потрапляють у `CrashLoopBackOff` (корельована
  відмова), а після відновлення БД API ще кілька хвилин чекає кінця backoff.
- Аналогія: `healthcheck` у docker-compose.

**Startup probe**
- Що це: probe для повільного старту. Поки він не пройшов, liveness і readiness вимкнені.
  Після першого успіху startup probe більше не запускається.
- Чому не `initialDelaySeconds`: це фіксована затримка при кожному старті. Startup probe
  закінчується, щойно застосунок готовий.
- У проєкті: не потрібен, PHP-FPM стартує за 1-2 секунди.

**Requests / Limits**
- **Requests** — скільки CPU/RAM Pod гарантовано отримує. Scheduler обирає node за ними.
  HPA рахує відсоток саме від request.
- **Limits** — максимум. Якщо контейнер перевищив ліміт RAM, ядро Linux вбиває процес
  (`OOMKilled`, exit code 137). Якщо перевищив ліміт CPU, його гальмують (throttling), а не
  вбивають.
- У проєкті: requests `cpu: 100m`, `memory: 128Mi`, limit `memory: 256Mi`, без CPU limit.

**QoS classes**
- Що це: клас pod за його requests/limits. `Guaranteed` (requests = limits), `Burstable`
  (є requests), `BestEffort` (немає нічого). При нестачі пам'яті на node kubelet спершу
  виселяє pods, які використовують більше за свої requests. `BestEffort` pods виселяються
  першими, бо їхній request дорівнює нулю.

**HPA (Horizontal Pod Autoscaler) / metrics-server**
- **HPA** — автоматично змінює кількість replicas за навантаженням (наприклад, CPU > 70%
  від request).
- **metrics-server** — збирає CPU/RAM з Pods. Без нього HPA не працює. Він також дає
  `kubectl top`.
- Без `requests.cpu` HPA показує `<unknown>` і пише `missing request for cpu`.
- **Stabilization window** — HPA зменшує кількість pods тільки після 5 хвилин низького
  навантаження.
- Якщо є HPA, приберіть `replicas` з Deployment. Інакше кожен `kubectl apply` скидає
  кількість pods.

**CrashLoopBackOff / OOMKilled**
- **CrashLoopBackOff** — контейнер падає після старту, і Kubernetes перезапускає його з
  дедалі більшою паузою (10 с, 20 с, 40 с... до 5 хвилин). Одразу після падіння STATUS
  показує `Error` або `OOMKilled`, а `CrashLoopBackOff` з'являється пізніше.
- **OOMKilled** — контейнер вбито, бо він перевищив ліміт RAM. Логи порожні або обірвані.
- Як дебажити: `kubectl describe pod` (`Last State`, `Reason`, `Exit Code`),
  `kubectl logs` та `kubectl logs --previous`, `kubectl get events`.

**ImagePullBackOff / CreateContainerConfigError**
- **ImagePullBackOff** — kubelet не може завантажити образ (неправильний тег, немає
  доступу до registry).
- **CreateContainerConfigError** — контейнер неможливо створити: немає Secret або
  ConfigMap, або `runAsNonRoot` з нечисловим `USER` в образі (день 3).

**`kubectl port-forward`**
- Що це: прокидає порт з Pod або Service на ваш ноутбук. Для тестів без Ingress.
- Навіть для Service він підключається до **одного** pod і не балансує трафік. Якщо цей
  pod зник, з'єднання обривається (день 3, Task 4).

**`kubectl rollout restart`**
- Що це: додає в template Deployment анотацію з часом. Template змінився → Deployment
  робить rolling update.
- У проєкті: оновлює pods після `kind load` нового образу з тим самим тегом.

**CRD / Operator**
- **CRD (Custom Resource Definition)** — новий тип об'єкта в Kubernetes. Наприклад,
  `ServiceMonitor` або ArgoCD `Application`.
- **Operator** — програма, яка керує цим типом об'єктів.

---

## 7. Kubernetes — EKS

**EKS (Elastic Kubernetes Service)**
- Що це: керований Kubernetes в AWS. AWS керує control plane, а ви керуєте nodes.
- Ціна: control plane ~$0.10 на годину + EC2 для nodes.

**Managed node group**
- Що це: група EC2-інстансів для nodes. AWS сам створює та оновлює їх.

**Fargate**
- Що це: режим без nodes. Кожен Pod працює в окремій мікро-VM, яку керує AWS.
- У проєкті: не використовуємо. Типове питання "managed nodes vs Fargate".

**IRSA / EKS Pod Identity**
- Що це: способи дати Pod-у IAM role без access keys.
  - **IRSA** (IAM Roles for Service Accounts) — старіший спосіб через OIDC.
  - **Pod Identity** — новіший і простіший спосіб (з 2023 року).
- У проєкті: потрібно, якщо Pod працює з AWS API (наприклад, EBS CSI driver для дисків).

---

## 8. Helm

**Helm**
- Що це: пакетний менеджер для Kubernetes. Він генерує маніфести з шаблонів.

**Chart / Template / Values**
- **Chart** — пакет: шаблони + файл значень за замовчуванням.
- **Template** — YAML з плейсхолдерами (`{{ .Values.image.tag }}`).
- **Values** — значення для шаблонів. Різні файли для різних середовищ.
- У проєкті: `values-local.yaml` для kind, `values-eks.yaml` для EKS.
- Аналогія: Ansible role з Jinja2-шаблонами та `group_vars`.

**Release**
- Що це: встановлений екземпляр chart у кластері. Helm зберігає історію release, тому
  `helm rollback` можливий.

**Repository**
- Що це: сервер, де лежать чарти. `helm repo add traefik https://traefik.github.io/charts`
  додає його, `helm repo update` оновлює список версій.
- У проєкті (день 4): чарти Traefik та metrics-server. Версію завжди фіксуємо через
  `--version`.

**Команди**
- `helm lint` — перевіряє chart на помилки.
- `helm template` — показує готові маніфести, нічого не встановлює.
- `helm install` / `helm upgrade` — встановлює або оновлює release.

**kube-prometheus-stack**
- Що це: Helm chart, який встановлює повний моніторинг: Prometheus, Grafana,
  Alertmanager, kube-state-metrics, node-exporter та Prometheus Operator.
- Аналогія: ваш стек моніторингу, але одною командою.

---

## 9. GitOps та ArgoCD

**GitOps**
- Що це: Git — єдине джерело правди про стан кластера. Щоб змінити кластер, ви змінюєте Git.

**Push deploy vs Pull deploy**
- **Push** — CI сам підключається до сервера і деплоїть. Так працює ваш Bitbucket
  pipeline зараз.
- **Pull** — агент у кластері сам дивиться в Git і підтягує зміни. CI не має доступу до
  кластера.

**ArgoCD**
- Що це: GitOps-інструмент для Kubernetes з веб-інтерфейсом. Він працює за моделлю pull.

**Application (ArgoCD)**
- Що це: CRD, який каже ArgoCD: "цей Helm chart з цього Git-репозиторію встанови в цей
  namespace".

**Sync**
- Що це: ArgoCD приводить кластер до стану з Git.

**PreSync hook**
- Що це: ресурс, який ArgoCD запускає **перед** основним sync.
- У проєкті: Job з міграціями. Спочатку міграції, потім новий код.

---

## 10. Моніторинг

**Prometheus / Grafana / Alertmanager**
- Що це: ви вже знаєте їх з поточної роботи.

**Prometheus Operator / ServiceMonitor**
- **Prometheus Operator** — керує Prometheus у Kubernetes.
- **ServiceMonitor** — CRD: "збирай метрики з цього Service на цьому порту".
- Аналогія: `scrape_configs` у `prometheus.yml`, але як окремий Kubernetes-об'єкт поруч
  із застосунком.

**kube-state-metrics**
- Що це: exporter стану об'єктів Kubernetes. Наприклад, скільки рестартів у Pod, скільки
  replicas готові.
- У проєкті: alert на рестарти Pod використовує його метрики.

---

## 11. CI/CD

**GitHub Actions / Workflow**
- **GitHub Actions** — CI/CD від GitHub.
- **Workflow** — YAML-файл у `.github/workflows/`.
- Аналогія: `bitbucket-pipelines.yml`.

**Pull request (PR)**
- Що це: запит на злиття гілки в `main` з code review та запуском CI.

**OIDC (OpenID Connect) для GitHub Actions**
- Що це: GitHub видає workflow короткий токен. AWS перевіряє токен і дає тимчасові
  ключі від IAM role.
- Навіщо: у GitHub secrets не лежать постійні AWS-ключі. Якщо репозиторій зламали,
  нема чого красти.
- У проєкті: IAM OIDC provider та role створює Terraform у `base/`.
