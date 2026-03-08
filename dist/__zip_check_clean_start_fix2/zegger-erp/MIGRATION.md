# Zegger ERP - MIGRATION

## 1. Strategia migracji
Przyjęta strategia: `clean-start accounts + data migration`.

Co to oznacza:
- konta użytkowników legacy nie są automatycznie przenoszone 1:1
- tworzony jest bootstrap owner w firmie startowej `Zegger Tech`
- kluczowe dane biznesowe legacy są migrowane do modelu ERP

## 2. Zakres migracji danych
Migracja wykonywana przez `ZERP_Migration::run_full_migration()`:
- `zqos_settings` -> konfiguracja Google Source (`wp_zerp_google_sources`)
- `zqos_clients` -> `wp_zerp_clients`
- `zqos_offers` -> `wp_zerp_offers`
- `zqos_events` -> `wp_zerp_offer_events`

Zachowana kompatybilność PDF:
- jeżeli oferta ma `legacy_offer_id` i PDF nie jest jeszcze w `wp_zerp_offers.pdf_path`, system czyta fallback z `zqos_offers.pdf_path`.

## 3. Mapowanie statusów legacy -> ERP
- `unset` -> `new`
- `new` -> `new`
- `sent` -> `sent`
- `in_progress` -> `in_progress`
- `won` -> `accepted`
- `lost` -> `rejected`
- `canceled` -> `canceled`
- `needs_update` -> `in_progress`

Oryginalny status legacy jest zachowany w polu `legacy_status`.

## 4. Kroki migracji produkcyjnej
1. Backup kodu + DB dump.
2. Wdrożenie pluginu `zegger-erp`.
3. Aktywacja pluginu.
4. Weryfikacja migracji w panelu `Zegger ERP -> Migracja`.
5. Testy akceptacyjne Gate 1 i Gate 2.
6. Cutover ruchu użytkowników na `/?zegger_erp=1`.
7. Testy Gate 3, Gate 4 i E2E.

## 5. Uwagi o kontach i dostępach
- Po aktywacji zapis bootstrap ownera jest dostępny w opcji `zerp_bootstrap_owner`.
- Owner może:
- tworzyć użytkowników firmy
- przypisywać role/uprawnienia
- obsługiwać join requesty
- konfigurować relacje A↔B

## 6. Integracja kalkulatora
Plik kalkulatora został przełączony na model launcher-only:
- usunięto legacy login overlay
- usunięto legacy iframe bridge i export bridge
- dodano przycisk otwierający `/?zegger_erp=1`

## 7. Ograniczenia i ryzyka
- Legacy konta nie są migrowane automatycznie (decyzja architektoniczna zgodna ze spec).
- W przypadku rollbacku legacy runtime nie obsłuży rekordów utworzonych wyłącznie w `wp_zerp_*`.
- Zalecany eksport raportowy przed rollbackiem.
