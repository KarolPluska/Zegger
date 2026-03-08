# Zegger ERP - CLEAN START

## Cel
Po wykonaniu poniższych kroków system Zegger ERP startuje jako pusty:
- 0 ofert
- 0 klientów
- 0 wątków
- 0 relacji
- 0 załączników

## 1) Usuń tabele ERP (prefix dopasuj do swojej instalacji, np. wp_)

```sql
DROP TABLE IF EXISTS
  wp_zerp_companies,
  wp_zerp_company_members,
  wp_zerp_company_member_permissions,
  wp_zerp_auth_tokens,
  wp_zerp_join_requests,
  wp_zerp_company_relations,
  wp_zerp_relation_events,
  wp_zerp_google_sources,
  wp_zerp_google_cache,
  wp_zerp_catalog_categories,
  wp_zerp_catalog_items,
  wp_zerp_catalog_variants,
  wp_zerp_catalog_events,
  wp_zerp_clients,
  wp_zerp_offers,
  wp_zerp_offer_events,
  wp_zerp_offer_chat_link,
  wp_zerp_offer_pdf_archive,
  wp_zerp_thread_categories,
  wp_zerp_threads,
  wp_zerp_thread_participants,
  wp_zerp_thread_messages,
  wp_zerp_thread_attachments,
  wp_zerp_thread_pings,
  wp_zerp_thread_events,
  wp_zerp_notifications,
  wp_zerp_notification_reads,
  wp_zerp_maintenance_logs;
```

## 2) Usuń opcje ERP

```sql
DELETE FROM wp_options
WHERE option_name IN (
  'zerp_db_version',
  'zerp_settings',
  'zerp_migration_state',
  'zerp_bootstrap_owner'
);
```

## 3) Usuń transjenty rate-limit logowania ERP (opcjonalnie)

```sql
DELETE FROM wp_options
WHERE option_name LIKE '_transient_zerp_login_rl_%'
   OR option_name LIKE '_transient_timeout_zerp_login_rl_%';
```

## 4) (Opcjonalnie) Usuń dane legacy, jeśli chcesz absolutnie czyste środowisko

```sql
DROP TABLE IF EXISTS
  wp_zqos_accounts,
  wp_zqos_tokens,
  wp_zqos_clients,
  wp_zqos_account_clients,
  wp_zqos_offers,
  wp_zqos_events,
  wp_zqos_sheets_cache;

DELETE FROM wp_options
WHERE option_name IN ('zqos_settings', 'zqos_bootstrap_creds', 'zqos_dbver');
```

## 5) Aktywuj plugin ponownie

Po ponownej aktywacji:
- nie ma automatycznej migracji legacy
- nie ma automatycznego seedowania firmy/ownera w ERP
- pierwsza firma i pierwszy użytkownik powstają przez standardowy flow rejestracji w `/?zegger_erp=1`