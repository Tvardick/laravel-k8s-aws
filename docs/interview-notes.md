1. Q. Яка разниця між IAM User, IAM Role, IAM Group?
   A. IAM User потрібен для надання доступу одній людини або додатку, IAM Group потоібен щоб об'єдневати кількох користувачів в групи з однаковими правами, щоб не налаштовувавти кожного IAM User окремо. AWS Role це сучасний спосіб надати доступ до кількох сервісів чи ресурсів для людини з тимчасовими credentials.
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
