# Photography Booking System – Backend API

**A Web-based Centralized Photography Booking System for Photographers of Bulan, Sorsogon with Dynamic Package Customization and Rating System**

![Backend tests](https://img.shields.io/badge/backend%20tests-508%20passed-brightgreen)
![E2E tests](https://img.shields.io/badge/e2e%20tests-60%20passed-brightgreen)

Laravel REST API for the photography booking platform. The React + TypeScript client lives in the companion repo **`capstone-frontend`**.

## Key Features

- Client, photographer and admin roles with email verification, password reset, suspension and appeals
- Photographer applications (freelance and studio) with admin review, revision requests and reapplication
- Fixed packages, **dynamic custom packages** (fixed, hourly, per-day and per-person pricing), add-ons and multi-session bookings
- Availability windows, blocked dates, slot conflicts, waitlist and slot offers
- Booking lifecycle: request, accept or decline, expiry, budget negotiation, reschedule, modify, cancel, no-show reports with admin review
- GCash payment submission and verification, onsite balance recording and refund recording
- Service tracker, reviews and ratings, notifications, reports, dashboards and activity logs

## Project Snapshot

### Automated test results (latest recorded run)

| Layer | Repo | Framework | Environment | Tests | Passed | Failed | Duration |
|---|---|---|---|---:|---:|---:|---:|
| Backend (API) | `casptone-backend` | Laravel `php artisan test` (Unit + Feature) | Local, test database | 508 |  508 | 0 | 140.72 s |
| Frontend (E2E) | `capstone-frontend` | Playwright | Chromium, real Laravel API + seeded data | 60 |  60 | 0 | 20.9 min |
| **Total** | | | | **568** |  **568** | **0** | |

The backend run reports 1,968 assertions.

### Documentation snapshot

| Document | Scope | Result |
|---|---|---|
| Manual test cases | 130 designed cases in 13 modules | 106 passed manually · 24 not yet run manually (all 24 now have automated coverage, see [reconciliation](#manual-cases-not-yet-run-vs-automated-coverage)) · 0 failed |
| Bug report | 18 bugs across 3 prototypes | 13 fixed · 2 for enhancement · 2 open · 1 status not recorded |

##  Getting Started

```bash
composer install
copy .env.example .env      # use cp on macOS/Linux
php artisan key:generate
# set your database credentials in .env, then:
php artisan migrate --seed
php artisan serve
```

## Automated Testing

Backend and end-to-end suites both pass in full. This repo owns the backend suite; the e2e suite lives in `capstone-frontend`.

### Backend – Laravel (508 tests, all passing)

```bash
php artisan test
```

| Area | Test classes | Tests | Result |
|---|---:|---:|---|
| Accounts, auth and sessions | 9 | 57 |  all passed |
| Photographer applications and profiles | 6 | 46 |  all passed |
| Packages, custom packages and add-ons | 4 | 59 |  all passed |
| Availability and public discovery | 7 | 53 |  all passed |
| Booking creation and multi-session | 3 | 24 |  all passed |
| Booking lifecycle, expiry and service tracker | 3 | 36 |  all passed |
| Change requests, cancellation and no-show | 4 | 73 |  all passed |
| Budget negotiation | 1 | 28 |  all passed |
| Waitlist and slot offers | 2 | 60 |  all passed |
| Payments and refunds | 5 | 45 |  all passed |
| Notifications | 4 | 17 |  all passed |
| Unit tests and smoke | 6 | 10 |  all passed |
| **Total** | **54** | **508** |  **508 passed (1,968 assertions)** |

### Frontend E2E – Playwright (60 tests, all passing)

Summary of the companion suite, which runs against this API with seeded data. Full detail is in the `capstone-frontend` README.

| Spec group | Tests |
|---|---:|
| No-show reports and too-soon accept | 5 |
| Booking form: extra sessions | 2 |
| Custom package charge methods | 9 |
| Package: outdoor / on-location price | 3 |
| Regular package: maximum sessions | 5 |
| Custom package: maximum sessions | 4 |
| Real seeded Laravel read-only smoke | 8 |
| Not shown in supplied screenshot | 24 |
| **Total** | **60 ** |

##  Manual Test Case Documentation

The manual document (`docs/test-case.docx`) holds 130 designed test cases with *What to test*, *Expected result*, *Actual result* and *Status* for each. It is a **snapshot of the last manual pass**, written after most of the system was built, so it is **not** the source of truth for what currently works. Use the automated results above for that.

| Module | Designed | Passed (manual) | Not tested (manual) |
|---|---:|---:|---:|
| Authentication and Account Status | 30 | 30 | 0 |
| Profile, Settings, Portfolio and Application | 12 | 12 | 0 |
| Packages, Custom Packages and Add-ons | 14 | 8 | 6 |
| Availability and Waitlist | 12 | 4 | 8 |
| Explore and Favorites | 4 | 3 | 1 |
| Booking | 18 | 13 | 5 |
| Booking Management | 10 | 9 | 1 |
| Payments | 10 | 7 | 3 |
| Service Process | 2 | 2 | 0 |
| Reviews and Ratings | 3 | 3 | 0 |
| Notifications | 4 | 4 | 0 |
| Reports | 2 | 2 | 0 |
| Dashboards, Analytics, Logs and Settings | 9 | 9 | 0 |
| **Total** | **130** | **106** | **24** |

## Bug Report

Full details are in `docs/bug-report.docx`. Bugs were reported by panelists and testers across three prototype rounds.

| Metric | Count |
|---|---:|
| Bugs logged | **18** |
| Fixed | 13 |
| For Enhancement | 2 |
| Open | 2 |
| Status not recorded | 1 |
| Severity – High / Medium / Low | 10 / 6 / 2 |

<br>

## Proponents

Muring, Erica G. · Cruzet, Jhazel G. · Demetrial, Chariza Mae · Palacio, Patrick B.

*A Web-based Centralized Photography Booking System for Photographers of Bulan, Sorsogon with Dynamic Package Customization and Rating System*
