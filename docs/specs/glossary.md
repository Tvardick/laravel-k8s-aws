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

---

## 6. Kubernetes — основа

**Kubernetes (K8s)**
- Що це: система, яка запускає контейнери на кількох серверах і сама підтримує бажаний
  стан. Якщо контейнер впав, Kubernetes запускає новий.
- Аналогія: docker-compose, але для кластера з багатьох серверів, з автовідновленням.

**Cluster / Control plane / Node**
- **Cluster** — уся система Kubernetes.
- **Control plane** — "мозок" кластера: API server, scheduler, etcd (база стану).
- **Node** — сервер, на якому працюють контейнери.

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
- Що це: Kubernetes IN Docker. Він запускає повний кластер у Docker-контейнерах на
  вашому ноутбуці.
- У проєкті: локальний кластер для днів 3-8.

**k3s**
- Що це: полегшений дистрибутив Kubernetes для одного сервера або малих кластерів.
- У проєкті: відхилений варіант (див. spec, секція 2).

**Pod**
- Що це: найменша одиниця в Kubernetes. Він має один або кілька контейнерів зі спільною
  мережею (IP) та дисками.
- Pod — "смертний". Якщо він впав, Kubernetes створює новий з новим IP.
- Аналогія: один сервіс з `docker-compose.yml`, але з одним або кількома контейнерами.

**Deployment / ReplicaSet**
- **Deployment** — описує, скільки копій (replicas) Pod має працювати і з яким образом.
  Він керує оновленнями.
- **ReplicaSet** — внутрішній об'єкт, який Deployment створює для кожної версії.
- У проєкті: Laravel API.

**Rolling update / Rollback**
- **Rolling update** — Deployment замінює старі Pods на нові поступово. Старі Pods
  працюють, доки нові не стануть "ready".
- **Rollback** — повернення до попередньої версії.
- Аналогія: ваш bash-скрипт для zero-downtime deploy з Traefik, але вбудований.

**StatefulSet**
- Що це: як Deployment, але для сервісів зі станом (БД). Pods мають стабільні імена
  (`postgres-0`) та власні диски.
- У проєкті: PostgreSQL у локальному кластері.

**PV / PVC / StorageClass**
- **PersistentVolume (PV)** — реальний диск.
- **PersistentVolumeClaim (PVC)** — запит Pod-а: "мені потрібно 1Gi диска".
- **StorageClass** — правило, як створити диск. У kind — локальна папка, в EKS — EBS `gp3`.
- Аналогія: Docker volume.

**Service (ClusterIP / NodePort / LoadBalancer)**
- Що це: стабільна адреса для групи Pods. Pods змінюють IP, а Service ні.
  - **ClusterIP** — адреса лише всередині кластера (за замовчуванням).
  - **NodePort** — відкриває порт на кожній node.
  - **LoadBalancer** — хмара створює зовнішній load balancer.
- Аналогія: ім'я сервісу в мережі docker-compose (`db:5432`).

**Ingress / Ingress controller**
- **Ingress** — правила HTTP-маршрутизації: "host `api.local` → Service `notes-api`".
- **Ingress controller** — програма, яка реально виконує ці правила (Traefik).
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
- Аналогія: `.env`, розділений на дві частини.

**External Secrets Operator**
- Що це: компонент, який бере секрети з AWS Secrets Manager і створює з них Kubernetes
  Secret.
- У проєкті: лише описуємо в README.

**Job**
- Що це: Pod, який виконує задачу один раз і завершується.
- У проєкті: `php artisan migrate`.

**Liveness / Readiness probe**
- **Liveness** — "чи процес живий?". Якщо ні, Kubernetes перезапускає контейнер.
- **Readiness** — "чи готовий приймати трафік?". Якщо ні, Service не шле трафік на Pod,
  але контейнер не перезапускається.
- У проєкті: `/health` для liveness, `/ready` для readiness.
- Чому liveness не перевіряє БД: якщо БД впала, рестарт PHP не допоможе. Він лише
  створить хвилю рестартів.
- Аналогія: `healthcheck` у docker-compose.

**Requests / Limits**
- **Requests** — скільки CPU/RAM Pod гарантовано отримує. Scheduler обирає node за ними.
- **Limits** — максимум. Якщо Pod перевищив ліміт RAM, Kubernetes вбиває його
  (`OOMKilled`).

**HPA (Horizontal Pod Autoscaler) / metrics-server**
- **HPA** — автоматично змінює кількість replicas за навантаженням (наприклад, CPU > 70%).
- **metrics-server** — збирає CPU/RAM з Pods. Без нього HPA не працює.

**CrashLoopBackOff / OOMKilled**
- **CrashLoopBackOff** — контейнер падає після старту, і Kubernetes перезапускає його з
  дедалі більшою паузою.
- **OOMKilled** — контейнер вбито, бо він перевищив ліміт RAM.
- Як дебажити: `kubectl describe pod`, `kubectl logs --previous`, `kubectl get events`.

**`kubectl port-forward`**
- Що це: прокидає порт з Pod або Service на ваш ноутбук. Для тестів без Ingress.

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
