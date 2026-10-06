# Add-on requirements: Agent University and strict agent company assignment

_Received from the product owner on 2026-10-06. Kept for traceability, condensed in wording only (every requirement, example and acceptance criterion is retained). The master spec (§20A, §20B) points here and docs/implementation-plan.md §4i tracks delivery. Decisions: D40–D43._

---

# SURE HELP SOLUTION — ADD-ON REQUIREMENTS
## Agent Portal University + Strict Agent Company Assignment & Access Control

We need to add two enterprise-level capabilities to the existing Sure Help Solution platform.

IMPORTANT:
Do not treat these as simple UI features. They must be implemented as proper platform-level capabilities with database design, authorization, audit logging, security, scalability, and future mobile-app support.

Before making changes, inspect the existing architecture, authentication, roles/permissions, organization/company structure, agent management, supervisor functionality, APIs, database relationships, and current Agent Portal.

Do not break existing functionality.

## 1. Agent Portal University

Create a new module called "Agent University": the centralized learning and training platform for Sure Help Solution agents, so that every agent has access to the correct training, understands company-specific procedures, and can continuously improve their performance.

Designed to support: training courses, learning paths, videos, documents, PDFs, presentations, audio, text lessons, knowledge articles, quizzes, assessments, certifications, progress tracking, mandatory training, optional training, company-specific training, agent-specific assignments, training expiration, refresher training, supervisor monitoring, training analytics.

### 1.1 Navigation
Agent University → My Learning, Assigned Training, Recommended, Completed, Certifications, Training History. Simple and professional. The agent should immediately understand what training is required, in progress, completed, overdue, which certifications are active, and what is recommended.

### 1.2 Training dashboard
Overall training progress; required training; in-progress courses; recently completed; upcoming/overdue; certifications; recommended courses. Example: "Training Progress 72%", "Required Training 3 of 5 completed", "In Progress: Customer Communication", "Overdue: Company Security Training", "Certification: Virtual Receptionist — Active".

### 1.3 Content types
Video, PDF, document, presentation, audio, text lesson, external training resource, quiz, assessment. Do not hard-code the system around videos only; use a flexible content architecture.

### 1.4 Course structure
University → Learning Path → Course → Module → Lesson → Assessment. Example: Customer Service Learning Path → Course "Virtual Receptionist Fundamentals" → Modules 1–5 (Introduction, Call Handling, Customer Communication, Appointment Scheduling, Escalation Procedures) → Final Assessment. Must allow future expansion.

### 1.5 Course metadata
Title, description, thumbnail, category, difficulty, estimated duration, instructor/owner, required/optional, active/inactive, version, published date, last updated, expiration period, certification eligibility.

### 1.6 Mandatory training
Training can be Required or Optional; required training appears prominently ("Required before handling ABC Plumbing calls"). An agent is not considered fully qualified for a company/service until required training is completed. Do not automatically block all agent activity unless the business rule explicitly requires it. Configurable enforcement levels: Informational, Warning, Restricted, Blocking.

### 1.7 Company-specific training (critical)
Companies have unique procedures, services, policies, pricing rules, escalation rules, communication standards and appointment rules. Training assignable at platform-wide, company-wide, team-wide, role-based and agent-specific levels. An agent assigned to two companies receives both companies' programmes.

### 1.8 Training assignment
Assign to an individual agent, agent team, company, role, or the entire agent population. Assignments include assigned by, assigned date, due date, priority, required/optional, completion requirement, expiration date, status (Assigned, Started, In Progress, Completed, Overdue, Expired, Revoked).

### 1.9 Training progress
Track course started, lesson started/completed, quiz attempts and scores, completion percentage, time spent, last accessed, completion date. Persisted server-side, not only in the frontend.

### 1.10 Quiz and assessment
Multiple choice, multiple answer, true/false, scenario-based questions; passing score; attempt limits; retakes; randomised questions where appropriate. Store all attempts for reporting.

### 1.11 Certifications
Courses or learning paths can generate certifications: agent, certification name, course, issue date, expiration date, certification ID, status (Active, Expiring Soon, Expired, Revoked), version. Future: downloadable certificate PDF.

### 1.12 Expiration / recertification
Training valid for a period (e.g. security, 12 months); after expiry "Recertification required" (security, privacy, company policies, call handling standards, compliance).

### 1.13 Supervisor training dashboard
Agents, required training completed %, overdue, expiring soon, at risk; table of agent, company, training, progress, score, due date, status.

### 1.14 Training notifications
New training assigned, due soon, overdue, completed, certification expiring, certification expired, new recommended training. In-app, email, push later. Do not spam; configurable rules.

### 1.15 Recommendations
Eventually based on assigned companies, role, performance, failed assessments, new company procedures, expiring certifications, supervisor recommendations ("You recently started supporting ABC Plumbing. Complete 'ABC Plumbing Service & Booking Guide' before your next shift.").

### 1.16 Versioning
Content supports versions (1.0, 2.0). When a critical course changes, supervisors decide whether agents must complete the updated version. Never silently overwrite historical training records.

### 1.17 Audit trail
Who created, edited, published, assigned, completed it; score; attempts; completion date; version completed.

## 2. Agent company assignment and strict access control (security-critical)

An agent may ONLY access companies explicitly assigned by an authorized supervisor or administrator. The agent must NEVER be able to browse or search all companies, guess another company ID, or access another company's customers, calls, appointments, messages, knowledge base, files, calendar, reports, training, billing or integrations. Enforced server-side; hiding companies in the UI is not sufficient.

### 2.1 Assignment model
Agent → Agent Company Assignment → Company. Fields: id, agent_id, organization_id, assigned_by, assigned_at, start_date, end_date, status (Active, Scheduled, Suspended, Ended, Revoked), assignment_type, notes, timestamps. Do not delete historical assignments.

### 2.2 Assignment history
Assigned / removed dates and reason stay auditable.

### 2.3 Supervisor responsibility
An "Agent Assignments" module: view agents and their companies, assign, remove, schedule future assignment, end, reassign, view history. Before assigning, show workload, current companies, active assignments, training status, availability.

### 2.4 Workflow
Select agent + company → checks (agent active? company active? required training completed? conflict? already assigned?) → confirm → Active → agent notified → company appears in the Agent Portal. Transactional.

### 2.5 "My Companies"
Only assigned companies, e.g. "ABC Plumbing — Active — 5 appointments today".

### 2.6 Company context
Inside a company: Dashboard, Customers, Calls, Appointments, Messages, Services, Knowledge Base, Company Training, Tasks, Calendar; clearly showing "Currently viewing: ABC Plumbing".

### 2.7 Context switching
A secure company switcher listing only active assignments. Never allow manual organization IDs to switch context.

### 2.8 Backend authorization (mandatory)
Every company-scoped query validates authenticated user + role + active assignment + organization ID. No reliance on hidden dropdowns, frontend routes, JavaScript or supplied IDs.

### 2.9 API protection
Every company-data endpoint enforces assignment authorization (e.g. GET /api/v1/organizations/{organization}/customers rejects unassigned agents). Don't reveal whether an unauthorized organization exists.

### 2.10 Query protection
Company-scoped queries apply the user's organization scope in the database (WHERE organization_id IN assigned organizations), never filter after loading.

### 2.11 Nested resources
Every nested resource (customers, calls, appointments, messages under a company) verifies ownership/organization scope; changing a customer ID must not expose another company's record.

### 2.12 Cross-tenant security tests
Agent A (Company A) CAN view Company A and its customers, calls, appointments, messages; CANNOT view Company B or its customers, calls, appointments, messages, calendar, files. Test web routes and API endpoints, including direct ID manipulation.

### 2.13 Visibility
Agents see only assigned companies and their data, training, tasks, appointments, customers, messages. Not client billing, subscription, payment methods, other companies, platform administration or internal platform-only information unless separately permitted.

## 3. Company training + assignment integration
On assignment: identify required company training → check history → auto-assign missing → notify → supervisor monitors → training status visible on the assignment screen.

### 3.1 Training eligibility
Configurable: assigned but cannot handle calls independently until trained, or accessible but marked "Training Required". No universal hard-coded blocking.

### 3.2 Supervisor assignment screen
Agent's current companies, training %, incomplete required items; company's required training and "Training Ready: Yes/No" with a warning such as "John has 2 required training items outstanding for this company."

## 4. Data model
Inspect the schema first; don't duplicate existing structures. Candidates: organizations, agents, agent_company_assignments, assignment_history, training_categories, learning_paths, courses, course_modules, lessons, lesson_contents, assessments, assessment_questions, assessment_options, assessment_attempts, training_assignments, training_progress, certifications, certification_records, training_versions, training_audit_logs. Follow naming conventions; respect tenant isolation.

## 5. Permissions
agent_university.view / learn / complete / assessment; training.create / update / publish / assign / view_progress / manage_certifications; agent_assignments.view / create / update / revoke; company.view, company.customers.view, company.calls.view, company.appointments.view, company.messages.view, company.calendar.view. Supervisors don't get unrestricted access by default; permission-based authorization.

## 6. Admin / supervisor UX
Agent Management (list, profile, companies, training status, availability, performance); Company Assignments (agent, company, status, dates, training readiness, assigned by); Training Management (courses, learning paths, assignments, progress, certifications, expiring certifications).

## 7. Agent profile
Basic information, availability, assigned companies, training progress, certifications, performance, tasks, recent activity; nothing sensitive unnecessarily.

## 8. Mobile
Everything API-ready with the same authorization rules; no separate mobile authorization system.

## 9. Audit logging
Company assigned / unassigned, assignment suspended / resumed / expired, training assigned / completed / revoked, certification issued / revoked: who, what, when, agent, company, previous state, new state, reason.

## 10. Notifications
"You have been assigned to ABC Plumbing." "You have new required training for ABC Plumbing." "Your ABC Plumbing training is overdue." "Your assignment to ABC Plumbing has ended." "Your Customer Communication certification expires in 30 days."

## 11. Performance
No loading everything into the browser: server-side pagination, indexed queries, relationships, eager loading, caching, background jobs. Many assignments per agent without degradation.

## 12. Security acceptance criteria
Agent sees only active assignments; cannot reach another company by URL, API request, organization ID, customers, calls, appointments, messages, calendar, files, billing, private knowledge or nested IDs; mobile/API follows the same rules; cross-tenant tests pass.

## 13–14. Approach and order
Audit first, plan, don't duplicate, no second auth system, don't break the Agent Dashboard. Order: (1) assignment architecture, (2) server-side authorization and isolation, (3) My Companies, (4) supervisor assignment management, (5) University foundation, (6) courses / lessons / paths, (7) assignments and progress, (8) company-specific training, (9) assessments and certifications, (10) notifications and audit, (11) automatic training on company assignment, (12) automated and security tests.

## 15. Principle
Agents know which companies they serve, what to learn and what to do today; supervisors assign confidently and see qualification, workload and training; business owners know the agent only sees their business; administrators scale securely. **Never rely on UI visibility for security.**
