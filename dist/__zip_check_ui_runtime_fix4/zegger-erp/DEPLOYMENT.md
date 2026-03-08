# Zegger ERP - DEPLOYMENT

## 1. Wymagania środowiskowe
- WordPress 6.4+
- PHP 8.1+
- Włączone WP REST API
- Zapisywalny katalog `wp-content/uploads`
- Aktywny cron (WP-Cron lub systemowy)
- Dostęp HTTP do opublikowanego Google Sheets (jeżeli używasz źródła Google)

## 2. Instalacja pluginu
1. Zrób pełny backup kodu i bazy danych.
2. Wyłącz legacy plugin `zq-offer-suite` (jeżeli jest aktywny).
3. Wgraj `zegger-erp-v1.0.0.zip` przez `Wtyczki -> Dodaj nową -> Wyślij wtyczkę`.
4. Aktywuj plugin `Zegger ERP`.

Po aktywacji uruchamiają się:
- migracje schema `wp_zerp_*`
- seed firmy startowej `Zegger Tech`
- migracje danych legacy (oferty/klienci/eventy/Google source settings)
- harmonogramy maintenance/sync

## 3. Wejście do aplikacji
- Główny entrypoint ERP: `/?zegger_erp=1`
- Legacy Panel Ofertowy (moduł osadzony): `/?zq_offer_panel=1&embed=1`

## 4. Post-deploy checklist
1. Sprawdź, czy tabela `wp_zerp_companies` istnieje i zawiera `Zegger Tech`.
2. Sprawdź log migracji w `WP Admin -> Zegger ERP -> Migracja`.
3. Zaloguj się do ERP i zweryfikuj moduły:
- Dashboard
- Panel Ofertowy
- Komunikator
- Firma i Użytkownicy
- Biblioteka Produktów
- Powiadomienia
4. Zweryfikuj `Future modules` widoczne tylko zgodnie z `future_modules_access` i `module_visibility`.
5. Zweryfikuj launcher kalkulatora - powinien otwierać wyłącznie `/?zegger_erp=1`.

## 5. Security/hardening
- Autoryzacja po tokenie Bearer + fallback secure cookie
- Capability checks backendowo w REST
- Sanityzacja wejścia i escape wyjścia
- Limity uploadu i retencja załączników
- Brak twardych ścieżek systemowych w runtime pluginu

## 6. Diagnostyka
W WP Admin:
- `Zegger ERP -> Dashboard`
- `Zegger ERP -> Migracja`
- `Zegger ERP -> Diagnostyka`

REST:
- `/?rest_route=/zegger-erp/v1/ping`

## 7. Brama jakości przed Go-Live
Uruchom scenariusze z `codex_zegger_erp_materials/09_ACCEPTANCE_TEST_MATRIX.txt`:
- Gate 1: A, L
- Gate 2: B, C, D
- Gate 3: E, F, G, H, I, J
- Gate 4: K, M, N, O, P
- E2E: E2E-001..E2E-010
