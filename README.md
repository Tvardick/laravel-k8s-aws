# laravel-k8s-aws

A Laravel API deployed to AWS EKS with Terraform, Helm, ArgoCD and GitHub
Actions.

Work in progress. Design: [docs/specs/2026-10-14-laravel-k8s-aws-design.
md](docs/specs/2026-10-14-laravel-k8s-aws-design.md)

## Stack

- App: Laravel API + PostgreSQL
- Infrastructure: AWS (VPC, ECR, EKS, RDS) with Terraform
- Kubernetes: Helm, Traefik, ArgoCD, kube-prometheus-stack
- CI/CD: GitHub Actions with OIDC (no static AWS keys)
