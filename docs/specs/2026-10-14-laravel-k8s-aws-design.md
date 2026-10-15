# laravel-k8s-aws — Design Spec

Date: 2026-10-14
Status: Draft (waiting for review)
Glossary: [glossary.md](glossary.md) (Ukrainian, explains every technology name used here)

## 1. Goal

A pet project for a DevOps job search. The project gives hands-on experience with
AWS, Terraform and Kubernetes (EKS) in 2 weeks of full-time work.

Success criteria:

- The repository is public on GitHub and has a README with an architecture diagram,
  run instructions and screenshots.
- The application ran on a real EKS cluster at least once (screenshots prove it).
- The author can explain the architecture in 10 minutes.
- The author can answer typical interview questions about VPC, IAM, Pods,
  Deployments, Services, Ingress, Helm, GitOps and Terraform state.
- AWS cost for the whole project is under $20, covered by the $100 Free plan credits.

Out of scope:

- High availability and multi-region setup.
- Production-grade secrets management (documented only, see section 7).
- GCP and Azure.

## 2. Approach

Local first, EKS at the end.

- Week 1: the application runs in a local `kind` cluster. Terraform creates only cheap
  AWS resources (VPC without NAT, ECR, IAM).
- Week 2: ArgoCD and monitoring run locally first. Then Terraform creates EKS and RDS
  for 1-2 days. After the demo, `terraform destroy` removes them.

Rejected alternatives:

- EKS from day 1 with a daily destroy: costs ~$2-4 per day, and each EKS create takes
  15-20 minutes.
- k3s on EC2: cheap, but job offers ask for EKS, and cluster admin work takes time
  from AWS topics.

## 3. Architecture

```
Developer ── git push ──> GitHub ──> GitHub Actions
                                       │  PR: pint, pest, docker build,
                                       │      helm lint, terraform fmt/validate
                                       │  main: build image ──> ECR (OIDC, no keys)
                                       │        update image tag in values-eks.yaml
                                       v
                                   Git repo (desired state)
                                       │
                                       v  (pull)
              ┌──────────────── Kubernetes (kind locally / EKS) ────────────────┐
              │ ArgoCD ──> Helm chart "notes-api"                               │
              │   PreSync Job: php artisan migrate                              │
              │   Deployment (+HPA) ──> Service ──> Ingress (Traefik)           │
              │   PostgreSQL StatefulSet + PVC   (local only)                   │
              │ kube-prometheus-stack: Prometheus, Grafana, Alertmanager        │
              └─────────────────────────────────────────────────────────────────┘
                                       │ (EKS only)
                                       v
                                 RDS PostgreSQL
```

## 4. Components

### 4.1 Application (`app/`)

- Laravel API with a `notes` resource (CRUD) and Pest tests.
- `GET /health`: liveness probe. It does not check the database.
- `GET /ready`: readiness probe. It checks the database connection.
- Container image: based on `serversideup/php` (nginx + php-fpm in one image),
  multi-stage build, runs as a non-root user.
- Migrations do not run at container start. A separate Job runs them (ArgoCD PreSync
  hook). Reason: with 3 replicas, 3 parallel migrations can break the schema.
- Local development: `docker-compose.yml` with PostgreSQL.

### 4.2 Helm chart (`helm/notes-api/`)

Templates: Deployment, Service, Ingress, ConfigMap, Secret, migration Job, HPA,
PostgreSQL StatefulSet + PVC.

| Value file          | Database                       | Ingress class | Image source      |
|---------------------|--------------------------------|---------------|-------------------|
| `values-local.yaml` | StatefulSet in the chart       | traefik       | local image (kind load) |
| `values-eks.yaml`   | RDS (StatefulSet disabled)     | traefik       | ECR, tag set by CI |

Ingress controller: Traefik in both environments. ingress-nginx is retired (end of
maintenance in 2026), and the author already knows Traefik.

### 4.3 GitOps (`k8s/argocd/`)

- ArgoCD `Application` manifests for the local cluster and for EKS.
- CI changes the image tag in `values-eks.yaml` and commits it. ArgoCD syncs the
  cluster from Git.

### 4.4 Monitoring

- kube-prometheus-stack (Prometheus, Grafana, Alertmanager, kube-state-metrics).
- ServiceMonitor for Traefik metrics.
- One alert rule: Pod restarts.
- One Grafana dashboard for the application (request rate, errors, latency from
  Traefik).

### 4.5 AWS with Terraform (`terraform/`)

Three separate root modules with separate state files:

| Module       | Resources                                              | Lifetime          |
|--------------|--------------------------------------------------------|-------------------|
| `bootstrap/` | S3 bucket for state (versioning, encryption)           | Whole project     |
| `base/`      | VPC (public + private subnets, no NAT), ECR, IAM OIDC provider + role for GitHub Actions | Whole project |
| `eks/`       | EKS, managed node group, RDS PostgreSQL, one NAT Gateway | Days 9-10 only  |

- State backend: S3 with native locking (`use_lockfile = true`, Terraform 1.10+).
  No DynamoDB table.
- `eks/` reads `base/` outputs with `terraform_remote_state`.
- Community modules: `terraform-aws-modules/vpc` and `terraform-aws-modules/eks`.
- `bootstrap/` keeps its own state locally (chicken-and-egg problem). This is a
  known pattern and an interview topic.

### 4.6 CI/CD (`.github/workflows/`)

- `ci.yml` (on pull request): Pint, Pest, `docker build`, `helm lint`,
  `terraform fmt -check`, `terraform validate`.
- `release.yml` (on push to main): build image, push to ECR with the commit SHA as the
  tag, update `values-eks.yaml`, commit the change.
- AWS access: GitHub OIDC + IAM role. No static access keys in GitHub secrets.

## 5. Security

- Account type: "new AWS experience" (sign-up since September 2026). There is no root
  user and no console IAM users. Sign-in uses AWS Builder ID. Account settings live in
  `https://settings.aws.com`.
- Region: `eu-north-1` (Stockholm). AWS assigns it by country, it cannot be changed.
- Builder ID: MFA on.
- CLI access: `aws login` (temporary credentials from the console session). No
  long-term access keys on disk.
- Do not activate "advanced features" unless a required service is blocked. Activation
  is irreversible and removes the spend limit.
- If a tool cannot use `aws login` credentials (for example, Terraform), export them
  for the shell session: `eval "$(aws configure export-credentials --format env)"`.
- `.gitignore` blocks `*.tfstate`, `*.tfvars`, `.env`, `kubeconfig*`.
- Container runs as non-root.
- RDS is in private subnets. Its security group allows port 5432 only from EKS nodes.
- EKS API endpoint: public, restricted to the author's IP if possible.

## 6. Cost control

- Plan: Free plan with $100 credits, valid until 2027-04-14. On the Free plan AWS does
  not charge the card. When credits end, resources stop. EKS and RDS are available on
  the Free plan.
- AWS Budgets: alert at $10 of usage, to see credit burn early.
- Week 1: VPC without NAT, ECR, S3. Expected cost: near $0.
- Days 9-10: EKS control plane (~$0.10/h), 2 small nodes, RDS `db.t4g.micro`,
  one NAT Gateway (~$0.045/h + traffic).
- If day 9 does not finish, run `terraform destroy` in `eks/` in the evening and
  `terraform apply` again in the morning.
- Day 10 ends with a check in AWS Billing: no running EKS, RDS, NAT or load balancers.

## 7. Known simplifications (documented in README)

- Database secrets on EKS are a Kubernetes Secret. Production practice: External
  Secrets Operator + AWS Secrets Manager.
- One NAT Gateway, one region, no Multi-AZ RDS.
- Service type LoadBalancer for Traefik on EKS. Alternative: AWS Load Balancer
  Controller with an ALB.
- nginx and php-fpm in one container. Alternative: two containers in one Pod.

## 8. Testing

- Application: Pest feature tests for the `notes` API and `/ready`.
- Chart: `helm lint` and `helm template` in CI.
- Terraform: `fmt -check` and `validate` in CI, `plan` before each `apply`.
- Manual checks (day 10): delete a Pod, deploy a broken image, roll back with
  ArgoCD/Helm, load test to trigger HPA.

## 9. Repository layout

```
laravel-k8s-aws/
├── app/                      Laravel API
├── docker-compose.yml
├── helm/notes-api/
├── k8s/argocd/
├── terraform/
│   ├── bootstrap/
│   ├── base/
│   └── eks/
├── .github/workflows/
└── docs/
    ├── specs/
    ├── architecture.md
    └── interview-notes.md
```

## 10. Day-by-day plan

Each day: ~6 hours of practice, then theory (max 1.5 hours). Theory answers go to
`docs/interview-notes.md`.

Theory format (agreed on 2026-10-15):

1. Claude explains the mechanism (5-10 lines, a scheme, an example from this project).
2. The author asks follow-up questions in the same session.
3. The author writes 3-5 sentences in own words. Deep details go to a "Deeper" subsection.
4. Claude reviews once. Time-box: 25 minutes per question.

A question moves to the day when the author practices the related technology.

| Day | Practice | Theory | Done when |
|-----|----------|--------|-----------|
| 1 | AWS account security (Builder ID MFA), Budgets, `aws login`. GitHub repo. Laravel `notes` API + Pest | IAM: users, roles, policies, least privilege | `aws sts get-caller-identity` works, tests pass |
| 2 | Dockerfile, docker-compose, `/health`, `/ready`. `ci.yml` | Docker layers, image size, non-root | CI is green on a PR |
| 3 | `kind` cluster. Raw manifests: Deployment, Service, ConfigMap, Secret, probes, PostgreSQL StatefulSet | Pod lifecycle, probes, Deployment vs StatefulSet | API answers via `kubectl port-forward` |
| 4 | Traefik Ingress, migration Job, requests/limits, metrics-server, HPA, rolling update | Service types, Ingress, `CrashLoopBackOff`, `OOMKilled`, Pod debugging | API works via Ingress, HPA scales |
| 5 | Helm chart from the manifests. Terraform `bootstrap/` | Helm releases and rollback. Terraform state and locking | One `helm install` starts everything |
| 6 | Terraform `base/`. `release.yml` pushes to ECR via OIDC | VPC, subnets, route tables, IGW vs NAT, SG vs NACL. Day 1 backlog: Q4 least privilege, Q7 STS, Q8 OIDC | Image is in ECR, no AWS keys in GitHub |
| 7 | ArgoCD locally, PreSync migration hook, CI updates image tag | GitOps, push vs pull deploy | Merge to main reaches the cluster |
| 8 | kube-prometheus-stack, ServiceMonitor, alert, dashboard. Terraform `eks/` code, `plan` only | kube-state-metrics, what to monitor in K8s | Dashboard and alert work, `plan` is clean |
| 9 | `apply` `eks/`. Install Traefik, ArgoCD. Deploy with `values-eks.yaml`. Fix kind vs EKS differences | EKS architecture, managed nodes vs Fargate, Pod Identity / IRSA | API works on EKS via a public load balancer |
| 10 | Monitoring on EKS. Failure drills. Screenshots, README, diagram. `destroy` `eks/`, check Billing | AWS cost model, review of all notes | Project done, nothing is running |
| 11-12 | Buffer. CV update. Mock interview | — | CV has a "Projects" section |

If time runs out, cut in this order:

1. Monitoring stays local only.
2. ArgoCD is replaced by `helm upgrade` from CI.

Do not cut EKS or Terraform. Job offers ask for them.
