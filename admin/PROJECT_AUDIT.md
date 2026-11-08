# PBI Admin Project Audit & Navigation Correction

Date: 2026-09-19

## Scope inspected

The submitted ZIP contains 51 files under `admin/`, including the PHP feature pages, AJAX/support endpoints, SQL schema, authentication helpers, theme assets, and the bundled PHPMailer library. The review traced the visible admin navigation, feature-to-page routing, role/permission guards, settings/archive flows, evaluation/reporting flows, and local/shared dependencies.

## Functional map

### 1. Entry, authentication, and session flow
- `index.php` — role/login landing page.
- `admin_login.php` — admin authentication and redirect into the admin dashboard.
- `admin_register.php` — registration flow with registration lock/approval behavior.
- `force_password_change.php` — mandatory password-change gate after login.
- `forgot_password.php` / `reset_password.php` — password reset request and token completion.
- `session_bootstrap.php` — shared admin-session guard/bootstrap.
- `logout.php` — admin/session termination endpoint.
- `db.php` — MySQL connection and upload-path constants used throughout admin pages.

### 2. Main dashboard shell
- `admin_dashboard.php` — single-page admin shell; owns the sidebar, settings modal, page switching, dashboard polling, appearance synchronization, and embedded feature pages.
- `dashboard_counts.php` — JSON counts/live dashboard data endpoint.
- `dashboard_activity.php` — dashboard activity/audit feed endpoint.

### 3. Evaluation setup and assignment
- `questionnaire.php` — evaluation-type configuration, questionnaire management, target grouping, and assignment flows.
- `manage_periods.php` — legacy/standalone evaluation-period management surface.
- `teaching_assignments.php` — teaching assignment and evaluator/target preparation flow.
- `personnel_registry.php` — personnel registry/maintenance.

### 4. Evaluation execution
- `evaluate_questionnaire.php` — retired legacy form; returns HTTP 410. Student evaluation is handled by the authenticated `../student/student_dashboard.php` workflow.
- `evaluation_tracker.php` — evaluation tracking/status view.
- `evaluation_tracker1.php` — alternate/legacy tracker implementation retained in the package.
- `student_tracker.php` — student-focused tracker view.
- `ea_evaluation.php` — Executive Assistant evaluation roster.
- `ea_evaluate.php` — per-person Executive Assistant evaluation form.

The former standalone `faculty/my_evaluations.php` report is also retired and returns HTTP 410. Current faculty and staff results are served from their authenticated dashboards. Both legacy files previously assigned fallback user identities and must not be restored as active pages.

### 5. Results, reporting, and analytics
- `admin_analytics.php` — evaluation results/reporting surface; filters by evaluation context/type and target group, with archive/restore-related roster handling.
- `certification.php` — certification-related administration/output.
- `documents.php` — document management feature.

### 6. Administration, governance, and audit
- `accounts.php` — user/account management.
- `manage_privileged_accounts.php` — privileged/special-role account administration.
- `permissions.php` — permission checks used by admin features.
- `system_logs.php` — system/audit log surface.
- `settings.php` — unified Settings container and tab routing.
- `system_settings.php` — academic structure/term/evaluation schedule configuration.
- `system_archive.php` — archive/restore completed evaluation periods.
- `admin_ajax.php` — shared AJAX/UX utility code.
- `admin_analytics.php` also contains result/archive support logic used by the reporting workflow.

### 7. Shared support/configuration
- `notify_role_change.php` — role-change notification helper.
- `mail_config.php` — mail configuration values.
- `admin_appearance.css` / `admin_appearance.js` — shared appearance/theming behavior.
- `admin_aura_theme.css` — additional theme styling.
- `admin_palette.png` / `background.png` / `bacjground.png` — image/theme assets.
- `evaluation.sql` — database schema/data definitions used by the evaluation system.
- `ROLE_EVALUATION_RULES.md` — documented role/evaluation rules.
- `PHPMailer.php`, `SMTP.php`, `PHPMailer/src/SMTP.php`, `Exception.php` — bundled PHPMailer library files.

## End-to-end process traced

1. A user enters through the landing/login flow, then receives an authenticated session.
2. `admin_dashboard.php` becomes the shell and stores the last selected page in `localStorage`.
3. Questionnaire/assignment configuration determines which evaluation direction, target pool, and period are active.
4. Evaluation submission pages write tracker/submission/answer/result data using the shared period/context rules.
5. Tracker/report pages read those records and expose progress, target-level results, and analytics.
6. Administrative pages manage users, permissions, system settings, logs, and archival state.
7. `settings.php` consolidates system configuration, archive, and appearance into one Settings workspace.

## Navigation correction made

The sidebar previously mixed administrative account management into the main evaluation workflow. It is now grouped by workflow ownership:

**MAIN**
- Dashboard
- Questionnaire
- Evaluation Tracker
- My Evaluations / EA Evaluations
- Evaluation Reports

**ADMINISTRATION**
- Account Management (Super Admin only)
- System Logs
- Settings

**ACCOUNT**
- Log Out

The page IDs, `showPage()` targets, and existing permission behavior were preserved. Only the visual/order grouping and the report label were changed. Section labels also hide when the compact-sidebar option is enabled.

## Why this placement matches the implementation

- Dashboard, Questionnaire, Tracker, EA/My Evaluations, and Reports form the primary evaluation workflow.
- Account Management is privileged administration rather than an evaluation step.
- System Logs and Settings are operational/administrative controls.
- Logout is an account/session action and therefore belongs at the bottom under ACCOUNT.

## Settings tabs

The nested Settings workspace still contains:
- System & Period
- System Archive
- Appearance

Those are already functionally consolidated inside `settings.php`, so the navigation fix did not split or duplicate them.

## Validation performed

- PHP syntax checked for every `.php` file in the submitted `admin/` folder; no syntax errors were reported.
- The modified `admin_dashboard.php` was independently linted after the navigation change.
- The navigation diff was reviewed to confirm only sidebar ordering/grouping, section-label styling, and the plural `Evaluation Reports` label changed.

## Package-level limitations found during inspection

The ZIP does not include several runtime dependencies referenced by the admin pages, including:
- `admin_ui_theme.css`
- `admin_compact_ui.css`
- `choose_role.php`
- several `../shared/*.php` services such as `EvaluationContextService.php`, `SchoolHeadAssignmentService.php`, and `system_settings_service.php`
- role-specific login pages referenced by `index.php`

These files may exist in the full deployed application, but they were not present in the submitted ZIP, so their runtime behavior could not be independently verified from this package alone.
