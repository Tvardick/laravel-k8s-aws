1. Q. Яка різниця між IAM User, IAM Role, IAM Group?
   A. IAM User потрібен для надання доступу одній людині або застосунку. Він має постійні
   credentials (пароль, access keys). IAM Group об'єднує кількох користувачів з однаковими
   правами, щоб не налаштовувати кожного IAM User окремо. IAM Role не має постійних
   credentials. Її тимчасово беруть (assume) людина, сервіс AWS або CI, і отримують
   тимчасові credentials. Наприклад, у проєкті GitHub Actions буде брати role через OIDC.
2. Q. Що таке IAM Policy? Що таке Effort, Action, Resourse та Condition?
A. IAM Policy — це JSON-документ, який описує дозволи. Identity-based policy
   прикріплена до user/group/role і описує, що ця identity може робити.
   Resource-based policy прикріплена до ресурсу (S3 bucket, ECR repository) і
   описує, хто має доступ до ресурсу. Тому вона має додатковий елемент Principal.
   Policy складається зі Statement, кожен має Effect (Allow або Deny), Action
   (операція, наприклад ecr:PutImage), Resource (ARN ресурсу) та необов'язковий
   Condition (умова, наприклад гілка main або наявність MFA).
   AWS за замовчуванням забороняє все, що не дозволено явно (implicit deny).
   Це допомагає застосувати least privilege. Explicit Deny у будь-якій policy
   перемагає будь-який Allow.
   У role є дві policies: trust policy відповідає на питання "хто може стати
   цією role", permissions policy — "що може робити ця role".
   Приклад: CI має trust policy з Condition на sub = гілка main та permissions
   policy, яка дозволяє лише push actions для repository laravel-app.
3. Q. What happens if one policy says Allow and another says Deny for the same action?
   A. AWS спочатку збирає всі policies, які стосуються запиту, і оцінює їх разом.
   Порядок policies та statements не важливий. Результат один з трьох:
   - explicit deny — хоча б одна policy має Deny для запиту. Deny виграє завжди;
   - allow — Deny немає, і хоча б одна policy має Allow;
   - implicit deny — немає ні Deny, ні Allow.
   Identity-based та resource-based policies дають дозволи. SCP та permissions
   boundary ніколи не дають дозволів, вони лише обмежують максимум. Якщо SCP не
   дозволяє action, результат — implicit deny, навіть якщо identity policy має Allow.
   Приклад:
     Policy A: { "Effect": "Allow", "Action": "ecr:*", "Resource": "*" }
     Policy B: { "Effect": "Deny", "Action": "ecr:Delete*", "Resource": "*" }
   ecr:DeleteRepository -> explicit deny (Policy B).
   ecr:BatchDeleteImage -> allow, бо назва починається з Batch, і Policy B її не покриває.
   Висновок: Deny з wildcard — ненадійний захист. Надійний захист — вузький Allow
   лише на потрібні actions (least privilege). Wildcard в Allow небезпечний, бо
   автоматично дозволяє нові actions від AWS. Wildcard в Deny небезпечний, бо може
   пропустити actions з іншою назвою.
5. Q. Why must you not use the root account for daily work?
    A. Використовувати root акаунт для щоденної роботи небезпечно, тому що його права неможливо
    обмежити через політики. Якщо хтось отримає доступ до вашого root акаунта, він без проблем
    може змінити пошту, видалити ресурси або додати нові, наприклад майнер, який буде витрачати
    ваші гроші. Тому root захищають MFA і більше не використовують. Для щоденної роботи
    створюють IAM users або roles з мінімальними правами (least privilege). Root залишають
    лише для кількох задач, які можна виконати тільки від root, наприклад закрити акаунт або
    змінити support plan.
    До речі, у 2026 році AWS запустив новий досвід для акаунтів, зареєстрованих з вересня.
    У таких акаунтах root немає, він залишився тільки в класичних акаунтах. Вхід в акаунт
    відбувається через Builder ID з увімкненим MFA, а налаштування живуть у settings.aws.com.
    Для CLI вхід робиться через aws login з тимчасовими credentials.
6. Q. "Where do you keep AWS keys? What do you do if a key leaked to a public repository?"
    A. Краще спочатку розібратися, який саме ключ у вас: long-term чи temporary credentials,
    і не використовувати long-term ключі взагалі. Зараз кожен клієнт може отримати свої
    тимчасові credentials: CI через OIDC (OpenID Connect), людина в CLI через aws login або
    IAM Identity Center. Такі ключі діють від ~15 хв до кількох годин. Якщо такий ключ витече,
    вікно для атаки буде обмежене годинами, а не роками, як з long-term ключами.
    Якщо long-term ключ все ж потрібен, його зберігають у secrets manager або у файлі
    ~/.aws/credentials з правами 600. Ніколи не в коді, не в .env під git і не в Docker image.
    Якщо ключ витік, спочатку його терміново деактивують (для тимчасових credentials роблять
    Revoke active sessions). Відкликання йде першим, бо боти, які сканують GitHub, знайдуть
    ключ за хвилини, а видалення коміту не допоможе: ключ залишиться в історії, у forks і в
    кеші ботів. Після цього перевіряють дії ключа в CloudTrail і шукають сліди атакувальника
    у всіх регіонах: нові IAM users або roles, EC2, рахунок у Billing. Все створене
    атакувальником видаляють. Наостанок видаляють секрет з історії git і з'ясовують, як ключ
    витік: перевіряють код, шари Docker image і чи не потрапив .env під git.
    У моєму проєкті CI використовує OIDC, а CLI — aws login, тож long-term ключів немає.
9. Q. How does the new AWS experience (2026) differ from a classic account: Builder ID, projects, spend limits, aws login? Why do companies still use the classic model?
    A. Новий досвід потрібен, щоб знизити поріг входу на платформу. Раніше при реєстрації ви
    отримували root і самі налаштовували IAM, регіони та контроль витрат. Зараз root немає:
    користувач входить через Builder ID з MFA і працює через role AccountFullAccessRole з
    повним керуванням акаунтом. CLI отримує тимчасові credentials через aws login. З'явились
    projects для ізоляції ресурсів і команди. Кожен project має свій spend limit, а акаунт
    обмежений одним регіоном.
    Spend limit — теж нова функція: при досягненні ліміту ресурси зупиняються, і рахунок не
    росте безкінечно. Для навчання це плюс, але для production це серйозна небезпека, бо для
    компанії простій сервісу дорожчий за переплату.
    Компанії залишаються на класичній моделі також через кілька регіонів (disaster recovery,
    затримка для клієнтів) і multi-account з AWS Organizations policies та SCP. У новому
    досвіді ці можливості відкриває кнопка advanced features, але вона прибирає spend limit.

## DAY 2 Docker questions

1. Q. Why does the Dockerfile copy composer.json and composer.lock before the rest of the code?
   What did you see in Task 2, Step 4?
   A. Це зроблено через механізм кешування шарів. Кожна інструкція RUN, COPY та ADD створює
   шар в image. Для COPY Docker порівнює checksum файлів. Якщо шар змінився, кеш усіх
   наступних шарів інвалідується. Тому на нижні шари кладуть те, що рідко змінюється:
   системні залежності та залежності застосунку. Код застосунку, який постійно оновлюється,
   кладуть поверх цих шарів. Якщо першим поставити COPY усіх файлів, то зміна одного рядка
   коду інвалідує кеш, і composer install буде виконуватись щоразу.
   У Task 2, Step 4 я змінив коментар у ProbeController.php і зібрав image ще раз. Крок
   RUN composer install мав позначку CACHED, бо composer.json і composer.lock не змінились.
2. Q. Why does the container run as www-data and not as root? How did you check it?
   A. Це зроблено для безпеки хоста, на якому запущений контейнер. Процес усередині
   контейнера — це звичайний процес на хості, ізольований власними namespaces. Root у
   контейнері — це uid 0 на хості. Якщо атакувальник вийде з контейнера через вразливість
   ядра або помилку конфігурації (--privileged, змонтований docker.sock), він буде root на
   хості. Тому за принципом least privilege контейнер запускають від непривілейованого
   користувача. У app/Dockerfile це задано через USER www-data. Перевірка:
   docker run --rm notes-api:local whoami показує www-data. У Kubernetes те саме гарантує
   securityContext.runAsNonRoot: true.

## Day 3: Kubernetes basics

1. Describe the Pod lifecycle: phases, what happens on `kubectl delete pod`.
   Why did the delete take ~30 seconds in Task 4, Step 3?
   A. Pod має 5 фаз життевого циклу: 
        Pending - це фаза при якій триває одна з кількох дій:
            - api сервер створив об'єкт pod в базі etcd.
            - Scheduler бачить новий об'єкт без nodeName та обирає для нього Node звільними ресурсами.
            - Kubelet бачить що на його Node був призначений новий pod та починає ініціалізацію та запуск контейнерів
            - Якщо контейнер стартував без помилок, поді kubelet звертається до api server та повідомляє що под працює, 
            тоді api server змінює статус з pending на running;
        Running - це означає що Pod прив'язаний до Node і як мінімум один контейнер запущений та працює.
        Succeeded - усі контейнери в Pod завершили свою роботу з кодом 0 і не перезапускаються
        Failed - Усі контенери в Pod завершили свою роботу але як мінімум 1 має exitcode не 0
        Unknown - Означає що api server не отримує дані від kubelet, можлива проблема з node.
        Також потрібно розуміти що колонка status коли коли звертаєшся до kubectl get pods не обов'язково
        означає саме фазу, ця колонка збирає дані з об'єкта Pod і може означати фазу пода (status.phase),
        стан контейнерів (status.containerStatuses[].state), стан об'єкту якщо запущений стан видалення
        (metadata.deletionTimestamp). 
        Що відбувається при видалені Pod? Коли ми вводим команду kubectl delete pod ми звертаємось до 
        api server -> він виставляє метадані metadata.deletionTimestamp, фаза при цьому залишається running. 
        Далі паралельно запускаються кілька процесів EndpointSlice Controller прибирає цей pod з endpoint Service
        і на нього перестають маршрутизуватися запити. Та kubelet на ноді в якому запущений pod робить кілька речей:
            - якщо є preStop hook запускає його
            - відправляє STOPSIGNAL в кожний контейнер
            - чекає terminationGracePeriodSeconds, default це 30 секунд
            - якщо процес ще працює, надсилає SIGKILL
            - повідомляє control plane що pod видалено -> api видаляє об'єкт
        Паралельно працює Deployment/ReplicaSet якщо в replicaSet стоїть pod більше ніж є на даний момент, тоді створюють новий з новим ім'ям.
        Чому видалення в Task 4, Step 3 зайняло 30 секунд? Kubelet надсилає сиграл в conteinerd про зупинку контейнерів. Conteinerd надсилає STOPSIGNAL, а якщо контейнер його не знає тоді SIGTERM, базовий образ serversideup/php оброляє STOPSIGNAL та надсилає SIGQUIT в процес з id 1 це /init який не обробляє SIGQUIT тому після очікування terminationGracePeriodSeconds, kubelet надсилає SIGKILL.
2. When do you need a startupProbe in addition to liveness and readiness?
   What would happen in Task 4, Step 2 if /health checked the database?
   A. Для чого потрібен startupProbe? Якщо застосунок має довгий або непередбачуваний час старту. Наприклад
   якщо застосунок завантажується більше ніж liveness periodSeconds × failureThreshold тоді kubelet його перезапустить
   ще до того як він стартував і все почнеться з початку. Застосунок ніколи не запрацює, а якщо у нас є startupProbe
   тоді ми маємо окремий час для перевірки доступності застосунку ще до початку роботи liveness/readiness перевірки.
   Є ще один мехінізм відкладеного старту перевірки - це initialDelaySeconds, тоді ми задаємо час початку перевірки
   liveness самостійно, але цей метод не гнучкий, наприклад ми встановили delay на 120секунд, а контейнер запустився
   на 15сек тоді ми ще маємо чекати поки відкладена перевірка запрацює і ready стане 1/1, а якщо ми опитуємо контейнер
   через startupProbe кожні 5 секунд тоді після першої вдалої спроби пичинає працювати liveness\readiness перевірки і ми
   швидше бачимо роботу застосунку. Окремо ще хочу зазначити про маштаб проблеми, replicas в цьому випадку не допоможе,
   бо всі pod мають однакову залежність від бази даних і вони всі потраплять під механізм перезапуску kubelet.
   Що станеться якщо наша liveness перевірка буде залежати від бази даних? 
   Якщо раптом наш endpoint /health буде мати зовнішню залежність, тоді liveness після вичерпання failureThreshold почне
   перезапускати контейнери в pod та додавати після кожного разу паузу між перезапусками від 10 сек до 5хв, але через те що
   \проблема не з контейнером це проблему не вирішить, ми тільки побачимо що Restarts ростуть поки база даних не запрацює.
   І якщо контейнер в 5хв таймауті і база даних почне працювати, тоді сервіс все одно не відновить роботи до поки readiness
   не пройде після паузи. Висновок в тому що перезапуск контейнера виправляє проблеми самого контейнера (deadlock, витік пам'яті)
   отже liveness не має перевіряти залежності, це відповідальність readiness. 
3. Deployment vs StatefulSet: why does PostgreSQL run as a StatefulSet?
   What was different after you deleted the notes-api pod and the postgres-0 pod?
   A. Для початку давай те з'ясуємо як відразняються ці два типи котроллерів які по рідному керують подами. Deployment це тип який
   не має постійного стану,
   всі його дані кліентів живуть в БД і перестворення пода не впливає на дані кліента. StatefulSet потрібен для баз даних щоб
   ідентичність пода postgres-0 не мінялась і він отримував той самий PVC. А також StatefulSet гарантує що не буде одночасно
   двох подів з тою самою назвою для гарантування цілісності даних. PVC з data-postgres-0 не видаляється при цьому і живе поза pod.
   Також після drill я побачив що при створенні нового deployment, назва та IP pod змінюєся наприклад 
   notes-api-7976d756bd-g4gbs на notes-api-7976d756bd-lk42w а StatefulSet не має окремої IP для кожного пода бо звернення іде 
   через DNS та ім'я залишається тим самим.  
   Це і є головна причина чому postgreSQL запускається як StatefulSet.
   Яка різниця після видалення notes-api pod та postgres-0 pod? Для початку різниця в тому як працює механізм відмово стійкості для Deployment та SteatefulSet. Для deployments є ReplicaSet вона створює новий под одразу після зміни status на terminating, новий под з'являється ще до
   того як видалився старий. А в випадку postres-0 (StatefulSet), подом керує сам StatefulSet Controoller новий под стартує тільки після видалення попереднього пода.
4. Is a Kubernetes Secret secure? What did you see in Task 2, Step 6?
   What do you use in production instead?
   A. Ні, він не є безпечним сам оп собі, так як не шифрує паролі а тільки їх кодує в base64. Будь-хто з правами get secret в namespace
   може побачити їх. Захист дають додаткові механізми RBAC, шифрування etcd (в EKS — через KMS) і зовнішній менеджер секретів. 
   Команда з Task 2 Step 6 показала мій пароль у відкритому вигляді це і є доказ того що пароль не шифрується.
   В production треба використовувати AWS Secrets manager з шифруванням паролів, ключів та ротацією.


Чим відрізняються probes liveness vs readiness?
Liveness відповідає на питання, чи процес живий і не завис. Якщо probe не пройшов, kubelet
перезапускає контейнер. Liveness не залежить від бази даних, зовнішніх сервісів чи кешу.
Readiness перевіряє залежності, наприклад базу даних. Він віддає HTTP 200, коли застосунок
готовий приймати трафік, і HTTP 503, коли не готовий. Тоді Service не надсилає на pod
запити, але контейнер не перезапускається.
