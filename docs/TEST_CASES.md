# EPEMS Functional Test Cases

## Purpose

This test set maps the EPEMS scope to the implementation in this repository. Run it against a dedicated test installation containing synthetic accounts and evaluation data. Do not execute write, archive, or reset cases against production data.

The attached EPEMS project document describes role-based evaluation, questionnaire and personnel management, assignments, submissions, rating computation, tracking, reports, and logs. It does not include an executable test-case table or recorded results.

## Test data

Prepare synthetic accounts for the Executive Assistant/administrator, Dean, Principal, Faculty/Teacher, Staff, and Students from Junior High, Senior High, and College. Include approved, pending, blocked, inactive, assigned, unassigned, and multi-role cases. Prepare two periods, one open and one closed, and questionnaires for each applicable target/evaluator context.

Record the build, schema version, test date, tester, and browser. Never use real student or evaluation data for these tests.

## Cases

| Test Case ID | Test Scenario | Test Steps | Test Data | Expected Results | Actual Results | Passed/Failed |
|---|---|---|---|---|---|---|
| AUTH-01 | Login for admin/EA, student, teacher, staff, dean, and principal | Sign in through each role's portal, then open the redirected landing page. | Six synthetic approved accounts in the isolated test database. | Each account reaches its matching portal without a PHP/database error. | All six sign-ins redirected to their expected landing pages (HTTP 302); each landing page returned HTTP 200 without PHP/database errors. | Pass |
| AUTH-02 | Student login rejection | Attempt login as a pending, blocked, inactive, then approved account with an incorrect password. | Four synthetic student accounts/states; no production accounts. | Pending, blocked, inactive, and invalid credentials cannot authenticate. | All four attempts stayed on the login page and showed the expected pending, blocked, or generic rejection message; no login redirect. | Pass |
| AUTH-03 | Role-based portal isolation | For each role, request the next role's protected dashboard directly. | Six authenticated synthetic role sessions. | Cross-role dashboard access is denied and redirected to the requested portal's sign-in page. | All six cross-role dashboard requests returned HTTP 302 to that role's sign-in page. | Pass |
| AUTH-04 | Logout and session invalidation | Log out of each role session, then revisit that role's protected dashboard. | Six authenticated synthetic role sessions. | Logout redirects to sign-in; protected pages reject the old session. | All six logout flows redirected to sign-in; each subsequent dashboard request returned HTTP 302 to sign-in. | Pass |
| ADMIN-01 | Account management | Authorized administrator and synthetic accounts. | Create, edit, approve, block, deactivate, and reactivate an account. | Changes persist; only authorized roles can make them; required actions are logged. | — | Not run |
| ADMIN-02 | Personnel and assignments | Synthetic faculty/staff accounts. | Add and remove teaching, year-level, and department assignments. | Eligibility lists reflect only the saved assignments. | — | Not run |
| QUESTION-01 | Questionnaire management | Authorized EA/admin; test targets exist. | Create, edit, deactivate, and view questions for Faculty, Staff, Dean/Principal, and EA contexts. | Correct question set appears for each context; unrelated contexts remain separate. | — | Not run |
| PERIOD-01 | Schedule | Open and closed test periods configured. | Try submitting before opening, during the window, after closing, and under Force Open/Closed. | Access follows the schedule and override; inactive periods cannot accept submissions. | — | Not run |
| STUDENT-01 | Eligible student evaluation | Student and target share an assignment; active period and questionnaire exist. | Submit a complete evaluation. | One submission and its answers are stored for the correct target, period, and context. | — | Not run |
| STUDENT-02 | Student eligibility rejection | Mismatched and unassigned targets exist. | Attempt submission in the UI, then post a crafted target ID. | Both are rejected server-side; no tracker or answer rows are written. | — | Not run |
| STUDENT-03 | School-head scope | JHS/SHS and College students; Dean and Principal targets. | Try evaluating each head from each student level. | Only the applicable head is available and accepted for that level. | — | Not run |
| STUDENT-04 | Rating validation and duplicate | Eligible target and open period. | Submit missing/out-of-range ratings, then valid ratings, then repeat the valid submission. | Invalid/incomplete and duplicate submissions are rejected; accepted ratings are within scale. | — | Not run |
| PEER-01 | Peer evaluation | Faculty/Staff evaluator and eligible colleague. | Submit a peer evaluation; try self-evaluation and a non-Faculty/Staff target. | Valid submission is stored; self and invalid targets are rejected. | — | Not run |
| STAFF-01 | Staff and Multi-Role contexts | Staff target has a genuine separate additional role. | Submit evaluations under each configured context; also test a teaching assignment alone. | Contexts stay separate; an assignment alone does not create Multi-Role. | — | Not run |
| HEAD-01 | Dean/Principal evaluation | Dean and Principal evaluator accounts with in- and out-of-scope targets. | Submit in-scope evaluations and try out-of-scope targets. | In-scope submissions succeed; invalid scope is denied; only applicable head channel is open. | — | Not run |
| EA-01 | EA evaluation | EA account; Principal, Dean, and non-teaching Staff targets have question sets. | Submit for each; try teaching-assigned Staff and duplicate submission. | Only eligible targets are accepted; EA questions and one-per-period rules apply. | — | Not run |
| RESULT-01 | Rating computation | Known synthetic answers with a manually computed average. | Submit answers and open the resulting report. | Overall/category/question results match the manual calculation and correct context/period. | — | Not run |
| RESULT-02 | Confidentiality and record scope | Evaluations exist for multiple targets/evaluators. | Sign in as an evaluated user; change result IDs; inspect returned fields. | Only permitted results appear; evaluator identity is withheld where anonymity is required. | — | Not run |
| TRACK-01 | Evaluation tracking | Synthetic submissions with submitted, incomplete, and absent states. | Open the relevant EA, Dean, and Principal trackers. | Counts/statuses match the correct period, role, target, and completion state. | — | Not run |
| REPORT-01 | Reports and analytics | Synthetic submissions across periods and academic scopes. | Generate reports and change period/scope filters. | Totals and averages match test records; unrelated data is excluded. | — | Not run |
| LOG-01 | System logs | Test accounts can perform account/configuration/evaluation actions. | Perform actions and inspect System Logs. | Required actions record the correct actor, target, action, and time. | — | Not run |
| ARCHIVE-01 | Period archive | Test period has submissions/results; backup exists. | Archive, inspect historical reports, and restore if supported. | Historical records remain intact/retrievable and active workflows use the current period. | — | Not run |
| SECURITY-01 | CSRF and direct endpoint protection | Submit the student login form without a CSRF token; separately test a protected mutation and a signed-out action endpoint. | Synthetic student session; no token on the first request. | Requests without valid CSRF or authentication are rejected without changing data. | The login request without its CSRF token was rejected with the session-expired message; mutation and signed-out action endpoints were not exercised. | Partial |
| SECURITY-02 | Retired legacy pages | Request `/admin/evaluate_questionnaire.php` and `/faculty/my_evaluations.php` without signing in. | No login or data required. | Both return HTTP 410 without evaluation or database content. | Both isolated-server requests returned HTTP 410 and only their retirement messages. | Pass |
| USABILITY-01 | Usability | Representative account per role and seeded tasks. | Ask testers to sign in, locate an assigned evaluation, submit it, and find its status/results. Record time/errors. | Task completion and usability evidence are recorded for evaluation against agreed criteria. | — | Not run |
| EFFICIENCY-01 | Efficiency | Repeatable synthetic dataset and fixed browser/server. | Measure dashboard, questionnaire, submission, and report response times over repeated runs. | Measurements meet acceptance thresholds set before execution; dataset and setup are recorded. | — | Not run |

## Reported Dean regression checks

These checks correspond to the five failures reported from the separate 42-case review. They were rerun after correcting the Dean database include to use the exact tracked spelling of the shared authentication service filename.

| Test Case ID | Test Scenario | Test Steps | Test Data | Expected Results | Actual Results | Passed/Failed |
|---|---|---|---|---|---|---|
| EVAL15 | Dean login page availability | Open `/dean/dean_login.php` in a fresh session. | Isolated synthetic test database; no sign-in. | Login form responds successfully with HTTP 200. | HTTP 200; Dean login title and form rendered. | Pass |
| EVAL16 | Dean login can be opened | Open `/dean/dean_login.php` in a second fresh session. | Isolated synthetic test database; no sign-in. | Login page opens without an HTTP 500. | Independent request returned HTTP 200. | Pass |
| EVAL17 | Dean dashboard availability and access guard | Sign in as synthetic Dean and open the dashboard; then request it in a fresh signed-out session. | Synthetic Dean account; isolated test database. | Signed-in dashboard returns HTTP 200; signed-out request redirects to Dean login rather than returning HTTP 500. | Sign-in redirected to the dashboard (HTTP 302), authenticated dashboard returned HTTP 200 without PHP/database errors, and signed-out request redirected to `dean_login.php` (HTTP 302). | Pass |
| EVAL41 | Authentication service include casing | Compare the include target in `dean/db.php` with the exact tracked filename in `shared/`. | Repository file names. | Include path matches the tracked file name on case-sensitive systems. | `dean/db.php` now includes `Authenticationservice.php`, matching the tracked shared file exactly. | Pass |
| EVAL42 | Dean login regression | Submit valid synthetic Dean credentials through the login form and open the redirect target. | Synthetic Dean account in the isolated test database. | Login succeeds and the dashboard opens without HTTP 500. | Login returned HTTP 302 to `dean_dashboard.php`; authenticated dashboard returned HTTP 200. | Pass |

## Execution record

| Field | Value |
|---|---|
| Test environment | Isolated PHP 8.2 built-in server at `http://127.0.0.1:8091/` and `http://127.0.0.1:8092/`; MariaDB 10.4.32 |
| Database | `epems_codex_test_20261006`, cloned schema only (43 table definitions; no project records), with nine synthetic accounts |
| Build/commit | `agent/ea-evaluation-access` based on `421f29a`, with local legacy-page retirement and Dean include-case fix |
| Test date / tester / client | 2026-10-06 / Codex / Python `urllib` HTTP checks and XAMPP PHP 8.2 lint |
| Executed evidence | The 26-case matrix has 25 successful HTTP assertions across login/landing, account-state rejection, role isolation, logout, CSRF login rejection, and retired-page responses. The five reported Dean regression cases EVAL15, EVAL16, EVAL17, EVAL41, and EVAL42 also pass in the isolated environment. The SECURITY-01 row is partial because its mutation and signed-out action cases were not run. |
| Syntax check | 133 PHP files linted with XAMPP PHP 8.2; 0 syntax errors (syntax check is not a functional test) |
| Overall result | In the original 26-case matrix, five cases passed (AUTH-01 through AUTH-04 and SECURITY-02), one is partial (SECURITY-01), and 20 remain not run. Separately, all five reported Dean regressions (EVAL15, EVAL16, EVAL17, EVAL41, EVAL42) now pass. Remaining workflow cases need synthetic assignments, periods, questionnaires, evaluation answers, reports, and/or action-specific test coverage. |

For every run, record the actual result and evidence, such as a sanitized screenshot, HTTP response, or test-database query result. Mark Fail when observed behavior differs from expected; mark Blocked when required environment or seed data is unavailable. Do not mark a case Pass based only on code inspection.
