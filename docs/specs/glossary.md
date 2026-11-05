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
| Labels Traefik у `docker-compose.yml`    | Об'єкт Ingress                                |
| Порт `80:80` у `docker-compose.yml`      | Service `NodePort` / `LoadBalancer` для Traefik |
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

**L4 / L7**
- **L4** — балансувальник бачить тільки IP та порт (TCP/UDP). Він пересилає з'єднання і
  не читає HTTP.
- **L7** — балансувальник читає HTTP-запит: host, path, headers. Він маршрутизує за цими
  даними.
- У проєкті: Traefik уже працює на L7. Тому балансувальнику перед ним достатньо L4.

**Load Balancer (CLB / NLB / ALB)**
- Що це: розподіляє трафік між кількома цілями. AWS має три типи (сімейство ELB):

  | | CLB (Classic) | NLB (Network) | ALB (Application) |
  |---|---|---|---|
  | Рівень | L4 та простий L7 | L4 (TCP, UDP, TLS) | L7 (HTTP, HTTPS, gRPC) |
  | Статус | legacy, без нових функцій | сучасний | сучасний |
  | Маршрутизація за host/path | ні | ні | так |
  | IP клієнта | втрачається | зберігається | у `X-Forwarded-For` |
  | Статичний IP | ні | так, один на кожну AZ | ні |
  | Targets | тільки nodes | nodes або IP pods | nodes, IP pods, Lambda |
  | Як створити з Kubernetes | Service `LoadBalancer` без annotations | Service `LoadBalancer` + annotation | Ingress з `ingressClassName: alb` |

- Service — це поняття L4. Тому Service типу `LoadBalancer` створює тільки CLB або NLB.
  ALB з'являється тільки через Ingress.
- Ціна: погодинна оплата (~$0.02-0.03) + трафік або LCU (одиниця навантаження). Точні
  ціни залежать від region.
- У проєкті: Service `LoadBalancer` для Traefik в EKS (день 9). Рекомендований тип — NLB:
  L7 вже робить Traefik, а NLB зберігає IP клієнта.
- Видаліть Service `traefik` раніше, ніж виконаєте `terraform destroy`. Балансувальник
  створив cloud controller, тому Terraform про нього не знає. Балансувальник продовжує
  коштувати гроші, а його мережеві інтерфейси блокують видалення VPC
  (`DependencyViolation`).
- Аналогія: HAProxy перед кількома VPS. NLB — режим `mode tcp`, ALB — режим `mode http`.

**AWS Load Balancer Controller**
- Що це: компонент у Kubernetes, який створює ALB з Ingress або NLB з Service.
- `target-type: ip` — балансувальник надсилає трафік напряму на IP pods, без NodePort та
  kube-proxy. Це можливо, бо в EKS pods мають справжні IP з VPC (VPC CNI).
- Потребує окремий Helm-чарт та IAM role (Pod Identity або IRSA).
- У проєкті: не використовуємо. Описуємо як production-альтернативу.

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
- Перевірка локального образу: `docker image inspect <image> --format '{{.Config.StopSignal}}'`.
- Перевірка образу в node kind: `crictl inspecti` з полем
  `{{.info.imageSpec.config.StopSignal}}` (цикл по pods — день 4, Task 4, Step 3).
- `docker history` або `created_by` може показати кілька `STOPSIGNAL`: кожен шар має
  свій запис. Діє тільки останній.

**Image ID / manifest digest / index digest**
- Що це: три різні hash одного образу.
  - **Config digest (image ID)** — hash конфігурації образу (`Env`, `Cmd`, `StopSignal`).
  - **Manifest digest** — hash manifest. Manifest описує config та шари для однієї
    платформи.
  - **Index digest** — hash списку manifests для різних платформ (`linux/amd64`,
    `linux/arm64`).
- `docker image inspect --format '{{.Id}}'`: з containerd image store — index digest, у
  класичному сховищі — config digest.
- `imageID` pod — manifest digest. `kind load` імпортує образ з `ctr images import
  --digests`, тому ім'я виглядає як `import-<дата>@sha256:...`.
- У проєкті (день 4): локальний `.Id` (`de17...`) ніколи не збігається з `imageID` pod
  (`3427...`). `docker save` під час `kind load` записує manifest заново. Порівнюйте
  властивість образу (`StopSignal`), а не hash.
- Перевірка: `docker image ls --tree <image>`, на node — `crictl images --digests
  --no-trunc` (колонки `DIGEST` та `IMAGE ID`).

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
- У проєкті: `kindnet` у kind, діапазон pods `10.244.x.x`. В EKS — VPC CNI: pods
  отримують справжні IP з subnets VPC.

**kube-proxy**
- Що це: компонент на кожній node. Він читає Services та EndpointSlices і пише правила
  в ядро Linux (iptables або nftables).
  - ClusterIP: пакет на `<ClusterIP>:<port>` → один з ready pods.
  - NodePort: пакет на `<IP node>:<nodePort>` → один з ready pods.
- На порту NodePort немає процесу, який слухає. Ядро змінює адресу призначення пакета
  (DNAT) на IP pod.
- У проєкті: kube-proxy доставляє трафік з `node:30080` у pod Traefik (`:8000`). Далі
  Traefik оминає kube-proxy і йде на IP pods `notes-api` напряму.
- Перевірка: `docker exec notes-control-plane iptables-save | grep 30080` (або
  `nft list ruleset`, якщо kube-proxy працює в режимі nftables).

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
- **extraPortMappings** — поле `kind-config.yaml`. Docker публікує порт node на host:
  `127.0.0.1:80` → `node:30080`. Його можна задати тільки під час створення кластера.
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
- Що це: стабільна адреса для групи Pods. Pods змінюють IP, а Service ні. Service працює
  на рівні L4 (IP + порт). Типи будуються один на одному:
  - **ClusterIP** — віртуальна IP-адреса лише всередині кластера (за замовчуванням).
    kube-proxy розподіляє трафік між ready pods.
  - **NodePort** — `ClusterIP` + порт (30000-32767) на **кожній** node. У проєкті:
    Traefik у kind (30080/30443).
  - **LoadBalancer** — `NodePort` + зовнішній load balancer, який створює cloud
    controller. У kind cloud controller немає, тому `EXTERNAL-IP` залишається
    `<pending>`. У проєкті: Traefik в EKS.
  - **Headless** (`clusterIP: None`) — без віртуальної IP. DNS одразу повертає IP pods. З
    StatefulSet дає ім'я кожному pod: `postgres-0.postgres`.
- Три поля портів (приклад — Service `traefik` у kind):
  - `nodePort: 30080` — порт на node;
  - `port: 80` — порт на ClusterIP;
  - `targetPort: web` — порт контейнера (за іменем, тут `8000`).
- **externalTrafficPolicy** — що робити, коли запит прийшов на node без потрібного pod.
  - `Cluster` (за замовчуванням) — kube-proxy пересилає запит на інший node і замінює IP
    клієнта на IP node (SNAT). Pod не бачить справжній IP клієнта.
  - `Local` — тільки pods на цьому node. IP клієнта зберігається, але балансувальник має
    знати, на яких nodes є pods.
- Аналогія: ім'я сервісу в мережі docker-compose (`db:5432`). `NodePort` — як `ports:
  "80:80"` у compose, але на кожному сервері кластера.

**EndpointSlice**
- Що це: список IP-адрес pods для Service. Його веде EndpointSlice controller за
  selector Service.
- Поля:
  - label `kubernetes.io/service-name: <service>` — зв'язок з Service. Ім'я slice має
    випадковий суфікс, тому шукають за label;
  - `endpoints[].addresses` — IP pod;
  - `endpoints[].conditions` — `ready`, `serving`, `terminating`;
  - `endpoints[].targetRef` — ім'я pod. Тільки довідка, маршрутизація його не
    використовує;
  - `ports` — `targetPort` Service, уже перетворений на число (`http` → `8080`).
- Один Service може мати кілька slices: кожен slice має до 100 endpoints.
- Pod не знаходять за ім'ям. Ланцюг: Ingress (`service.name`, `port.name`) → Service
  (selector `app: notes-api`) → EndpointSlice (label `service-name`) → IP pods.
- У проєкті: Traefik читає EndpointSlices, бере endpoints з `ready: true` і надсилає
  запити на `<IP pod>:8080` напряму.
- Перевірка: `kubectl -n notes get endpointslices -l kubernetes.io/service-name=notes-api
  -o yaml`.

**Informer (list + watch)**
- Що це: спосіб, яким controllers читають API (бібліотека client-go).
  - **List** — під час старту один раз бере всі об'єкти в локальний cache.
  - **Watch** — тримає відкрите з'єднання. API server сам надсилає події `ADDED`,
    `MODIFIED`, `DELETED`.
- Тому RBAC для controllers має verbs `list` та `watch`. Приклад: ClusterRole Traefik
  дає `list, watch` на `endpointslices`.
- У проєкті: Traefik отримує подію після кожної зміни pods (rollout, HPA, видалення) і
  будує нову конфігурацію. Кілька подій поспіль він обробляє як одну зміну.
- Затримка між `deletionTimestamp` і новою конфігурацією Traefik — причина `502` без
  `preStop` (день 4, Task 4).
- Аналогія: `inotify` замість циклу `ls` кожну секунду.

**Ingress / Ingress controller**
- **Ingress** — правила HTTP-маршрутизації (L7): "host `notes.localhost`, path `/` →
  Service `notes-api`". Це тільки дані в API, а не програма. Без controller Ingress
  створюється, але колонка `ADDRESS` порожня, і запити нікуди не йдуть.
- **Ingress controller** — програма, яка читає Ingress і справді проксіює запити
  (Traefik). Це звичайний Deployment зі своїм Service. Через цей Service (`NodePort` або
  `LoadBalancer`) трафік заходить у кластер.
- **IngressClass** — зв'язує Ingress з конкретним controller (`ingressClassName:
  traefik`). Потрібен, якщо в кластері кілька controllers.
- У проєкті: `k8s/manifests/23-app-ingress.yaml`, `http://notes.localhost` → Service
  `notes-api`.
- Два Services у ланцюгу:
  - `traefik` (NodePort) — доставляє трафік **до** controller (kube-proxy);
  - `notes-api` (ClusterIP) — дає controller список pods. ClusterIP Traefik не
    використовує.
- Аналогія: Ingress = файл конфігурації nginx (`server_name`, `location`), controller =
  процес nginx. У docker-compose ту саму роль, що й Ingress, грають labels Traefik:

  | Label у compose | Поле Ingress |
  |---|---|
  | `traefik.enable=true` | `ingressClassName: traefik` |
  | `routers.api.rule=Host(...)` | `rules[].host`, `paths[].path` |
  | `services.api.loadbalancer.server.port=8080` | `backend.service.name` + `port.name` |

**Traefik**
- Що це: reverse proxy, ви його вже знаєте. У Kubernetes він працює як Ingress controller.
- **Entrypoint** — порт, на якому Traefik приймає запити. У чарті: `web` (`:8000`),
  `websecure` (`:8443`). Ці імена мають і порти Service `traefik`.
- **Provider** — джерело маршрутів, яке Traefik постійно читає.
  - У compose: provider `docker`, читає labels через `docker.sock`.
  - У Kubernetes: provider `kubernetesingress` (Ingress) та `kubernetescrd` (CRD
    `IngressRoute`). Доступ до API дає RBAC: чарт створює ServiceAccount та ClusterRole.
- Налаштування, які є тільки в Traefik, задають через annotations на Ingress
  (`traefik.ingress.kubernetes.io/...`) або через `IngressRoute`.
- У проєкті: `k8s/traefik-values.yaml` задає тільки `type: NodePort` та `nodePort`.
  Решту (`port: 80`, порти контейнера) бере чарт за замовчуванням.

**Шлях запиту в kind (день 4)**
1. `curl` перетворює `notes.localhost` на `127.0.0.1` (без DNS).
2. Docker (`extraPortMappings`): `127.0.0.1:80` → `node:30080`.
3. kube-proxy (Service `traefik`, NodePort): `node:30080` → pod Traefik `:8000`.
4. Traefik (Ingress + EndpointSlice Service `notes-api`): → IP pod `notes-api:8080`.

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

**Cloud controller**
- Що це: компонент, який з'єднує Kubernetes з API хмари. Він бачить Service типу
  `LoadBalancer` і створює балансувальник AWS (за замовчуванням CLB, з annotation — NLB).
- У kind його немає, тому Service `LoadBalancer` залишається `<pending>`.
- AWS вважає цей шлях legacy і рекомендує AWS Load Balancer Controller. Перевірте
  поведінку для вашої версії EKS у дні 9.

**Шлях запиту в EKS (день 9)**
1. DNS AWS перетворює ім'я `<...>.elb.amazonaws.com` на публічні IP балансувальника.
2. Балансувальник (public subnets) → `<IP node>:<nodePort>` (private subnets). Security
   group nodes має дозволити NodePort.
3. kube-proxy → pod Traefik. Pod може бути на іншому node (див. `externalTrafficPolicy`).
4. Traefik → IP pod `notes-api`, як у kind.
- Різниця з kind — тільки переходи 1-2. З AWS Load Balancer Controller та `target-type:
  ip` перехід 3 зникає: NLB надсилає трафік напряму на pod Traefik.

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
