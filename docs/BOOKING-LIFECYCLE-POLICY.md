# TutorSlot booking lifecycle policy

This document records the business rules that lifecycle code and tests must enforce.

## Past confirmed lessons

Decision: a `confirmed` booking does **not** become `completed` merely because its
`end_utc` time has passed.

`completed` means that the lesson was delivered. The passage of time proves only
that the scheduled window ended; it does not prove attendance. Automatic completion
could therefore inflate completed-lesson reporting and make later review, refund,
credit, or dispute decisions rely on a false attendance record.

After `end_utc`, an unresolved booking remains `confirmed` until an authorized tutor
or site manager records one of the attendance outcomes:

- `completed` when the lesson was delivered;
- `no_show` when the student did not attend.

The tutor-facing application should treat such records as **attendance due** so they
can be resolved, rather than silently changing their status in a scheduled job.

## Implementation contract

- No cron or Action Scheduler task may perform `confirmed -> completed`.
- Attendance transitions must start from the exact `confirmed` state.
- Attendance may be recorded only after the lesson's `end_utc` time.
- The tutor who owns the booking or a site manager may record attendance.
- Every accepted transition must be idempotent and audit logged.
- A rejected transition must leave the booking unchanged.

The transition-specific checklist items cover enforcement and regression tests for
`completed` and `no_show` separately.

## Integration regression matrix

The integration suite locks every supported booking lifecycle edge:

| From | To | Regression coverage |
| --- | --- | --- |
| booking creation | `confirmed` / `pending_payment` | free, credit, and payable booking fixtures |
| `pending_payment` | `confirmed` | verified payment event and replay tests |
| `pending_payment` | `payment_failed` | failed-payment and safe-retry test |
| `pending_payment` | `payment_expired` | exact-attempt expiry, rollback, and late-capture tests |
| `confirmed` | `completed` | tutor/manager, lesson-end, replay, and authorization tests |
| `confirmed` | `no_show` | tutor/manager, lesson-end, replay, and cross-outcome tests |
| `confirmed` | `moved` plus replacement `confirmed` | atomic reschedule, authorization, and rollback tests |
| `confirmed` | `cancelled` | authorization, credit rollback, replay, and cleanup tests |
| `completed` | `refunded` | paid/credit atomicity, retry, and rescheduled-payment tests |
| `payment_expired` | `refunded` | late provider capture auto-refund test |

Closed-state matrix coverage additionally verifies that `completed`, `no_show`,
`moved`, `cancelled`, `refunded`, and `payment_expired` cannot be changed by an
attendance, cancellation, or reschedule request. Replaying the already-applied
attendance/cancellation outcome remains a successful no-op.

## Lifecycle side-effect contract

- Booking creation schedules future 24-hour and 1-hour reminders; rescheduling
  removes the old jobs and schedules them for the replacement booking.
- Cancellation, refund, and pending-payment expiry remove every outstanding
  reminder for the terminal booking.
- Cancellation, rescheduling, and refund request remote meeting cleanup. A
  successful cleanup clears the stored reference and records one
  `meeting.cancelled` audit event.
- Provider cleanup failures retain the reference, retry through Action Scheduler,
  and record `meeting.cleanup_failed` after the final attempt.
- Lifecycle audit events are emitted only after durable state changes. Idempotent
  replays must not create duplicate booking, payment, credit, or meeting events.
