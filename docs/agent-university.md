# Agent University

The learning platform for SureHelp agents (spec §20B, decisions D40–D43). Requirements: [requirements/agent-university-and-assignments.md](requirements/agent-university-and-assignments.md).

## Who sees what

| Person | Can do |
|---|---|
| Agent | Take platform-wide courses, and courses of the companies they serve **right now**. Their own progress, history and certificates |
| Agent supervisor | All of the above. Create, edit and publish courses for their own companies. Give those courses, and published platform-wide courses, to the agents serving their companies. See those agents' progress and certificates |
| Platform staff (super admin, operations manager) | Everything, including platform-wide courses, rules for everyone or a role, and revoking certificates |
| Business owner, manager, staff | Nothing. Training is SureHelp's own workforce tool (D41) |

A company course is invisible to anyone not serving that company. It answers 404 like any unknown page, including its files. When an agent stops serving a company, that company's training disappears for them; their records stay.

## Building a course

*Agent portal › Training › New course*, then:

1. **Content.** Add modules, then lessons. Lesson types:
   - reading (Markdown);
   - video (YouTube or Vimeo link, or an upload);
   - PDF, document, presentation or audio (upload or link);
   - external resource (link);
   - quiz.
2. **Quiz.**
   - Question types: one answer, several answers, true or false, scenario.
   - Settings: pass mark, attempts allowed, and optionally a random subset per attempt and a shuffled order.
   - An explanation is shown when a question is answered wrongly. The correct answers never reach the browser.
3. **Details.**
   - Category, level, length and owner.
   - **Valid for (months):** completions expire and need recertification.
   - **Certification:** a name and certificate ID, issued on completion.
4. **Publish.**
   - The publish panel lists anything still missing.
   - Publishing freezes the draft as version *N*, with a note on what changed.
   - Tick **Agents must take it again** for important changes: earlier completions stop counting.
5. **Agents.** Give the course to:
   - every agent;
   - a role;
   - the agents serving a company;
   - one agent.

   Rules keep themselves up to date. For example, an agent newly assigned to a company gets that company's training straight away, with the rule's due period.

## Company readiness (D43)

A company rule can be *required*, with an enforcement level:
- informational;
- warning;
- restricted (no calls or bookings until done);
- blocking (only the company's training is open).

"Training ready" means every required course for that company, plus every required platform or role course, is completed, on the version that counts, and not expired. Readiness shows in three places:
- *My companies*, for the agent;
- the company *Training* tab;
- the agent profile and the *Assignments* screen, before a supervisor confirms an assignment.

Enforcing *restricted* and *blocking* in the workspace arrives with step A-4.

## Records

Nothing is overwritten:
- every lesson completion, with time spent;
- every quiz attempt: questions shown, answers, score;
- every completion: version, score, time, expiry;
- every certificate: issued, expired or revoked, with a reason.

These actions are written to the audit log: course created, updated and published; rules added and removed; training assigned and removed; completed; certificate issued and revoked.

## Operations

- **Starter content:** `php artisan training:starter` creates two draft platform courses: *SureHelp Call Handling Standards* and *Privacy and Security Essentials*. Review, adapt and publish them. Running it again skips courses that already exist.
- **Files:** stored on `TRAINING_DISK` (default `local`, private), up to `TRAINING_MAX_UPLOAD_MB` (default 50). PHP's `upload_max_filesize` and `post_max_size` must allow that size. Link long videos (unlisted YouTube or Vimeo) instead of uploading them.
