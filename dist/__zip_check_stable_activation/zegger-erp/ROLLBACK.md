# Zegger ERP - ROLLBACK

## 1. Założenia
Rollback jest `code rollback + data preservation`.

To znaczy:
- wracasz do legacy runtime (`zq-offer-suite` + legacy kalkulator)
- dane `wp_zerp_*` pozostają nienaruszone
- nie uruchamiasz automatycznego kasowania tabel ERP

## 2. Minimalny plan rollback
1. Włącz maintenance mode.
2. Dezaktywuj plugin `zegger-erp`.
3. Aktywuj legacy plugin `zq-offer-suite` (wersja referencyjna).
4. Przywróć legacy plik kalkulatora (sprzed launcher-only).
5. Wyczyść cache aplikacyjne/CDN.
6. Wyłącz maintenance mode.

## 3. Dane i spójność
- Tabele `wp_zerp_*` zostają w bazie.
- Legacy plugin operuje na własnym modelu `zqos_*`.
- Rekordy utworzone wyłącznie przez ERP po cutover nie będą widoczne w legacy runtime.

## 4. Ryzyko operacyjne rollbacku
Główne ryzyko:
- utrata dostępności operacyjnej nowych danych ERP po powrocie do legacy.

Mitigacja przed rollbackiem:
- wykonaj eksport raportowy nowych danych ERP (oferty/rozmowy/powiadomienia/relacje)
- zabezpiecz pełny dump DB

## 5. Recovery forward
Po rollbacku możesz wrócić do ERP bez utraty danych ERP:
1. ponownie aktywuj `zegger-erp`
2. migracje są idempotentne
3. dane `wp_zerp_*` pozostają dostępne

## 6. Checklista po rollbacku
- działa legacy login + panel
- działa zapis oferty/PDF/historii w legacy
- kalkulator uruchamia legacy flow
- brak błędów krytycznych w logach PHP/WP
