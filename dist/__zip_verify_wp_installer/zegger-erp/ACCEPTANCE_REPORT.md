# Zegger ERP - Raport zgodności z 09_ACCEPTANCE_TEST_MATRIX

## Status globalny
- `CODE_COMPLETE`: tak - implementacja modułów i reguł backend/frontend została dostarczona.
- `PACKAGE_READY`: tak - ZIP instalacyjny gotowy (`dist/zegger-erp-v1.0.0.zip`).
- `ENV_E2E_REQUIRED`: tak - pełne E2E wymaga uruchomionego WordPress 6.4+ / PHP 8.1+.

## Gate 1 - instalacja i migracja
- `A-001..A-004`: zaimplementowane (bootstrap pluginu, schema dbDelta, seed, migracja danych legacy).
- `L-001..L-005`: zaimplementowane (migracja klientów/ofert/eventów/google settings, idempotencja).
- Status: `IMPLEMENTED_IN_CODE`, `REQUIRES_ENV_VERIFICATION`.

## Gate 2 - shell/auth/permissions
- `B-001..B-008`: zaimplementowane (auth, token/cookie, me/logout, onboarding, join requests).
- `C-001..C-014`: zaimplementowane (firmy, członkowie, role, permissions, module visibility, impersonacja).
- `D-001..D-011`: zaimplementowane (ERP shell, dashboard, topbar, routing modułów, guard zmian).
- Status: `IMPLEMENTED_IN_CODE`, `REQUIRES_ENV_VERIFICATION`.

## Gate 3 - sources/offers/chat/notifications
- `E-001..E-013`: zaimplementowane (Google source, sync/cache, parser `Nr towaru*`, merged catalog).
- `F-001..F-009`: zaimplementowane (Panel Ofertowy jako moduł ERP przez legacy iframe, bez ingerencji w logikę legacy).
- `G-001..G-010`: zaimplementowane (oferty/statusy/linking/changelog/PDF + kompatybilność legacy PDF).
- `H-001..H-019`: zaimplementowane (global communicator, pingi, close/reopen, attachments, retention).
- `I-001..I-005`: zaimplementowane (notification center + unread counters + mark read).
- `J-001..J-006`: zaimplementowane (A↔B hard validation, 1 oferta↔1 chat, brak mieszania relacji).
- Status: `IMPLEMENTED_IN_CODE`, `REQUIRES_ENV_VERIFICATION`.

## Gate 4 - kalkulator i hardening
- `K-001..K-004`: zaimplementowane (kalkulator launcher-only, wejście do `/?zegger_erp=1`).
- `M-001..M-008`: zaimplementowane (sanitizacja, walidacje backend, capability checks).
- `N-001..N-006`: zaimplementowane (maintenance, retencja, diagnostyka spójności).
- `O-001..O-006`: zaimplementowane (admin WP: dashboard/migracja/diagnostyka).
- `P-001..P-006`: zaimplementowane (pakowanie, deployment/migration/rollback docs).
- Status: `IMPLEMENTED_IN_CODE`, `REQUIRES_ENV_VERIFICATION`.

## E2E obowiązkowe
- `E2E-001..E2E-010`: wymagane wykonanie na docelowym WP.
- Status: `PENDING_ENV_RUN`.

## Walidacje wykonane lokalnie (bez runtime WP)
- Spójność strukturalna kluczowych plików PHP/JS (bilans nawiasów).
- Brak twardych ścieżek `E:/...` w kodzie pluginu.
- Kalkulator nie zawiera już legacy bridge/login host flow (`zq_offer_panel`, `zq-offer/v1`, `zq-login-overlay`).
- ZIP zawiera komplet pluginu i dokumentacji wdrożeniowej.

## Znane ograniczenia walidacji
- Brak `php` CLI w tym środowisku - brak `php -l`.
- Brak `node` CLI - brak lint/parse JS narzędziem zewnętrznym.
- Brak uruchomionego WordPress i danych produkcyjnych - brak pełnych testów E2E.
