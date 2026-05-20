---
name: php-erp-crm
description: Use for local PHP ERP/CRM development on shared hosting, including CRUD modules, admin panels, forms, MySQL queries, CSV/XLSX import-export, access control, refactoring legacy PHP, and project-specific code review.
***

# PHP ERP/CRM

Use this skill for a classic PHP ERP/CRM codebase running on shared hosting with a local development workflow.

## When to use

Use this skill when the task involves:
- PHP business logic, CRUD modules, admin screens, filters, reports, exports, imports.
- Legacy PHP refactoring without introducing heavy infrastructure.
- MySQL query review, optimization, and safer query patterns.
- Form handling, validation, sessions, authentication, and role-based access.
- ERP/CRM entities such as clients, orders, invoices, products, warehouse, payments, managers, tasks, leads.
- CSV/XLSX imports and exports.
- Shared-hosting-friendly architecture and deployment constraints.

Do not use this skill for:
- Laravel, Symfony, Docker-first, Kubernetes, or microservice-heavy recommendations unless the user explicitly asks for them.
- Recommending infrastructure that shared hosting cannot support.

## Operating assumptions

Assume:
- The project is mostly local files.
- The target runtime is plain PHP on shared hosting.
- MySQL or MariaDB is the primary database.
- The codebase may be partly legacy and mixed procedural/OOP.
- Backward-compatible, incremental improvements are preferred over rewrites.

## Core rules

1. Prefer incremental refactoring over full rewrites.
2. Keep solutions compatible with shared hosting.
3. Prefer plain PHP, PDO, simple classes, reusable helpers, and clear folder structure.
4. Avoid suggesting Redis, queues, Docker-only workflows, daemons, workers, or background services unless explicitly requested.
5. Optimize for maintainability, security, and low-risk deployment.
6. Preserve existing business logic unless the user asks to redesign it.
7. When editing legacy code, explain the safest minimal change first.

## Architecture guidance

Prefer structures like:
- `admin/` or `panel/` for back office pages.
- `includes/`, `system/`, `lib/`, `classes/` for reusable logic.
- `templates/` or `views/` for HTML fragments.
- `config.php` or `config/*.php` for configuration.
- `uploads/` with validation and access restrictions.
- `storage/exports/` or temporary export directories outside public web access when possible.

When proposing improvements:
- Separate DB access from HTML rendering where practical.
- Extract repeated SQL or validation logic into helpers.
- Reduce copy-paste CRUD patterns.
- Keep routing simple if the project is file-based.

## Database rules

- Prefer PDO with prepared statements.
- Never interpolate raw request data into SQL.
- Validate and normalize GET/POST values before DB usage.
- For list screens, separate filtering logic, sorting logic, and pagination logic.
- When reviewing slow modules, inspect SELECT fields, indexes, joins, and repeated queries.
- For reports, prefer explicit date boundaries and controlled aggregation queries.

## Security rules

Always check for:
- SQL injection.
- XSS in echoed HTML values.
- Missing CSRF protection on state-changing forms.
- Weak session handling.
- Insecure file upload validation.
- Missing role checks on admin actions.
- Direct access to config or export files.

When generating fixes:
- Escape output in HTML contexts.
- Use CSRF tokens for create/update/delete actions.
- Validate file type, extension, MIME, and size for uploads.
- Restrict dangerous actions by role.
- Prefer allowlists over blocklists.

## Forms and CRUD

For CRUD modules:
- Keep list, create, edit, save, delete flows explicit.
- Reuse validation between create and update.
- Return user-friendly validation messages.
- Preserve submitted values after validation errors.
- For destructive actions, require confirmation.
- For list pages, support search, filters, sorting, and pagination when useful.

## Import/export rules

For CSV/XLSX features:
- Validate header mapping before import.
- Normalize encoding, separators, decimals, and dates.
- Log skipped rows and reasons.
- Design import as preview -> validate -> commit when possible.
- For exports, keep columns predictable and stable.
- For large exports on shared hosting, prefer chunked processing where feasible and avoid memory-heavy approaches.

## Coding style

- Prefer clear, boring, readable PHP over clever abstractions.
- Keep functions small and named by business action.
- Use early returns for validation failures.
- Avoid hidden side effects.
- Use associative arrays and DTO-like structures consistently.
- When changing legacy code, match the surrounding style unless a local refactor clearly improves safety.

## How to respond

When helping with a task:
1. Identify the module or business entity involved.
2. Respect shared-hosting constraints.
3. Suggest the smallest safe implementation path.
4. Call out security and data integrity risks.
5. If relevant, propose a future improvement path separately from the immediate fix.

## Good task examples

- Refactor this orders module from mysqli to PDO with minimal risk.
- Add role checks for manager/admin actions in the clients section.
- Review this invoice export for encoding and memory issues.
- Design a clean import flow for products from XLSX.
- Find duplicate SQL logic in leads and contacts modules.
- Improve the structure of this CRM admin page without changing business behavior.