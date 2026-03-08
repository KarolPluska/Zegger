<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_Panel {

  public static function init(){
    add_filter('query_vars', array(__CLASS__, 'query_vars'));
    add_action('template_redirect', array(__CLASS__, 'maybe_render_panel'), 0);
  }

  public static function activate(){
    // nothing
  }

  public static function query_vars($vars){
    $vars[] = 'zq_offer_panel';
    $vars[] = 'embed';
    return $vars;
  }

  public static function maybe_render_panel(){
    $q = get_query_var('zq_offer_panel', '');
    if ((string)$q !== '1') return;

    // Panel to aplikacja JS; nie ładujemy motywu.
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    $settings = ZQOS_DB::settings();
    $tabs = isset($settings['tabs']) && is_array($settings['tabs']) ? $settings['tabs'] : array();
    $vat = isset($settings['vat_rate']) ? (float)$settings['vat_rate'] : 0.23;

    $tabNames = array();
    $gidMap = array();
    foreach ($tabs as $t){
      $name = isset($t['name']) ? (string)$t['name'] : '';
      $gid = isset($t['gid']) ? (string)$t['gid'] : '';
      $name = trim($name);
      $gid = trim($gid);
      if ($name){
        $tabNames[] = $name;
        if ($gid) $gidMap[$name] = $gid;
      }
    }
    if (!$tabNames){
      $tabNames = array('Ogrodzenia Panelowe','Ogrodzenia Palisadowe','Słupki','Akcesoria');
    }

    $apiBase = esc_url_raw( home_url( '/' ) );
    $apiNs = '/' . ltrim( ZQOS_Rest::NS, '/' );
    $panelEmbed = (string)get_query_var('embed', '') === '1';

    echo "<!doctype html>\n<html lang=\"pl\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n";
    echo "<title>Panel Ofertowy - ZEGGER</title>\n";
    // Font: Poppins (Google Fonts) + bezpieczny fallback
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
    echo "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
    echo "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">\n";
    echo "<style>" . self::inline_css() . "</style>\n";
    echo "</head>\n<body>\n";

    echo self::html_shell();

    echo "<script>\n";
    echo "window.ZQOS = window.ZQOS || {};
";
    echo "window.ZQOS.apiBase = " . json_encode($apiBase) . ";
";
    echo "window.ZQOS.apiNs = " . json_encode($apiNs) . ";
";
    echo "window.ZQOS.vatRate = " . json_encode($vat) . ";\n";
    echo "window.ZQOS.tabs = " . json_encode($tabNames) . ";\n";
    echo "window.ZQOS.gids = " . json_encode($gidMap) . ";\n";
    echo "</script>\n";

    echo "<script>" . self::inline_js() . "</script>\n";

    echo "</body>\n</html>";
    exit;
  }

  private static function html_shell(){
    // Minimalne HTML: sekcja wyboru produktów + lista + klient + historia
    return <<<HTML
<div id="zq-offer-backdrop" class="zq-offer-backdrop is-open" role="dialog" aria-modal="true" aria-label="Panel Ofertowy">
  <div class="zq-offer-modal" role="document" aria-describedby="zq-offer-desc">
    <div class="zq-offer-head">
      <div class="zq-offer-head-left">
        <div class="zq-brand">ZEGGER</div>
        <h1 class="zq-offer-title">Panel ofertowy</h1>
      </div>
      <div class="zq-offer-head-right">
        <button id="zq-offer-close" class="zq-btn secondary zq-offer-close" type="button">Zamknij</button>
      </div>
    </div>

    <div class="zq-offer-body">
      <div id="zq-offer-desc" class="zq-sr-only">Panel ofertowy - dobór produktów, lista pozycji, klienci, historia ofert i eksport PDF.</div>

      <div class="zq-card zq-card--top">
        <div class="zq-topbar">
          <div class="zq-topbar-left">
            <div class="zq-status" id="zq-sync-status">Synchronizacja: nie wykonano</div>
          </div>
          <div class="zq-topbar-right">
            <div class="zq-user-switch" id="zq-user-switch">
              <button class="zq-userbtn" id="zq-userbtn" type="button" aria-haspopup="menu" aria-expanded="false">
                <span class="zq-user" id="zq-user"></span>
                <svg class="zq-usercaret" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 10l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <div class="zq-usermenu" id="zq-usermenu" role="menu" aria-label="Przełącz konto" style="display:none"></div>
            </div>
                        <button class="zq-btn danger" id="zq-clear-btn" type="button">Wyczyść listę</button>
          </div>
        </div>

        <div class="zq-banner" id="zq-draft-banner" style="display:none">
          <div class="t" id="zq-draft-text"></div>
          <div class="a">
            <button class="zq-btn secondary" id="zq-draft-restore" type="button">Przywróć szkic</button>
            <button class="zq-btn ghost" id="zq-draft-discard" type="button">Odrzuć</button>
          </div>
        </div>

      </div>

      <div class="zq-stack zq-stack--main">


        <div class="zq-card zq-prof" id="zq-prof-card">
          <div class="zq-prof-cover" id="zq-prof-cover" aria-hidden="true">
          </div>

          <div class="zq-prof-inner">
            <div class="zq-prof-top">
              <div class="zq-prof-avatar" id="zq-prof-avatar" aria-hidden="true"></div>

              <div class="zq-prof-info">
                <div class="zq-prof-name" id="zq-prof-name">—</div>
                <div class="zq-prof-role" id="zq-prof-role">—</div>

                <div class="zq-prof-contact" id="zq-prof-chips">
                  <a class="zq-prof-contact-item" id="zq-prof-phone" href="#" rel="nofollow">—</a>
                  <a class="zq-prof-contact-item" id="zq-prof-email" href="#" rel="nofollow">—</a>

                  <div class="zq-prof-stat" aria-label="Oferty">
                    <span class="k">Oferty</span>
                    <span class="v" id="zq-prof-stat-offers">0</span>
                  </div>
                  <div class="zq-prof-stat" aria-label="Klienci">
                    <span class="k">Klienci</span>
                    <span class="v" id="zq-prof-stat-clients">0</span>
                  </div>
                  <div class="zq-prof-stat" aria-label="Czas w panelu">
                    <span class="k">Czas</span>
                    <span class="v" id="zq-prof-stat-time">0m</span>
                  </div>
                </div>

                <div class="zq-prof-statusbar" id="zq-prof-statusbar" aria-label="Statusy ofert"></div>
              </div>

              <div class="zq-prof-actions">
                <button class="zq-prof-gear" id="zq-prof-edit" type="button" aria-label="Edytuj profil" title="Edytuj profil">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.08 7.08 0 0 0-1.63-.94l-.36-2.54A.5.5 0 0 0 13.9 1h-3.8a.5.5 0 0 0-.49.42l-.36 2.54c-.58.23-1.12.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 7.48a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.83 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.13.23.39.32.6.22l2.39-.96c.5.4 1.05.71 1.63.94l.36 2.54c.04.24.25.42.49.42h3.8c.24 0 .45-.18.49-.42l.36-2.54c.58-.23 1.12-.54 1.63-.94l2.39.96c.22.09.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z"/>
</svg>
                </button>
              </div>
            </div>
          </div>
        </div>


        <div class="zq-card" id="zq-add-card">
          <div class="zq-card-head">
            <h3 class="zq-h" data-zq-help="Dodawanie pozycji do oferty. Wybierz produkt, ilość i rabat, a następnie kliknij przycisk Dodaj pozycję.">Dodaj pozycję</h3>
            <div class="zq-muted" id="zq-disc-all-hint"></div>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-9">
              <div class="zq-label" data-zq-help="Szybkie wyszukiwanie po nazwie, SKU, wymiarze lub kolorze RAL. Wybór z listy uzupełni selektory poniżej.">Szukaj produktu (nazwa/SKU/wymiar/RAL)</div>
              <div class="zq-dropwrap">
                <input class="zq-input" id="zq-search" type="text" autocomplete="off" placeholder="Np. panel 3D 2500, SKU 123, RAL 7016">
                <div class="zq-drop" id="zq-search-drop" style="display:none"></div>
              </div>
            </div>
            <div class="zq-col zq-3">
              <div class="zq-label">&nbsp;</div>
              <button class="zq-btn secondary zq-w-full" id="zq-fav-open" type="button">Ulubione</button>
            </div>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-4">
              <div class="zq-label" data-zq-help="Zawęża listę produktów do wybranej kategorii. Dane pochodzą z arkusza (synchronizacja).">Kategoria</div>
              <select class="zq-select" id="zq-cat"></select>
            </div>
            <div class="zq-col zq-4">
              <div class="zq-label" data-zq-help="Zawęża listę produktów w ramach kategorii.">Podkategoria</div>
              <select class="zq-select" id="zq-subcat"></select>
            </div>
            <div class="zq-col zq-4">
              <div class="zq-label" data-zq-help="Wybierz produkt. Dostępne wymiary i RAL zależą od produktu.">Produkt</div>
              <select class="zq-select" id="zq-prod"></select>
            </div>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-3">
              <div class="zq-label" data-zq-help="Wybierz wariant wymiaru/rozmiaru produktu (jeśli dostępny).">Wymiar/Rozmiar</div>
              <select class="zq-select" id="zq-dim"></select>
            </div>
            <div class="zq-col zq-3">
              <div class="zq-label" data-zq-help="Wybierz kolor RAL (jeśli produkt ma warianty kolorystyczne).">RAL</div>
              <select class="zq-select" id="zq-ral"></select>
            </div>
            <div class="zq-col zq-2">
              <div class="zq-label" data-zq-help="Ilość pozycji (szt.). Tryb Paleta/TIR zmienia tylko cenę jednostkową.">Ilość</div>
              <input class="zq-input" id="zq-qty" type="number" min="1" step="1" value="1">
            </div>
            <div class="zq-col zq-2">
              <div class="zq-label" data-zq-help="Rabat procentowy dla tej pozycji. Wartości po rabacie są liczone automatycznie.">Rabat %</div>
              <input class="zq-input" id="zq-disc" type="number" min="0" max="100" step="1" value="0">
            </div>
            <div class="zq-col zq-2">
              <div class="zq-label">&nbsp;</div>
              <button class="zq-btn primary zq-w-full" id="zq-add" type="button">Dodaj pozycję</button>
            </div>
          </div>

          <div class="zq-err" id="zq-form-err"></div>
        </div>

        <div class="zq-card">
          <div class="zq-card-head">
            <h3 class="zq-h" data-zq-help="Lista pozycji w ofercie. Możesz edytować ilości/rabaty, zmieniać tryb ceny (szt./paleta/TIR) i usuwać pozycje.">Pozycje</h3>
            <div class="zq-note" id="zq-totals-note">VAT: 23% - rabat per pozycja. | Pozycji: <span id="zq-lines-count">0</span></div>
          </div>

          <div class="zq-lines">
            <div class="zq-lines-head">
              <div>Pozycja</div>
              <div>Ilość</div>
              <div class="hide-md">Cena jedn. (Netto)</div>
              <div>Rabat %</div>
              <div class="hide-md">Wartość Netto</div>
              <div>Netto (po rabacie)</div>
              <div class="hide-md">Wartość Brutto</div>
              <div>Brutto (po rabacie)</div>
              <div>Akcje</div>
            </div>
            <div id="zq-lines-empty" class="zq-empty">Brak pozycji. Dodaj produkty powyżej.</div>
            <div id="zq-lines-list"></div>
          </div>

          <div class="zq-totals">
            <div class="zq-legend-box" aria-label="Legenda i ustawienia">
              <div class="zq-legend-title">Legenda</div>

              <div class="zq-legend-row">
                <div class="zq-legend-pill" title="Pojedynczy produkt - cena standardowa">
                  <img src="https://zegger.pl/wp-content/uploads/2026/02/oneproduct.png" alt="" loading="lazy" decoding="async" />
                  <span>Pojedynczy</span>
                </div>
                <div class="zq-legend-pill" title="Paleta - cena paletowa (zmienia tylko cenę jednostkową)">
                  <img src="https://zegger.pl/wp-content/uploads/2026/02/paleta.png" alt="" loading="lazy" decoding="async" />
                  <span>Paleta</span>
                </div>
                <div class="zq-legend-pill" title="TIR - cena TIR (zmienia tylko cenę jednostkową)">
                  <img src="https://zegger.pl/wp-content/uploads/2026/02/tir.png" alt="" loading="lazy" decoding="async" />
                  <span>TIR</span>
                </div>
              </div>

              <div class="zq-legend-note">
                Tryb Paleta / TIR zmienia tylko cenę jednostkową (wg arkusza). Ilość zawsze oznacza sztuki.
              </div>

              <div class="zq-legend-bottom" aria-label="Ustawienia oferty">
                <div class="zq-legend-ctrl">
                  <div class="zq-legend-disc">
                  <div class="zq-label zq-label--sm" data-zq-help="Ustawia rabat procentowy na wszystkie pozycje na liście (z uwzględnieniem limitu konta).">Rabat globalny %</div>
                  <div class="zq-legend-disc-row">
                    <input class="zq-input zq-input--sm" id="zq-disc-all" type="number" min="0" max="100" step="1" value="0">
                    <button class="zq-btn dark" id="zq-apply-disc-all" type="button">Zastosuj</button>
                  </div>
                </div>

                <label class="zq-check zq-check--sm"><input id="zq-special" type="checkbox"> Oferta specjalna (ręczna cena)</label>
                </div>

                <div class="zq-legend-actions">
                  <button class="zq-btn dark" id="zq-add-transport-line" type="button">Usługi transportowe</button>
                  <button class="zq-btn dark" id="zq-add-custom-line" type="button">Dodaj niestandardową pozycję</button>
                </div>
              </div>
            </div>

            <div class="zq-total-box">
              <div class="k">Razem netto</div>
              <div class="v" id="zq-total-net-before">0,00 zł</div>
              <div class="k">Razem netto (po rabacie)</div>
              <div class="v" id="zq-total-net-after">0,00 zł</div>
              <div class="k">Razem brutto</div>
              <div class="v" id="zq-total-gross-before">0,00 zł</div>
              <div class="k">Razem brutto (po rabacie)</div>
              <div class="v" id="zq-total-gross-after">0,00 zł</div>
            </div>
          </div>
        </div>

<div class="zq-card zq-client-card">
  <div class="zq-card-head">
    <h3 class="zq-h" data-zq-help="Wybór klienta przypisanego do oferty. Dane klienta trafiają do PDF oraz historii ofert.">Klient</h3>
    <span class="zq-badge" id="zq-client-badge" style="display:none">Klient stały</span>
  </div>

  <div class="zq-row" id="zq-client-select-row">
    <div class="zq-col zq-8" id="zq-client-select-wrap">
      <div class="zq-label" data-zq-help="Wybierz klienta z listy. Po wyborze dane klienta są użyte w eksporcie PDF i zapisie do historii.">Wybór klienta</div>
      <select class="zq-select" id="zq-client-select"></select>
    </div>
    <div class="zq-col zq-4" id="zq-client-actions-col">
      <div class="zq-label">&nbsp;</div>
      <div class="zq-stack">
        <div id="zq-client-add-wrap">
          <button class="zq-btn dark zq-w-full" id="zq-client-add" type="button">Dodaj nowego klienta</button>
        </div>
        <div id="zq-client-edit-wrap">
          <button class="zq-btn dark zq-w-full" id="zq-client-edit" type="button">Edytuj dane klienta</button>
        </div>
      </div>
    </div>
  </div>

  <div class="zq-note" id="zq-client-note" style="margin:-4px 0 12px;"></div>

  <div class="zq-row">
    <div class="zq-col zq-6">
      <div class="zq-label">Imię i nazwisko</div>
      <input class="zq-input" id="zq-client-fullname" type="text">
    </div>
    <div class="zq-col zq-6">
      <div class="zq-label">Nazwa firmy</div>
      <input class="zq-input" id="zq-client-company" type="text">
    </div>
  </div>

  <div class="zq-row">
    <div class="zq-col zq-3">
      <div class="zq-label">NIP</div>
      <input class="zq-input" id="zq-client-nip" type="text">
    </div>
    <div class="zq-col zq-3">
      <div class="zq-label">Telefon</div>
      <input class="zq-input" id="zq-client-phone" type="text">
    </div>
    <div class="zq-col zq-3">
      <div class="zq-label">Email</div>
      <input class="zq-input" id="zq-client-email" type="email">
    </div>
    <div class="zq-col zq-3">
      <div class="zq-label">Adres</div>
      <input class="zq-input" id="zq-client-address" type="text">
    </div>
  </div>

  <div class="zq-muted" id="zq-client-status" style="min-height:16px"></div>
</div>


<!-- Modal: Klient (dodaj/edytuj) -->
<div class="zq-modal" id="zq-client-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-client-modal-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-client-modal-title">Klient</div>
        <div class="zq-modal-sub" id="zq-client-modal-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-client-modal-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-client-modal-id" value="">
    <input type="hidden" id="zq-client-modal-mode" value="">

    <div class="zq-modal-body">
      <div class="zq-row">
        <div class="zq-col zq-6">
          <div class="zq-label">Imię i nazwisko</div>
          <input class="zq-input" id="zq-cm-fullname" type="text">
        </div>
        <div class="zq-col zq-6">
          <div class="zq-label">Nazwa firmy</div>
          <input class="zq-input" id="zq-cm-company" type="text">
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-4">
          <div class="zq-label">NIP</div>
          <input class="zq-input" id="zq-cm-nip" type="text">
        </div>
        <div class="zq-col zq-4">
          <div class="zq-label">Telefon</div>
          <input class="zq-input" id="zq-cm-phone" type="text">
        </div>
        <div class="zq-col zq-4">
          <div class="zq-label">Email</div>
          <input class="zq-input" id="zq-cm-email" type="email">
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Adres</div>
          <input class="zq-input" id="zq-cm-address" type="text">
        </div>
      </div>

      <div class="zq-err" id="zq-client-modal-err" style="margin-top:8px"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-client-modal-cancel" type="button">Anuluj</button>
      <button class="zq-btn primary" id="zq-client-modal-save" type="button">Zapisz</button>
    </div>
  </div>
</div>


<!-- Modal: Profil -->
<div class="zq-modal" id="zq-prof-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-prof-modal-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-prof-modal-title">Profil</div>
        <div class="zq-modal-sub">Dane profilu są widoczne w panelu i w eksporcie PDF (handlowiec).</div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-prof-modal-close" aria-label="Zamknij">×</button>
    </div>

    <div class="zq-row">
      <div class="zq-col zq-6">
        <div class="zq-label">Imię i nazwisko</div>
        <input class="zq-input zq-locked" id="zq-prof-seller-name" type="text" autocomplete="name" readonly>
      </div>
      <div class="zq-col zq-6">
        <div class="zq-label">Nazwa profilu</div>
        <input class="zq-input zq-locked" id="zq-prof-seller-branch" type="text" autocomplete="organization" readonly>
      </div>
      <div class="zq-col zq-6">
        <div class="zq-label">Telefon</div>
        <input class="zq-input" id="zq-prof-seller-phone" type="text" inputmode="tel" autocomplete="tel">
      </div>
      <div class="zq-col zq-6">
        <div class="zq-label">Email</div>
        <input class="zq-input" id="zq-prof-seller-email" type="email" autocomplete="email">
      </div>
    </div>

    <div class="zq-sep" style="margin:14px 0"></div>

    <div class="zq-row">
      <div class="zq-col zq-6">
        <div class="zq-label">Zdjęcie profilowe (URL)</div>
        <input class="zq-input" id="zq-prof-avatar-url" type="url" inputmode="url" placeholder="https://...">
      </div>
      <div class="zq-col zq-6">
        <div class="zq-label">Tło profilu (URL)</div>
        <input class="zq-input" id="zq-prof-cover-url" type="url" inputmode="url" placeholder="https://...">
      </div>
    </div>

    <div class="zq-muted" id="zq-prof-status" style="min-height:16px"></div>

    <div class="zq-modal-actions">
      <button class="zq-btn ghost" id="zq-prof-cancel" type="button">Anuluj</button>
      <button class="zq-btn primary" id="zq-prof-save" type="button">Zapisz</button>
    </div>
  </div>
</div>

<!-- Modal: Niestandardowa pozycja (dodaj/edytuj) -->
<div class="zq-modal" id="zq-custom-line-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-custom-line-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-custom-line-title">Niestandardowa pozycja</div>
        <div class="zq-modal-sub" id="zq-custom-line-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-custom-line-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-custom-line-id" value="">
    <input type="hidden" id="zq-custom-line-mode" value="">

    <div class="zq-modal-body">
      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Nazwa pozycji</div>
          <input class="zq-input" id="zq-custom-name" type="text" maxlength="160" placeholder="Np. Transport, Montaż, Usługa...">
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-4">
          <div class="zq-label" data-zq-help="Ilość pozycji (szt.). Tryb Paleta/TIR zmienia tylko cenę jednostkową.">Ilość</div>
          <input class="zq-input" id="zq-custom-qty" type="number" min="1" step="1" value="1">
        </div>
        <div class="zq-col zq-4">
          <div class="zq-label">Cena jednostki netto</div>
          <input class="zq-input" id="zq-custom-unit-net" type="number" min="0" step="0.01" value="0">
        </div>
        <div class="zq-col zq-4">
          <div class="zq-label" data-zq-help="Rabat procentowy dla tej pozycji. Wartości po rabacie są liczone automatycznie.">Rabat %</div>
          <input class="zq-input" id="zq-custom-disc" type="number" min="0" max="100" step="1" value="0">
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Komentarz (opcjonalnie)</div>
          <textarea class="zq-input zq-textarea" id="zq-custom-comment" rows="3" maxlength="500"></textarea>
        </div>
      </div>

      <div class="zq-err" id="zq-custom-line-err" style="min-height:16px"></div>

      <div class="zq-modal-actions">
        <button class="zq-btn secondary" id="zq-custom-cancel" type="button">Anuluj</button>
        <button class="zq-btn primary" id="zq-custom-save" type="button">Dodaj</button>
      </div>
    </div>
  </div>
</div>





<!-- Modal: Usługi transportowe (dodaj/edytuj) -->
<div class="zq-modal" id="zq-transport-line-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-transport-line-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-transport-line-title">Usługi transportowe</div>
        <div class="zq-modal-sub" id="zq-transport-line-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-transport-line-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-transport-line-id" value="">
    <input type="hidden" id="zq-transport-line-mode" value="">

    <div class="zq-modal-body">
      <div class="zq-row">
        <div class="zq-col zq-6">
          <div class="zq-label">Liczba KM</div>
          <input class="zq-input" id="zq-transport-km" type="number" min="1" step="1" value="1">
        </div>
        <div class="zq-col zq-6">
          <div class="zq-label">Tryb wyceny</div>
          <div class="zq-transport-mode" role="group" aria-label="Tryb wyceny transportu">
            <label class="zq-check zq-check--sm"><input type="radio" name="zq-transport-mode" id="zq-transport-mode-flat" value="flat" checked> Stała stawka</label>
            <label class="zq-check zq-check--sm"><input type="radio" name="zq-transport-mode" id="zq-transport-mode-tier" value="tier"> Progi (stawka zależna od KM)</label>
          </div>
        </div>
      </div>

      <div class="zq-row" id="zq-transport-flat-row">
        <div class="zq-col zq-6">
          <div class="zq-label">Cena za 1 KM netto</div>
          <input class="zq-input" id="zq-transport-unit-net" type="number" min="0" step="0.01" value="0">
        </div>
        <div class="zq-col zq-6">
          <div class="zq-label">Minimalna cena netto (opcjonalnie)</div>
          <input class="zq-input" id="zq-transport-min-net" type="number" min="0" step="0.01" value="0">
        </div>
      </div>

      <div class="zq-row" id="zq-transport-tier-row" style="display:none">
        <div class="zq-col zq-3">
          <div class="zq-label">Do KM</div>
          <input class="zq-input" id="zq-transport-km1" type="number" min="1" step="1" value="30">
        </div>
        <div class="zq-col zq-3">
          <div class="zq-label">Stawka netto / KM</div>
          <input class="zq-input" id="zq-transport-rate1" type="number" min="0" step="0.01" value="0">
        </div>
        <div class="zq-col zq-3">
          <div class="zq-label">Do KM</div>
          <input class="zq-input" id="zq-transport-km2" type="number" min="1" step="1" value="100">
        </div>
        <div class="zq-col zq-3">
          <div class="zq-label">Stawka netto / KM</div>
          <input class="zq-input" id="zq-transport-rate2" type="number" min="0" step="0.01" value="0">
        </div>

        <div class="zq-col zq-6" style="margin-top:10px">
          <div class="zq-label">Powyżej KM2 - stawka netto / KM</div>
          <input class="zq-input" id="zq-transport-rate3" type="number" min="0" step="0.01" value="0">
        </div>
        <div class="zq-col zq-6" style="margin-top:10px">
          <div class="zq-label">Minimalna cena netto (opcjonalnie)</div>
          <input class="zq-input" id="zq-transport-min-net2" type="number" min="0" step="0.01" value="0">
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Dopłaty (netto, opcjonalnie)</div>
          <div class="zq-transport-extras">
            <label class="zq-check zq-check--sm"><input id="zq-transport-x-hds-on" type="checkbox"> HDS</label>
            <input class="zq-input zq-input--sm" id="zq-transport-x-hds" type="number" min="0" step="0.01" value="0" placeholder="0">

            <label class="zq-check zq-check--sm"><input id="zq-transport-x-unload-on" type="checkbox"> Rozładunek/wniesienie</label>
            <input class="zq-input zq-input--sm" id="zq-transport-x-unload" type="number" min="0" step="0.01" value="0" placeholder="0">

            <label class="zq-check zq-check--sm"><input id="zq-transport-x-sat-on" type="checkbox"> Dostawa sobota</label>
            <input class="zq-input zq-input--sm" id="zq-transport-x-sat" type="number" min="0" step="0.01" value="0" placeholder="0">
          </div>
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-6">
          <div class="zq-label">Rabat transportu %</div>
          <input class="zq-input" id="zq-transport-disc" type="number" min="0" max="100" step="1" value="0">
        </div>
        <div class="zq-col zq-6" style="display:flex;align-items:flex-end">
          <label class="zq-check zq-check--sm" style="margin:0"><input id="zq-transport-no-global" type="checkbox" checked> Nie stosuj rabatu globalnego do transportu</label>
        </div>
      </div>

      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Komentarz (opcjonalnie)</div>
          <textarea class="zq-input zq-textarea" id="zq-transport-comment" rows="2" maxlength="500"></textarea>
        </div>
      </div>

      <div class="zq-note" id="zq-transport-preview" style="margin-top:8px"></div>

      <div class="zq-err" id="zq-transport-line-err" style="min-height:16px"></div>

      <div class="zq-modal-actions">
        <button class="zq-btn secondary" id="zq-transport-cancel" type="button">Anuluj</button>
        <button class="zq-btn primary" id="zq-transport-save" type="button">Dodaj</button>
      </div>
    </div>
</div>
</div>

<!-- Modal: Status oferty (historia) -->
<div class="zq-modal" id="zq-offer-status-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-osm-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-osm-title">Zmień status oferty</div>
        <div class="zq-modal-sub" id="zq-osm-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-osm-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-osm-offer-id" value="">

    <div class="zq-modal-body">
      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Status</div>
          <select class="zq-select" id="zq-osm-status"></select>
        </div>
      </div>

      <div class="zq-err" id="zq-osm-err" style="min-height:16px"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-osm-cancel" type="button">Anuluj</button>
      <button class="zq-btn primary" id="zq-osm-save" type="button">Zapisz</button>
    </div>
  </div>
</div>



<!-- Modal: Historia oferty (audyt zmian) -->
<div class="zq-modal" id="zq-offer-history-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-ohm-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-ohm-title">Historia oferty</div>
        <div class="zq-modal-sub" id="zq-ohm-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-ohm-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-ohm-offer-id" value="">

    <div class="zq-modal-body">
      <div class="zq-err" id="zq-ohm-err" style="min-height:16px"></div>
      <div id="zq-ohm-list" class="zq-timeline"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-ohm-ok" type="button">Zamknij</button>
    </div>
  </div>
</div>



<!-- Modal: Podgląd oferty (szybki podgląd - read-only) -->
<div class="zq-modal" id="zq-offer-preview-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-qvm-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-qvm-title">Podgląd oferty</div>
        <div class="zq-modal-sub" id="zq-qvm-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-qvm-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-qvm-offer-id" value="">

    <div class="zq-modal-body">
      <div class="zq-err" id="zq-qvm-err" style="min-height:16px"></div>
      <div id="zq-qvm-body" class="zq-qvm-body"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-qvm-ok" type="button">Zamknij</button>
    </div>
  </div>
</div>

<!-- Modal: Notatka handlowa (historia) -->
<div class="zq-modal" id="zq-sales-note-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-snm-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-snm-title">Notatka handlowa</div>
        <div class="zq-modal-sub" id="zq-snm-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-snm-close" aria-label="Zamknij">×</button>
    </div>

    <input type="hidden" id="zq-snm-offer-id" value="">

    <div class="zq-modal-body">
      <div class="zq-row">
        <div class="zq-col zq-12">
          <div class="zq-label">Notatka (wewnętrzna - tylko historia ofert)</div>
          <textarea class="zq-input zq-textarea" id="zq-snm-note" rows="6" maxlength="5000" placeholder="Wpisz notatkę handlową..."></textarea>
          <div class="zq-note" style="margin-top:6px">Ta notatka jest widoczna tylko w panelu (historia ofert). Nie trafia do PDF.</div>
        </div>
      </div>

      <div class="zq-err" id="zq-snm-err" style="min-height:16px"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-snm-cancel" type="button">Anuluj</button>
      <button class="zq-btn primary" id="zq-snm-save" type="button">Zapisz</button>
    </div>
  </div>
</div>

<!-- Modal: Potwierdzenie (działa w iframe sandbox - bez confirm()) -->
<div class="zq-modal" id="zq-confirm-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-confirm-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-confirm-title">Potwierdź</div>
        <div class="zq-modal-sub" id="zq-confirm-sub"></div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-confirm-close" aria-label="Zamknij">×</button>
    </div>

    <div class="zq-modal-body">
      <div class="zq-muted" id="zq-confirm-msg" style="font-size:14px;line-height:1.45"></div>
      <div class="zq-err" id="zq-confirm-err" style="min-height:14px;margin-top:10px;display:none"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn secondary" id="zq-confirm-cancel" type="button">Anuluj</button>
      <button class="zq-btn danger" id="zq-confirm-ok" type="button">OK</button>
    </div>
  </div>
</div>

<!-- Modal: Oferty wymagające aktualizacji (status systemowy) -->
<div class="zq-modal" id="zq-needs-update-modal" aria-hidden="true">
  <div class="zq-modal-card" role="dialog" aria-modal="true" aria-labelledby="zq-num-title">
    <div class="zq-modal-head">
      <div>
        <div class="zq-modal-title" id="zq-num-title">Wymaga zaktualizowania</div>
        <div class="zq-modal-sub" id="zq-num-sub">Niektóre oferty nie były edytowane od co najmniej 72h.</div>
      </div>
      <button type="button" class="zq-icon-btn" id="zq-num-close" aria-label="Zamknij">×</button>
    </div>

    <div class="zq-modal-body">
      <div class="zq-muted" style="font-size:13px;line-height:1.45;margin-bottom:10px">
        Poniżej lista ofert oznaczonych statusem „Wymaga zaktualizowania”.
      </div>
      <div id="zq-num-list" class="zq-note" style="background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25)"></div>
    </div>

    <div class="zq-modal-actions">
      <button class="zq-btn ghost" id="zq-num-show" type="button">Pokaż w historii</button>
      <button class="zq-btn primary" id="zq-num-ok" type="button">OK</button>
    </div>
  </div>
</div>

<div class="zq-card zq-card--export">
          <div class="zq-card-head">
            <h3 class="zq-h" data-zq-help="Zapisuje ofertę do historii. Eksport oraz pobieranie PDF jest dostępne w sekcji „Historia ofert”.">Zapis oferty</h3>
            <span id="zq-export-status" class="zq-muted"></span>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-6">
              <div class="zq-label" data-zq-help="Tytuł oferty widoczny w historii i na PDF. Używany także do identyfikacji i deduplikacji zapisów.">Nazwa kalkulacji (wymagana)</div>
              <input class="zq-input" id="zq-offer-title" type="text" placeholder="Np. Oferta - Kowalski">
            </div>
            <div class="zq-col zq-3">
              <div class="zq-label" data-zq-help="Status oferty używany w historii, filtrach i statystykach. Jest zapisywany razem z ofertą.">Status (wymagany)</div>
              <select class="zq-select" id="zq-offer-status">
                <option value="unset" selected disabled>Wybierz status...</option>
                <option value="new">Nowa</option>
                <option value="sent">Wysłana</option>
                <option value="in_progress">W trakcie</option>
        <option value="won">Zrealizowana (sukces)</option>
                <option value="lost">Odrzucona (porażka)</option>
                <option value="canceled">Anulowana</option>
              </select>
            </div>
            <div class="zq-col zq-3">
              <div class="zq-label" data-zq-help="Określa, przez ile dni oferta jest ważna od daty eksportu. Wartość trafia do danych oferty i może być wyświetlana w PDF.">Ważność (dni)</div>
              <input class="zq-input" id="zq-valid-days" type="number" min="1" max="365" step="1" value="14">
            </div>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-12">
              <div class="zq-label" data-zq-help="Dodatkowa notatka do oferty. Może być przenoszona do PDF i historii.">Komentarz</div>
              <input class="zq-input" id="zq-offer-comment" type="text">
            </div>
          </div>

          <div class="zq-row">
            <div class="zq-col zq-12">
              <div class="zq-label" data-zq-help="Dane handlowca wynikające z konta (np. imię, telefon, email, oddział). Pole tylko do odczytu.">Handlowiec</div>
              <input class="zq-input" id="zq-seller" type="text" readonly>
            </div>
          </div>

          <div class="zq-actionsbar">
            <button class="zq-btn secondary" id="zq-save-offer" type="button">Zapisz ofertę</button>
            <button class="zq-btn secondary" id="zq-overwrite-offer" type="button" style="display:none" disabled>Nadpisz ofertę</button>
</div>
        </div>

        <div class="zq-card" id="zq-history-card">
  <div class="zq-card-head">
    <h3 class="zq-h" data-zq-help="Lista zapisanych ofert. Możesz je filtrować, sortować i zarządzać statusem (jeśli masz uprawnienia).">Historia ofert</h3>
    <button class="zq-btn secondary" id="zq-refresh-history" type="button">Odśwież</button>
  </div>

  <div class="zq-row zq-history-search-row">
    <div class="zq-col zq-9">
      <div class="zq-label" data-zq-help="Filtruje historię po fragmencie nazwy oferty.">Wyszukaj w historii</div>
      <input class="zq-input" id="zq-history-search" type="text" placeholder="Wpisz fragment nazwy...">
    </div>
    <div class="zq-col zq-3">
      <div class="zq-label">&nbsp;</div>
      <button class="zq-btn ghost zq-w-full" id="zq-history-clear" type="button">Wyczyść</button>
    </div>
  </div>

  <div class="zq-row zq-history-controls-row">
    <div class="zq-col zq-6">
      <div class="zq-label" data-zq-help="Ogranicza widok historii do wybranego statusu.">Filtr statusu</div>
      <select class="zq-select" id="zq-history-status-filter">
        <option value="all">Wszystkie</option>
        <option value="new">Nowa</option>
        <option value="sent">Wysłana</option>
        <option value="in_progress">W trakcie</option>
        <option value="needs_update">Wymaga zaktualizowania</option>
        <option value="won">Zrealizowana (sukces)</option>
        <option value="lost">Odrzucona (porażka)</option>
        <option value="canceled">Anulowana</option>
        <option value="unset">Brak statusu</option>
      </select>
    </div>
    <div class="zq-col zq-6">
      <div class="zq-label" data-zq-help="Sposób sortowania ofert w historii (np. najnowsze/najstarsze).">Sortowanie</div>
      <select class="zq-select" id="zq-history-sort">
        <option value="newest">Najnowsze</option>
        <option value="oldest">Najstarsze</option>
        <option value="title_asc">Nazwa A-Z</option>
        <option value="title_desc">Nazwa Z-A</option>
        <option value="status">Status</option>
        <option value="status_updated">Ostatnia zmiana statusu</option>
      </select>
    </div>
  </div>

  <div class="zq-subhead" id="zq-history-mini-head">
    <div class="zq-muted" id="zq-history-meta-top"></div>
  </div>

  <div id="zq-history-mini-wrap" class="zq-history-mini-wrap">
    <div id="zq-history-mini" class="zq-history zq-history-mini"></div>

    <!-- Gradientowy przycisk rozwijania (widoczny tylko gdy jest więcej niż 5 wyników) -->
    <button id="zq-history-expand" class="zq-history-expand" type="button" aria-controls="zq-history-details">Pokaż więcej</button>

    <!-- Licznik (używany w JS do wyświetlenia +N; w UI ukryty) -->
    <span id="zq-history-more-count" class="zq-sr-only" aria-hidden="true"></span>
  </div>

  <details id="zq-history-details" class="zq-details">
    <summary class="zq-summary" aria-hidden="true">
      <span id="zq-history-toggle-label">Pokaż więcej</span>
    </summary>

    <div class="zq-details-body">
      <div id="zq-history-meta" class="zq-muted" style="margin-top:8px;"></div>
      <div id="zq-history" class="zq-history"></div>
      <div id="zq-history-end" class="zq-history-end" aria-hidden="true"></div>
    </div>
  </details>

  <!-- Przycisk zwijania (dokowany do modala, widoczny tylko gdy lista jest rozwinięta i sekcja Historii jest w viewport) -->
  <div id="zq-history-collapse-wrap" class="zq-history-collapse-wrap" aria-hidden="true">
    <button class="zq-btn ghost zq-history-collapse" id="zq-history-collapse" type="button">Zwiń listę</button>
  </div>
</div>

      </div>
      </div>

    </div>
  </div>
</div>
HTML;
  }


  private static function inline_css(){
    // CSS scoped do panelu
    return <<<CSS
:root{
  --bg:#f3f5f9;
  --surface:#ffffff;
  --surface-2:#f8fafc;
  --stroke:#e6e9f0;
  --border: var(--stroke);
  --text:#0b1220;
  --muted:#667085;

  --primary:#111827;
  --primary-2:#0b1220;
  --danger:#b42318;
  --danger-bg:#fff5f5;

  --accent:#2563eb;

  --radius:18px;
  --radius-sm:14px;

  --shadow-sm:0 1px 2px rgba(16,24,40,.06);
  --shadow-md:0 16px 40px rgba(16,24,40,.16);

  --focus:0 0 0 4px rgba(37,99,235,.18);
}

*{box-sizing:border-box;}
html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;-webkit-text-size-adjust:100%;overflow-x:hidden;overscroll-behavior:contain;}
button,input,select{font-family:inherit;}
.zq-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}

.zq-offer-backdrop{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:calc(14px + env(safe-area-inset-top)) calc(14px + env(safe-area-inset-right)) calc(14px + env(safe-area-inset-bottom)) calc(14px + env(safe-area-inset-left));}
.zq-offer-backdrop::before{content:"";position:absolute;inset:0;background:rgba(255,255,255,.72);backdrop-filter:blur(10px) saturate(1.1);-webkit-backdrop-filter:blur(10px) saturate(1.1);}

.zq-offer-modal{position:relative;width:min(96vw,1280px);height:calc(var(--zq-vh, 1vh) * 92);max-height:calc(var(--zq-vh, 1vh) * 92);background:var(--surface);border:1px solid var(--stroke);border-radius:var(--radius);box-shadow:var(--shadow-md);display:flex;flex-direction:column;overflow:hidden;}
@media (max-width:640px){.zq-offer-modal{height:calc(var(--zq-vh, 1vh) * 94);max-height:calc(var(--zq-vh, 1vh) * 94);border-radius:16px;}}

@supports (height: 100dvh){
  .zq-offer-modal{height:92dvh;max-height:92dvh;}
  @media (max-width:640px){.zq-offer-modal{height:94dvh;max-height:94dvh;}}
}

.zq-offer-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--stroke);background:linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);min-height:62px;}
.zq-offer-head-left{display:flex;align-items:center;gap:10px;min-width:0;}
.zq-brand{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);border:1px solid var(--stroke);padding:6px 9px;border-radius:999px;background:var(--surface);}
.zq-offer-title{margin:0;font-size:16px;letter-spacing:.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.zq-offer-close{display:inline-flex;align-items:center;justify-content:center;}

.zq-offer-body{padding:14px 16px;overflow:auto;-webkit-overflow-scrolling:touch;background:var(--bg);padding-bottom:calc(14px + env(safe-area-inset-bottom));}
@media (max-width:720px){.zq-offer-body{padding:12px;}}

.zq-stack{display:flex;flex-direction:column;gap:12px;}
.zq-stack--main{gap:44px;}

.zq-card{background:var(--surface);border:1px solid var(--stroke);border-radius:var(--radius-sm);padding:14px;box-shadow:var(--shadow-sm);}
.zq-card + .zq-card{margin-top:0;}
.zq-card--top{position:sticky;top:0;z-index:20;box-shadow:0 10px 24px rgba(16,24,40,.10);padding:12px;margin-bottom:12px;}
.zq-card--export{padding-bottom:16px;}

.zq-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid var(--stroke);}
.zq-h{margin:0;font-size:15px;letter-spacing:-.01em;text-transform:none;color:var(--text);font-weight:800;line-height:1.2;}

.zq-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.zq-topbar-left{min-width:0;}
.zq-topbar-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.zq-status{font-size:12px;color:var(--muted);}
.zq-user{font-size:12px;color:var(--muted);padding-right:2px;}

.zq-user-switch{position:relative;display:inline-flex;align-items:center;}
.zq-userbtn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  border:0;
  background:transparent;
  padding:6px 8px;
  border-radius:12px;
  cursor:pointer;
}
.zq-userbtn:hover{background:rgba(15,23,42,.04);}
.zq-userbtn:focus-visible{outline:none;box-shadow:var(--focus);}
.zq-userbtn[disabled]{cursor:default;opacity:.95;}
.zq-userbtn[disabled]:hover{background:transparent;}
.zq-usercaret{width:16px;height:16px;opacity:.65;}
.zq-userbtn[disabled] .zq-usercaret{display:none;}

.zq-usermenu{
  position:absolute;
  right:0;
  top:calc(100% + 8px);
  min-width:260px;
  max-width:min(92vw, 420px);
  max-height:60vh;
  overflow:auto;
  background:rgba(255,255,255,.98);
  border:1px solid rgba(15,23,42,.12);
  border-radius:16px;
  box-shadow:0 18px 42px rgba(2,6,23,.18);
  padding:6px;
  z-index:60;
}
.zq-usermenu .zq-um-title{font-size:11px;font-weight:800;color:rgba(15,23,42,.55);padding:8px 10px;}
.zq-usermenu button{
  width:100%;
  text-align:left;
  border:0;
  background:transparent;
  padding:10px 10px;
  border-radius:12px;
  cursor:pointer;
  display:flex;
  justify-content:space-between;
  gap:10px;
}
.zq-usermenu button:hover{background:rgba(15,23,42,.04);}
.zq-usermenu button:focus-visible{outline:none;box-shadow:var(--focus);}
.zq-usermenu .zq-um-login{font-weight:900;font-size:13px;color:rgba(15,23,42,.9);}
.zq-usermenu .zq-um-sub{font-weight:700;font-size:11px;color:rgba(15,23,42,.55);}
.zq-usermenu .is-active{background:rgba(37,99,235,.08);}

.zq-banner{border:1px solid var(--stroke);border-radius:var(--radius-sm);padding:10px 12px;background:var(--surface-2);display:flex;align-items:center;justify-content:space-between;gap:10px;margin:12px 0 0;}
.zq-banner .t{font-size:13px;color:var(--text);}
.zq-banner .a{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

.zq-tabs{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 0;}
.zq-tab{border:1px solid var(--stroke);background:var(--surface);border-radius:999px;padding:10px 12px;cursor:pointer;font-size:13px;line-height:1;box-shadow:0 1px 0 rgba(16,24,40,.02);}
.zq-tab:hover{border-color:#cfd6e4;background:var(--surface-2);}
.zq-tab.is-on{border-color:var(--primary);background:var(--primary);color:#fff;}

.zq-row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px;align-items:end;margin:0 0 12px;}
.zq-row:last-child{margin-bottom:0;}
.zq-row--opts{align-items:center;}

.zq-col{grid-column:span 12;min-width:0;}
.zq-1{grid-column:span 1;}
.zq-2{grid-column:span 2;}
.zq-3{grid-column:span 3;}
.zq-4{grid-column:span 4;}
.zq-5{grid-column:span 5;}
.zq-6{grid-column:span 6;}
.zq-7{grid-column:span 7;}
.zq-8{grid-column:span 8;}
.zq-9{grid-column:span 9;}
.zq-10{grid-column:span 10;}
.zq-11{grid-column:span 11;}
.zq-12{grid-column:span 12;}

@media (max-width:920px){
  .zq-1,.zq-2,.zq-3,.zq-4,.zq-5,.zq-6,.zq-7,.zq-8,.zq-9,.zq-10,.zq-11{grid-column:span 12;}
}

.zq-label{font-size:12px;color:var(--muted);margin:0 0 6px;min-height:14px;}

/* Help tooltips (ikonka ? + podpowiedź) */
.zq-label[data-zq-help],.zq-h[data-zq-help]{display:flex;align-items:center;gap:8px;}
.zq-label[data-zq-help]{flex-wrap:wrap;}

/* Ikona ? (szara) */
.zq-help{
  width:18px;height:18px;border-radius:999px;
  border:1px solid var(--stroke);
  background:var(--surface-2);
  color:var(--muted);
  font-size:12px;font-weight:800;
  padding:0;
  display:inline-flex;align-items:center;justify-content:center;
  cursor:help;
  position:relative;
  flex:0 0 auto;
}
.zq-help:hover{background:#eef2f7;color:var(--text);}
.zq-help:focus-visible{outline:none;box-shadow:var(--focus);border-color:rgba(37,99,235,.55);}

/* Tooltip - jasny, pozycjonowany przez JS (bez ucinania przy krawędziach) */
.zq-tip{
  position:fixed;
  left:0; top:0;
  transform:none;
  background:var(--surface);
  color:var(--text);
  border:1px solid var(--stroke);
  padding:10px 12px;
  border-radius:12px;
  width:max-content;
  max-width:min(380px, calc(100vw - 24px));
  font-size:12px;
  line-height:1.35;
  box-shadow:0 24px 60px rgba(16,24,40,.16);
  display:none;
  z-index:2147483647;
  text-align:left;
  white-space:normal;
  pointer-events:none;
}
.zq-tip::after{
  content:"";
  position:absolute;
  left:var(--zq-tip-arrow-x, 50%);
  width:12px;height:12px;
  background:var(--surface);
  transform:translateX(-50%) rotate(45deg);
}
.zq-tip.is-above::after{
  top:100%;
  margin-top:-6px;
  border-right:1px solid var(--stroke);
  border-bottom:1px solid var(--stroke);
}
.zq-tip.is-below::after{
  top:-6px;
  border-left:1px solid var(--stroke);
  border-top:1px solid var(--stroke);
}

.zq-help[aria-expanded="true"] .zq-tip{display:block;}

.zq-select,.zq-input{width:100%;border:1px solid #d5dbe7;border-radius:14px;padding:10px 12px;font-size:14px;background:var(--surface);height:44px;box-shadow:0 1px 0 rgba(16,24,40,.03);}
.zq-select{appearance:none;background-image:linear-gradient(45deg, transparent 50%, #98a2b3 50%),linear-gradient(135deg, #98a2b3 50%, transparent 50%);background-position:calc(100% - 18px) 19px, calc(100% - 13px) 19px;background-size:5px 5px, 5px 5px;background-repeat:no-repeat;padding-right:36px;}
.zq-input::placeholder{color:#98a2b3;}
.zq-input:focus,.zq-select:focus{outline:none;box-shadow:var(--focus);border-color:rgba(37,99,235,.55);}
.zq-input[readonly]{background:var(--surface-2);}

.zq-hint{margin-top:6px;font-size:12px;line-height:1.2;color:var(--muted);}

.zq-check{font-size:13px;color:var(--text);display:flex;gap:8px;align-items:center;user-select:none;}
.zq-check input{width:16px;height:16px;}

.zq-dropwrap{position:relative;}
.zq-drop{position:absolute;left:0;right:0;top:100%;margin-top:8px;background:var(--surface);border:1px solid var(--stroke);border-radius:16px;box-shadow:0 18px 50px rgba(16,24,40,.18);max-height:360px;overflow:auto;z-index:50;}
.zq-drop .sec{padding:8px 12px;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;border-top:1px solid var(--stroke);background:var(--surface-2);}
.zq-drop .sec:first-child{border-top:0;}
.zq-drop .it{padding:10px 12px;display:flex;gap:12px;align-items:flex-start;justify-content:space-between;cursor:pointer;}
.zq-drop .it:hover{background:var(--surface-2);}
.zq-drop .it .l{min-width:0;}
.zq-drop .it .t{font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.zq-drop .it .m{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.zq-drop .it .r{display:flex;gap:8px;align-items:center;flex:0 0 auto;}

.zq-btn{border-radius:14px;padding:10px 12px;font-size:14px;cursor:pointer;border:1px solid #d5dbe7;background:var(--surface);color:var(--text);height:44px;line-height:1;box-shadow:0 1px 0 rgba(16,24,40,.02);}
.zq-btn:hover{background:var(--surface-2);border-color:#cfd6e4;}
.zq-btn:focus-visible{outline:none;box-shadow:var(--focus);border-color:rgba(37,99,235,.55);}

.zq-btn.primary{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 10px 24px rgba(17,24,39,.20);}
.zq-btn.primary:hover{filter:brightness(.96);border-color:var(--primary);background:var(--primary);}
.zq-btn.secondary{border-color:var(--primary);color:var(--primary);background:var(--surface);}
.zq-btn.secondary:hover{background:var(--surface-2);}
.zq-btn.dark{background:#111827;border-color:#111827;color:#fff;box-shadow:0 10px 22px rgba(17,24,39,.14);}
.zq-btn.dark:hover{filter:brightness(.97);border-color:#111827;background:#111827;}
.zq-btn.ghost{border-color:#d5dbe7;background:var(--surface);}
.zq-btn.danger{border-color:rgba(180,35,24,.35);background:var(--danger-bg);color:var(--danger);}
.zq-btn.danger:hover{border-color:rgba(180,35,24,.50);}

.zq-w-full{width:100%;}

.zq-actionsbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:12px;}
.zq-actionsbar .zq-btn{min-width:200px;}
@media (max-width:520px){.zq-actionsbar .zq-btn{min-width:0;flex:1 1 auto;}}

.zq-err{margin:10px 0 0;color:var(--danger);font-size:13px;white-space:pre-wrap;}
#zq-form-err:empty{display:none;}
/* Toast notifications (lewy-dół widoku - zawsze w zasięgu wzroku) */
.zq-toasts{
  position:fixed;
  left:16px;
  bottom:16px;
  z-index:999999;
  display:flex;
  flex-direction:column;
  gap:10px;
  max-width:min(520px, calc(100vw - 32px));
  pointer-events:none;
}
@media (max-width:640px){
  .zq-toasts{left:12px;bottom:12px;max-width:calc(100vw - 24px);}
}
.zq-toast{
  pointer-events:auto;
  background:var(--surface);
  border:1px solid var(--stroke);
  border-radius:14px;
  box-shadow:0 16px 40px rgba(16,24,40,.14);
  padding:10px 12px;
  display:flex;
  gap:10px;
  align-items:flex-start;
  transform:translateX(-8px);
  opacity:0;
  transition:transform .18s ease, opacity .18s ease;
  border-left:4px solid transparent;
}
.zq-toast.is-in{transform:translateX(0);opacity:1;}
@media (prefers-reduced-motion: reduce){
  .zq-toast{transition:none;}
}
.zq-toast .i{
  width:18px;height:18px;border-radius:999px;
  border:1px solid var(--stroke);
  background:var(--surface-2);
  display:inline-flex;align-items:center;justify-content:center;
  flex:0 0 auto;
  font-size:12px;font-weight:800;color:var(--muted);
  margin-top:1px;
}
.zq-toast .t{
  font-size:13px;
  line-height:1.35;
  color:var(--text);
  white-space:pre-wrap;
  word-break:break-word;
  flex:1 1 auto;
}
.zq-toast .x{
  border:1px solid var(--stroke);
  background:var(--surface);
  color:var(--muted);
  width:24px;height:24px;border-radius:10px;
  display:inline-flex;align-items:center;justify-content:center;
  cursor:pointer;
  flex:0 0 auto;
}
.zq-toast .x:hover{background:var(--surface-2);color:var(--text);border-color:#cfd6e4;}
.zq-toast .x:focus-visible{outline:none;box-shadow:var(--focus);}

.zq-toast.is-info{border-left-color:rgba(37,99,235,.65);}
.zq-toast.is-success{border-left-color:rgba(22,163,74,.65);}

/* Ostrzeżenia/błędy - czerwone i bardziej czytelne */
.zq-toast.is-warn,
.zq-toast.is-error{
  background:var(--danger-bg);
  border-color:rgba(180,35,24,.28);
  border-left-color:rgba(180,35,24,.85);
}
.zq-toast.is-warn .t,
.zq-toast.is-error .t{color:var(--danger);}
.zq-toast.is-warn .i,
.zq-toast.is-error .i{
  border-color:rgba(180,35,24,.35);
  background:#fff;
  color:var(--danger);
}
.zq-toast.is-warn .x,
.zq-toast.is-error .x{
  border-color:rgba(180,35,24,.22);
  background:#fff;
  color:var(--danger);
}
.zq-toast.is-warn .x:hover,
.zq-toast.is-error .x:hover{
  background:rgba(180,35,24,.07);
  border-color:rgba(180,35,24,.30);
}

.zq-muted{color:var(--muted);font-size:12px;}
.zq-note{font-size:12px;color:var(--muted);}


.zq-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--stroke);background:var(--surface-2);color:var(--muted);font-size:12px;line-height:1;white-space:nowrap;}
.zq-badge.is-click{cursor:pointer;}
.zq-badge.is-click:hover{filter:brightness(.985);}
.zq-client-card #zq-client-status{margin-top:-2px;}
.zq-client-card .zq-note{line-height:1.45;}
.zq-client-card.is-locked .zq-input,.zq-client-card.is-locked .zq-select{background:var(--surface-2);}

#zq-client-actions-col .zq-stack{flex-direction:row;gap:10px;}
#zq-client-actions-col .zq-stack > div{flex:1;}
/* Modal - klient */
.zq-stack{display:flex;flex-direction:column;gap:8px;}
.zq-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(255,255,255,.72);backdrop-filter:blur(6px) saturate(1.08);-webkit-backdrop-filter:blur(6px) saturate(1.08);z-index:99999;}
.zq-modal.is-open{display:flex;}
.zq-modal-card{width:min(760px,100%);max-height:90vh;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.28);padding:16px;}
.zq-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:10px;}

.zq-modal-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:12px;}
@media (max-width:520px){.zq-modal-actions .zq-btn{width:100%;}}
.zq-modal-title{font-weight:800;font-size:16px;letter-spacing:-.01em;}
.zq-modal-sub{font-size:12px;color:var(--muted);margin-top:2px;}
.zq-icon-btn{width:34px;height:34px;border-radius:12px;border:1px solid var(--border);background:var(--surface-2);display:inline-flex;align-items:center;justify-content:center;font-size:20px;line-height:1;cursor:pointer;}
.zq-icon-btn:hover{filter:brightness(.98);}
.zq-modal-body{padding:6px 0 6px;}
.zq-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:10px;padding-top:12px;border-top:1px solid var(--border);}
@media (max-width: 720px){
  #zq-client-actions-col .zq-stack{flex-direction:row;gap:10px;}
  #zq-client-actions-col .zq-stack > div{flex:1;}
}


.zq-lines{border:1px solid var(--stroke);border-radius:16px;overflow-x:auto;overflow-y:hidden;background:var(--surface);-webkit-overflow-scrolling:touch;}
.zq-lines .zq-input,.zq-lines .zq-select{height:34px;padding:6px 10px;font-size:13px;border-radius:12px;}
.zq-lines-head{display:grid;grid-template-columns:2.8fr 0.75fr 0.95fr 0.75fr 1fr 1fr 1fr 1fr 0.9fr;gap:12px;padding:12px 14px;background:var(--surface-2);border-bottom:1px solid var(--stroke);font-size:12px;color:var(--muted);align-items:center;position:sticky;top:0;z-index:3;}
.zq-line{display:grid;grid-template-columns:2.8fr 0.75fr 0.95fr 0.75fr 1fr 1fr 1fr 1fr 0.9fr;gap:12px;padding:12px 14px;border-bottom:1px solid var(--stroke);align-items:flex-start;background:var(--surface);}
.zq-lines-head,.zq-line{min-width:1180px;}
.zq-line:nth-child(even){background:rgba(15,23,42,.015);}
.zq-line:hover{background:inherit;}
.zq-lines-head > div:last-child{position:sticky;right:0;background:var(--surface-2);z-index:4;}
.zq-line > div.zq-acts{position:sticky;right:0;background:inherit;z-index:2;}
.zq-line > div.zq-acts::before{content:'';position:absolute;inset:-12px -14px -12px -14px;background:inherit;z-index:-1;}
.zq-line > div{min-width:0;}
.zq-cell{font-size:13px;line-height:1.2;}
.zq-unit,.zq-disc,.zq-value{display:flex;flex-direction:column;align-items:flex-start;gap:6px;}
.zq-unit .zq-input,.zq-disc .zq-input{width:100%;}
.zq-disc .zq-input{max-width:70px;}
.zq-qty .zq-input{max-width:92px;}
.zq-disc .zq-input,.zq-qty .zq-input{text-align:left;padding-left:12px;}
.zq-value{font-variant-numeric:tabular-nums;letter-spacing:.01em;}
.zq-before{color:var(--muted);}
.zq-after{font-weight:800;}
.zq-qty{display:flex;flex-direction:column;gap:6px;}
.zq-qty .zq-input{width:100%;}
.zq-qty .zq-hint{margin-top:0;text-align:right;}
.zq-line:last-child{border-bottom:0;}
.zq-name .main{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;font-size:13px;font-weight:600;min-width:0;}
.zq-name .main .t{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.zq-price-modes{display:flex;gap:6px;align-items:center;flex:0 0 auto;}
.zq-pmode{width:28px;height:28px;border-radius:10px;border:1px solid #d5dbe7;background:var(--surface);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:0 1px 0 rgba(16,24,40,.02);}
.zq-pmode:hover{background:var(--surface-2);border-color:#cfd6e4;}
.zq-pmode:focus-visible{outline:none;box-shadow:var(--focus);border-color:rgba(37,99,235,.55);}
.zq-pmode.is-on{border-color:var(--primary);background:var(--primary);}
.zq-pmode.is-on img{filter:invert(1) brightness(1.15);}
.zq-pmode:disabled{opacity:.45;cursor:not-allowed;}
.zq-pmode img{width:15px;height:15px;display:block;}
.zq-name .sub{font-size:11px;color:var(--muted);margin-top:2px;}
.zq-empty{padding:14px 12px;color:var(--muted);font-size:13px;background:var(--surface);}

.zq-actions-mini{display:flex;gap:8px;justify-content:flex-end;flex-wrap:nowrap;align-items:center;}
.zq-ico{width:30px;height:30px;border-radius:10px;border:1px solid #d5dbe7;background:var(--surface);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:0 1px 0 rgba(16,24,40,.02);}
.zq-ico:hover{background:var(--surface);border-color:#cfd6e4;}
.zq-ico:focus-visible{outline:none;box-shadow:var(--focus);border-color:rgba(37,99,235,.55);}
.zq-ico svg{width:15px;height:15px;display:block;}
.zq-ico.is-danger{border-color:rgba(180,35,24,.35);background:var(--danger-bg);color:var(--danger);}
.zq-ico.is-danger:hover{background:var(--danger-bg);border-color:rgba(180,35,24,.50);}
.zq-ico.is-on{border-color:var(--primary);background:var(--primary);color:#fff;}
.zq-mini,.zq-star{border:1px solid #d5dbe7;background:var(--surface);border-radius:12px;padding:8px 9px;font-size:12px;cursor:pointer;line-height:1;box-shadow:0 1px 0 rgba(16,24,40,.02);white-space:nowrap;}
.zq-mini:hover,.zq-star:hover{background:var(--surface-2);border-color:#cfd6e4;}
.zq-star.is-on{border-color:var(--primary);}
.zq-mini.danger{border-color:rgba(180,35,24,.35);background:var(--danger-bg);color:var(--danger);}

.zq-totals{display:flex;gap:14px;flex-wrap:wrap;align-items:stretch;justify-content:space-between;margin-top:14px;}

.zq-legend-box{flex:1 1 520px;min-width:320px;border-radius:16px;padding:12px 14px;display:flex;flex-direction:column;
  background:linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
  border:1px solid var(--stroke);
  box-shadow:var(--shadow-sm);}
.zq-legend-title{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:8px;}
.zq-legend-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.zq-legend-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;
  background:var(--surface-2);border:1px solid var(--stroke);color:var(--text);
  font-size:12px;font-weight:800;}
.zq-legend-pill img{width:15px;height:15px;display:block;opacity:.9;}
.zq-legend-note{margin-top:8px;font-size:12px;line-height:1.35;color:var(--muted);}
.zq-legend-bottom{margin-top:auto;padding-top:10px;border-top:1px dashed var(--stroke);
  display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;}
.zq-legend-ctrl{display:flex;gap:10px;flex-wrap:wrap;flex-direction:column;align-items:flex-start;justify-content:flex-start;}
.zq-check--sm{font-size:12px;font-weight:800;color:var(--text);opacity:.95;}
.zq-legend-actions{margin:0;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-self:flex-end;width:auto;}
@media (max-width:520px){
  .zq-legend-bottom{flex-direction:column;align-items:stretch;}
  .zq-legend-actions{width:100%;flex-direction:column;}
  .zq-legend-actions .zq-btn{width:100%;}
}
.zq-textarea{height:auto;min-height:92px;resize:vertical;}
.zq-name .sub2{font-size:11px;color:var(--muted);margin-top:4px;white-space:normal;line-height:1.35;}
.zq-check--sm input{transform:scale(1.02);}
.zq-label--sm{font-size:12px;}
.zq-input--sm{height:34px;padding:6px 10px;border-radius:12px;font-size:13px;}

/* Transport: modal UX */
.zq-transport-mode{display:flex;flex-direction:column;gap:6px;padding:8px 10px;border:1px solid rgba(17,17,17,.14);border-radius:14px;background:rgba(255,255,255,.8);} 
.zq-transport-extras{display:grid;grid-template-columns:1fr 120px;gap:10px 10px;align-items:center;}
.zq-transport-extras .zq-check{margin:0;}
.zq-legend-disc{flex:0 0 auto;min-width:220px;max-width:360px;}
.zq-legend-disc-row{display:flex;gap:8px;align-items:center;justify-content:flex-start;}
.zq-legend-disc-row .zq-input{max-width:78px;text-align:left;}
.zq-legend-disc-row .zq-btn{height:34px;padding:6px 10px;border-radius:12px;}
.zq-total-box{border:1px solid rgba(17,17,17,.14);border-radius:16px;padding:14px;min-width:280px;
  background:linear-gradient(135deg, #2a2f38 0%, #16181d 100%);
  box-shadow:0 16px 34px rgba(16,24,32,.16);
  color:rgba(255,255,255,.92);}
.zq-total-box .k{font-size:12px;color:rgba(255,255,255,.62);}
.zq-total-box .v{font-size:18px;font-weight:900;margin:2px 0 10px;color:#fff;letter-spacing:.2px;}

.zq-history{display:flex;flex-direction:column;gap:10px;margin-top:12px;}
.zq-subhead{display:flex;align-items:center;justify-content:space-between;margin-top:6px;}
.zq-history-mini{margin-top:8px;}
.zq-history-controls-row{margin-top:10px;}
.zq-details{margin-top:10px;}
.zq-summary{cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--stroke);border-radius:var(--radius-sm);background:var(--surface-2);user-select:none;}
.zq-details[open]>.zq-summary{background:var(--surface);box-shadow:var(--shadow-sm);}
.zq-summary::-webkit-details-marker{display:none;}
.zq-details-body{padding-top:10px;}
.zq-summary{display:none;} /* toggle sterowany JS (gradient + sticky przycisk) */

.zq-history-mini-wrap{position:relative;}
.zq-history-mini-wrap.has-more #zq-history-mini{padding-bottom:70px;}

.zq-history-expand{
  position:absolute;left:0;right:0;bottom:0;
  height:70px;
  border:0;
  cursor:pointer;
  display:none;
  align-items:center;
  justify-content:center;
  text-align:center;
  font-weight:800;
  letter-spacing:.2px;
  color:var(--text);
  background:linear-gradient(to bottom, rgba(243,245,249,0), rgba(243,245,249,.92) 55%, rgba(243,245,249,1));
  border-radius:0 0 14px 14px;
}
.zq-history-mini-wrap.has-more .zq-history-expand{display:flex;}
.zq-history-expand:hover{background:linear-gradient(to bottom, rgba(243,245,249,0), rgba(243,245,249,.95) 55%, rgba(243,245,249,1));}
.zq-history-expand:focus{outline:none;box-shadow:var(--focus);}

.zq-history-end{height:1px;width:100%;}

.zq-history-collapse-wrap{
  position:fixed;
  left:var(--zq-hcol-left, 14px);
  width:var(--zq-hcol-width, calc(100vw - 28px));
  bottom:var(--zq-hcol-bottom, 14px);
  z-index:60;
  display:flex;
  justify-content:center;
  padding:0;
  margin:0;
  pointer-events:none;

  /* Wsuwanie od dołu - widoczne tylko na końcu listy */
  opacity:0;
  visibility:hidden;
  transform:translateY(18px);
  transition: opacity .22s ease, transform .22s ease, visibility 0s linear .22s;
}
.zq-history-collapse-wrap.is-active{
  opacity:1;
  visibility:visible;
  transform:translateY(0);
  transition: opacity .22s ease, transform .22s ease;
}
.zq-history-collapse{pointer-events:auto;min-width:160px;border-radius:999px;}
@media (prefers-reduced-motion: reduce){
  .zq-history-expand{transition:none;}
  .zq-history-collapse-wrap{transition:none;transform:none;}
}
.zq-hitem{border:1px solid var(--stroke);border-radius:16px;padding:12px;background:var(--surface);display:flex;gap:12px;align-items:center;justify-content:space-between;}
.zq-hitem:hover{background:var(--surface-2);}
.zq-htitle{display:flex;align-items:center;gap:8px;font-weight:700;min-width:0;flex-wrap:nowrap;}
.zq-htitle .zq-badge--status{flex:0 0 auto;}
.zq-htitle-text{display:inline-block;line-height:1.25;min-width:0;flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.zq-lockbtn{border:1px solid var(--stroke);background:var(--surface);color:var(--muted);width:28px;height:28px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}
.zq-lockbtn:hover{background:var(--surface-2);color:var(--text);border-color:#cfd6e4;}
.zq-lockbtn:disabled{opacity:.55;cursor:not-allowed;}
.zq-lockbtn svg{width:16px;height:16px;}
.zq-lockbtn.is-locked{color:var(--danger);}

.zq-histbtn{border:1px solid var(--stroke);background:var(--surface);color:var(--muted);width:28px;height:28px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;}
.zq-histbtn:hover{background:var(--surface-2);color:var(--text);border-color:#cfd6e4;}
.zq-histbtn:disabled{opacity:.55;cursor:not-allowed;}
.zq-histbtn svg{width:16px;height:16px;}

/* Szybki podgląd oferty (lupa + kwota) */
.zq-qvbtn{border:1px solid var(--stroke);background:var(--surface);color:var(--muted);height:28px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:0 10px;cursor:pointer;flex:0 0 auto;}
.zq-qvbtn:hover{background:var(--surface-2);color:var(--text);border-color:#cfd6e4;}
.zq-qvbtn:disabled{opacity:.55;cursor:not-allowed;}
.zq-qvbtn svg{width:16px;height:16px;}
.zq-qvamt{font-size:12px;font-weight:800;color:var(--text);line-height:1;}
.zq-qvamt.is-muted{color:var(--muted);font-weight:700;}

/* Modal: podgląd oferty */
.zq-qvm-body{display:flex;flex-direction:column;gap:12px;}
.zq-qvm-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media (max-width:640px){.zq-qvm-grid{grid-template-columns:1fr;}}
.zq-qvm-kv{border:1px solid var(--stroke);background:var(--surface);border-radius:14px;padding:10px 12px;}
.zq-qvm-kv .k{color:var(--muted);font-size:12px;margin-bottom:2px;}
.zq-qvm-kv .v{font-weight:700;font-size:13px;line-height:1.25;word-break:break-word;}
.zq-qvm-lines{border:1px solid var(--stroke);background:var(--surface);border-radius:14px;padding:10px 12px;}
.zq-qvm-lines .h{font-weight:800;font-size:12px;color:var(--muted);margin-bottom:6px;}
.zq-qvm-lines .li{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px dashed rgba(102,112,133,.25);}
.zq-qvm-lines .li:first-child{border-top:0;}
.zq-qvm-lines .nm{min-width:0;flex:1 1 auto;font-size:13px;line-height:1.25;overflow:hidden;text-overflow:ellipsis;}
.zq-qvm-lines .qt{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:2px;color:var(--muted);font-size:12px;white-space:nowrap;text-align:right;}
.zq-qvm-lines .qv{font-weight:700;color:var(--text);}

/* Modal: historia oferty */
.zq-timeline{display:flex;flex-direction:column;gap:10px;margin:0;padding:0;}
.zq-tl-item{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--stroke);background:var(--surface);border-radius:14px;padding:10px 12px;}
.zq-tl-num{flex:0 0 auto;width:26px;height:26px;border:1px solid var(--stroke);background:var(--surface-2);color:var(--muted);border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;line-height:1;margin-top:1px;}
.zq-tl-time{flex:0 0 auto;color:var(--muted);font-size:12px;line-height:1.2;min-width:120px;white-space:nowrap;}
.zq-tl-body{min-width:0;flex:1 1 auto;}
.zq-tl-title{font-weight:700;font-size:13px;line-height:1.25;margin:0 0 4px 0;}
.zq-tl-title .zq-badge--status{margin:0 4px; padding:4px 9px; font-size:12px;}
.zq-tl-sub{color:var(--muted);font-size:12px;line-height:1.25;margin:0;}
.zq-tl-meta{color:var(--muted);font-size:12px;line-height:1.25;margin-top:6px;white-space:pre-wrap;word-break:break-word;}


.zq-hitem .meta{font-size:12px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.zq-hitem .meta2{font-size:12px;color:var(--muted);margin-top:6px;display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;}
.zq-hitem .meta2 .zq-by{white-space:nowrap;}
.zq-hitem .meta2 .zq-cmt{flex:1 1 100%;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;text-overflow:ellipsis;line-height:1.35;}
.zq-hitem .meta2 .zq-sn{flex:1 1 100%;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;text-overflow:ellipsis;line-height:1.35;}
@media (min-width:601px){
  .zq-hitem .meta2 .zq-cmt{-webkit-line-clamp:1;}
  .zq-hitem .meta2 .zq-sn{-webkit-line-clamp:1;}
}

.zq-hitem > div:first-child{min-width:0;}
.zq-hitem > div:first-child > div:first-child{font-size:13px;font-weight:700;white-space:nowrap;}

@media (max-width:920px){
  /* na tablet chowamy kolumnę 'Cena jedn.' (hide-md) */
  .zq-lines-head,.zq-line{grid-template-columns:2.6fr 0.9fr 0.8fr 1fr 1fr 0.9fr;min-width:0;gap:10px;}
  .hide-md{display:none;}
  .zq-legend-box{min-width:0;}
  .zq-legend-grid{grid-template-columns:1fr;}
}
@media (max-width:640px){
  .zq-offer-backdrop{padding:10px;}
}
@media (max-width:600px){
  .zq-lines-head{display:none;}
  .zq-lines{overflow:visible;}

  /* Pozycje: kompaktowy układ 2-kolumnowy (mobile) */
  .zq-line{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:10px;min-width:0;}
  .zq-line > div.zq-name{grid-column:1 / -1;display:block;}
  .zq-name .main{flex-wrap:wrap;align-items:flex-start;}
  .zq-price-modes{margin-top:6px;flex-wrap:wrap;}

  .zq-line > div[data-l]{display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:6px;min-width:0;}
  .zq-line > div[data-l]::before{content:attr(data-l);font-size:11px;color:var(--muted);line-height:1.2;}
  .zq-line > div[data-l] .zq-hint{margin-top:0;text-align:right;}

  .zq-line input.zq-input{max-width:none;width:100%;}
  .zq-disc .zq-input{max-width:none;}
  .zq-qty .zq-input{max-width:none;}

  .zq-line > div.zq-acts{grid-column:1 / -1;position:static;}
  .zq-line > div.zq-acts::before{display:none;}
  .zq-line .zq-actions-mini{justify-content:flex-end;}

  .zq-hitem{flex-direction:column;align-items:flex-start;}
  .zq-hitem .zq-actions-mini{width:100%;justify-content:flex-start;flex-wrap:wrap;}
}
@media (max-width:360px){
  .zq-line{grid-template-columns:1fr;}
  .zq-line .zq-actions-mini{justify-content:flex-start;}
}

/* ====== MOBILE UX (dopracowanie) ====== */
@media (max-width:520px){
  /* Topbar: elementy zawsze bez ucinania */
  .zq-topbar-right{width:100%;justify-content:flex-end;gap:8px;}
  .zq-userbtn{padding:8px 10px;}
  /* Menu przełączania konta - jako "bottom sheet" */
  .zq-usermenu{
    position:fixed;
    left:10px; right:10px;
    top:auto;
    bottom:calc(10px + env(safe-area-inset-bottom));
    max-height:calc(var(--zq-vh, 1vh) * 70);
    border-radius:18px;
  }
}
@supports (height: 100dvh){
  @media (max-width:520px){
    .zq-usermenu{max-height:70dvh;}
  }
}

@media (max-width:600px){
  /* większe hitboxy na dotyk */
  .zq-ico{width:36px;height:36px;border-radius:12px;}
  .zq-pmode{width:34px;height:34px;border-radius:12px;}
}

/* Modale - lepsza obsługa telefonów (bottom sheet + safe area) */
@media (max-width:520px){
  .zq-modal{align-items:flex-end;padding:10px 10px calc(10px + env(safe-area-inset-bottom));}
  .zq-modal-card{
    width:100%;
    border-radius:18px 18px 0 0;
    max-height:calc(var(--zq-vh, 1vh) * 92);
  }
}
@supports (height: 100dvh){
  @media (max-width:520px){
    .zq-modal-card{max-height:92dvh;}
  }
}

/* Profil: na mobile nie ucinaj kontaktu/statystyk */
@media (max-width:720px){
  .zq-prof-contact{flex-wrap:wrap;overflow:visible;}
}

/* Profil (modal): pola zablokowane - edycja tylko w backendzie */
.zq-input.zq-locked{
  background:#f3f4f6;
  color:#6b7280;
  border-color:rgba(15,23,42,.10);
}
.zq-input.zq-locked:focus{
  box-shadow:none;
  border-color:rgba(15,23,42,.10);
}

/* Transport: na bardzo wąskich ekranach nie rozwalaj gridu dopłat */
@media (max-width:420px){
  .zq-transport-extras{grid-template-columns:1fr;}
}

/* EMBED MODE */
html.zq-embed,body.zq-embed{height:100%;background:transparent;}
.zq-embed .zq-offer-body{background:transparent;padding:0;}
.zq-embed .zq-stack{gap:10px;}
.zq-embed .zq-card{border-radius:0;box-shadow:none;margin:0;}
.zq-embed .zq-card--top{position:sticky;top:0;box-shadow:none;}
.zq-embed .zq-offer-backdrop{position:static;inset:auto;padding:0;display:block;}
.zq-embed .zq-offer-backdrop::before{display:none;}
.zq-embed .zq-offer-modal{width:100%;height:100%;max-height:none;border-radius:var(--radius);box-shadow:none;border:0;background:transparent;overflow:hidden;}
.zq-embed .zq-offer-head{display:none;}

/* ====== Profil (karta nad "Dodaj pozycję") ====== */
.zq-prof{
  padding:16px;
  overflow:hidden;
  border:1px solid var(--stroke);
  box-shadow:0 10px 28px rgba(2,6,23,.05);
}
.zq-prof-cover{
  height:108px;
  border-radius:18px;
  background:
    radial-gradient(120% 120% at 12% 18%, rgba(255,255,255,.95) 0%, rgba(255,255,255,0) 58%),
    radial-gradient(120% 120% at 86% 8%, rgba(255,255,255,.70) 0%, rgba(255,255,255,0) 52%),
    linear-gradient(135deg, #f8fafc 0%, #eef2f7 48%, #f6f7fb 100%);
  position:relative;
  overflow:hidden;
}
.zq-prof-cover.has-img{background-size:cover;background-position:center;}
.zq-prof-cover::after{
  content:"";
  position:absolute; inset:0;
  background:
    radial-gradient(140% 120% at 0% 0%, rgba(255,255,255,.20) 0%, rgba(255,255,255,0) 55%),
    radial-gradient(120% 140% at 100% 0%, rgba(15,23,42,.05) 0%, rgba(15,23,42,0) 55%),
    linear-gradient(180deg, rgba(255,255,255,.12) 0%, rgba(255,255,255,0) 65%, rgba(15,23,42,.03) 100%);
}


.zq-prof-inner{padding:12px 4px 2px;position:relative;background:transparent;}
.zq-prof-top{
  position:relative;
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding-left:78px;
  padding-top:0;
}
.zq-prof-avatar{
  position:absolute;
  left:0;
  top:-32px;
  width:64px; height:64px;
  border-radius:999px;
  background-color:#fff;
  background-image:url("https://zegger.pl/wp-content/uploads/2026/03/default-avatar-icon-of-social-media-user-vector.jpg");
  background-repeat:no-repeat;
  background-size:cover;
  background-position:center;
  border:2px solid #fff;
  box-shadow:0 10px 22px rgba(2,6,23,.10);
}
.zq-prof-info{min-width:0;flex:1 1 auto;}
.zq-prof-name{font-weight:900;font-size:18px;letter-spacing:-.02em;margin-top:0;line-height:1.15;}
.zq-prof-role{margin-top:2px;font-size:12px;color:var(--muted);font-weight:700;}
.zq-prof-contact{margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:nowrap;overflow:hidden;}
.zq-prof-contact-item{
  color:rgba(15,23,42,.92);
  font-weight:700;
  font-size:12px;
  text-decoration:none;
  border:0;
  background:rgba(15,23,42,.04);
  padding:6px 10px;
  border-radius:999px;
}
.zq-prof-contact-item:hover{background:rgba(15,23,42,.06);}

.zq-prof-statusbar{margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

.zq-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;border:1px solid rgba(15,23,42,.10);background:rgba(15,23,42,.04);font-size:12px;font-weight:800;color:rgba(15,23,42,.85);white-space:nowrap;}
.zq-badge--status{padding:6px 10px;font-weight:900;}
.zq-st-unset{background:#fff5f5;border-color:rgba(180,35,24,.20);color:#7a271a;}
.zq-st-new{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.18);color:#1d4ed8;}
.zq-st-sent{background:rgba(2,132,199,.08);border-color:rgba(2,132,199,.18);color:#0369a1;}
.zq-st-in_progress{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.22);color:#92400e;}
.zq-st-won{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.22);color:#047857;}
.zq-st-lost{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.22);color:#b91c1c;}
.zq-st-canceled{background:rgba(100,116,139,.12);border-color:rgba(100,116,139,.22);color:#334155;}
.zq-st-needs_update{background:rgba(245,158,11,.28);border-color:rgba(245,158,11,.70);color:#7c2d12;}
.zq-status-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;margin-left:6px;border-radius:999px;background:rgba(255,255,255,.68);color:inherit;font-size:11px;font-weight:800;line-height:1;box-sizing:border-box;}

.zq-hitem.is-needs_update{border-color:rgba(245,158,11,.92);box-shadow:0 0 0 4px rgba(245,158,11,.25);background:rgba(245,158,11,.06);}
.zq-hitem.is-needs_update:hover{background:rgba(245,158,11,.10);}
.zq-needicon{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;border:1px solid rgba(245,158,11,.92);background:rgba(245,158,11,.30);color:#7c2d12;margin-right:8px;flex:0 0 auto;}
.zq-needicon svg{width:12px;height:12px;display:block;}

.zq-pulse{animation:zqPulse 1.6s ease-in-out infinite;}
@keyframes zqPulse{
  0%{transform:scale(1);box-shadow:0 0 0 0 rgba(245,158,11,.45);}
  60%{transform:scale(1.02);box-shadow:0 0 0 10px rgba(245,158,11,0);}
  100%{transform:scale(1);box-shadow:0 0 0 0 rgba(245,158,11,0);}
}
@media (prefers-reduced-motion: reduce){
  .zq-pulse{animation:none;}
}


.zq-select-sm{height:34px;padding:6px 10px;font-size:12px;border-radius:12px;border:1px solid #d5dbe7;background:var(--surface);box-shadow:0 1px 0 rgba(16,24,40,.03);}
.zq-select-sm.is-warn{border-color:rgba(180,35,24,.35);background:#fff5f5;}
.zq-prof-actions{position:absolute;right:0;top:-4px;display:flex;align-items:flex-start;}
.zq-prof-gear{width:38px;height:38px;border-radius:14px;border:1px solid rgba(15,23,42,.10);background:rgba(255,255,255,.75);backdrop-filter:blur(8px);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;}
.zq-prof-gear:hover{background:rgba(255,255,255,.92);border-color:rgba(15,23,42,.16);}
.zq-prof-gear:focus-visible{outline:none;box-shadow:var(--focus);}
.zq-prof-gear svg{width:18px;height:18px;fill:rgba(15,23,42,.82);}


.zq-prof-stats{
  margin-top:10px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  padding:0;
  border:0;
  background:transparent;
}
.zq-prof-stat{
  display:flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  border:0;
  background:rgba(15,23,42,.04);
}
.zq-prof-stat .k{
  font-size:12px;
  color:var(--muted);
  font-weight:700;
  letter-spacing:0;
}
.zq-prof-stat .v{
  font-size:12px;
  font-weight:900;
  letter-spacing:.15px;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 720px){
  .zq-prof{padding:14px;}
  .zq-prof-top{flex-wrap:wrap;gap:10px;padding-left:0;padding-top:38px;}
  .zq-prof-avatar{left:0;top:-30px;}
  .zq-prof-actions{width:100%;position:relative;right:auto;top:auto;margin-top:10px;}
    .zq-prof-stats{grid-template-columns:1fr;gap:8px;}
}

CSS;
  }

  private static function inline_js(){
    # We'll implement JS based on existing Panel with additions. Keep smaller but functional.
    return <<<JS
(function(){
  'use strict';

  // iOS/Android: stabilny viewport dla 92vh (adres bar) – używane w CSS jako --zq-vh
  (function(){
    var docEl = document.documentElement;
    var raf = 0;
    function setVH(){
      try{
        var vh = (window.innerHeight || 0) * 0.01;
        if (vh > 0) docEl.style.setProperty('--zq-vh', vh + 'px');
      }catch(e){}
    }
    function onResize(){
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function(){ raf = 0; setVH(); });
    }
    setVH();
    window.addEventListener('resize', onResize, { passive:true });
    window.addEventListener('orientationchange', onResize, { passive:true });
  })();
  var DOC = document;
  var state = {
    activeSheet: '__ALL__',
    data: {},
    searchIndex: null,
    syncedAt: null,
    lastSyncAttemptAt: 0,
    lastSyncAttemptErr: null,
    offerLines: [],
    specialOffer: false,
    authToken: null,
    actor: null,
    canSwitch: false,
    actorCaps: null,
    _switch: { inited:false, open:false, list:null },
    _confirm: { inited:false, open:false, resolve:null, opts:null },
    selectedClientId: null,
    clientModalBusy: false,
    clientModalMode: null,
    account: null,
    profile: null,
    profileStats: null,
    _timeTrack: { started:false, lastTs:0, timerId:0 },
    canForceSync: false,
    clients: [],
    offers: [],
    editingOffer: null,
    historyQuery: '',
    historySort: 'newest',
    historyStatusFilter: 'all',
    exportPending: null,
    exportLock: false,
    exportNonce: null,
    exportTimer: 0,
    exportStartedAt: 0,
    exportFromOfferId: null,
    exportFromBtn: null,
    _offerRestored: false,
    _allowedTabs: null,
    favs: [],
    recents: [],
    transportProfile: null,
    draftMeta: null,
    draftToRestore: null,
    _draftBannerShown: false
  };

  function perfNow(){
    try{ return (window.performance && performance.now) ? performance.now() : Date.now(); }catch(e){ return Date.now(); }
  }

  function fastClear(el){
    if (!el) return;
    try{ el.textContent = ''; return; }catch(e){}
    try{ while (el.firstChild) el.removeChild(el.firstChild); }catch(e2){}
  }

  // Search perf state (indeks + wyszukiwanie w chunkach, debounce input)
  var _zqSearch = {
    build: { building:false, waiters:[] },
    run: { seq:0 },
    debounceT: 0,
    lastQ: ''
  };


  // Help tooltips (? + podpowiedź)
  function initHelpTooltips(){
    try{
      var nodes = DOC.querySelectorAll('[data-zq-help]');
      for (var i=0;i<nodes.length;i++){
        var n = nodes[i];
        if (!n || !n.getAttribute) continue;
        var tag = (n.tagName || '').toUpperCase();
        if (tag === 'BUTTON' || tag === 'A' || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') continue;
        if (n.querySelector && n.querySelector('.zq-help')) continue;
        var txt = String(n.getAttribute('data-zq-help') || '').trim();
        if (!txt) continue;

        var btn = DOC.createElement('button');
        btn.type = 'button';
        btn.className = 'zq-help';
        btn.setAttribute('aria-label', 'Podpowiedź');
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = '?';

        var tip = DOC.createElement('span');
        tip.className = 'zq-tip';
        tip.setAttribute('role', 'tooltip');
        tip.textContent = txt;
        btn.appendChild(tip);

        bindHelpEvents(btn);
        n.appendChild(btn);
      }

      // klik poza = zamknij, klik na ? = toggle (pin)
      DOC.addEventListener('click', function(ev){
        var t = ev && ev.target ? ev.target : null;
        if (!t) return;
        var btn = null;
        try{
          if (t.classList && t.classList.contains('zq-help')) btn = t;
          else if (t.closest) btn = t.closest('.zq-help');
        }catch(e){}

        if (!btn){
          closeAllHelp();
          return;
        }

        ev.preventDefault();
        ev.stopPropagation();
        toggleHelp(btn);
      }, true);

      DOC.addEventListener('keydown', function(ev){
        if (!ev) return;
        if (ev.key === 'Escape') closeAllHelp();
      });

      var sc = DOC.querySelector('.zq-offer-body');
      if (sc) sc.addEventListener('scroll', function(){ closeAllHelp(); }, { passive:true });
      window.addEventListener('resize', function(){ closeAllHelp(); }, { passive:true });
    }catch(e){}
  }

  function bindHelpEvents(btn){
    if (!btn || !btn.addEventListener) return;

    btn.addEventListener('mouseenter', function(){
      if (isPinned(btn)) return;
      openHelp(btn, false);
    });

    btn.addEventListener('mouseleave', function(){
      if (isPinned(btn)) return;
      closeHelp(btn);
    });

    btn.addEventListener('focus', function(){
      if (isPinned(btn)) return;
      openHelp(btn, false);
    });

    btn.addEventListener('blur', function(){
      if (isPinned(btn)) return;
      closeHelp(btn);
    });
  }

  function getPinnedHelp(){
    try{ return DOC.querySelector('.zq-help[data-zq-pinned="1"][aria-expanded="true"]'); }catch(e){ return null; }
  }

  function isPinned(btn){
    try{ return !!btn && btn.getAttribute('data-zq-pinned') === '1'; }catch(e){ return false; }
  }

  function closeHelp(btn){
    try{
      if (!btn) return;
      btn.setAttribute('aria-expanded','false');
      btn.removeAttribute('data-zq-pinned');
      var tip = btn.querySelector ? btn.querySelector('.zq-tip') : null;
      if (tip){
        tip.classList.remove('is-above','is-below');
        tip.style.left = '';
        tip.style.top = '';
        try{ tip.style.removeProperty('--zq-tip-arrow-x'); }catch(e){}
      }
    }catch(e){}
  }

  function closeAllHelp(){
    try{
      var open = DOC.querySelectorAll('.zq-help[aria-expanded="true"]');
      for (var i=0;i<open.length;i++){
        closeHelp(open[i]);
      }
    }catch(e){}
  }

  function closeOtherNonPinned(exceptBtn){
    try{
      var open = DOC.querySelectorAll('.zq-help[aria-expanded="true"]');
      for (var i=0;i<open.length;i++){
        var b = open[i];
        if (b === exceptBtn) continue;
        if (isPinned(b)) continue;
        closeHelp(b);
      }
    }catch(e){}
  }

  function openHelp(btn, pinned){
    if (!btn || !btn.getAttribute) return;

    // Jeżeli jest przypięta inna chmurka, nie otwieraj hoverem kolejnej
    if (!pinned){
      var pinnedBtn = getPinnedHelp();
      if (pinnedBtn && pinnedBtn !== btn) return;
    }

    if (pinned){
      closeAllHelp();
      btn.setAttribute('data-zq-pinned', '1');
    }else{
      btn.removeAttribute('data-zq-pinned');
      closeOtherNonPinned(btn);
    }

    btn.setAttribute('aria-expanded','true');
    positionHelp(btn);
  }

  function toggleHelp(btn){
    if (!btn || !btn.getAttribute) return;
    var isOpen = btn.getAttribute('aria-expanded') === 'true';
    var pinned = isPinned(btn);

    if (isOpen && pinned){
      closeHelp(btn);
      return;
    }
    openHelp(btn, true);
  }

  function positionHelp(btn){
    try{
      var tip = btn.querySelector('.zq-tip');
      if (!tip) return;

      requestAnimationFrame(function(){
        try{
          var br = btn.getBoundingClientRect();
          var vw = DOC.documentElement.clientWidth || window.innerWidth || 0;
          var vh = DOC.documentElement.clientHeight || window.innerHeight || 0;

          var w = tip.offsetWidth || 0;
          var h = tip.offsetHeight || 0;
          if (!w || !h) return;

          var margin = 8;
          var gap = 10;

          var spaceAbove = br.top - margin;
          var spaceBelow = (vh - br.bottom) - margin;

          var placeBelow = false;
          if (spaceBelow >= (h + gap)) placeBelow = true;
          else if (spaceAbove >= (h + gap)) placeBelow = false;
          else placeBelow = spaceBelow > spaceAbove;

          tip.classList.remove('is-above','is-below');
          tip.classList.add(placeBelow ? 'is-below' : 'is-above');

          var top = placeBelow ? (br.bottom + gap) : (br.top - gap - h);
          top = Math.max(margin, Math.min(top, vh - margin - h));

          var centerX = br.left + (br.width / 2);
          var left = centerX - (w / 2);
          left = Math.max(margin, Math.min(left, vw - margin - w));

          tip.style.left = Math.round(left) + 'px';
          tip.style.top = Math.round(top) + 'px';

          var arrowPad = 14;
          var arrowX = centerX - left;
          arrowX = Math.max(arrowPad, Math.min(arrowX, w - arrowPad));
          tip.style.setProperty('--zq-tip-arrow-x', Math.round(arrowX) + 'px');
        }catch(e){}
      });
    }catch(e){}
  }

  // Ikony trybu ceny (per pozycja)

  var ZQ_PRICE_MODE_ICONS = {
    unit: 'https://zegger.pl/wp-content/uploads/2026/02/oneproduct.png',
    paleta: 'https://zegger.pl/wp-content/uploads/2026/02/paleta.png',
    tir: 'https://zegger.pl/wp-content/uploads/2026/02/tir.png'
  };

  // Bridge open/close (iframe)
  var lastPayload = null;
  var lastOpenTs = 0;
  var lastOpenToken = '';// dedupe open spam
  var lastOpenHostSeq = 0;
  var openRefreshPromise = null;
  function isEmbedded(){ try{ if (window.ZQOS && window.ZQOS.forceEmbed) return true; return window.parent && window.parent !== window; }catch(e){ return !!(window.ZQOS && window.ZQOS.forceEmbed); } }

  function runOpenRefresh(){
    if (openRefreshPromise) return openRefreshPromise;
    openRefreshPromise = refreshMe().finally(function(){
      refreshClients();
      refreshHistory();

      // Auto-sync tylko jeśli jeszcze nie mamy danych (backend sam odświeża cache co 5-10 min).
      var hasAny = false;
      try{ hasAny = !!(state.data && state.activeSheet && state.data[state.activeSheet] && state.data[state.activeSheet].items && state.data[state.activeSheet].items.length); }catch(e){ hasAny = false; }
      if (!state.syncedAt || !hasAny){ syncAll(false); }
    }).finally(function(){
      openRefreshPromise = null;
    });
    return openRefreshPromise;
  }

  function openPanel(payload, hostSeq){
    lastPayload = (payload === undefined) ? lastPayload : payload;
    var now = Date.now();
    hostSeq = parseInt(hostSeq, 10) || 0;

    if (isEmbedded()){
      DOC.documentElement.classList.add('zq-embed');
      DOC.body.classList.add('zq-embed');
    }

    // token: prefer payload (źródło prawdy), potem override (po switch), potem istniejący.
    // BUGFIX: stary override nie może nadpisywać świeżego tokenu z hosta (powodowało 401 po zalogowaniu).
    var token = '';
    try{
      lastPayload = (payload === undefined) ? lastPayload : payload;

      var pTok = (payload && payload.token) ? String(payload.token) : '';
      var ovTok = readTokenOverride();

      if (pTok){
        token = pTok;

        // jeśli mamy zapisany override z innej sesji/konta - usuń (żeby nie wracał po refresh)
        try{
          if (ovTok && String(ovTok) !== token){ clearTokenOverride(); }
        }catch(e){}
      } else if (ovTok){
        token = String(ovTok);
      } else {
        token = state.authToken ? String(state.authToken) : '';
      }

      if (token) state.authToken = token;
    }catch(e){
      token = state.authToken ? String(state.authToken) : '';
    }

    // dedupe: najpierw po host_seq (źródło prawdy z hosta), potem po tokenie/czasie.
    if (hostSeq && hostSeq === lastOpenHostSeq){
      try{
        if (window.parent && window.parent !== window){
          window.parent.postMessage({ type:'zq:offer:open:ack' }, '*');
        }
      }catch(e){}
      return;
    }
    if ((token && token === lastOpenToken && (now - lastOpenTs) < 60000) || (!token && !lastOpenToken && (now - lastOpenTs) < 3000)){
      try{
        if (window.parent && window.parent !== window){
          window.parent.postMessage({ type:'zq:offer:open:ack' }, '*');
        }
      }catch(e){}
      return;
    }
    lastOpenToken = token;
    lastOpenTs = now;
    if (hostSeq) lastOpenHostSeq = hostSeq;

    // display user (tylko UI)
    try{
      if (payload && payload.user){
        var elUser = DOC.getElementById('zq-user');
        if (elUser) elUser.textContent = 'Zalogowany: ' + String(payload.user);
      }
    }catch(e){}

    // refetch klient+historia po otwarciu (współdzielona obietnica - brak lawiny równoległych requestów)
    runOpenRefresh();

    try{
      if (window.parent && window.parent !== window){
        window.parent.postMessage({ type:'zq:offer:open:ack' }, '*');
      }
    }catch(e){}
  }


  function closePanel(silent){
    if (!silent){
      try{
        if (window.parent && window.parent !== window){
          window.parent.postMessage({ type:'zq:offer:closed' }, '*');
        }
      }catch(e){}
    }
  }

  window.addEventListener('message', function(ev){
    var d = ev && ev.data;
    if (!d || typeof d !== 'object') return;
    if (d.type === 'zq:offer:open') openPanel(d.payload || null, d.host_seq || 0);
    if (d.type === 'zq:offer:close') closePanel(true);
    if (d.type === 'zq:offer:ping'){
      try{ ev.source && ev.source.postMessage({ type:'zq:offer:pong', open:true }, '*'); }catch(e){}
    }
    if (d.type === 'zq:offer:export:done'){
      // nonce check (chroni przed spóźnioną odpowiedzią po timeout)
      var rNonce = (d.payload && d.payload.nonce) ? String(d.payload.nonce) : '';
      if (state.exportNonce && rNonce && String(state.exportNonce) !== rNonce){
        return;
      }
      if (state.exportTimer){ try{ clearTimeout(state.exportTimer); }catch(e){} state.exportTimer = 0; }
      state.exportStartedAt = 0;

      var ok = !!(d.payload && d.payload.ok);
      var expId = (ok && d.payload && d.payload.id) ? String(d.payload.id) : '';
      var expTitle = (d.payload && d.payload.title) ? String(d.payload.title) : '';
      var errMsg = (d.payload && d.payload.message) ? String(d.payload.message) : 'export';

      // Wyzeruj nonce, żeby nie blokować kolejnych eksportów
      state.exportNonce = null;

      setExportStatus(ok ? 'OK: zapisano PDF.' : ('Błąd: ' + errMsg));

      if (ok && expId){
        // Aktualizuj historię - pdf_path musi pojawić się w ofercie (bez tworzenia duplikatu).
        refreshHistory({bust:true});

        // Pobierz PDF (zawsze to samo ID oferty)
        setTimeout(function(){
          try{ downloadPdf(expId, expTitle); }catch(e){}
        }, 160);
      }

      // Odblokuj przycisk eksportu (ten z historii)
      state.exportFromOfferId = null;
      setExportLock(false);
    }
  });

  // inform host że jesteśmy gotowi
  try{
    if (window.parent && window.parent !== window){
      window.parent.postMessage({ type:'zq:offer:ready' }, '*');
    }
  }catch(e){}

  // Helpers
  function norm(s){ return String(s == null ? '' : s).trim().toLowerCase(); }
  function $(id){ return DOC.getElementById(id); }
  function plainFromHtml(s){
    s = String(s == null ? '' : s);
    if (!s) return '';
    try{
      var tmp = DOC.createElement('div');
      tmp.innerHTML = s;
      return String(tmp.textContent || tmp.innerText || '').trim();
    }catch(e){
      return s.replace(/<[^>]+>/g, '').trim();
    }
  }


  // Token override (po przełączeniu konta w panelu). Używane gdy host (kalkulator) nie zaktualizował tokenu.
  function kTokenOverride(){ return 'zqos_token_override_v1'; }
  function readTokenOverride(){
    try{
      var raw = localStorage.getItem(kTokenOverride());
      if (!raw) return null;
      var j = JSON.parse(raw);
      if (!j || typeof j !== 'object') return null;
      var tok = j.token ? String(j.token) : '';
      var actorLogin = j.actor_login ? String(j.actor_login) : '';
      var setAt = j.set_at ? parseInt(j.set_at, 10) : 0;
      if (!tok) return null;

      // TTL safety (max 10 dni)
      var now = Date.now();
      if (setAt && (now - setAt) > (10 * 24 * 3600 * 1000)){
        clearTokenOverride();
        return null;
      }

      // jeśli host podał innego użytkownika - wyczyść override
      try{
        if (lastPayload && lastPayload.user && actorLogin && String(lastPayload.user) !== actorLogin){
          clearTokenOverride();
          return null;
        }
      }catch(e){}

      return tok;
    }catch(e){ return null; }
  }
  function setTokenOverride(token, actorLogin){
    try{
      localStorage.setItem(kTokenOverride(), JSON.stringify({
        token: String(token || ''),
        actor_login: String(actorLogin || ''),
        set_at: Date.now()
      }));
    }catch(e){}
  }
  function clearTokenOverride(){
    try{ localStorage.removeItem(kTokenOverride()); }catch(e){}
  }


// Toasty (notyfikacje) - lewy dół widoku (na wysokości aktualnie przeglądanego panelu)
function positionToastHost(host){
  try{
    host = host || $('zq-toasts');
    if (!host) return;

    var vw = DOC.documentElement.clientWidth || window.innerWidth || 0;
    var vh = DOC.documentElement.clientHeight || window.innerHeight || 0;
    var pad = (vw <= 640) ? 12 : 16;

    var anchor = DOC.querySelector('.zq-offer-body') || DOC.querySelector('.zq-offer-modal') || DOC.body;
    var r = (anchor && anchor.getBoundingClientRect) ? anchor.getBoundingClientRect() : null;
    if (!r) return;

    var left = Math.round(r.left + pad);
    var bottom = Math.round((vh - r.bottom) + pad);

    // clamp do viewport (żeby nigdy nie zniknęło poza ekranem)
    left = Math.max(8, Math.min(left, Math.max(8, vw - 200)));
    bottom = Math.max(8, Math.min(bottom, Math.max(8, vh - 120)));

    host.style.left = left + 'px';
    host.style.bottom = bottom + 'px';

    var maxW = Math.round(r.width - (pad * 2));
    maxW = Math.max(220, Math.min(520, maxW));
    maxW = Math.min(maxW, Math.max(220, vw - left - 8));
    host.style.maxWidth = maxW + 'px';
  }catch(e){}
}

function ensureToastHost(){
  var host = $('zq-toasts');
  if (host) { positionToastHost(host); return host; }

  host = DOC.createElement('div');
  host.id = 'zq-toasts';
  host.className = 'zq-toasts';
  host.setAttribute('aria-live', 'polite');
  host.setAttribute('aria-relevant', 'additions text');

  // position:fixed -> mount może być dowolny, ale trzymajmy w modalu dla porządku DOM
  var mount = DOC.querySelector('.zq-offer-modal') || $('zq-offer-backdrop') || DOC.body;
  mount.appendChild(host);

  // utrzymuj pozycję przy zmianie rozmiaru / przewijaniu (embed/iframe potrafi przewijać viewport)
  try{
    window.addEventListener('resize', function(){ positionToastHost(host); }, { passive:true });
    window.addEventListener('scroll', function(){ positionToastHost(host); }, { passive:true, capture:true });
    var sc = DOC.querySelector('.zq-offer-body');
    if (sc) sc.addEventListener('scroll', function(){ positionToastHost(host); }, { passive:true });
  }catch(e){}

  positionToastHost(host);
  return host;
}

function toast(type, msg, opts){
  msg = String(msg == null ? '' : msg).trim();
  if (!msg) return;

  type = String(type || 'info');
  if (type !== 'info' && type !== 'success' && type !== 'warn' && type !== 'error') type = 'info';
  opts = opts && typeof opts === 'object' ? opts : {};

  var now = Date.now();
  var key = type + '|' + msg;

  try{
    if (!state._toastLast) state._toastLast = { key:'', ts:0 };
    if (state._toastLast.key === key && (now - state._toastLast.ts) < 900) return;
    state._toastLast.key = key;
    state._toastLast.ts = now;
  }catch(e){}

  var host = ensureToastHost();
  positionToastHost(host);

  // limit - nie zalewaj UI
  try{
    var max = 4;
    while (host.children && host.children.length >= max){
      host.removeChild(host.children[0]);
    }
  }catch(e){}

  var el = DOC.createElement('div');
  el.className = 'zq-toast is-' + type;
  el.setAttribute('role', (type === 'error' || type === 'warn') ? 'alert' : 'status');

  var ico = DOC.createElement('div');
  ico.className = 'i';
  ico.textContent = (type === 'success') ? '✓' : (type === 'warn' ? '!' : (type === 'error' ? '!' : 'i'));

  var txt = DOC.createElement('div');
  txt.className = 't';
  txt.textContent = msg;

  var close = DOC.createElement('button');
  close.type = 'button';
  close.className = 'x';
  close.setAttribute('aria-label', 'Zamknij');
  close.textContent = '×';

  var tmr = null;

  function removeToast(){
    try{ if (tmr) { clearTimeout(tmr); tmr = null; } }catch(e){}
    try{
      el.classList.remove('is-in');
      setTimeout(function(){
        try{ if (el && el.parentNode) el.parentNode.removeChild(el); }catch(e){}
      }, 180);
    }catch(e){
      try{ if (el && el.parentNode) el.parentNode.removeChild(el); }catch(e2){}
    }
  }

  close.addEventListener('click', function(ev){
    try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
    removeToast();
  });

  el.appendChild(ico);
  el.appendChild(txt);
  el.appendChild(close);

  host.appendChild(el);
  requestAnimationFrame(function(){
    try{ el.classList.add('is-in'); }catch(e){}
  });

  var timeout = 0;
  if (typeof opts.timeout === 'number' && isFinite(opts.timeout)) timeout = Math.max(0, opts.timeout);
  else timeout = (type === 'error') ? 6500 : (type === 'warn' ? 5200 : 3400);

  if (!opts.sticky && timeout > 0){
    tmr = setTimeout(removeToast, timeout);
  }
}

  function setStatus(txt){ var el=$('zq-sync-status'); if(el) el.textContent=txt; }

function enhanceErrMessage(txt){
  var t = String(txt == null ? '' : txt).trim();
  if (!t) return '';
  if (t === 'Wybierz kategorię i produkt.'){
    return 'Nie dodano pozycji - wybierz Kategorię i Produkt (albo użyj pola „Szukaj produktu”), a następnie kliknij „Dodaj pozycję”.';
  }
  if (t === 'Wybierz RAL.'){
    return 'Ten produkt wymaga wyboru koloru RAL - wybierz RAL z listy i spróbuj ponownie.';
  }
  if (t === 'Nazwa kalkulacji jest wymagana.'){
    return 'Uzupełnij nazwę kalkulacji (tytuł oferty) - bez tego nie zapiszę ani nie wyeksportuję oferty.';
  }
  if (t === 'Brak pozycji do zapisania.'){
    return 'Nie ma czego zapisać - dodaj przynajmniej jedną pozycję (wybierz produkt i kliknij „Dodaj pozycję”).';
  }
  if (t === 'Brak pozycji do eksportu.'){
    return 'Nie ma czego wyeksportować - dodaj przynajmniej jedną pozycję (wybierz produkt i kliknij „Dodaj pozycję”).';
  }
  if (t === 'Brak pozycji na liście.'){
    return 'Lista jest pusta - dodaj pozycje w sekcji „Dodaj pozycję” albo przywróć szkic, jeśli jest dostępny.';
  }
  if (t === 'Wybierz status oferty.'){
    return 'Wybierz status oferty z listy (np. „Nowa”, „Wysłana”) - bez statusu nie zapiszę zmian.';
  }
  if (t === 'Nie znaleziono pozycji w danych (po synchronizacji).'){
    return 'Nie znaleziono tej pozycji w danych po synchronizacji - uruchom synchronizację ponownie lub wybierz inny produkt.';
  }
  return t;
}

// setErr - region a11y zostaje, ale UI pokazujemy jako toast w widoku
function setErr(txt, level, opts){
  var el = $('zq-form-err');
  if (el){
    el.textContent = txt || '';
    try{ el.classList.add('zq-sr-only'); }catch(e){}
  }
  var msg = enhanceErrMessage(txt);
  if (msg) toast(level || 'error', msg, opts);
}

  function setExportStatus(txt){ var el=$('zq-export-status'); if(el) el.textContent = txt || ''; }
  function setExportLock(on, btnEl){
    state.exportLock = !!on;

    // Jeżeli wskazano przycisk - traktuj go jako „aktywny” dla bieżącego eksportu (np. w historii).
    if (btnEl) state.exportFromBtn = btnEl;

    var btn = btnEl || state.exportFromBtn;
    if (!btn) return;

    btn.disabled = !!on;
    btn.setAttribute('aria-busy', on ? 'true' : 'false');

    if (!on) state.exportFromBtn = null;
  }
  function toMoney(n){
    var v = (typeof n === 'number' && isFinite(n)) ? n : 0;
    try{ return v.toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' zł'; }
    catch(e){ return (Math.round(v*100)/100).toFixed(2) + ' zł'; }
  }

  function accountSuffix(){
    try{
      if (state.account && (state.account.id != null)) return String(state.account.id);
      if (state.account && state.account.login) return String(state.account.login);
    }catch(e){}
    return 'anon';
  }
  function kFav(){ return 'zqos_favs_v1_' + accountSuffix(); }
  function kRec(){ return 'zqos_recents_v1_' + accountSuffix(); }
  function kDraft(){ return 'zqos_draft_v1_' + accountSuffix(); }
  function kTransport(){ return 'zqos_transport_profile_v1_' + accountSuffix(); }
  function kLastOfferStatus(){ return 'zqos_last_offer_status_v1_' + accountSuffix(); }
  function kHistorySort(){ return 'zqos_history_sort_v1_' + accountSuffix(); }
  function kHistoryStatusFilter(){ return 'zqos_history_status_filter_v1_' + accountSuffix(); }

  var OFFER_STATUS_META = {
    unset:       { label: 'Brak statusu', cls: 'zq-st-unset' },
    new:         { label: 'Nowa', cls: 'zq-st-new' },
    sent:        { label: 'Wysłana', cls: 'zq-st-sent' },
    in_progress: { label: 'W trakcie', cls: 'zq-st-in_progress' },
    needs_update:{ label: 'Wymaga zaktualizowania', cls: 'zq-st-needs_update' },
    won:         { label: 'Zrealizowana (sukces)', cls: 'zq-st-won' },
    lost:        { label: 'Odrzucona (porażka)', cls: 'zq-st-lost' },
    canceled:    { label: 'Anulowana', cls: 'zq-st-canceled' }
  };

  function normOfferStatus(raw){
    var s = String(raw == null ? '' : raw).trim();
    s = s.toLowerCase();
    if (!s) return 'unset';
    if (!Object.prototype.hasOwnProperty.call(OFFER_STATUS_META, s)) return 'unset';
    return s;
  }

  function getSelectedOfferStatus(){
    var el = $('zq-offer-status');
    if (!el) return 'unset';
    return normOfferStatus(el.value);
  }

  function requireSelectedOfferStatus(){
    var st = getSelectedOfferStatus();
    if (!st || st === 'unset'){
      setErr('Wybierz status oferty.');
      try{ $('zq-offer-status').focus(); }catch(e){}
      return null;
    }
    return st;
  }

  function rememberOfferStatus(st){
    st = normOfferStatus(st);
    if (!st || st === 'unset') return;
    try{ localStorage.setItem(kLastOfferStatus(), st); }catch(e){}
  }

  function applyDefaultOfferStatus(){
    var el = $('zq-offer-status');
    if (!el) return;
    var cur = normOfferStatus(el.value);
    if (cur && cur !== 'unset') return;
    var last = '';
    try{ last = String(localStorage.getItem(kLastOfferStatus()) || ''); }catch(e){ last = ''; }
    last = normOfferStatus(last);
    if (last && last !== 'unset'){
      el.value = last;
    }
  }

  function makeStatusBadge(status, count){
    status = normOfferStatus(status);
    var meta = OFFER_STATUS_META[status] || OFFER_STATUS_META.unset;
    var b = DOC.createElement('span');
    b.className = 'zq-badge zq-badge--status ' + meta.cls;

    var label = DOC.createElement('span');
    label.textContent = meta.label;
    b.appendChild(label);

    var hasCount = (count !== undefined && count !== null && count !== '');
    if (hasCount){
      var n = parseInt(count, 10);
      if (!isFinite(n) || isNaN(n) || n < 0) n = 0;
      var cnt = DOC.createElement('span');
      cnt.className = 'zq-status-count';
      cnt.textContent = String(n);
      cnt.title = 'Liczba zmian statusu: ' + String(n);
      b.appendChild(cnt);
    }
    return b;
  }

  function fillStatusSelect(sel, current, includeUnset){
    if (!sel) return;
    var cur = normOfferStatus(current);
    while (sel.firstChild) sel.removeChild(sel.firstChild);

    function addOpt(val, label, disabled){
      var o = DOC.createElement('option');
      o.value = val;
      o.textContent = label;
      if (disabled) o.disabled = true;
      sel.appendChild(o);
    }

    if (includeUnset){
      addOpt('unset', 'Wybierz status...', true);
    }
    addOpt('new', 'Nowa', false);
    addOpt('sent', 'Wysłana', false);
    addOpt('in_progress', 'W trakcie', false);
    addOpt('won', 'Zrealizowana (sukces)', false);
    addOpt('lost', 'Odrzucona (porażka)', false);
    addOpt('canceled', 'Anulowana', false);

    sel.value = (cur && cur !== 'unset' && cur !== 'needs_update') ? cur : ((cur === 'unset' && includeUnset) ? 'unset' : 'new');
  }

  function normHistorySort(v){
    v = String(v || '').trim();
    if (v === 'newest' || v === 'oldest' || v === 'title_asc' || v === 'title_desc' || v === 'status' || v === 'status_updated') return v;
    return 'newest';
  }

  function normHistoryFilter(v){
    v = String(v || '').trim();
    if (!v || v === 'all') return 'all';
    v = normOfferStatus(v);
    return v ? v : 'all';
  }

  function applyHistoryPrefs(){
    // Preferencje historii: sort i filtr (localStorage, per konto)
    var sort = normHistorySort(state.historySort || 'newest');
    var filt = normHistoryFilter(state.historyStatusFilter || 'all');
    try{ sort = normHistorySort(localStorage.getItem(kHistorySort()) || sort); }catch(e){}
    try{ filt = normHistoryFilter(localStorage.getItem(kHistoryStatusFilter()) || filt); }catch(e){}
    state.historySort = sort;
    state.historyStatusFilter = filt;
    var es = $('zq-history-sort');
    if (es) es.value = sort;
    var ef = $('zq-history-status-filter');
    if (ef) ef.value = filt;
  }

  function persistHistoryPrefs(){
    try{ localStorage.setItem(kHistorySort(), normHistorySort(state.historySort || 'newest')); }catch(e){}
    try{ localStorage.setItem(kHistoryStatusFilter(), normHistoryFilter(state.historyStatusFilter || 'all')); }catch(e){}
  }

  function loadTransportProfile(){
    // Profil transportu: zapisany lokalnie per konto (stawki/minima/dopłaty i ustawienia)
    try{
      var raw = localStorage.getItem(kTransport());
      if (!raw) return null;
      var obj = safeJsonParse(raw);
      if (!obj || typeof obj !== 'object') return null;
      return obj;
    }catch(e){ return null; }
  }

  function saveTransportProfile(p){
    try{
      if (!p || typeof p !== 'object') return;
      localStorage.setItem(kTransport(), JSON.stringify(p));
    }catch(e){}
  }

  function safeJsonParse(raw){
    try{ return JSON.parse(raw); }catch(e){ return null; }
  }

  function loadFavs(){
    state.favs = [];
    try{
      var raw = localStorage.getItem(kFav());
      if (!raw) return;
      var arr = safeJsonParse(raw);
      if (!Array.isArray(arr)) return;
      state.favs = arr.filter(function(x){ return x && x.sig; }).slice(0, 200);
    }catch(e){}
  }
  function saveFavs(){
    try{ localStorage.setItem(kFav(), JSON.stringify(state.favs.slice(0,200))); }catch(e){}
  }

  function loadRecents(){
    state.recents = [];
    try{
      var raw = localStorage.getItem(kRec());
      if (!raw) return;
      var arr = safeJsonParse(raw);
      if (!Array.isArray(arr)) return;
      state.recents = arr.filter(function(x){ return x && x.sig; }).slice(0, 50);
    }catch(e){}
  }
  function saveRecents(){
    try{ localStorage.setItem(kRec(), JSON.stringify(state.recents.slice(0,50))); }catch(e){}
  }

  function sigOf(sheet, cat, sub, prod, dim, ral){
    return [sheet||'', cat||'', sub||'', prod||'', dim||'', ral||''].join('||');
  }
  function parseSig(sig){
    sig = String(sig || '');
    var p = sig.split('||');
    while (p.length < 6) p.push('');
    return { sheet:p[0]||'', cat:p[1]||'', sub:p[2]||'', prod:p[3]||'', dim:p[4]||'', ral:p[5]||'' };
  }
  function isFav(sig){
    sig = String(sig || '');
    for (var i=0;i<state.favs.length;i++){
      if (String(state.favs[i].sig) === sig) return true;
    }
    return false;
  }
  function toggleFav(sig, meta){
    sig = String(sig || '');
    if (!sig) return;
    var out = [];
    var removed = false;
    for (var i=0;i<state.favs.length;i++){
      if (String(state.favs[i].sig) === sig){ removed = true; continue; }
      out.push(state.favs[i]);
    }
    if (!removed){
      var it = { sig: sig, t: Date.now() };
      if (meta && meta.label) it.label = String(meta.label);
      if (meta && meta.sku) it.sku = String(meta.sku);
      out.unshift(it);
    }
    state.favs = out.slice(0, 200);
    saveFavs();
  }

  function pushRecent(sig, meta){
    sig = String(sig || '');
    if (!sig) return;
    var out = [];
    for (var i=0;i<state.recents.length;i++){
      if (String(state.recents[i].sig) === sig) continue;
      out.push(state.recents[i]);
    }
    var it = { sig: sig, t: Date.now() };
    if (meta && meta.label) it.label = String(meta.label);
    if (meta && meta.sku) it.sku = String(meta.sku);
    out.unshift(it);
    state.recents = out.slice(0, 50);
    saveRecents();
  }

  function clearDraft(){
    state.draftMeta = null;
    state.draftToRestore = null;
    state._draftBannerShown = false;
    try{ localStorage.removeItem(kDraft()); }catch(e){}
    hideDraftBanner();
  }

  function hideDraftBanner(){
    var b = $('zq-draft-banner');
    if (b) b.style.display = 'none';
  }

  var draftSaveT = 0;
  function scheduleDraftSave(){
    if (draftSaveT) clearTimeout(draftSaveT);
    draftSaveT = setTimeout(function(){ draftSaveT = 0; saveDraftNow(); }, 450);
  }

  function saveDraftNow(){
    // szkic zapisujemy tylko jeśli są jakieś dane (linijki albo klient albo tytuł)
    var has = false;
    try{ has = !!(state.offerLines && state.offerLines.length); }catch(e){ has = false; }
    var title = ($('zq-offer-title') && $('zq-offer-title').value) ? $('zq-offer-title').value.trim() : '';
    var comment = ($('zq-offer-comment') && $('zq-offer-comment').value) ? $('zq-offer-comment').value.trim() : '';
    var client = collectClient();
    var hasClient = !!(client.full_name || client.company || client.phone || client.email || client.address || client.nip);
    if (title || comment || hasClient) has = true;

    if (!has){
      try{ localStorage.removeItem(kDraft()); }catch(e){}
      return;
    }

    var payload = {
      v: 1,
      t: Date.now(),
      price_view: getPriceView(),
      title: title,
      comment: comment,
      validity_days: getValidityDays(),
      client: client,
      lines: state.offerLines.map(function(l){
        var isC = isCustomLine(l);
        return {
          id: l.id,
          is_custom: isC,
          custom_kind: isC ? (isTransportLine(l) ? 'transport' : 'custom') : null,
          sheet: isC ? '__CUSTOM__' : (l.item ? l.item.sheet : ''),
          kategoria: (l.item && l.item.kategoria) ? l.item.kategoria : '',
          podkategoria: (l.item && l.item.podkategoria) ? (l.item.podkategoria || '') : '',
          produkt: (l.item && l.item.produkt) ? l.item.produkt : '',
          wymiar: (l.item && l.item.wymiar) ? (l.item.wymiar || '') : '',
          ral: isC ? '' : (l.ral || ''),
          priceMode: isC ? 'unit' : getLineMode(l),
          qty: l.qty,
          disc: l.disc,
          manualUnitNet: l.manualUnitNet,
          custom_unit_net: isC ? ((l.item && typeof l.item.cenaNetto === 'number' && isFinite(l.item.cenaNetto)) ? l.item.cenaNetto : 0) : null,
          line_comment: l.lineComment || '',
          transport: isTransportLine(l) ? (l.transport || null) : null
        };
      })
};
    try{ localStorage.setItem(kDraft(), JSON.stringify(payload)); }catch(e){}
  }

  function maybeShowDraftBanner(){
    if (state._draftBannerShown) return;
    // banner pokazujemy tylko jeśli bieżąca lista jest pusta
    if (state.offerLines && state.offerLines.length) return;
    var raw = null;
    try{ raw = localStorage.getItem(kDraft()); }catch(e){ raw = null; }
    if (!raw) return;
    var d = safeJsonParse(raw);
    if (!d || !d.t) return;

    // szkic wygasa po 7 dniach
    if ((Date.now() - (parseNumber(d.t) || 0)) > (7 * 24 * 3600 * 1000)){
      clearDraft();
      return;
    }

    state.draftMeta = d;
    state._draftBannerShown = true;

    var dt = new Date(parseNumber(d.t) || Date.now());
    var txt = 'Wykryto szkic oferty (' + dt.toLocaleString('pl-PL') + ').';
    var tEl = $('zq-draft-text');
    if (tEl) tEl.textContent = txt;
    var b = $('zq-draft-banner');
    if (b) b.style.display = 'flex';
  }

  function restoreDraft(){
    var d = state.draftMeta;
    if (!d || !Array.isArray(d.lines)) return;
    // wymaga danych z arkusza, więc jeśli jeszcze nie zsynchronizowane - wymuś sync
    state.draftToRestore = d;
    hideDraftBanner();
    if (!state.syncedAt){
      syncAll(false).finally(function(){ applyDraftNow(); });
    } else {
      applyDraftNow();
    }
  }

  function applyDraftNow(){
    var d = state.draftToRestore;
    if (!d) return;
    state.draftToRestore = null;

    // ustawienia UI
    var pv = (d.price_view === 'gross') ? 'gross' : 'net';
    if ($('zq-price-view')) $('zq-price-view').value = pv;

    var allowed = isSpecialAllowed();
    state.specialOffer = (!!d.special_offer) && allowed;
    if ($('zq-special')) $('zq-special').checked = state.specialOffer;

    if ($('zq-offer-title') && d.title != null) $('zq-offer-title').value = String(d.title);
    if ($('zq-offer-comment') && d.comment != null) $('zq-offer-comment').value = String(d.comment);
    if ($('zq-valid-days') && d.validity_days != null){
      $('zq-valid-days').value = String(clampNum(parseNumber(d.validity_days) || 14, 1, 365));
    }
    if (d.client && !isClientLocked()) applyClient(d.client);

    // linie
    var rebuilt = [];
    var maxD = getMaxDiscount();
    for (var i=0;i<d.lines.length;i++){
      var L = d.lines[i] || {};
      var kind = (L.custom_kind != null) ? String(L.custom_kind) : '';
      var isC = !!(L.is_custom || L.isCustom || kind === 'transport' || String(L.sheet || '') === '__CUSTOM__');

      if (isC){
        var nm = (L.produkt != null) ? String(L.produkt).trim() : '';
        var unit = parseNumber(L.custom_unit_net);
        if (unit == null) unit = (L.manualUnitNet != null) ? parseNumber(L.manualUnitNet) : null;
        if (unit == null || unit < 0) unit = 0;

        var discC = clampNum(parseNumber(L.disc) || 0, 0, 100);
        if (discC > maxD) discC = maxD;

        rebuilt.push({
          id: L.id || lineId(),
          item: (kind === 'transport') ? makeTransportItem(unit) : makeCustomItem(nm, unit),
          ral: '',
          priceMode: 'unit',
          qty: clampNum(parseNumber(L.qty) || 1, 1, 999999),
          disc: discC,
          manualUnitNet: (L.manualUnitNet != null) ? parseNumber(L.manualUnitNet) : null,
          isCustom: true,
          isTransport: (kind === 'transport'),
          customKind: (kind === 'transport') ? 'transport' : '',
          transport: (kind === 'transport') ? normalizeTransportProfile(L.transport || { mode:'flat', flat_rate: unit, min_net:0, extras:{hds:0,unload:0,sat:0}, no_global_disc:true }) : null,
          lineComment: L.line_comment ? String(L.line_comment) : ''
        });
        continue;
      }

      var found = findItem(L.sheet, L.kategoria, L.podkategoria || '', L.produkt, L.wymiar || '');
      if (!found) continue;
      var ral = L.ral || '';
      var p = (found.ceny && Object.prototype.hasOwnProperty.call(found.ceny, ral)) ? found.ceny[ral] : 0;
      var cloned = JSON.parse(JSON.stringify(found));
      cloned.cenaNetto = p;

      var disc = clampNum(parseNumber(L.disc) || 0, 0, 100);
      if (disc > maxD) disc = maxD;

      var pm = (L.priceMode === 'paleta' || L.priceMode === 'tir' || L.priceMode === 'unit') ? L.priceMode : 'unit';

      rebuilt.push({
        id: L.id || lineId(),
        item: cloned,
        ral: ral,
        priceMode: pm,
        qty: clampNum(parseNumber(L.qty) || 1, 1, 999999),
        disc: disc,
        manualUnitNet: (L.manualUnitNet != null) ? parseNumber(L.manualUnitNet) : null,
        lineComment: L.line_comment ? String(L.line_comment) : ''
      });
    }
    state.offerLines = rebuilt;
    persistOffer();
    renderLines();
    setErr('');
    setExportStatus('Przywrócono szkic.');
  }
  
  function applyPermsToUI(){
    // max discount in inputs
    var maxD = getMaxDiscount();
    var discEl = $('zq-disc');
    if (discEl){
      discEl.max = String(maxD);
      var cur = parseNumber(discEl.value);
      if (cur != null && cur > maxD) discEl.value = String(maxD);
    }

    var discAllEl = $('zq-disc-all');
    if (discAllEl){
      discAllEl.max = String(maxD);
      var cur2 = parseNumber(discAllEl.value);
      if (cur2 != null && cur2 > maxD) discAllEl.value = String(maxD);
    }

    var discCustomEl = $('zq-custom-disc');
    if (discCustomEl){
      discCustomEl.max = String(maxD);
      var cur3 = parseNumber(discCustomEl.value);
      if (cur3 != null && cur3 > maxD) discCustomEl.value = String(maxD);
    }

    var discTransportEl = $('zq-transport-disc');
    if (discTransportEl){
      discTransportEl.max = String(maxD);
      var cur4 = parseNumber(discTransportEl.value);
      if (cur4 != null && cur4 > maxD) discTransportEl.value = String(maxD);
    }
    var discHint = $('zq-disc-all-hint');
    if (discHint){
      discHint.textContent = (maxD < 100) ? ('Limit rabatu konta: ' + maxD + '%') : '';
    }

    // special offer toggle
    var specEl = $('zq-special');
    if (specEl){
      var allowed = isSpecialAllowed();
      specEl.disabled = !allowed;
      if (!allowed && specEl.checked){
        specEl.checked = false;
        state.specialOffer = false;
      }
      // hint
      specEl.title = allowed ? '' : ('Brak uprawnień do "Oferta specjalna"');
    }

    // tabs: jeśli ograniczone, przebuduj
    var allowedTabs = getAllowedTabs();
    if (allowedTabs && allowedTabs.length){
      state._allowedTabs = allowedTabs;
    } else {
      state._allowedTabs = null;
    }
    buildTabs();
    populateCascades();
    // indeks wyszukiwania zależy od allowed_tabs
    state.searchIndex = null;
    if (state.syncedAt) buildSearchIndexAsync();
    renderLines();
    applyClientAccessUI();
  }



  function parseNumber(x){
    if (x == null) return null;
    if (typeof x === 'number' && isFinite(x)) return x;
    var s = String(x).trim();
    if (!s) return null;
    s = s.replace(/\\s+/g,'').replace(/zł/ig,'').replace(/pln/ig,'');
    s = s.replace(',', '.');
    s = s.replace(/[^0-9.+-]/g,'');
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  // "50x/60 zł" -> { qty:50, price:60 }
  function parsePackCell(x){
    if (x == null) return null;
    var s = String(x).trim();
    if (!s) return null;

    // usuń spacje i walutę
    var raw = s.replace(/\s+/g,'').replace(/zł/ig,'').replace(/pln/ig,'');

    // 1) format pakietowy: "50x/60" -> { qty:50, price:60 }
    var m = raw.match(/^(\d+)x\/(\d+(?:[.,]\d+)?)$/i);
    if (m){
      var q = parseInt(m[1], 10);
      if (!isFinite(q) || q <= 0) return null;
      var p = parseFloat(String(m[2]).replace(',', '.'));
      if (!isFinite(p) || p < 0) return null;
      return { qty: q, price: p };
    }

    // 2) format uproszczony: sama cena (np. "60" albo "60,00")
    var n = parseNumber(raw);
    if (n == null || !isFinite(n) || n < 0) return null;
    return { qty: 1, price: n };
  }

  function normalizeSku(v){
    v = (v == null) ? '' : String(v).trim();
    if (!v) return '';
    // typowy bug z XLSX: liczba jako float z .0 (np. 1000007.0)
    if (/^\d+\.0+$/.test(v)) v = v.replace(/\.0+$/, '');
    return v;
  }
  function clampNum(n, min, max){
    n = (typeof n === 'number' && isFinite(n)) ? n : min;
    if (n < min) n = min;
    if (n > max) n = max;
    return n;
  }
  function getVatRate(){
    var r = (window.ZQOS && typeof window.ZQOS.vatRate === 'number') ? window.ZQOS.vatRate : 0.23;
    return (typeof r === 'number' && isFinite(r)) ? r : 0.23;
  }

  function apiFetch(path, opts){
    opts = opts || {};
    if (!('credentials' in opts)) opts.credentials = 'same-origin';
    if (!('cache' in opts)) opts.cache = 'no-store';
    var headers = opts.headers || {};
    headers['Accept'] = 'application/json';
    if (opts.json){
      headers['Content-Type'] = 'application/json';
    }
    if (state.authToken){
      headers['Authorization'] = 'Bearer ' + state.authToken;
      headers['X-ZQ-Token'] = state.authToken;
    }
    opts.headers = headers;
    if (opts.json){
      opts.body = JSON.stringify(opts.json);
      delete opts.json;
      opts.method = opts.method || 'POST';
    }

    // Robust URL build for WP "?rest_route=" mode (allows adding extra query params like ?_ without breaking route).
    var base = (window.ZQOS && window.ZQOS.apiBase) ? String(window.ZQOS.apiBase) : '';
    var ns = (window.ZQOS && window.ZQOS.apiNs) ? String(window.ZQOS.apiNs) : '';
    var p = String(path || '');
    var pOnly = p;
    var qPart = '';
    var qIdx = p.indexOf('?');
    if (qIdx >= 0){
      pOnly = p.slice(0, qIdx);
      qPart = p.slice(qIdx + 1);
    }

    var url;
    try{
      url = new URL(base, window.location.origin);
    }catch(e){
      // fallback
      url = new URL(window.location.origin + '/');
    }

    url.searchParams.set('rest_route', ns + pOnly);

    // merge query from path itself (e.g. "/offers?force=1")
    if (qPart){
      qPart.split('&').forEach(function(kv){
        if (!kv) return;
        var parts = kv.split('=');
        var k = decodeURIComponent(parts[0] || '');
        var v = decodeURIComponent((parts[1] || '').replace(/\+/g,' '));
        if (k) url.searchParams.set(k, v);
      });
    }

    // optional extra query passed as opts.query
    if (opts.query && typeof opts.query === 'object'){
      Object.keys(opts.query).forEach(function(k){
        if (!k) return;
        url.searchParams.set(k, String(opts.query[k]));
      });
      delete opts.query;
    }

    var urlStr = url.toString();

    function stripAuthHeaders(h){
      try{
        var hh = Object.assign({}, h || {});
        delete hh['Authorization'];
        delete hh['X-ZQ-Token'];
        return hh;
      }catch(e){ return {}; }
    }

    function authLostOnce(){
      var now = Date.now();
      if (state._authLostAt && (now - state._authLostAt) < 3000) return;
      state._authLostAt = now;

      setStatus('Synchronizacja: błąd');
      setErr('Brak autoryzacji. Zaloguj się ponownie w kalkulatorze i otwórz panel jeszcze raz.', 'error', { sticky:true, timeout:0 });

      try{
        if (window.parent && window.parent !== window){
          window.parent.postMessage({ type:'zq:offer:auth:required' }, '*');
        }
      }catch(e){}
    }

    // 401-heal: jeśli mamy nieaktualny token w JS (np. localStorage override),
    // wyczyść go i spróbuj raz jeszcze bez headerów (cookie fallback).
    return fetch(urlStr, opts).then(function(r){
      if (r && r.status === 401){
        var hadTok = !!state.authToken;
        try{ clearTokenOverride(); }catch(e){}
        state.authToken = '';

        if (hadTok){
          var opts2 = Object.assign({}, opts);
          opts2.headers = stripAuthHeaders(opts && opts.headers);
          return fetch(urlStr, opts2).then(function(r2){
            if (r2 && r2.status === 401){
              authLostOnce();
            }
            return r2;
          });
        }

        authLostOnce();
      }
      return r;
    });
  }

  function refreshMe(){
    return apiFetch('/me', {method:'GET'}).then(function(r){ return r.json().catch(function(){return null;}).then(function(j){
      if (r.ok && j && j.ok){
        state.account = j.account || null;
        state.actor = (j.actor && typeof j.actor === 'object') ? j.actor : null;
        state.canSwitch = !!j.can_switch;
        state.actorCaps = (j.actor_caps && typeof j.actor_caps === 'object') ? j.actor_caps : null;
        state.transportProfile = loadTransportProfile() || null;
        // fixed client
        if (state.account && state.account.fixed_client){
          applyClient(state.account.fixed_client);
        }
        var elUser = $('zq-user');
        if (elUser && state.account && state.account.login){
          var label = 'Zalogowany: ' + state.account.login;
          if (state.actor && state.actor.login && state.actor.login !== state.account.login){
            label += ' (SA: ' + state.actor.login + ')';
          }
          elUser.textContent = label;
        }
        applyUserSwitchUI();
        applySellerToUI();
        applyPermsToUI();
        applyClientAccessUI();
        loadFavs();
        loadRecents();
        maybeShowDraftBanner();
        applyDefaultOfferStatus();
        applyHistoryPrefs();
        refreshProfile();
        startTimeTracking();
      }
    }); }).catch(function(){});
  }


  function applyUserSwitchUI(){
    var btn = $('zq-userbtn');
    var menu = $('zq-usermenu');
    var wrap = $('zq-user-switch');
    if (!btn || !menu || !wrap) return;

    // init once
    if (!state._switch.inited){
      state._switch.inited = true;

      btn.addEventListener('click', function(ev){
        if (btn.disabled) return;
        ev.preventDefault();
        ev.stopPropagation();
        toggleUserMenu();
      });

      DOC.addEventListener('click', function(){
        closeUserMenu();
      });

      DOC.addEventListener('keydown', function(ev){
        if (ev && ev.key === 'Escape') closeUserMenu();
      });

      menu.addEventListener('click', function(ev){
        ev.stopPropagation();
      });
    }

    if (!state.canSwitch){
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
      closeUserMenu();
      return;
    }

    btn.disabled = false;
    btn.removeAttribute('aria-disabled');
  }

  function toggleUserMenu(){
    if (state._switch.open) closeUserMenu();
    else openUserMenu();
  }

  function openUserMenu(){
    var btn = $('zq-userbtn');
    var menu = $('zq-usermenu');
    if (!btn || !menu) return;
    if (!state.canSwitch) return;

    state._switch.open = true;
    btn.setAttribute('aria-expanded', 'true');
    menu.style.display = 'block';

    if (!state._switch.list){
      menu.innerHTML = '<div class="zq-um-title">Ładowanie kont...</div>';
      apiFetch('/accounts', {method:'GET'}).then(function(r){ return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok) throw new Error((j && j.message) ? j.message : 'accounts');
        state._switch.list = Array.isArray(j.accounts) ? j.accounts : [];
        renderUserMenu();
      }); }).catch(function(e){
        menu.innerHTML = '<div class="zq-um-title">Błąd: ' + (e && e.message ? e.message : 'accounts') + '</div>';
      });
    } else {
      renderUserMenu();
    }
  }

  function closeUserMenu(){
    var btn = $('zq-userbtn');
    var menu = $('zq-usermenu');
    if (!btn || !menu) return;
    state._switch.open = false;
    btn.setAttribute('aria-expanded', 'false');
    menu.style.display = 'none';
  }

  function renderUserMenu(){
    var menu = $('zq-usermenu');
    if (!menu) return;

    var list = state._switch.list;
    if (!Array.isArray(list) || !list.length){
      menu.innerHTML = '<div class="zq-um-title">Brak kont.</div>';
      return;
    }

    var curId = state.account && state.account.id ? parseInt(state.account.id, 10) : 0;

    var html = '<div class="zq-um-title">Przełącz konto</div>';
    for (var i=0;i<list.length;i++){
      var a = list[i] || {};
      var id = a.id != null ? parseInt(a.id, 10) : 0;
      if (!id) continue;
      var login = a.login ? String(a.login) : ('konto #' + id);
      var sub = a.seller_name ? String(a.seller_name) : '';
      var cls = (id === curId) ? ' class="is-active"' : '';
      html += '<button type="button" data-id="' + id + '"' + cls + ' role="menuitem">' +
        '<span><div class="zq-um-login">' + escapeHtml(login) + '</div>' +
        (sub ? ('<div class="zq-um-sub">' + escapeHtml(sub) + '</div>') : '') +
        '</span>' +
        (id === curId ? '<span class="zq-um-sub">aktywny</span>' : '') +
      '</button>';
    }
    menu.innerHTML = html;

    // bind
    var btns = menu.querySelectorAll('button[data-id]');
    for (var j=0;j<btns.length;j++){
      (function(b){
        b.addEventListener('click', function(){
          var id = parseInt(b.getAttribute('data-id') || '0', 10) || 0;
          if (!id) return;
          switchAccount(id);
        });
      })(btns[j]);
    }
  }

  function switchAccount(targetId){
    if (!targetId || !state.canSwitch) return;

    closeUserMenu();
    setErr('');
    setExportStatus('Przełączam konto...');

    apiFetch('/switch', { json: { account_id: targetId } }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok || !j.token) throw new Error((j && j.message) ? j.message : 'switch');

        // ustaw nowy token + override (dla hosta)
        state.authToken = String(j.token);
        var actorLogin = '';
        try{
          // aktor = SA (jeśli jest) inaczej obecne konto
          actorLogin = (state.actor && state.actor.login) ? String(state.actor.login) : ((state.account && state.account.login) ? String(state.account.login) : '');
        }catch(e){ actorLogin = ''; }
        setTokenOverride(state.authToken, actorLogin);

        // opcjonalnie powiadom hosta
        try{
          if (window.parent && window.parent !== window){
            window.parent.postMessage({ type:'zq:auth:token:update', payload:{ token: state.authToken } }, '*');
            window.parent.postMessage({ type:'zq:offer:token:update', payload:{ token: state.authToken } }, '*');
          }
        }catch(e){}

        // odśwież UI pod nowe konto
        state._switch.list = null; // wymuś świeżą listę (żeby aktywne odznaczyło)
        return refreshMe().then(function(){
          return syncAll(false);
        }).then(function(){
          refreshClients();
          refreshHistory();
          setExportStatus('OK: przełączono konto.');
        });
      });
    }).catch(function(e){
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'switch'));
    });
  }

  function escapeHtml(s){
    s = String(s == null ? '' : s);
    return s.replace(/[&<>"']/g, function(ch){
      if (ch === '&') return '&amp;';
      if (ch === '<') return '&lt;';
      if (ch === '>') return '&gt;';
      if (ch === '"') return '&quot;';
      if (ch === "'") return '&#39;';
      return ch;
    });
  }


  
  function formatDuration(sec){
    sec = (typeof sec === 'number' && isFinite(sec)) ? Math.max(0, Math.floor(sec)) : 0;
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function renderProfile(){
    var acc = state.account || null;
    var prof = (state.profile && typeof state.profile === 'object') ? state.profile : {};
    var stats = (state.profileStats && typeof state.profileStats === 'object') ? state.profileStats : {};

    var seller = getSeller();

    var name = seller.name || (acc && acc.login ? String(acc.login) : '');
    var role = seller.branch || 'Profil';

    var avatarUrl = (prof.avatar_url && typeof prof.avatar_url === 'string') ? prof.avatar_url : '';
    var coverUrl  = (prof.cover_url && typeof prof.cover_url === 'string') ? prof.cover_url : '';

    var elName = $('zq-prof-name');
    if (elName) elName.textContent = name || '—';

    var elRole = $('zq-prof-role');
    if (elRole) elRole.textContent = role || '—';

    var elAvatar = $('zq-prof-avatar');
    if (elAvatar){
      if (avatarUrl){
        elAvatar.style.backgroundImage = 'url("' + avatarUrl.replace(/"/g, '%22') + '")';
      } else {
        elAvatar.style.backgroundImage = 'url("https://zegger.pl/wp-content/uploads/2026/03/default-avatar-icon-of-social-media-user-vector.jpg")';
      }
    }

    var elCover = $('zq-prof-cover');
    if (elCover){
      if (coverUrl){
        elCover.classList.add('has-img');
        elCover.style.backgroundImage = 'url("' + coverUrl.replace(/"/g, '%22') + '")';
      } else {
        elCover.classList.remove('has-img');
        elCover.style.backgroundImage = '';
      }
    }

    var elPhone = $('zq-prof-phone');
    if (elPhone){
      var p = seller.phone ? String(seller.phone) : '';
      if (p){
        elPhone.textContent = p;
        elPhone.href = 'tel:' + p.replace(/\s+/g,'');
        elPhone.style.display = '';
      } else {
        elPhone.textContent = '—';
        elPhone.href = '#';
        elPhone.style.display = '';
      }
    }

    var elEmail = $('zq-prof-email');
    if (elEmail){
      var e = seller.email ? String(seller.email) : '';
      if (e){
        elEmail.textContent = e;
        elEmail.href = 'mailto:' + e;
        elEmail.style.display = '';
      } else {
        elEmail.textContent = '—';
        elEmail.href = '#';
        elEmail.style.display = '';
      }
    }

    if ($('zq-prof-stat-offers')) $('zq-prof-stat-offers').textContent = String((stats.offers_count != null) ? stats.offers_count : 0);
    if ($('zq-prof-stat-clients')) $('zq-prof-stat-clients').textContent = String((stats.clients_count != null) ? stats.clients_count : 0);
    if ($('zq-prof-stat-time')) $('zq-prof-stat-time').textContent = formatDuration((stats.time_total_sec != null) ? stats.time_total_sec : 0);

    // KPI statusów
    var bar = $('zq-prof-statusbar');
    if (bar){
      while (bar.firstChild) bar.removeChild(bar.firstChild);
      var sc = (stats.status_counts && typeof stats.status_counts === 'object') ? stats.status_counts : {};
      var won = parseInt(sc.won || 0, 10) || 0;
      var lost = parseInt(sc.lost || 0, 10) || 0;
      var prog = parseInt(sc.in_progress || 0, 10) || 0;
      var need = parseInt(sc.needs_update || 0, 10) || 0;
      var sent = parseInt(sc.sent || 0, 10) || 0;
      var neu = parseInt(sc.new || 0, 10) || 0;
      var unset = parseInt(sc.unset || 0, 10) || 0;


	      function jumpToNeedsUpdateHistory(){
	        // Zamknij profil i przenieś do historii z filtrem "Wymaga zaktualizowania"
	        try{ closeProfileModal(); }catch(e){}

	        // Uwaga: render historii opiera się o state.historyStatusFilter ustawiany w handlerze "change".
	        // Samo przypisanie hf.value nie wystarcza.
	        var run = function(){
	          var hf = $('zq-history-status-filter');
	          var det = $('zq-history-details');
	          if (det && typeof det.open !== 'undefined') det.open = true;

	          if (hf){
	            hf.value = 'needs_update';
	            try{
	              hf.dispatchEvent(new Event('change', { bubbles:true }));
	            }catch(e){
	              // starsze przeglądarki
	              try{ var ev = DOC.createEvent('Event'); ev.initEvent('change', true, true); hf.dispatchEvent(ev); }catch(e2){}
	            }
	          } else {
	            // fallback: ustaw stan bez selekta
	            try{ state.historyStatusFilter = normHistoryFilter('needs_update'); persistHistoryPrefs(); renderHistory(); renderHistoryMini(); }catch(e){}
	          }

	          // przewiń do historii (kotwica w DOM)
	          var anchor = $('zq-refresh-history') || $('zq-history-details') || $('zq-history') || $('zq-history-mini');
	          if (anchor && anchor.scrollIntoView){
	            try{ anchor.scrollIntoView({ behavior:'smooth', block:'start' }); }catch(e){ try{ anchor.scrollIntoView(true); }catch(e2){} }
	          }

	          // fokus na filtr statusu dla jasności
	          if (hf && hf.focus){
	            try{ hf.focus({ preventScroll:true }); }catch(e){ try{ hf.focus(); }catch(e2){} }
	          }
	        };

	        // Daj DOMowi chwilę na zamknięcie profilu (overlay/scroll-lock), potem ustaw filtr i scroll.
	        try{ setTimeout(run, 80); }catch(e){ try{ run(); }catch(e2){} }
	      }

	      function addKpi(text, extraClass, onClick){
	        var b = DOC.createElement('span');
	        b.className = 'zq-badge' + (extraClass ? (' ' + extraClass) : '');
	        b.textContent = text;
	        if (typeof onClick === 'function'){
	          b.classList.add('is-click');
	          b.setAttribute('role','button');
	          b.setAttribute('tabindex','0');
	          b.addEventListener('click', function(ev){
	            ev.preventDefault();
	            ev.stopPropagation();
	            onClick();
	          });
	          b.addEventListener('keydown', function(ev){
	            var k = ev.key || '';
	            if (k === 'Enter' || k === ' ' || k === 'Spacebar'){
	              ev.preventDefault();
	              ev.stopPropagation();
	              onClick();
	            }
	          });
	        }
	        bar.appendChild(b);
	      }

      addKpi('Sukces: ' + won, 'zq-badge--status zq-st-won');
      addKpi('W trakcie: ' + prog, 'zq-badge--status zq-st-in_progress');
	      if (need > 0) addKpi('Wymaga aktualizacji: ' + need, 'zq-badge--status zq-st-needs_update zq-pulse', jumpToNeedsUpdateHistory);
      addKpi('Wysłane: ' + sent, 'zq-badge--status zq-st-sent');
      addKpi('Nowe: ' + neu, 'zq-badge--status zq-st-new');
      if (unset > 0) addKpi('Brak statusu: ' + unset, 'zq-badge--status zq-st-unset');

      var denom = won + lost;
      if (denom > 0){
        var rate = Math.round((won / denom) * 100);
        var kcls = 'zq-badge--status ' + (rate >= 60 ? 'zq-st-won' : (rate >= 30 ? 'zq-st-in_progress' : 'zq-st-lost'));
        addKpi('Skuteczność: ' + rate + '%', kcls);
      }
    }
  }

  function refreshProfile(){
    return apiFetch('/profile', {method:'GET'}).then(function(r){
      return r.json().catch(function(){ return null; }).then(function(j){
        if (!r.ok || !j || !j.ok) return;
        state.profile = (j.profile && typeof j.profile === 'object') ? j.profile : {};
        state.profileStats = (j.stats && typeof j.stats === 'object') ? j.stats : {};
        renderProfile();
      });
    }).catch(function(){});
  }

  function startTimeTracking(){
    if (state._timeTrack && state._timeTrack.started) return;

    if (!state._timeTrack) state._timeTrack = { started:false, lastTs:0, timerId:0 };
    state._timeTrack.started = true;
    state._timeTrack.lastTs = Date.now();

    function flushTime(force){
        if (!force && DOC.hidden) { state._timeTrack.lastTs = Date.now(); return; }

      var now = Date.now();
      var delta = Math.floor((now - (state._timeTrack.lastTs || now)) / 1000);
      state._timeTrack.lastTs = now;
      if (!delta || delta < 1) return;
      if (delta > 3600) delta = 3600;

      apiFetch('/profile/time', { json: { seconds: delta } }).then(function(r){
        return r.json().catch(function(){ return null; }).then(function(j){
          if (!r.ok || !j || !j.ok) return;
          // update cached time
          if (!state.profileStats) state.profileStats = {};
          if (j.time_total_sec != null) state.profileStats.time_total_sec = j.time_total_sec;
          renderProfile();
        });
      }).catch(function(){});
    }

    state._timeTrack.timerId = window.setInterval(function(){ flushTime(false); }, 60000);

    DOC.addEventListener('visibilitychange', function(){
      if (DOC.hidden) flushTime(true);
      else state._timeTrack.lastTs = Date.now();
    });

    window.addEventListener('beforeunload', function(){
      try{ flushTime(true); }catch(e){}
    });
  }

  function openProfileModal(){
    var modal = $('zq-prof-modal');
    if (!modal) return;

    var seller = getSeller();
    var prof = (state.profile && typeof state.profile === 'object') ? state.profile : {};

    if ($('zq-prof-seller-name')) $('zq-prof-seller-name').value = seller.name || '';
    if ($('zq-prof-seller-branch')) $('zq-prof-seller-branch').value = seller.branch || '';
    if ($('zq-prof-seller-phone')) $('zq-prof-seller-phone').value = seller.phone || '';
    if ($('zq-prof-seller-email')) $('zq-prof-seller-email').value = seller.email || '';

    if ($('zq-prof-avatar-url')) $('zq-prof-avatar-url').value = prof.avatar_url || '';
    if ($('zq-prof-cover-url')) $('zq-prof-cover-url').value = prof.cover_url || '';

    if ($('zq-prof-status')) $('zq-prof-status').textContent = '';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
  }

  function closeProfileModal(){
    var modal = $('zq-prof-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
  }

  function saveProfile(){

    var payload = {
      seller_phone: $('zq-prof-seller-phone') ? $('zq-prof-seller-phone').value : '',
      seller_email: $('zq-prof-seller-email') ? $('zq-prof-seller-email').value : '',
      avatar_url: $('zq-prof-avatar-url') ? $('zq-prof-avatar-url').value : '',
      cover_url: $('zq-prof-cover-url') ? $('zq-prof-cover-url').value : '',
    };

    if ($('zq-prof-status')) $('zq-prof-status').textContent = 'Zapisywanie...';

    apiFetch('/profile', { json: payload }).then(function(r){
      return r.json().catch(function(){ return null; }).then(function(j){
        if (!r.ok || !j || !j.ok){
          if ($('zq-prof-status')) $('zq-prof-status').textContent = 'Błąd: nie udało się zapisać profilu.';
          return;
        }
        // refresh account + profile
        if ($('zq-prof-status')) $('zq-prof-status').textContent = 'Zapisano.';
        refreshMe();
        refreshProfile();
        window.setTimeout(function(){ closeProfileModal(); }, 220);
      });
    }).catch(function(){
      if ($('zq-prof-status')) $('zq-prof-status').textContent = 'Błąd: sieć.';
    });
  }

function refreshClients(){
    if (isClientLocked() || !isClientSelectAllowed()){
      state.clients = [];
      renderClients();
      return Promise.resolve();
    }
    return apiFetch('/clients', {method:'GET'}).then(function(r){ return r.json().catch(function(){return null;}).then(function(j){
      if (!r.ok || !j || !j.ok) return;
      state.clients = Array.isArray(j.clients) ? j.clients : [];
      renderClients();
    }); }).catch(function(){});
  }

  function refreshHistory(opts){
    opts = opts || {};
    var bust = !!opts.bust;
    var url = '/offers' + (bust ? ('?_=' + Date.now()) : '');
    return apiFetch(url, {method:'GET'}).then(function(r){ return r.json().catch(function(){return null;}).then(function(j){
      if (!r.ok || !j || !j.ok) return;
      state.offers = Array.isArray(j.offers) ? j.offers : [];
      renderHistory();
      renderHistoryMini();
      maybeShowNeedsUpdatePopupOnBoot();
    }); }).catch(function(){});
  }

  function refreshHistoryAfterExport(expectedId){
    expectedId = expectedId ? String(expectedId) : '';
    var tries = 0;
    var prevLen = Array.isArray(state.offers) ? state.offers.length : 0;
    var prevTopId = (state.offers && state.offers[0] && state.offers[0].id) ? String(state.offers[0].id) : '';

    function hasId(id){
      try{
        for (var i=0;i<state.offers.length;i++){
          if (String(state.offers[i].id) === id) return true;
        }
      }catch(e){}
      return false;
    }

    function once(){
      tries++;
      return refreshHistory({bust:true}).then(function(){
        if (expectedId){
          if (hasId(expectedId)) return;
          if (tries >= 8) return;
        } else {
          var nowLen = Array.isArray(state.offers) ? state.offers.length : 0;
          var nowTopId = (state.offers && state.offers[0] && state.offers[0].id) ? String(state.offers[0].id) : '';
          if (nowLen > prevLen || (nowTopId && nowTopId !== prevTopId)) return;
          if (tries >= 4) return;
        }
        var wait = 180 * tries;
        return new Promise(function(res){ setTimeout(res, wait); }).then(once);
      });
    }
    return once();
  }

  function fetchOfferById(id){
    id = String(id || '').replace(/[^0-9]/g, '');
    if (!id || !state.authToken) return Promise.resolve(null);
    return apiFetch('/offers/' + id + '?_=' + Date.now(), {method:'GET'}).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok || !j.offer) return null;
        return j.offer;
      });
    }).catch(function(){ return null; });
  }

  function upsertOfferToHistory(offer){
    if (!offer || !offer.id) return;
    var id = String(offer.id);
    var item = {
      id: offer.id,
      title: offer.title || ('Oferta #' + id),
      created_at: offer.created_at || '',
      updated_at: offer.updated_at || '',
      pdf_path: offer.pdf_path || '',
      status: offer.status || 'unset',
      status_updated_at: offer.status_updated_at || '',
      comment: offer.comment || '',
      sales_note: offer.sales_note || '',
      account_login: offer.account_login || '',
      locked: (offer.locked === 1 || offer.locked === '1' || offer.locked === true) ? 1 : 0,
      locked_at: offer.locked_at || null,
      locked_by: offer.locked_by || null,
      lock_reason: offer.lock_reason || null,
      locked_by_login: offer.locked_by_login || null
    };
    var out = [];
    var arr = Array.isArray(state.offers) ? state.offers : [];
    for (var i=0;i<arr.length;i++){
      if (!arr[i]) continue;
      if (String(arr[i].id) === id) continue;
      out.push(arr[i]);
    }
    out.unshift(item);
    state.offers = out;
  }

function renderClients(){
    var sel = $('zq-client-select');
    if (!sel) return;
    // reset
    while (sel.firstChild) sel.removeChild(sel.firstChild);

    var allowManual = isClientManualAllowed();

    var opt0 = DOC.createElement('option');
    opt0.value = '';
    opt0.textContent = allowManual ? '- ręcznie -' : 'Wybierz klienta...';
    if (!allowManual){
      opt0.disabled = true;
      // jeśli nic nie jest wybrane, trzymaj placeholder
      opt0.selected = true;
    }
    sel.appendChild(opt0);

    for (var i=0; i<state.clients.length; i++){
      var c = state.clients[i];
      var label = (c.company ? c.company : '') + (c.full_name ? (' - ' + c.full_name) : '');
      label = label.trim();
      if (!label) label = 'Klient #' + c.id;
      var opt = DOC.createElement('option');
      opt.value = String(c.id);
      opt.textContent = label;
      sel.appendChild(opt);
    }
  }

  function applyClient(c){
    if (!c) return;
    if ($('zq-client-fullname')) $('zq-client-fullname').value = c.full_name || '';
    if ($('zq-client-company')) $('zq-client-company').value = c.company || '';
    if ($('zq-client-nip')) $('zq-client-nip').value = c.nip || '';
    if ($('zq-client-phone')) $('zq-client-phone').value = c.phone || '';
    if ($('zq-client-email')) $('zq-client-email').value = c.email || '';
    if ($('zq-client-address')) $('zq-client-address').value = c.address || '';
  }

  function getSelectedClient(){
    var sel = $('zq-client-select');
    var id = null;
    if (sel && sel.value) id = String(sel.value);
    if (!id) return null;
    for (var i=0;i<state.clients.length;i++){
      if (state.clients[i] && String(state.clients[i].id) === id) return state.clients[i];
    }
    return null;
  }


function collectClient(){
  // jeśli konto ma klienta stałego - zawsze zwracaj fixed_client
  if (isClientLocked() && state.account && state.account.fixed_client){
    var fc = state.account.fixed_client;
    return {
      id: fc.id || null,
      full_name: (fc.full_name || '').toString().trim(),
      company: (fc.company || '').toString().trim(),
      nip: (fc.nip || '').toString().trim(),
      phone: (fc.phone || '').toString().trim(),
      email: (fc.email || '').toString().trim(),
      address: (fc.address || '').toString().trim()
    };
  }

  return {
    full_name: ($('zq-client-fullname') && $('zq-client-fullname').value) ? $('zq-client-fullname').value.trim() : '',
    company: ($('zq-client-company') && $('zq-client-company').value) ? $('zq-client-company').value.trim() : '',
    nip: ($('zq-client-nip') && $('zq-client-nip').value) ? $('zq-client-nip').value.trim() : '',
    phone: ($('zq-client-phone') && $('zq-client-phone').value) ? $('zq-client-phone').value.trim() : '',
    email: ($('zq-client-email') && $('zq-client-email').value) ? $('zq-client-email').value.trim() : '',
    address: ($('zq-client-address') && $('zq-client-address').value) ? $('zq-client-address').value.trim() : ''
  };
}

function permDefault(key, def){
  var p = (state.account && state.account.perms && typeof state.account.perms === 'object') ? state.account.perms : null;
  if (!p || !Object.prototype.hasOwnProperty.call(p, key)) return !!def;
  return !!p[key];
}

function isClientLocked(){
  var fc = (state.account && state.account.fixed_client && typeof state.account.fixed_client === 'object') ? state.account.fixed_client : null;
  if (!fc) return false;
  return !!(fc.id || fc.company || fc.full_name || fc.phone || fc.email || fc.address || fc.nip);
}

function isClientSelectAllowed(){
  if (isClientLocked()) return false;
  return permDefault('can_select_client', true);
}

function isClientAddAllowed(){
  if (isClientLocked()) return false;
  return permDefault('can_add_client', true);
}

function isClientEditAllowed(){
  if (isClientLocked()) return false;
  var p = (state.account && state.account.perms && typeof state.account.perms === 'object') ? state.account.perms : null;
  if (p && Object.prototype.hasOwnProperty.call(p, 'can_edit_client')) return !!p.can_edit_client;
  // domyślnie: tylko konta admin (Wszyscy klienci)
  return !!(p && p.can_view_all_clients);
}

function isClientManualAllowed(){
  // Jeśli użytkownik ma tylko wybór klienta (bez add/edit) - wyłącz ręczne wpisywanie.
  if (isClientLocked()) return false;
  var canSel = isClientSelectAllowed();
  var canAdd = isClientAddAllowed();
  var canEdit = isClientEditAllowed();
  if (canSel && !canAdd && !canEdit) return false;
  // w pozostałych przypadkach zostaw ręczne jako fallback
  return true;
}


function applyClientAccessUI(){
  var badge = $('zq-client-badge');
  var note = $('zq-client-note');
  var selWrap = $('zq-client-select-wrap');
  var addWrap = $('zq-client-add-wrap');
  var editWrap = $('zq-client-edit-wrap');
  var sel = $('zq-client-select');
  var btnAdd = $('zq-client-add');
  var btnEdit = $('zq-client-edit');

  var locked = isClientLocked();
  var canSel = isClientSelectAllowed();
  var canAdd = isClientAddAllowed();
  var canEdit = isClientEditAllowed();

  var card = DOC.querySelector('.zq-client-card');
  if (card){
    if (locked) card.classList.add('is-locked');
    else card.classList.remove('is-locked');
  }

  if (badge) badge.style.display = locked ? 'inline-flex' : 'none';

  if (selWrap) selWrap.style.display = canSel ? '' : 'none';
  if (addWrap) addWrap.style.display = canAdd ? '' : 'none';
  if (editWrap) editWrap.style.display = canEdit ? '' : 'none';


  // disable inputs
  var ids = ['zq-client-fullname','zq-client-company','zq-client-nip','zq-client-phone','zq-client-email','zq-client-address'];
  for (var i=0;i<ids.length;i++){
    var el = $(ids[i]);
    if (!el) continue;
    el.disabled = !!locked || (canSel && !canAdd && !canEdit);
  }

  if (sel) sel.disabled = (!!locked) || (!canSel);
  if (btnAdd) btnAdd.disabled = (!!locked) || (!canAdd);
  if (btnEdit) btnEdit.disabled = (!!locked) || (!canEdit) || !(sel && sel.value);

if (note){
    if (locked){
      note.textContent = 'To konto ma przypisanego stałego klienta - zmiana danych klienta jest zablokowana.';
    } else if (!canSel && !canAdd && !canEdit){
      note.textContent = 'Brak uprawnień do bazy klientów (wybór/dodawanie/edycja). Uzupełnij dane ręcznie.';
    } else if (!canSel && (canAdd || canEdit)){
      note.textContent = 'Brak uprawnień do wyboru klienta z bazy. Możesz uzupełnić dane ręcznie i zapisać jako nowego klienta.';
    } else if (canSel && !canAdd && !canEdit){
      note.textContent = 'Możesz wybierać klientów z bazy. Brak uprawnień do dodawania i edycji danych klienta.';
    } else if (canSel && !canAdd && canEdit){
      note.textContent = 'Możesz wybierać klientów z bazy i edytować dane wybranego klienta. Brak uprawnień do dodawania nowych.';
    } else if (canSel && canAdd && !canEdit){
      note.textContent = 'Możesz wybierać klientów z bazy i dodawać nowych. Brak uprawnień do edycji istniejących.';
    } else {
      note.textContent = 'Możesz wybrać klienta z bazy, dodać nowego lub edytować dane wybranego klienta.';
    }
  }
}



  function setClientStatus(msg, isErr){
    var el = $('zq-client-status');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = isErr ? '#b42318' : '';
  }

  function validateClientForSave(c){
    c = c || {};
    var full = (c.full_name || '').trim();
    var comp = (c.company || '').trim();
    if (!full && !comp){
      return { ok:false, message:'Podaj imię i nazwisko lub nazwę firmy.' };
    }

    var email = (c.email || '').trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
      return { ok:false, message:'Niepoprawny adres email.' };
    }

    // NIP: jeśli podany, preferuj cyfry (typowy zapis: 123-456-78-90)
    var nip = (c.nip || '').trim();
    if (nip){
      var nipDigits = nip.replace(/[^0-9]/g, '');
      c.nip = nipDigits || nip;
    } else {
      c.nip = '';
    }

    c.full_name = full;
    c.company = comp;
    c.email = email;
    c.phone = (c.phone || '').trim();
    c.address = (c.address || '').trim();

    return { ok:true, client:c };
  }

  function openClientModal(mode, client){
    if (state.clientModalBusy) return;
  
    var modal = $('zq-client-modal');
    if (!modal) return;
  
    $('zq-client-modal-err').textContent = '';
    $('zq-client-modal-err').style.display = 'none';
  
    $('zq-client-modal-mode').value = mode || '';
    $('zq-client-modal-id').value = (client && client.id) ? String(client.id) : '';
  
    var title = $('zq-client-modal-title');
    var sub = $('zq-client-modal-sub');
    var btn = $('zq-client-modal-save');
  
    if (mode === 'edit'){
      if (title) title.textContent = 'Edytuj dane klienta';
      if (sub) sub.textContent = 'Zmiany zostaną zapisane w bazie danych klientów.';
      if (btn) btn.textContent = 'Zapisz zmiany';
    } else {
      if (title) title.textContent = 'Dodaj nowego klienta';
      if (sub) sub.textContent = 'Klient zostanie zapisany w bazie i przypisany do Twojego konta.';
      if (btn) btn.textContent = 'Dodaj klienta';
    }
  
    // fill fields
    var src = client || collectClient();
    if ($('zq-cm-fullname')) $('zq-cm-fullname').value = src.full_name || '';
    if ($('zq-cm-company')) $('zq-cm-company').value = src.company || '';
    if ($('zq-cm-nip')) $('zq-cm-nip').value = src.nip || '';
    if ($('zq-cm-phone')) $('zq-cm-phone').value = src.phone || '';
    if ($('zq-cm-email')) $('zq-cm-email').value = src.email || '';
    if ($('zq-cm-address')) $('zq-cm-address').value = src.address || '';
  
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  
    setTimeout(function(){
      var f = $('zq-cm-fullname') || $('zq-cm-company');
      if (f) try{ f.focus(); }catch(e){}
    }, 20);
  }
  
  function closeClientModal(){
    var modal = $('zq-client-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }
  
  
  /* -------------------- Niestandardowa pozycja (Custom line) -------------------- */
  function isCustomLine(line){
    try{
      if (!line) return false;
      if (line.isCustom) return true;
      var sh = (line.item && line.item.sheet) ? String(line.item.sheet) : '';
      return sh === '__CUSTOM__';
    }catch(e){ return false; }
  }

  function makeCustomItem(name, unitNet){
    name = String(name || '').trim();
    unitNet = (typeof unitNet === 'number' && isFinite(unitNet)) ? unitNet : 0;
    return {
      sheet: '__CUSTOM__',
      kategoria: 'Niestandardowe',
      podkategoria: '',
      produkt: name,
      wymiar: '',
      cenaNetto: unitNet,
      ceny: {},
      ralMap: {},
      tiers: {}
    };
  }

  function isTransportLine(line){
    try{
      if (!line) return false;
      if (line.isTransport) return true;
      if (line.customKind && String(line.customKind) === 'transport') return true;
      return false;
    }catch(e){ return false; }
  }

  function makeTransportItem(unitNet){
    unitNet = (typeof unitNet === 'number' && isFinite(unitNet)) ? unitNet : 0;
    return {
      sheet: '__CUSTOM__',
      kategoria: 'Usługi',
      podkategoria: '',
      produkt: 'Usługi transportowe',
      wymiar: '',
      cenaNetto: unitNet,
      ceny: {},
      ralMap: {},
      tiers: {}
    };
  }


  function openCustomLineModal(mode, line){
    if (state.customLineModalBusy) return;

    mode = (mode === 'edit') ? 'edit' : 'add';
    var modal = $('zq-custom-line-modal');
    if (!modal) return;

    if ($('zq-custom-line-err')){
      $('zq-custom-line-err').textContent = '';
      $('zq-custom-line-err').style.display = 'none';
    }

    if ($('zq-custom-line-mode')) $('zq-custom-line-mode').value = mode;
    if ($('zq-custom-line-id')) $('zq-custom-line-id').value = (mode === 'edit' && line && line.id) ? String(line.id) : '';

    var title = $('zq-custom-line-title');
    var sub = $('zq-custom-line-sub');
    var btn = $('zq-custom-save');

    if (mode === 'edit'){
      if (title) title.textContent = 'Edytuj niestandardową pozycję';
      if (sub) sub.textContent = 'Zmiany zostaną zastosowane tylko w tej ofercie.';
      if (btn) btn.textContent = 'Zapisz';
    } else {
      if (title) title.textContent = 'Dodaj niestandardową pozycję';
      if (sub) sub.textContent = 'Pozycja zostanie dodana do listy poniżej jako nowy produkt.';
      if (btn) btn.textContent = 'Dodaj';
    }

    var maxD = getMaxDiscount();
    if ($('zq-custom-disc')) $('zq-custom-disc').max = String(maxD);

    var src = line || null;
    var name = (src && src.item && src.item.produkt) ? String(src.item.produkt) : '';
    var qty = (src && src.qty != null) ? src.qty : 1;
    var disc = (src && src.disc != null) ? src.disc : 0;
    var unitNet = (src && src.item && typeof src.item.cenaNetto === 'number' && isFinite(src.item.cenaNetto)) ? src.item.cenaNetto : 0;
    var comment = (src && src.lineComment != null) ? String(src.lineComment) : '';

    if ($('zq-custom-name')) $('zq-custom-name').value = name;
    if ($('zq-custom-qty')) $('zq-custom-qty').value = String(clampNum(parseNumber(qty) || 1, 1, 999999));
    if ($('zq-custom-unit-net')) $('zq-custom-unit-net').value = String(clampNum(parseNumber(unitNet) || 0, 0, 999999999));
    var d2 = clampNum(parseNumber(disc) || 0, 0, 100);
    if (d2 > maxD) d2 = maxD;
    if ($('zq-custom-disc')) $('zq-custom-disc').value = String(d2);
    if ($('zq-custom-comment')) $('zq-custom-comment').value = comment;

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    setTimeout(function(){
      var f = $('zq-custom-name') || $('zq-custom-qty');
      if (f) try{ f.focus(); }catch(e){}
    }, 0);
  }

  function closeCustomLineModal(){
    var modal = $('zq-custom-line-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }



  /* -------------------- Usługi transportowe (Transport line) -------------------- */
  function openTransportLineModal(mode, line){
    if (state.transportLineModalBusy) return;

    mode = (mode === 'edit') ? 'edit' : 'add';
    var modal = $('zq-transport-line-modal');
    if (!modal) return;

    if ($('zq-transport-line-err')){
      $('zq-transport-line-err').textContent = '';
      $('zq-transport-line-err').style.display = 'none';
    }

    if ($('zq-transport-line-mode')) $('zq-transport-line-mode').value = mode;
    if ($('zq-transport-line-id')) $('zq-transport-line-id').value = (mode === 'edit' && line && line.id) ? String(line.id) : '';

    var title = $('zq-transport-line-title');
    var sub = $('zq-transport-line-sub');
    var btn = $('zq-transport-save');

    if (mode === 'edit'){
      if (title) title.textContent = 'Edytuj usługi transportowe';
      if (sub) sub.textContent = 'Zmiany zostaną zastosowane tylko w tej ofercie.';
      if (btn) btn.textContent = 'Zapisz';
    } else {
      if (title) title.textContent = 'Dodaj usługi transportowe';
      if (sub) sub.textContent = 'Pozycja zostanie dodana do listy poniżej jako nowy produkt.';
      if (btn) btn.textContent = 'Dodaj';
    }

    var maxD = getMaxDiscount();
    if ($('zq-transport-disc')) $('zq-transport-disc').max = String(maxD);

    var km = (line && line.qty != null) ? line.qty : 1;
    km = clampNum(parseNumber(km) || 1, 1, 999999);
    km = Math.round(km);

    // Domyślne ustawienia: profil per konto (localStorage)
    var baseProf = state.transportProfile || loadTransportProfile() || {};
    baseProf = normalizeTransportProfile(baseProf);

    // Edycja istniejącej pozycji ma pierwszeństwo przed profilem
    var t = (line && line.transport && typeof line.transport === 'object') ? normalizeTransportProfile(line.transport) : baseProf;

    // W trybie "oferta specjalna" użytkownik mógł ręcznie edytować stawkę w tabeli
    // - w modalu pokazujemy to jako stawkę stałą.
    if (line && state.specialOffer && line.manualUnitNet != null && isFinite(line.manualUnitNet)){
      t.mode = 'flat';
      t.flat_rate = clampNum(parseNumber(line.manualUnitNet) || 0, 0, 999999999);
    }

    // discount + komentarz
    var disc = (line && line.disc != null) ? line.disc : 0;
    disc = clampNum(parseNumber(disc) || 0, 0, 100);
    if (disc > maxD) disc = maxD;
    var comment = (line && line.lineComment != null) ? String(line.lineComment) : '';

    if ($('zq-transport-km')) $('zq-transport-km').value = String(km);

    // radio
    if ($('zq-transport-mode-flat')) $('zq-transport-mode-flat').checked = (t.mode !== 'tier');
    if ($('zq-transport-mode-tier')) $('zq-transport-mode-tier').checked = (t.mode === 'tier');

    // flat
    if ($('zq-transport-unit-net')) $('zq-transport-unit-net').value = String(clampNum(parseNumber(t.flat_rate) || 0, 0, 999999999));
    if ($('zq-transport-min-net')) $('zq-transport-min-net').value = String(clampNum(parseNumber(t.min_net) || 0, 0, 999999999));

    // tier
    if ($('zq-transport-km1')) $('zq-transport-km1').value = String(clampNum(parseNumber(t.km1) || 30, 1, 999999));
    if ($('zq-transport-rate1')) $('zq-transport-rate1').value = String(clampNum(parseNumber(t.rate1) || 0, 0, 999999999));
    if ($('zq-transport-km2')) $('zq-transport-km2').value = String(clampNum(parseNumber(t.km2) || 100, 1, 999999));
    if ($('zq-transport-rate2')) $('zq-transport-rate2').value = String(clampNum(parseNumber(t.rate2) || 0, 0, 999999999));
    if ($('zq-transport-rate3')) $('zq-transport-rate3').value = String(clampNum(parseNumber(t.rate3) || 0, 0, 999999999));
    if ($('zq-transport-min-net2')) $('zq-transport-min-net2').value = String(clampNum(parseNumber(t.min_net) || 0, 0, 999999999));

    // extras
    var ex = (t.extras && typeof t.extras === 'object') ? t.extras : {hds:0,unload:0,sat:0};
    if ($('zq-transport-x-hds-on')) $('zq-transport-x-hds-on').checked = !!(ex.hds && ex.hds > 0);
    if ($('zq-transport-x-hds')) $('zq-transport-x-hds').value = String(clampNum(parseNumber(ex.hds) || 0, 0, 999999999));
    if ($('zq-transport-x-unload-on')) $('zq-transport-x-unload-on').checked = !!(ex.unload && ex.unload > 0);
    if ($('zq-transport-x-unload')) $('zq-transport-x-unload').value = String(clampNum(parseNumber(ex.unload) || 0, 0, 999999999));
    if ($('zq-transport-x-sat-on')) $('zq-transport-x-sat-on').checked = !!(ex.sat && ex.sat > 0);
    if ($('zq-transport-x-sat')) $('zq-transport-x-sat').value = String(clampNum(parseNumber(ex.sat) || 0, 0, 999999999));

    if ($('zq-transport-no-global')) $('zq-transport-no-global').checked = !!t.no_global_disc;
    if ($('zq-transport-disc')) $('zq-transport-disc').value = String(disc);
    if ($('zq-transport-comment')) $('zq-transport-comment').value = comment;

    setExtraEnabled('zq-transport-x-hds-on', 'zq-transport-x-hds');
    setExtraEnabled('zq-transport-x-unload-on', 'zq-transport-x-unload');
    setExtraEnabled('zq-transport-x-sat-on', 'zq-transport-x-sat');

    setTransportModeUI(t.mode);
    updateTransportPreview();

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    setTimeout(function(){
      var f = $('zq-transport-km') || $('zq-transport-unit-net');
      if (f) try{ f.focus(); }catch(e){}
    }, 0);
  }

  function closeTransportLineModal(){
    var modal = $('zq-transport-line-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function normalizeTransportProfile(p){
    p = (p && typeof p === 'object') ? p : {};
    var mode = (p.mode === 'tier' || p.mode === 'tiers') ? 'tier' : 'flat';

    var km1 = Math.round(clampNum(parseNumber(p.km1) || 30, 1, 999999));
    var km2 = Math.round(clampNum(parseNumber(p.km2) || 100, km1 + 1, 999999));

    var flatRate = clampNum(parseNumber(p.flat_rate) || 0, 0, 999999999);

    var r1 = parseNumber(p.rate1); if (r1 == null) r1 = flatRate;
    var r2 = parseNumber(p.rate2); if (r2 == null) r2 = r1;
    var r3 = parseNumber(p.rate3); if (r3 == null) r3 = r2;
    r1 = clampNum(r1, 0, 999999999);
    r2 = clampNum(r2, 0, 999999999);
    r3 = clampNum(r3, 0, 999999999);

    var minNet = clampNum(parseNumber(p.min_net) || 0, 0, 999999999);

    var extras = (p.extras && typeof p.extras === 'object') ? p.extras : {};
    var exHds = clampNum(parseNumber(extras.hds) || 0, 0, 999999999);
    var exUnload = clampNum(parseNumber(extras.unload) || 0, 0, 999999999);
    var exSat = clampNum(parseNumber(extras.sat) || 0, 0, 999999999);

    var noGlobal = (p.no_global_disc != null) ? !!p.no_global_disc : true;

    return {
      mode: mode,
      flat_rate: flatRate,
      km1: km1,
      km2: km2,
      rate1: r1,
      rate2: r2,
      rate3: r3,
      min_net: minNet,
      extras: { hds: exHds, unload: exUnload, sat: exSat },
      no_global_disc: noGlobal
    };
  }

  function transportRateFor(km, t){
    km = Math.round(clampNum(parseNumber(km) || 1, 1, 999999));
    t = normalizeTransportProfile(t);
    if (t.mode === 'tier'){
      if (km <= t.km1) return t.rate1;
      if (km <= t.km2) return t.rate2;
      return t.rate3;
    }
    return t.flat_rate;
  }

  function fmtNum(n){
    var v = (typeof n === 'number' && isFinite(n)) ? n : 0;
    try{ return v.toLocaleString('pl-PL', { minimumFractionDigits:2, maximumFractionDigits:2 }); }
    catch(e){ return (Math.round(v*100)/100).toFixed(2); }
  }

  function computeTransportFromMeta(km, discPct, t){
    km = Math.round(clampNum(parseNumber(km) || 1, 1, 999999));
    discPct = clampNum(parseNumber(discPct) || 0, 0, 100);
    t = normalizeTransportProfile(t);

    var rateUsed = transportRateFor(km, t);
    var baseRaw = km * rateUsed;
    var baseNet = baseRaw;
    var minApplied = false;
    if (t.min_net > 0 && baseNet < t.min_net){
      baseNet = t.min_net;
      minApplied = true;
    }

    var ex = t.extras || {hds:0,unload:0,sat:0};
    var extrasTotal = (ex.hds || 0) + (ex.unload || 0) + (ex.sat || 0);
    var netBefore = baseNet + extrasTotal;
    var netAfter = netBefore * (1 - (discPct/100));

    // opis: zwięzły, ale informacyjny
    var parts = [];
    parts.push('Transport: ' + km + ' km × ' + fmtNum(rateUsed) + ' zł/km = ' + toMoney(baseRaw));
    if (minApplied){
      parts.push('min. ' + toMoney(t.min_net) + ' -> ' + toMoney(baseNet));
    }
    var exParts = [];
    if (ex.hds && ex.hds > 0) exParts.push('HDS ' + toMoney(ex.hds));
    if (ex.unload && ex.unload > 0) exParts.push('Rozładunek ' + toMoney(ex.unload));
    if (ex.sat && ex.sat > 0) exParts.push('Sobota ' + toMoney(ex.sat));
    if (exParts.length){
      parts.push('dopłaty: ' + exParts.join(', '));
    }
    if (discPct > 0){
      parts.push('rabat: ' + fmtNum(discPct) + '%');
    }

    return {
      km: km,
      rate_used: rateUsed,
      base_raw: baseRaw,
      base_net: baseNet,
      min_applied: minApplied,
      extras_total: extrasTotal,
      net_before: netBefore,
      net_after: netAfter,
      summary: parts.join(' | ')
    };
  }

  function getTransportModeUI(){
    var el = $('zq-transport-mode-tier');
    if (el && el.checked) return 'tier';
    return 'flat';
  }

  function setTransportModeUI(mode){
    mode = (mode === 'tier') ? 'tier' : 'flat';
    var fr = $('zq-transport-flat-row');
    var tr = $('zq-transport-tier-row');
    if (fr) fr.style.display = (mode === 'flat') ? '' : 'none';
    if (tr) tr.style.display = (mode === 'tier') ? '' : 'none';

    // sync min between inputs (żeby nie gubić wartości przy przełączaniu)
    var m1 = $('zq-transport-min-net');
    var m2 = $('zq-transport-min-net2');
    if (m1 && m2){
      var v1 = parseNumber(m1.value);
      var v2 = parseNumber(m2.value);
      if (mode === 'tier'){
        if (v2 == null && v1 != null) m2.value = String(v1);
      } else {
        if (v1 == null && v2 != null) m1.value = String(v2);
      }
    }

    updateTransportPreview();
  }

  function setExtraEnabled(chkId, inputId){
    var c = $(chkId);
    var i = $(inputId);
    if (!c || !i) return;
    i.disabled = !c.checked;
    if (!c.checked) i.value = '0';
  }

  function updateTransportPreview(){
    var box = $('zq-transport-preview');
    if (!box) return;
    try{
      var data = collectTransportLineModal(true);
      if (!data) { box.textContent = ''; return; }
      var preview = computeTransportFromMeta(data.km, data.disc, data.transport);
      box.textContent = preview.summary + ' | Razem netto (po rabacie): ' + toMoney(preview.net_after) + ' | brutto: ' + toMoney(preview.net_after * (1 + getVatRate()));
    }catch(e){
      box.textContent = '';
    }
  }

  function setTransportLineModalErr(msg){
    var el = $('zq-transport-line-err');
    if (!el) return;
    el.textContent = msg || '';
    el.style.display = msg ? '' : 'none';
  }

  function collectTransportLineModal(silent){
    silent = !!silent;

    var kmRaw = ($('zq-transport-km') && $('zq-transport-km').value != null) ? $('zq-transport-km').value : 1;
    var km = clampNum(parseNumber(kmRaw) || 1, 1, 999999);
    km = Math.round(km);

    var mode = getTransportModeUI();

    // flat
    var unitRaw = ($('zq-transport-unit-net') && $('zq-transport-unit-net').value != null) ? $('zq-transport-unit-net').value : 0;
    var flatRate = parseNumber(unitRaw);
    if (flatRate == null || flatRate < 0) flatRate = 0;

    // min
    var minRaw = (mode === 'tier')
      ? (($('zq-transport-min-net2') && $('zq-transport-min-net2').value != null) ? $('zq-transport-min-net2').value : 0)
      : (($('zq-transport-min-net') && $('zq-transport-min-net').value != null) ? $('zq-transport-min-net').value : 0);
    var minNet = parseNumber(minRaw);
    if (minNet == null || minNet < 0) minNet = 0;

    // tier
    var km1 = Math.round(clampNum(parseNumber(($('zq-transport-km1') && $('zq-transport-km1').value != null) ? $('zq-transport-km1').value : 30) || 30, 1, 999999));
    var km2 = Math.round(clampNum(parseNumber(($('zq-transport-km2') && $('zq-transport-km2').value != null) ? $('zq-transport-km2').value : 100) || 100, km1 + 1, 999999));
    var rate1 = parseNumber(($('zq-transport-rate1') && $('zq-transport-rate1').value != null) ? $('zq-transport-rate1').value : null);
    var rate2 = parseNumber(($('zq-transport-rate2') && $('zq-transport-rate2').value != null) ? $('zq-transport-rate2').value : null);
    var rate3 = parseNumber(($('zq-transport-rate3') && $('zq-transport-rate3').value != null) ? $('zq-transport-rate3').value : null);

    if (rate1 == null || rate1 < 0) rate1 = flatRate;
    if (rate2 == null || rate2 < 0) rate2 = rate1;
    if (rate3 == null || rate3 < 0) rate3 = rate2;

    // extras
    var exHds = 0;
    var exUnload = 0;
    var exSat = 0;
    if ($('zq-transport-x-hds-on') && $('zq-transport-x-hds-on').checked){
      exHds = parseNumber(($('zq-transport-x-hds') && $('zq-transport-x-hds').value != null) ? $('zq-transport-x-hds').value : 0) || 0;
      if (!isFinite(exHds) || exHds < 0) exHds = 0;
    }
    if ($('zq-transport-x-unload-on') && $('zq-transport-x-unload-on').checked){
      exUnload = parseNumber(($('zq-transport-x-unload') && $('zq-transport-x-unload').value != null) ? $('zq-transport-x-unload').value : 0) || 0;
      if (!isFinite(exUnload) || exUnload < 0) exUnload = 0;
    }
    if ($('zq-transport-x-sat-on') && $('zq-transport-x-sat-on').checked){
      exSat = parseNumber(($('zq-transport-x-sat') && $('zq-transport-x-sat').value != null) ? $('zq-transport-x-sat').value : 0) || 0;
      if (!isFinite(exSat) || exSat < 0) exSat = 0;
    }

    // discount
    var discRaw = ($('zq-transport-disc') && $('zq-transport-disc').value != null) ? $('zq-transport-disc').value : 0;
    var disc = clampNum(parseNumber(discRaw) || 0, 0, 100);
    var maxD = getMaxDiscount();
    if (disc > maxD) disc = maxD;

    var noGlobal = ($('zq-transport-no-global') && $('zq-transport-no-global').checked) ? true : false;

    var comment = ($('zq-transport-comment') && $('zq-transport-comment').value != null) ? String($('zq-transport-comment').value).trim() : '';
    if (comment && comment.length > 500) comment = comment.slice(0, 500);

    // walidacja minimalna (bez wyświetlania błędów w podglądzie)
    if (!isFinite(km) || km < 1) return silent ? null : { km: 1, disc: disc, transport: normalizeTransportProfile({ mode: mode, flat_rate: flatRate, km1: km1, km2: km2, rate1: rate1, rate2: rate2, rate3: rate3, min_net: minNet, extras: {hds:exHds, unload:exUnload, sat:exSat}, no_global_disc: noGlobal }), comment: comment };

    return {
      km: km,
      disc: disc,
      transport: normalizeTransportProfile({
        mode: mode,
        flat_rate: flatRate,
        km1: km1,
        km2: km2,
        rate1: rate1,
        rate2: rate2,
        rate3: rate3,
        min_net: minNet,
        extras: { hds: exHds, unload: exUnload, sat: exSat },
        no_global_disc: noGlobal
      }),
      comment: comment
    };
  }

  function saveTransportLineModal(){
    if (state.transportLineModalBusy) return;

    setTransportLineModalErr('');

    var mode = ($('zq-transport-line-mode') && $('zq-transport-line-mode').value) ? $('zq-transport-line-mode').value : '';
    mode = (mode === 'edit') ? 'edit' : 'add';

    var id = ($('zq-transport-line-id') && $('zq-transport-line-id').value) ? $('zq-transport-line-id').value : '';
    var data = collectTransportLineModal(false);
    if (!data){
      setTransportLineModalErr('Niepoprawne dane transportu.');
      return;
    }

    if (!isFinite(data.km) || data.km < 1){
      setTransportLineModalErr('Liczba KM musi być liczbą >= 1.');
      return;
    }

    // sanity: progi
    if (data.transport && data.transport.mode === 'tier'){
      if (!isFinite(data.transport.km1) || data.transport.km1 < 1){
        setTransportLineModalErr('Próg KM1 musi być liczbą >= 1.');
        return;
      }
      if (!isFinite(data.transport.km2) || data.transport.km2 <= data.transport.km1){
        setTransportLineModalErr('Próg KM2 musi być większy od KM1.');
        return;
      }
    }

    if (mode === 'edit'){
      if (!id){
        setTransportLineModalErr('Błąd: brak ID pozycji do edycji.');
        return;
      }

      var updated = false;
      for (var i=0;i<state.offerLines.length;i++){
        var l = state.offerLines[i];
        if (String(l.id) !== String(id)) continue;

        l.isCustom = true;
        l.isTransport = true;
        l.customKind = 'transport';

        // transport meta
        l.transport = normalizeTransportProfile(data.transport);

        // item (dla kompatybilności z istniejącymi renderami)
        var baseUnit = (l.transport.mode === 'tier') ? l.transport.rate1 : l.transport.flat_rate;
        if (!l.item) l.item = makeTransportItem(baseUnit);
        l.item.sheet = '__CUSTOM__';
        l.item.kategoria = 'Usługi';
        l.item.podkategoria = '';
        l.item.produkt = 'Usługi transportowe';
        l.item.wymiar = '';
        l.item.cenaNetto = baseUnit;

        l.qty = data.km;
        l.ral = '';
        l.priceMode = 'unit';

        l.disc = data.disc;
        l.lineComment = data.comment || '';

        // w ofercie specjalnej nadal wspieramy ręczną stawkę (z tabeli)
        if (state.specialOffer && l.manualUnitNet != null && isFinite(l.manualUnitNet)){
          l.manualUnitNet = (l.transport.mode === 'tier') ? l.transport.rate1 : l.transport.flat_rate;
        }

        updated = true;
        break;
      }

      if (!updated){
        setTransportLineModalErr('Nie znaleziono pozycji do edycji (ID: ' + String(id) + ').');
        return;
      }
    } else {
      state.offerLines.push({
        id: lineId(),
        item: makeTransportItem((data.transport && data.transport.mode === 'tier') ? data.transport.rate1 : data.transport.flat_rate),
        ral: '',
        priceMode: 'unit',
        qty: data.km,
        disc: data.disc,
        manualUnitNet: null,
        isCustom: true,
        isTransport: true,
        customKind: 'transport',
        transport: normalizeTransportProfile(data.transport),
        lineComment: data.comment || ''
      });
    }

    // zapamiętaj stawki/dopłaty jako domyślne (per konto) - bez KM i komentarza
    try{
      var prof = normalizeTransportProfile(data.transport);
      state.transportProfile = prof;
      saveTransportProfile(prof);
    }catch(e){}

    persistOffer();
    renderLines();
    closeTransportLineModal();
  }
  function setCustomLineModalErr(msg){
    var el = $('zq-custom-line-err');
    if (!el) return;
    el.textContent = msg || '';
    el.style.display = msg ? '' : 'none';
  }

  function collectCustomLineModal(){
    var name = ($('zq-custom-name') && $('zq-custom-name').value) ? $('zq-custom-name').value.trim() : '';
    var qtyRaw = ($('zq-custom-qty') && $('zq-custom-qty').value != null) ? $('zq-custom-qty').value : 1;
    var qty = clampNum(parseNumber(qtyRaw) || 1, 1, 999999);
    qty = Math.round(qty);

    var unitRaw = ($('zq-custom-unit-net') && $('zq-custom-unit-net').value != null) ? $('zq-custom-unit-net').value : 0;
    var unitNet = parseNumber(unitRaw);
    if (unitNet == null || unitNet < 0) unitNet = 0;

    var discRaw = ($('zq-custom-disc') && $('zq-custom-disc').value != null) ? $('zq-custom-disc').value : 0;
    var disc = clampNum(parseNumber(discRaw) || 0, 0, 100);
    var maxD = getMaxDiscount();
    if (disc > maxD) disc = maxD;

    var comment = ($('zq-custom-comment') && $('zq-custom-comment').value != null) ? $('zq-custom-comment').value.trim() : '';
    if (comment && comment.length > 500) comment = comment.slice(0, 500);

    return { name:name, qty:qty, unitNet:unitNet, disc:disc, comment:comment };
  }

  function saveCustomLineModal(){
    if (state.customLineModalBusy) return;

    setCustomLineModalErr('');

    var mode = ($('zq-custom-line-mode') && $('zq-custom-line-mode').value) ? $('zq-custom-line-mode').value : '';
    mode = (mode === 'edit') ? 'edit' : 'add';

    var id = ($('zq-custom-line-id') && $('zq-custom-line-id').value) ? $('zq-custom-line-id').value : '';
    var data = collectCustomLineModal();

    if (!data.name){
      setCustomLineModalErr('Nazwa pozycji jest wymagana.');
      return;
    }
    if (!isFinite(data.unitNet) || data.unitNet < 0){
      setCustomLineModalErr('Cena jednostki netto musi być liczbą >= 0.');
      return;
    }

    if (mode === 'edit'){
      if (!id){
        setCustomLineModalErr('Błąd: brak ID pozycji do edycji.');
        return;
      }

      var updated = false;
      for (var i=0;i<state.offerLines.length;i++){
        var l = state.offerLines[i];
        if (String(l.id) !== String(id)) continue;

        l.isCustom = true;
        if (!l.item) l.item = makeCustomItem(data.name, data.unitNet);

        l.item.sheet = '__CUSTOM__';
        l.item.kategoria = l.item.kategoria || 'Niestandardowe';
        l.item.podkategoria = l.item.podkategoria || '';
        l.item.wymiar = l.item.wymiar || '';
        l.item.produkt = data.name;
        l.item.cenaNetto = data.unitNet;

        l.qty = data.qty;
        l.disc = data.disc;
        l.ral = '';
        l.priceMode = 'unit';
        l.lineComment = data.comment || '';
        updated = true;
        break;
      }

      if (!updated){
        setCustomLineModalErr('Nie znaleziono pozycji do edycji (ID: ' + String(id) + ').');
        return;
      }
    } else {
      state.offerLines.push({
        id: lineId(),
        item: makeCustomItem(data.name, data.unitNet),
        ral: '',
        priceMode: 'unit',
        qty: data.qty,
        disc: data.disc,
        manualUnitNet: null,
        isCustom: true,
        lineComment: data.comment || ''
      });
    }

    persistOffer();
    renderLines();
    closeCustomLineModal();
  }

  function setClientModalErr(msg){
    var el = $('zq-client-modal-err');
    if (!el) return;
    el.textContent = msg || '';
    el.style.display = msg ? '' : 'none';
  }
  
  function collectClientModal(){
    return {
      full_name: ($('zq-cm-fullname') && $('zq-cm-fullname').value) ? $('zq-cm-fullname').value.trim() : '',
      company: ($('zq-cm-company') && $('zq-cm-company').value) ? $('zq-cm-company').value.trim() : '',
      nip: ($('zq-cm-nip') && $('zq-cm-nip').value) ? $('zq-cm-nip').value.trim() : '',
      phone: ($('zq-cm-phone') && $('zq-cm-phone').value) ? $('zq-cm-phone').value.trim() : '',
      email: ($('zq-cm-email') && $('zq-cm-email').value) ? $('zq-cm-email').value.trim() : '',
      address: ($('zq-cm-address') && $('zq-cm-address').value) ? $('zq-cm-address').value.trim() : ''
    };
  }
  
  function saveClientModal(){
    if (state.clientModalBusy) return;
    if (!state.authToken){
      setClientModalErr('Brak sesji - zaloguj się ponownie.');
      return;
    }
    if (isClientLocked()){
      setClientModalErr('To konto ma stałego klienta - operacja niedozwolona.');
      return;
    }
  
    var mode = $('zq-client-modal-mode') ? $('zq-client-modal-mode').value : '';
    var id = $('zq-client-modal-id') ? $('zq-client-modal-id').value : '';
    id = id ? String(id) : '';
  
    if (mode === 'edit'){
      if (!isClientEditAllowed()){
        setClientModalErr('Brak uprawnień do edycji danych klienta.');
        return;
      }
      if (!id){
        setClientModalErr('Nie wybrano klienta do edycji.');
        return;
      }
    } else {
      if (!isClientAddAllowed()){
        setClientModalErr('Brak uprawnień do dodawania klientów.');
        return;
      }
    }
  
    var c = collectClientModal();
    var v = validateClientForSave(c);
    if (!v.ok){
      setClientModalErr(v.message || 'Niepoprawne dane klienta.');
      return;
    }
    c = v.client;
  
    state.clientModalBusy = true;
    var btn = $('zq-client-modal-save');
    if (btn) btn.disabled = true;
    setClientModalErr('');
  
    var onDone = function(){
      state.clientModalBusy = false;
      if (btn) btn.disabled = false;
    };
  
    var req;
    if (mode === 'edit'){
      req = apiFetch('/clients/' + encodeURIComponent(id), { method: 'PUT', json: { client: c } });
    } else {
      req = apiFetch('/clients', { json: c });
    }
  
    req.then(function(r){
      return r.json().catch(function(){ return null; }).then(function(j){
        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('Błąd zapisu klienta (' + r.status + ').');
          setClientModalErr(msg);
          onDone();
          return;
        }
  
        var saved = j.client || null;
        var savedId = (saved && saved.id) ? String(saved.id) : id;
  
        // odśwież listę klientów i ustaw wybranego
        refreshClients().then(function(){
          if (savedId && $('zq-client-select')){
            $('zq-client-select').value = savedId;
            state.selectedClientId = savedId;
          }
          if (saved) applyClient(saved);
          else {
            // fallback: spróbuj znaleźć w state.clients
            if (savedId){
              for (var i=0;i<state.clients.length;i++){
                if (state.clients[i] && String(state.clients[i].id) === String(savedId)){
                  applyClient(state.clients[i]);
                  break;
                }
              }
            }
          }
          applyClientAccessUI();
          scheduleDraftSave();
        }).catch(function(){});
  
        setClientStatus(mode === 'edit' ? 'Zapisano zmiany klienta.' : 'Dodano klienta.', false);
        closeClientModal();
        onDone();
      });
    }).catch(function(){
      setClientModalErr('Błąd sieci podczas zapisu klienta.');
      onDone();
    });
  }
  
  function saveNewClient(){
    // zgodnie z UX: przycisk otwiera modal dodawania
    if (isClientLocked()){
      setClientStatus('To konto ma stałego klienta - nie można dodać nowego.', true);
      return;
    }
    if (!isClientAddAllowed()){
      setClientStatus('Brak uprawnień do dodawania klientów.', true);
      return;
    }
    openClientModal('add', collectClient());
  }


  
  function editSelectedClient(){
    if (isClientLocked()){
      setClientStatus('To konto ma stałego klienta - edycja zablokowana.', true);
      return;
    }
    if (!isClientEditAllowed()){
      setClientStatus('Brak uprawnień do edycji danych klienta.', true);
      return;
    }
    var c = getSelectedClient();
    if (!c){
      setClientStatus('Wybierz klienta z listy, aby edytować.', true);
      return;
    }
    openClientModal('edit', c);
  }


  // Sheets -> build items (kategoria/podkategoria/produkt/wymiar + RAL map)
  function detectCols(headers){
    var H = headers.map(norm);
    var idx = function(names){
      // 1) exact match
      for (var i=0;i<names.length;i++){
        var n = norm(names[i]);
        var pos = H.indexOf(n);
        if (pos >= 0) return pos;
      }
      // 2) contains match (np. "kategoria produktu", "ral 6005 netto")
      for (var i2=0;i2<names.length;i2++){
        var n2 = norm(names[i2]);
        if (!n2) continue;
        for (var j=0;j<H.length;j++){
          if (H[j] && H[j].indexOf(n2) >= 0) return j;
        }
      }
      return -1;
    };

    var col = {
      cat: idx(['kategoria','category']),
      sub: idx(['podkategoria','subkategoria','sub','subcategory']),
      prod: idx(['produkt','product','nazwa']),
      dim: idx(['wymiar','rozmiar','wymiar/rozmiar','size','variant','wariant']),
      // Cena netto (PLN) - docelowe źródło ceny (ignoruj "Paleta"/"Tir")
      priceNet: (function(){
        // exacty
        for (var j=0; j<H.length; j++){
          if (H[j] === 'cena netto [pln]' || H[j] === 'cena netto pln' || H[j] === 'cena netto (pln)') return j;
        }
        // contains, ale bez paleta/tir
        for (var j2=0; j2<H.length; j2++){
          var h = H[j2] || '';
          if (!h) continue;
          if (h.indexOf('cena') >= 0 && h.indexOf('netto') >= 0 && h.indexOf('pln') >= 0 && h.indexOf('paleta') < 0 && h.indexOf('tir') < 0) return j2;
          if (h.indexOf('netto') >= 0 && h.indexOf('pln') >= 0 && h.indexOf('paleta') < 0 && h.indexOf('tir') < 0) return j2;
        }
        // ostateczny fallback
        return idx(['price net','unit net','netto [pln]','netto pln','cena [pln]']);
      })(),
      // Cena netto (PLN) - warianty logistyczne (np. "50x/60 zł")
      priceNetPaleta: idx(['cena netto [pln] paleta','cena netto paleta','netto paleta','paleta']),
      priceNetTir: idx(['cena netto [pln] tir','cena netto tir','netto tir','tir']),
      // Kolumny RAL (w aktualnym arkuszu: SKU/kod per RAL)
      p6005: idx(['6005','ral 6005','kod 6005','sku 6005','indeks 6005']),
      p7016: idx(['7016','ral 7016','kod 7016','sku 7016','indeks 7016']),
      p8017: idx(['8017','ral 8017','kod 8017','sku 8017','indeks 8017']),
      p9005: idx(['9005','ral 9005','kod 9005','sku 9005','indeks 9005']),
      // opcjonalnie nr item
      n6005: idx(['nr 6005','indeks 6005','item 6005','kod 6005']),
      n7016: idx(['nr 7016','indeks 7016','item 7016','kod 7016']),
      n8017: idx(['nr 8017','indeks 8017','item 8017','kod 8017']),
      n9005: idx(['nr 9005','indeks 9005','item 9005','kod 9005']),
      nrPlain: (function(){
        // \"Nr towaru\" bez RAL -> opcja \"Brak\" w selekcie RAL
        // Preferuj exact match \"Nr towaru\"; fallback: zawiera \"nr towaru\" ale NIE zawiera \"ral\".
        for (var j=0; j<H.length; j++){
          if (H[j] === 'nr towaru') return j;
        }
        for (var j2=0; j2<H.length; j2++){
          var h = H[j2] || '';
          if (!h) continue;
          if (h.indexOf('nr towaru') >= 0 && h.indexOf('ral') < 0) return j2;
        }
        return -1;
      })()
    };
    return col;
  }

  function buildItems(sheetName, matrix){
    var headers = Array.isArray(matrix.headers) ? matrix.headers : [];
    var rows = Array.isArray(matrix.rows) ? matrix.rows : [];
    var col = detectCols(headers);

    if (col.cat < 0 || col.prod < 0){
      // Minimalne wymagania
      return { headers: headers, items: [], cats: [], subs: {}, prods: {}, dims: {}, ralOrder: ['Brak','RAL 6005','RAL 7016','RAL 8017','RAL 9005'] };
    }

    var items = [];
    for (var i=0; i<rows.length; i++){
      var r = rows[i];
      if (!Array.isArray(r)) continue;

      var cat = (r[col.cat] != null) ? String(r[col.cat]).trim() : '';
      var sub = (col.sub >= 0 && r[col.sub] != null) ? String(r[col.sub]).trim() : '';
      var prod = (r[col.prod] != null) ? String(r[col.prod]).trim() : '';
      var dim = (col.dim >= 0 && r[col.dim] != null) ? String(r[col.dim]).trim() : '';
      if (!cat || !prod) continue;

      // cena netto (w arkuszu: "Cena netto [PLN]") + SKU per RAL (kolumny 6005/7016/8017/9005)
      var unitNetCol = (col.priceNet >= 0) ? parseNumber(r[col.priceNet]) : null;
      var hasUnitNet = (unitNetCol != null && isFinite(unitNetCol));

      // warianty: paleta/tir (format: "50x/60 zł")
      var pal = (col.priceNetPaleta >= 0) ? parsePackCell(r[col.priceNetPaleta]) : null;
      var tir = (col.priceNetTir >= 0) ? parsePackCell(r[col.priceNetTir]) : null;

      var prices;
      if (hasUnitNet){
        prices = {
          'Brak': unitNetCol,
          'RAL 6005': unitNetCol,
          'RAL 7016': unitNetCol,
          'RAL 8017': unitNetCol,
          'RAL 9005': unitNetCol
        };
      } else {
        // fallback (starsze arkusze): ceny w kolumnach RAL
        prices = {
          'Brak': null,
          'RAL 6005': (col.p6005 >= 0) ? parseNumber(r[col.p6005]) : null,
          'RAL 7016': (col.p7016 >= 0) ? parseNumber(r[col.p7016]) : null,
          'RAL 8017': (col.p8017 >= 0) ? parseNumber(r[col.p8017]) : null,
          'RAL 9005': (col.p9005 >= 0) ? parseNumber(r[col.p9005]) : null
        };
      }

      // walidacja ceny
      var any = null;
      for (var k in prices){
        if (prices[k] != null && isFinite(prices[k])) { any = prices[k]; break; }
      }
      if (any == null){
        // nie ma ceny - skip
        continue;
      }
      for (var k2 in prices){
        if (prices[k2] == null || !isFinite(prices[k2])) prices[k2] = any;
      }
      // "Brak" (Nr towaru) dziedziczy cenę bazową
      if (!Object.prototype.hasOwnProperty.call(prices, 'Brak') || prices['Brak'] == null || !isFinite(prices['Brak'])) prices['Brak'] = any;

      function skuVal(x){
        x = normalizeSku(x);
        if (!x || x === '-' || x === '—' || x === '–') return '';
        return x;
      }

      // SKU per RAL (jeśli mamy kolumny "nr/kod 6005" - użyj ich; inaczej (przy hasUnitNet) bierz z kolumn RAL)
      var ralMap = {
        'Brak': (col.nrPlain >= 0) ? skuVal(r[col.nrPlain]) : '',
        'RAL 6005': (col.n6005 >= 0) ? skuVal(r[col.n6005]) : (hasUnitNet ? ((col.p6005 >= 0) ? skuVal(r[col.p6005]) : '') : ''),
        'RAL 7016': (col.n7016 >= 0) ? skuVal(r[col.n7016]) : (hasUnitNet ? ((col.p7016 >= 0) ? skuVal(r[col.p7016]) : '') : ''),
        'RAL 8017': (col.n8017 >= 0) ? skuVal(r[col.n8017]) : (hasUnitNet ? ((col.p8017 >= 0) ? skuVal(r[col.p8017]) : '') : ''),
        'RAL 9005': (col.n9005 >= 0) ? skuVal(r[col.n9005]) : (hasUnitNet ? ((col.p9005 >= 0) ? skuVal(r[col.p9005]) : '') : '')
      };

      items.push({
        sheet: sheetName,
        kategoria: cat,
        podkategoria: sub,
        produkt: prod,
        wymiar: dim,
        ceny: prices,
        tiers: {
          paleta: (pal && isFinite(pal.qty) && isFinite(pal.price)) ? { qty: pal.qty, unit_net: pal.price } : null,
          tir: (tir && isFinite(tir.qty) && isFinite(tir.price)) ? { qty: tir.qty, unit_net: tir.price } : null
        },
        ralMap: ralMap,
        cenaNetto: prices['RAL 9005'] // default
      });
    }

    // buduj indeksy do cascades
    var cats = [];
    var subs = {};   // cat -> array sub
    var prods = {};  // cat|sub -> array prod
    var dims = {};   // cat|sub|prod -> array dim

    function key2(a,b){ return a + '||' + b; }
    function key3(a,b,c){ return a + '||' + b + '||' + c; }

    for (var i2=0; i2<items.length; i2++){
      var it = items[i2];
      if (cats.indexOf(it.kategoria) < 0) cats.push(it.kategoria);

      var subKey = it.kategoria;
      subs[subKey] = subs[subKey] || [];
      var subVal = it.podkategoria || '';
      if (subs[subKey].indexOf(subVal) < 0) subs[subKey].push(subVal);

      var prodKey = key2(it.kategoria, subVal);
      prods[prodKey] = prods[prodKey] || [];
      if (prods[prodKey].indexOf(it.produkt) < 0) prods[prodKey].push(it.produkt);

      var dimKey = key3(it.kategoria, subVal, it.produkt);
      dims[dimKey] = dims[dimKey] || [];
      var dimVal = it.wymiar || '';
      if (dims[dimKey].indexOf(dimVal) < 0) dims[dimKey].push(dimVal);
    }

    cats.sort();
    for (var sk in subs){ subs[sk].sort(); }
    for (var pk in prods){ prods[pk].sort(); }
    for (var dk in dims){ dims[dk].sort(); }

    return {
      headers: headers,
      items: items,
      cats: cats,
      subs: subs,
      prods: prods,
      dims: dims,
      ralOrder: ['Brak','RAL 6005','RAL 7016','RAL 8017','RAL 9005']
    };
  }


  function buildAllMerged(){
    var tabs = getTabs();
    var items = [];
    for (var ti=0; ti<tabs.length; ti++){
      var sname = tabs[ti];
      var s = state.data[sname];
      if (s && Array.isArray(s.items) && s.items.length){
        // items już zawierają it.sheet = sname
        items = items.concat(s.items);
      }
    }

    // buduj indeksy do cascades (jak w buildItems)
    var cats = [];
    var subs = {};   // cat -> array sub
    var prods = {};  // cat|sub -> array prod
    var dims = {};   // cat|sub|prod -> array dim

    function key2(a,b){ return a + '||' + b; }
    function key3(a,b,c){ return a + '||' + b + '||' + c; }

    for (var i2=0; i2<items.length; i2++){
      var it = items[i2];
      if (!it || !it.kategoria || !it.produkt) continue;
      if (cats.indexOf(it.kategoria) < 0) cats.push(it.kategoria);

      var subKey = it.kategoria;
      subs[subKey] = subs[subKey] || [];
      var subVal = it.podkategoria || '';
      if (subs[subKey].indexOf(subVal) < 0) subs[subKey].push(subVal);

      var prodKey = key2(it.kategoria, subVal);
      prods[prodKey] = prods[prodKey] || [];
      if (prods[prodKey].indexOf(it.produkt) < 0) prods[prodKey].push(it.produkt);

      var dimKey = key3(it.kategoria, subVal, it.produkt);
      dims[dimKey] = dims[dimKey] || [];
      var dimVal = it.wymiar || '';
      if (dims[dimKey].indexOf(dimVal) < 0) dims[dimKey].push(dimVal);
    }

    cats.sort();
    for (var sk in subs){ subs[sk].sort(); }
    for (var pk in prods){ prods[pk].sort(); }
    for (var dk in dims){ dims[dk].sort(); }

    return {
      headers: [],
      items: items,
      cats: cats,
      subs: subs,
      prods: prods,
      dims: dims,
      ralOrder: ['Brak','RAL 6005','RAL 7016','RAL 8017','RAL 9005']
    };
  }

  // ---- Search helpers (kontekstowe dopasowanie) ----
  function zqNormalizeSearchStr(s){
    s = String(s || '');
    // ujednolicenia znaków (PL + typografia)
    s = s.replace(/[\u00D7]/g, 'x'); // ×
    s = s.replace(/[łŁ]/g, 'l');
    try{
      // usuń znaki diakrytyczne (NFD)
      s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }catch(e){}
    s = s.toLowerCase();
    // separatory -> spacje
    s = s.replace(/[^a-z0-9]+/g, ' ');
    s = s.replace(/\s+/g, ' ').trim();

    // normalizacje domenowe
    // RAL: "RAL 7016" -> "ral7016"
    s = s.replace(/\bral\s*0*(\d{4})\b/g, 'ral$1');
    // FI: "fi 5" -> "fi5"
    s = s.replace(/\bfi\s*0*(\d+)\b/g, 'fi$1');
    // wymiar: "50 x 200" -> "50x200"
    s = s.replace(/\b(\d{1,4})\s*x\s*(\d{1,4})\b/g, '$1x$2');

    return s;
  }

  function zqUniqKeepOrder(arr){
    var out = [];
    var seen = Object.create(null);
    for (var i=0;i<(arr||[]).length;i++){
      var k = String(arr[i] || '').trim();
      if (!k) continue;
      if (seen[k]) continue;
      seen[k] = 1;
      out.push(k);
    }
    return out;
  }

  function zqTokenizeSearchQuery(q){
    q = String(q || '').trim();
    if (!q) return [];
    var norm = zqNormalizeSearchStr(q);
    if (!norm) return [];
    var toks = norm.split(' ').filter(Boolean);

    // odfiltruj zbyt krótkie tokeny (ale zostaw np. "3d")
    toks = toks.filter(function(t){
      if (!t) return false;
      if (/^\d+$/.test(t)) return t.length >= 3; // same cyfry są zbyt szerokie (np. 50)
      if (t.length >= 2) return true;
      return false;
    });
    return zqUniqKeepOrder(toks);
  }

  function zqScoreSearchItem(x, tokens){
    // wymaga: x.hayN (albo fallback do hay)
    var hayN = x && x.hayN ? x.hayN : zqNormalizeSearchStr((x && x.hay) ? x.hay : '');
    var labelN = x && x.labelN ? x.labelN : zqNormalizeSearchStr((x && x.label) ? x.label : '');
    var skuN = x && x.skuN ? x.skuN : zqNormalizeSearchStr((x && x.sku) ? x.sku : '');
    var ralN = x && x.ralN ? x.ralN : zqNormalizeSearchStr((x && x.ral) ? x.ral : '');

    var matched = 0;
    var score = 0;
    for (var i=0;i<tokens.length;i++){
      var t = tokens[i];
      if (!t) continue;
      var pos = hayN.indexOf(t);
      if (pos < 0) continue;
      matched++;
      // bazowy punkt
      score += 10;
      // lepsze: na początku etykiety
      if (labelN.indexOf(t) === 0) score += 8;
      // lepsze: dopasowanie SKU
      if (skuN && (skuN === t)) score += 25;
      else if (skuN && skuN.indexOf(t) === 0) score += 12;
      // lepsze: dopasowanie RAL
      if (/^ral\d{4}$/.test(t) && ralN === t) score += 15;
      // lekkie dociążenie dla pierwszego tokena
      if (i === 0) score += 2;
    }
    // preferuj bardziej kompletne dopasowania
    score += matched * 3;
    // bardzo lekka preferencja krótszych etykiet (zwykle bardziej precyzyjne)
    score += Math.max(0, 30 - (labelN ? labelN.length : 0)) * 0.05;
    return { matched: matched, score: score };
  }

  function buildSearchIndexSync(){
    // buduj indeks tylko dla dozwolonych zakładek
    var tabs = getTabs();
    var out = [];
    for (var ti=0; ti<tabs.length; ti++){
      var sheetName = tabs[ti];
      var sheet = state.data[sheetName];
      if (!sheet || !Array.isArray(sheet.items)) continue;
      for (var i=0; i<sheet.items.length; i++){
        var it = sheet.items[i];
        var rals = Array.isArray(sheet.ralOrder) && sheet.ralOrder.length ? sheet.ralOrder : ['Brak','RAL 6005','RAL 7016','RAL 8017','RAL 9005'];
        for (var ri=0; ri<rals.length; ri++){
          var ral = rals[ri];
          var sku = getSku(it, ral) || '';
          var label = (it.produkt || '') + (it.wymiar ? (' - ' + it.wymiar) : '');
          var meta = (it.kategoria || '') + (it.podkategoria ? (' / ' + it.podkategoria) : '') + ' | ' + sheetName + ' | ' + ral + (sku ? (' | ' + sku) : '');
          var hay = (label + ' ' + meta).toLowerCase();
          var hayN = zqNormalizeSearchStr(label + ' ' + meta);
          out.push({
            sheet: sheetName,
            cat: it.kategoria || '',
            sub: it.podkategoria || '',
            prod: it.produkt || '',
            dim: it.wymiar || '',
            ral: ral,
            sku: sku,
            label: label,
            meta: meta,
            hay: hay,
            hayN: hayN,
            labelN: zqNormalizeSearchStr(label),
            skuN: zqNormalizeSearchStr(sku),
            ralN: zqNormalizeSearchStr(ral)
          });
        }
      }
    }
    state.searchIndex = out;
  }

  function buildSearchIndexAsync(cb){
    // async + chunking: nie blokuj UI na dużych arkuszach
    if (state.searchIndex && Array.isArray(state.searchIndex) && state.searchIndex.length){
      try{ if (cb) cb(); }catch(e){}
      return;
    }
    if (_zqSearch.build.building){
      if (cb) _zqSearch.build.waiters.push(cb);
      return;
    }

    _zqSearch.build.building = true;
    _zqSearch.build.waiters = cb ? [cb] : [];

    var tabs = getTabs();
    var out = [];
    var ti = 0, ii = 0, ri = 0;
    var rals = null;

    function flushWaiters(){
      var ws = _zqSearch.build.waiters.slice();
      _zqSearch.build.waiters.length = 0;
      for (var k=0; k<ws.length; k++){
        try{ ws[k](); }catch(e){}
      }
    }

    function finish(){
      state.searchIndex = out;
      _zqSearch.build.building = false;
      flushWaiters();
    }

    function step(){
      var t0 = perfNow();

      while ((perfNow() - t0) <= 12){
        if (ti >= tabs.length){
          finish();
          return;
        }

        var sheetName = tabs[ti];
        var sheet = state.data[sheetName];

        if (!sheet || !Array.isArray(sheet.items) || !sheet.items.length){
          ti++; ii = 0; ri = 0; rals = null;
          continue;
        }

        if (!rals){
          rals = Array.isArray(sheet.ralOrder) && sheet.ralOrder.length ? sheet.ralOrder : ['Brak','RAL 6005','RAL 7016','RAL 8017','RAL 9005'];
        }

        if (ii >= sheet.items.length){
          ti++; ii = 0; ri = 0; rals = null;
          continue;
        }

        if (ri >= rals.length){
          ii++; ri = 0;
          continue;
        }

        var it = sheet.items[ii];
        var ral = rals[ri++];

        var sku = getSku(it, ral) || '';
        var label = (it.produkt || '') + (it.wymiar ? (' - ' + it.wymiar) : '');
        var meta = (it.kategoria || '') + (it.podkategoria ? (' / ' + it.podkategoria) : '') + ' | ' + sheetName + ' | ' + ral + (sku ? (' | ' + sku) : '');
        var hay = (label + ' ' + meta).toLowerCase();
        var hayN = zqNormalizeSearchStr(label + ' ' + meta);

        out.push({
          sheet: sheetName,
          cat: it.kategoria || '',
          sub: it.podkategoria || '',
          prod: it.produkt || '',
          dim: it.wymiar || '',
          ral: ral,
          sku: sku,
          label: label,
          meta: meta,
          hay: hay,
          hayN: hayN,
          labelN: zqNormalizeSearchStr(label),
          skuN: zqNormalizeSearchStr(sku),
          ralN: zqNormalizeSearchStr(ral)
        });
      }

      setTimeout(step, 0);
    }

    setTimeout(step, 0);
  }

  // zgodność wstecz (gdyby coś wywoływało sync build)
  function buildSearchIndex(){
    buildSearchIndexSync();
  }

  function ensureSearchIndex(){
    if (!state.searchIndex || !Array.isArray(state.searchIndex) || !state.searchIndex.length){
      buildSearchIndexSync();
    }
  }

  function hideSearchDrop(){
    var drop = $('zq-search-drop');
    if (drop) drop.style.display = 'none';
    if (drop) drop.setAttribute('aria-hidden', 'true');
  }

  function renderSearchHint(msg){
    var drop = $('zq-search-drop');
    if (!drop) return;
    fastClear(drop);

    var p = DOC.createElement('div');
    p.className = 'it';
    var l = DOC.createElement('div');
    l.className = 'l';
    var tt = DOC.createElement('div');
    tt.className = 't';
    tt.textContent = msg || '';
    l.appendChild(tt);
    p.appendChild(l);

    drop.appendChild(p);
    drop.style.display = 'block';
    drop.setAttribute('aria-hidden', 'false');
  }

  function renderSearchDrop(sections){
    var drop = $('zq-search-drop');
    if (!drop) return;
    fastClear(drop);

    var frag = DOC.createDocumentFragment();
    var hasAny = false;

    (sections || []).forEach(function(sec){
      if (!sec) return;
      var items = Array.isArray(sec.items) ? sec.items : [];
      if (!items.length) return;

      hasAny = true;
      var h = DOC.createElement('div');
      h.className = 'sec';
      h.textContent = sec.title || '';
      frag.appendChild(h);

      for (var j=0; j<items.length; j++){
        var x = items[j];
        if (!x) continue;

        var row = DOC.createElement('div');
        row.className = 'it';
        var sig = sigOf(x.sheet, x.cat, x.sub, x.prod, x.dim, x.ral);
        row.setAttribute('data-zq-sig', sig);
        row.setAttribute('data-zq-sku', x.sku || '');

        var left = DOC.createElement('div');
        left.className = 'l';

        var t = DOC.createElement('div');
        t.className = 't';
        t.textContent = x.label || '';

        var m = DOC.createElement('div');
        m.className = 'm';
        m.textContent = x.meta || '';

        left.appendChild(t);
        left.appendChild(m);

        var right = DOC.createElement('div');
        right.className = 'r';

        var star = DOC.createElement('button');
        star.type = 'button';
        star.className = 'zq-star';
        star.setAttribute('data-zq-star', '1');
        var on = isFav(sig);
        if (on) star.classList.add('is-on');
        star.textContent = on ? '★' : '☆';
        star.title = 'Ulubione';
        right.appendChild(star);

        row.appendChild(left);
        row.appendChild(right);

        frag.appendChild(row);
      }
    });

    if (!hasAny){
      var p = DOC.createElement('div');
      p.className = 'it';
      var l = DOC.createElement('div');
      l.className = 'l';
      var tt = DOC.createElement('div');
      tt.className = 't';
      tt.textContent = 'Brak wyników.';
      l.appendChild(tt);
      p.appendChild(l);
      frag.appendChild(p);
    }

    drop.appendChild(frag);
    drop.style.display = 'block';
    drop.setAttribute('aria-hidden', 'false');
  }

  function applySelectionFromSig(sig){
    var p = parseSig(sig);
    if (!p.sheet || !p.cat || !p.prod || !p.ral) return;
    // tryb ALL: nie przełączamy zakładek (wszystkie kategorie aktywne)

    // kaskady
    populateCascades();
    var catSel = $('zq-cat');
    if (catSel) catSel.value = p.cat;
    updateSubcats(p.sub, p.prod, p.dim, p.ral);
    // dopnij wartości na koniec (dla pewności)
    if ($('zq-subcat')) $('zq-subcat').value = p.sub;
    if ($('zq-prod')) $('zq-prod').value = p.prod;
    if ($('zq-dim')) $('zq-dim').value = p.dim;
    if ($('zq-ral')) $('zq-ral').value = p.ral;
  }

  function highlightTab(sheetName){
    var wrap = $('zq-tabs');
    if (!wrap) return;
    var btns = wrap.querySelectorAll('.zq-tab');
    Array.prototype.forEach.call(btns, function(b){
      if (b && String(b.textContent || '').trim() === String(sheetName || '').trim()) b.classList.add('is-on');
      else if (b) b.classList.remove('is-on');
    });
  }

  function performSearch(q){
    q = String(q || '').trim();
    if (!q){
      renderSearchDrop([
        { title: 'Ulubione', items: favItemsForDrop(12) },
        { title: 'Ostatnio dodawane', items: recentItemsForDrop(12) }
      ]);
      return;
    }

    if (q.length < 2){
      renderSearchHint('Wpisz min. 2 znaki.');
      return;
    }

    // tokenizacja (kolejność nie ma znaczenia, tokeny nie muszą tworzyć ciągłego fragmentu)
    var tokens = zqTokenizeSearchQuery(q);
    if (!tokens.length){
      renderSearchHint('Brak frazy do wyszukania.');
      return;
    }

    // indeks budujemy asynchronicznie (żeby nie blokować UI)
    if (!state.searchIndex || !Array.isArray(state.searchIndex) || !state.searchIndex.length){
      buildSearchIndexAsync(function(){
        // uruchom ponownie tylko jeśli użytkownik nadal ma tę samą frazę w polu
        var cur = $('zq-search') ? String($('zq-search').value || '').trim() : '';
        if (cur === q) runSearchAsync(q, tokens);
      });
      renderSearchHint('Indeksowanie bazy produktów...');
      return;
    }

    runSearchAsync(q, tokens);
  }

  function runSearchAsync(q, tokens){
    var idx = state.searchIndex || [];
    var first = tokens[0] || '';
    var needApprox = tokens.length >= 3 ? (tokens.length - 1) : tokens.length;

    var strict = [];
    var approx = [];

    var seq = ++_zqSearch.run.seq;
    var i = 0;

    renderSearchHint('Szukam...');

    function step(){
      if (seq !== _zqSearch.run.seq) return; // anulowane nowszym zapytaniem

      var t0 = perfNow();
      for (; i<idx.length; i++){
        if ((perfNow() - t0) > 12) break;

        var x = idx[i];
        if (!x || !x.hayN) continue;

        // szybki prefiltr po pierwszym tokenie
        if (first && x.hayN.indexOf(first) < 0) continue;

        var sc = zqScoreSearchItem(x, tokens);
        if (sc.matched === tokens.length){
          strict.push({ x: x, s: sc.score });
        } else if (tokens.length >= 3 && sc.matched >= needApprox){
          approx.push({ x: x, s: sc.score - 5 });
        }
      }

      if (i < idx.length){
        setTimeout(step, 0);
        return;
      }

      var list = strict.length ? strict : approx;
      var approxMode = (!strict.length && approx.length);

      if (!list.length){
        renderSearchHint('Brak wyników.');
        return;
      }

      list.sort(function(a,b){
        if (b.s !== a.s) return b.s - a.s;
        var la = (a.x && a.x.label) ? String(a.x.label).length : 9999;
        var lb = (b.x && b.x.label) ? String(b.x.label).length : 9999;
        return la - lb;
      });

      var res = [];
      for (var k=0; k<list.length && res.length < 50; k++) res.push(list[k].x);

      var title = approxMode ? ('Wyniki (przybliżone) (' + res.length + ')') : ('Wyniki (' + res.length + ')');
      renderSearchDrop([{ title: title, items: res }]);
    }

    setTimeout(step, 0);
  }

  function favItemsForDrop(limit){
    limit = limit || 12;
    var out = [];
    for (var i=0;i<state.favs.length && out.length<limit;i++){
      var p = parseSig(state.favs[i].sig);
      if (!p.sheet) continue;
      var label = state.favs[i].label ? String(state.favs[i].label) : (p.prod + (p.dim ? (' - ' + p.dim) : ''));
      var meta = p.cat + (p.sub ? (' / ' + p.sub) : '') + ' | ' + p.sheet + ' | ' + p.ral + (state.favs[i].sku ? (' | ' + state.favs[i].sku) : '');
      out.push({ sheet:p.sheet, cat:p.cat, sub:p.sub, prod:p.prod, dim:p.dim, ral:p.ral, sku:state.favs[i].sku||'', label:label, meta:meta });
    }
    return out;
  }

  function recentItemsForDrop(limit){
    limit = limit || 12;
    var out = [];
    for (var i=0;i<state.recents.length && out.length<limit;i++){
      var p = parseSig(state.recents[i].sig);
      if (!p.sheet) continue;
      var label = state.recents[i].label ? String(state.recents[i].label) : (p.prod + (p.dim ? (' - ' + p.dim) : ''));
      var meta = p.cat + (p.sub ? (' / ' + p.sub) : '') + ' | ' + p.sheet + ' | ' + p.ral + (state.recents[i].sku ? (' | ' + state.recents[i].sku) : '');
      out.push({ sheet:p.sheet, cat:p.cat, sub:p.sub, prod:p.prod, dim:p.dim, ral:p.ral, sku:state.recents[i].sku||'', label:label, meta:meta });
    }
    return out;
  }

  function getTabs(){
    var t = (window.ZQOS && Array.isArray(window.ZQOS.tabs)) ? window.ZQOS.tabs : [];
    if (!t.length) t = ['Ogrodzenia Panelowe','Ogrodzenia Palisadowe','Słupki','Akcesoria'];
    // ograniczenie per konto
    if (state._allowedTabs && Array.isArray(state._allowedTabs) && state._allowedTabs.length){
      var out = [];
      t.forEach(function(x){ if (state._allowedTabs.indexOf(x) >= 0) out.push(x); });
      return out.length ? out : t;
    }
    return t;
  }

  function buildTabs(){
    // UI zakładek usunięte: działamy na połączonym zbiorze (wszystkie dozwolone zakładki naraz)
    state.activeSheet = '__ALL__';
    var wrap = $('zq-tabs');
    if (wrap){
      wrap.style.display = 'none';
      fastClear(wrap);
    }
  }

  function populateSelect(sel, arr, emptyLabel, keepValue){
    var prev = (keepValue !== undefined) ? String(keepValue || '') : '';
    while (sel.firstChild) sel.removeChild(sel.firstChild);
    var opt0 = DOC.createElement('option');
    opt0.value = '';
    opt0.textContent = emptyLabel || '-';
    sel.appendChild(opt0);
    (arr || []).forEach(function(v){
      var o = DOC.createElement('option');
      o.value = v;
      o.textContent = v || '(brak)';
      sel.appendChild(o);
    });
    if (prev){
      try{
        for (var i=0; i<sel.options.length; i++){
          if (String(sel.options[i].value) === prev){ sel.value = prev; break; }
        }
      }catch(e){}
    }
  }

  function populateCascades(){
    var sheet = state.data[state.activeSheet];
    var catSel = $('zq-cat');
    var subSel = $('zq-subcat');
    var prodSel = $('zq-prod');
    var dimSel = $('zq-dim');
    var ralSel = $('zq-ral');
    if (!catSel || !subSel || !prodSel || !dimSel || !ralSel) return;

    // preserve current selections
    var prevCat = catSel.value || '';
    var prevSub = subSel.value || '';
    var prevProd = prodSel.value || '';
    var prevDim = dimSel.value || '';
    var prevRal = ralSel.value || '';

    if (!sheet){
      populateSelect(catSel, [], '-', '');
      populateSelect(subSel, [], '-', '');
      populateSelect(prodSel, [], '-', '');
      populateSelect(dimSel, [], '-', '');
      populateSelect(ralSel, [], '-', '');
      return;
    }

    populateSelect(catSel, sheet.cats, 'Wybierz...', prevCat);
    updateSubcats(prevSub, prevProd, prevDim, prevRal);
  }

  function updateSubcats(prevSub, prevProd, prevDim, prevRal){
    var sheet = state.data[state.activeSheet];
    if (!sheet) return;

    var cat = ($('zq-cat') && $('zq-cat').value) ? $('zq-cat').value : '';
    var arr = sheet.subs[cat] || [];
    populateSelect($('zq-subcat'), arr, 'Wybierz...', prevSub || ($('zq-subcat') && $('zq-subcat').value) || '');
    updateProducts(prevProd, prevDim, prevRal);
  }

  function updateProducts(prevProd, prevDim, prevRal){
    var sheet = state.data[state.activeSheet];
    if (!sheet) return;

    var cat = $('zq-cat').value || '';
    var sub = $('zq-subcat').value || '';
    var key = cat + '||' + sub;
    var arr = sheet.prods[key] || [];
    populateSelect($('zq-prod'), arr, 'Wybierz...', prevProd || ($('zq-prod') && $('zq-prod').value) || '');
    updateDims(prevDim, prevRal);
  }

  function updateDims(prevDim, prevRal){
    var sheet = state.data[state.activeSheet];
    if (!sheet) return;

    var cat = $('zq-cat').value || '';
    var sub = $('zq-subcat').value || '';
    var prod = $('zq-prod').value || '';
    var key = cat + '||' + sub + '||' + prod;
    var arr = sheet.dims[key] || [];
    populateSelect($('zq-dim'), arr, 'Wybierz...', prevDim || ($('zq-dim') && $('zq-dim').value) || '');
    updateRAL(prevRal);
  }

  function updateRAL(prevRal){
    var sheet = state.data[state.activeSheet];
    if (!sheet) return;
    var ralSel = $('zq-ral');
    populateSelect(ralSel, sheet.ralOrder, 'RAL / Brak...', prevRal || (ralSel && ralSel.value) || '');
  }

function findItem(sheetName, cat, sub, prod, dim){
    var sheet = state.data[sheetName];
    if (!sheet) return null;
    var items = sheet.items || [];
    for (var i=0;i<items.length;i++){
      var it = items[i];
      if (it.kategoria !== cat) continue;
      if ((it.podkategoria||'') !== (sub||'')) continue;
      if (it.produkt !== prod) continue;
      if ((it.wymiar||'') !== (dim||'')) continue;
      return it;
    }
    return null;
  }

  function lineId(){ return 'L' + Math.random().toString(16).slice(2) + Date.now().toString(16); }

  function addLine(){
    setErr('');
    var sheetName = state.activeSheet;
    var cat = $('zq-cat').value || '';
    var sub = $('zq-subcat').value || '';
    var prod = $('zq-prod').value || '';
    var dim = $('zq-dim').value || '';
    var ral = $('zq-ral').value || '';
    var qty = clampNum(parseNumber($('zq-qty').value) || 1, 1, 999999);
    var disc = clampNum(parseNumber($('zq-disc').value) || 0, 0, 100);
    var maxD = getMaxDiscount();
    if (disc > maxD) disc = maxD;

    if (!cat || !prod){
      setErr('Wybierz kategorię i produkt.');
      return;
    }
    if (!ral){
      setErr('Wybierz RAL.');
      return;
    }

    var item = findItem(sheetName, cat, sub, prod, dim);
    if (!item){
      setErr('Nie znaleziono pozycji w danych (po synchronizacji).', 'error');
      return;
    }

    // ustaw cenę dla wybranego RAL
    var p = (item.ceny && Object.prototype.hasOwnProperty.call(item.ceny, ral)) ? item.ceny[ral] : null;
    if (p == null) p = 0;
    var cloned = JSON.parse(JSON.stringify(item));
    cloned.cenaNetto = p;

    // w trybie ALL item.sheet zawiera prawdziwą zakładkę źródłową
    var actualSheet = (cloned && cloned.sheet) ? cloned.sheet : sheetName;

    state.offerLines.push({ id: lineId(), item: cloned, ral: ral, qty: qty, disc: disc, manualUnitNet: null, priceMode: 'unit' });
    pushRecent(sigOf(actualSheet, cat, sub, prod, dim, ral), { label: (prod + (dim ? (' - ' + dim) : '')), sku: (getSku(cloned, ral) || '') });
    persistOffer();
    renderLines();
  }

  function isModeAvailable(item, mode){
    if (!item || !mode) return false;
    if (mode === 'unit') return true;
    if (!item.tiers || !item.tiers[mode]) return false;
    var t = item.tiers[mode];
    return (t && typeof t.unit_net === 'number' && isFinite(t.unit_net) && t.unit_net >= 0);
  }

  function getLineMode(line){
    var m = (line && line.priceMode) ? String(line.priceMode) : 'unit';
    if (m !== 'paleta' && m !== 'tir') m = 'unit';
    if (m !== 'unit'){
      var item = line && line.item ? line.item : null;
      if (!isModeAvailable(item, m)) m = 'unit';
    }
    return m;
  }

  function setLineMode(id, mode){
    mode = (mode === 'paleta' || mode === 'tir') ? mode : 'unit';
    for (var i=0; i<state.offerLines.length; i++){
      var l = state.offerLines[i];
      if (l.id !== id) continue;
      if (mode !== 'unit' && !isModeAvailable(l.item, mode)) return;
      l.priceMode = mode;
      // zmiana trybu wraca do ceny bazowej dla trybu
      l.manualUnitNet = null;
      persistOffer();
      renderLines();
      return;
    }
  }

  function computeTransportLine(line){
    var qtyKm = clampNum(line && line.qty != null ? line.qty : 1, 1, 999999);
    qtyKm = Math.round(qtyKm);
    var disc = clampNum(line && line.disc != null ? line.disc : 0, 0, 100);

    // meta: z l.transport lub fallback z item.cenaNetto
    var baseRate = (line && line.item && typeof line.item.cenaNetto === 'number' && isFinite(line.item.cenaNetto)) ? line.item.cenaNetto : 0;
    var meta = (line && line.transport && typeof line.transport === 'object')
      ? normalizeTransportProfile(line.transport)
      : normalizeTransportProfile({ mode:'flat', flat_rate: baseRate, min_net: 0, extras:{hds:0,unload:0,sat:0}, no_global_disc:true });

    // oferta specjalna: ręczna stawka w tabeli -> traktujemy jako flat
    if (state.specialOffer && line && line.manualUnitNet != null && isFinite(line.manualUnitNet)){
      meta.mode = 'flat';
      meta.flat_rate = clampNum(parseNumber(line.manualUnitNet) || 0, 0, 999999999);
    }

    var t = computeTransportFromMeta(qtyKm, disc, meta);

    // Dla spójności UI: unitNet = stawka użyta (zależna od progu)
    var unitNet = t.rate_used;
    var unitNetAfter = unitNet * (1 - (disc/100));

    var netBefore = t.net_before;
    var netAfter = t.net_after;
    var grossBefore = netBefore * (1 + getVatRate());
    var grossAfter = netAfter * (1 + getVatRate());

    return {
      qty: qtyKm,
      units: qtyKm,
      packQty: 1,
      mode: 'unit',
      disc: disc,
      unitNet: unitNet,
      unitNetAfter: unitNetAfter,
      netBefore: netBefore,
      netAfter: netAfter,
      grossBefore: grossBefore,
      grossAfter: grossAfter,
      net: netAfter,
      gross: grossAfter,
      transport: t
    };
  }

  function computeLine(line){
    if (isTransportLine(line)){
      return computeTransportLine(line);
    }
    var item = line.item || {};
    var qty = clampNum(line.qty, 1, 999999);
    var disc = clampNum(line.disc, 0, 100);

    var unitNet = 0;
    if (state.specialOffer && line.manualUnitNet != null && isFinite(line.manualUnitNet)){
      unitNet = clampNum(line.manualUnitNet, 0, 999999999);
    } else {
      // cena bazowa zależna od trybu (pojedynczy/paleta/tir)
      var mode = getLineMode(line);
      if (mode !== 'unit' && item && item.tiers && item.tiers[mode] && typeof item.tiers[mode].unit_net === 'number' && isFinite(item.tiers[mode].unit_net)){
        unitNet = item.tiers[mode].unit_net;
      } else {
        unitNet = (item && typeof item.cenaNetto === 'number' && isFinite(item.cenaNetto)) ? item.cenaNetto : 0;
      }
    }

    var unitNetAfter = unitNet * (1 - (disc/100));

    // Ilość: zawsze oznacza sztuki (także w trybie Paleta/TIR).
    var modeNow = getLineMode(line);
    var packQty = 1;
    var units = qty;

    // wartości przed rabatem
    var netBefore = unitNet * units;
    var grossBefore = netBefore * (1 + getVatRate());

    // wartości po rabacie (zgodnie z dotychczasową logiką)
    var netAfter = unitNetAfter * units;
    var grossAfter = netAfter * (1 + getVatRate());

    // kompatybilność wsteczna: net/gross oznacza "po rabacie"
    return {
      qty: qty,
      units: units,
      packQty: packQty,
      mode: modeNow,
      disc: disc,
      unitNet: unitNet,
      unitNetAfter: unitNetAfter,
      netBefore: netBefore,
      netAfter: netAfter,
      grossBefore: grossBefore,
      grossAfter: grossAfter,
      net: netAfter,
      gross: grossAfter
    };
  }

  function getSku(item, ralLabel){
    if (!item || !ralLabel) return '';
    var v = (item.ralMap && Object.prototype.hasOwnProperty.call(item.ralMap, ralLabel)) ? item.ralMap[ralLabel] : '';
    v = normalizeSku(v);
    if (!v || v === '-' || v === '—' || v === '–') return '';
    return v;
  }

  function formatSku(v){
    v = normalizeSku(v);
    if (!v || v === '-' || v === '—' || v === '–') return 'brak kodu';
    return v;
  }

  function duplicateLine(id){
    for (var i=0; i<state.offerLines.length; i++){
      if (state.offerLines[i].id === id){
        var src = state.offerLines[i];
        var itemCopy = src.item;
        if (isCustomLine(src)){
          try{ itemCopy = JSON.parse(JSON.stringify(src.item || {})); }catch(e){ itemCopy = src.item; }
        }
        var tCopy = null;
        try{ tCopy = (src.transport && typeof src.transport === 'object') ? JSON.parse(JSON.stringify(src.transport)) : null; }catch(e){ tCopy = src.transport || null; }
        state.offerLines.push({ id: lineId(), item: itemCopy, ral: src.ral, qty: src.qty, disc: src.disc, manualUnitNet: src.manualUnitNet, priceMode: src.priceMode || 'unit', isCustom: !!isCustomLine(src), isTransport: !!isTransportLine(src), customKind: src.customKind || (isTransportLine(src) ? 'transport' : ''), transport: tCopy, lineComment: src.lineComment || '' });
        persistOffer();
        renderLines();
        return;
      }
    }
  }

  function removeLine(id){
    state.offerLines = state.offerLines.filter(function(x){ return x.id !== id; });
    persistOffer();
    renderLines();
  }

  function updateLine(id, patch){
    for (var i=0; i<state.offerLines.length; i++){
      if (state.offerLines[i].id === id){
        var l = state.offerLines[i];
        if (patch.qty != null) l.qty = patch.qty;
        if (patch.disc != null) l.disc = patch.disc;
        if (patch.manualUnitNet != null) l.manualUnitNet = patch.manualUnitNet;
        return;
      }
    }
  }

  function clearOffer(){
    clearEditingOfferContext();
    state.offerLines = [];
    persistOffer(true);
    clearDraft();
    renderLines();
  }

  function persistOffer(clear){
    try{
      var key = 'zq_offer_lines_v2';
      if (clear){ sessionStorage.removeItem(key); return; }
      var data = state.offerLines.map(function(l){
        var isC = isCustomLine(l);
        return {
          id: l.id,
          is_custom: isC,
          custom_kind: isC ? (isTransportLine(l) ? 'transport' : 'custom') : null,
          sheet: isC ? '__CUSTOM__' : (l.item ? l.item.sheet : ''),
          kategoria: (l.item && l.item.kategoria) ? l.item.kategoria : '',
          podkategoria: (l.item && l.item.podkategoria) ? l.item.podkategoria : '',
          produkt: (l.item && l.item.produkt) ? l.item.produkt : '',
          wymiar: (l.item && l.item.wymiar) ? l.item.wymiar : '',
          ral: isC ? '' : (l.ral || ''),
          priceMode: isC ? 'unit' : getLineMode(l),
          qty: l.qty,
          disc: l.disc,
          manualUnitNet: l.manualUnitNet,
          custom_unit_net: isC ? ((l.item && typeof l.item.cenaNetto === 'number' && isFinite(l.item.cenaNetto)) ? l.item.cenaNetto : 0) : null,
          line_comment: l.lineComment || '',
          transport: isTransportLine(l) ? (l.transport || null) : null
        };
      });
      sessionStorage.setItem(key, JSON.stringify(data));
      scheduleDraftSave();
    }catch(e){}
  }

  function restoreOffer(){
    try{
      var raw = sessionStorage.getItem('zq_offer_lines_v2');
      if (!raw) return;
      var arr = JSON.parse(raw);
      if (!Array.isArray(arr) || !arr.length) return;

      var rebuilt = [];
      var maxD = getMaxDiscount();
      for (var i=0; i<arr.length; i++){
        var it = arr[i] || {};
        var kind = (it.custom_kind != null) ? String(it.custom_kind) : '';
        var isC = !!(it.is_custom || it.isCustom || kind === 'transport' || String(it.sheet || '') === '__CUSTOM__');

        if (isC){
          var nm = (it.produkt != null) ? String(it.produkt).trim() : '';
          var unit = parseNumber(it.custom_unit_net);
          if (unit == null) unit = (it.manualUnitNet != null) ? parseNumber(it.manualUnitNet) : null;
          if (unit == null || unit < 0) unit = 0;

          var disc = clampNum(parseNumber(it.disc) || 0, 0, 100);
          if (disc > maxD) disc = maxD;

          rebuilt.push({
            id: it.id || lineId(),
            item: (kind === 'transport') ? makeTransportItem(unit) : makeCustomItem(nm, unit),
            ral: '',
            priceMode: 'unit',
            qty: clampNum(parseNumber(it.qty) || 1, 1, 999999),
            disc: disc,
            manualUnitNet: (it.manualUnitNet != null) ? parseNumber(it.manualUnitNet) : null,
            isCustom: true,
            isTransport: (kind === 'transport'),
            customKind: (kind === 'transport') ? 'transport' : '',
            transport: (kind === 'transport') ? normalizeTransportProfile(it.transport || { mode:'flat', flat_rate: unit, min_net:0, extras:{hds:0,unload:0,sat:0}, no_global_disc:true }) : null,
            lineComment: it.line_comment ? String(it.line_comment) : ''
          });
          continue;
        }

        var found = findItem(it.sheet, it.kategoria, it.podkategoria || '', it.produkt, it.wymiar || '');
        if (!found) continue;

        var p = (found.ceny && Object.prototype.hasOwnProperty.call(found.ceny, it.ral)) ? found.ceny[it.ral] : null;
        if (p == null) p = 0;
        var cloned = JSON.parse(JSON.stringify(found));
        cloned.cenaNetto = p;

        var disc2 = clampNum(parseNumber(it.disc) || 0, 0, 100);
        if (disc2 > maxD) disc2 = maxD;

        rebuilt.push({
          id: it.id || lineId(),
          item: cloned,
          ral: it.ral || '',
          priceMode: (it.priceMode === 'paleta' || it.priceMode === 'tir' || it.priceMode === 'unit') ? it.priceMode : 'unit',
          qty: clampNum(parseNumber(it.qty) || 1, 1, 999999),
          disc: disc2,
          manualUnitNet: (it.manualUnitNet != null) ? parseNumber(it.manualUnitNet) : null,
          lineComment: it.line_comment ? String(it.line_comment) : ''
        });
      }
      if (rebuilt.length){ state.offerLines = rebuilt; }
    }catch(e){}
  }

  function renderTotals(){
    var netBefore = 0;
    var netAfter = 0;
    var grossBefore = 0;
    var grossAfter = 0;
    for (var i=0; i<state.offerLines.length; i++){
      var c = computeLine(state.offerLines[i]);
      netBefore += (c.netBefore || 0);
      netAfter += (c.netAfter || 0);
      grossBefore += (c.grossBefore || 0);
      grossAfter += (c.grossAfter || 0);
    }

    if ($('zq-total-net-before')) $('zq-total-net-before').textContent = toMoney(netBefore);
    if ($('zq-total-net-after')) $('zq-total-net-after').textContent = toMoney(netAfter);
    if ($('zq-total-gross-before')) $('zq-total-gross-before').textContent = toMoney(grossBefore);
    if ($('zq-total-gross-after')) $('zq-total-gross-after').textContent = toMoney(grossAfter);

    // kompatybilność (jeśli gdzieś zostały stare ID)
    if ($('zq-total-net')) $('zq-total-net').textContent = toMoney(netAfter);
    if ($('zq-total-gross')) $('zq-total-gross').textContent = toMoney(grossAfter);
  }

  function renderLines(){
    var list = $('zq-lines-list');
    var empty = $('zq-lines-empty');
    if (!list || !empty) return;

    while (list.firstChild) list.removeChild(list.firstChild);

    if (!state.offerLines.length){
      empty.style.display = 'block';
      var cntEl0 = $('zq-lines-count');
      if (cntEl0) cntEl0.textContent = '0';
      renderTotals();
      return;
    }
    empty.style.display = 'none';

    // Układ stały tabeli: pokazujemy netto/brutto przed i po rabacie.
    // Select "Podgląd cen" zostaje (UX), ale tutaj nie przełączamy kolumn.

    var cntEl = $('zq-lines-count');
    if (cntEl) cntEl.textContent = String(state.offerLines.length);


    state.offerLines.forEach(function(line, idx){
      var item = line.item;
      var calc = computeLine(line);

      var skuRaw = getSku(item, line.ral);
      var skuText = formatSku(skuRaw);
      var unitShow = calc.unitNet;

      var row = DOC.createElement('div');
      row.className = 'zq-line';
      row.setAttribute('data-id', line.id);

      var c1 = DOC.createElement('div');
      c1.className = 'zq-name';
      var m = DOC.createElement('div');
      m.className = 'main';

      var tMain = DOC.createElement('div');
      tMain.className = 't';
      tMain.textContent = String((idx + 1)) + '. ' + item.produkt;

      var modeNow = getLineMode(line);
      var modes = DOC.createElement('div');
      modes.className = 'zq-price-modes';

      function modeTitle(mode){
        if (mode === 'unit') return 'Pojedynczy produkt - cena standardowa';
        var t = (item && item.tiers && item.tiers[mode]) ? item.tiers[mode] : null;
        if (t && typeof t.unit_net === 'number' && isFinite(t.unit_net)){
          var base = (mode === 'paleta' ? 'Paleta' : 'TIR') + ': ' + toMoney(t.unit_net) + ' netto/szt';
          // Jeśli w arkuszu jest format "50x/60", pokaż informacyjnie "50x" bez sugerowania przelicznika ilości.
          if (t.qty && isFinite(t.qty) && t.qty > 1){
            base += ' (wg arkusza: ' + String(t.qty) + 'x)';
          }
          return base;
        }
        return (mode === 'paleta' ? 'Paleta' : 'TIR') + ': brak danych w arkuszu';
      }

      function addModeBtn(mode){
        var btn = DOC.createElement('button');
        btn.type = 'button';
        btn.className = 'zq-pmode' + ((modeNow === mode) ? ' is-on' : '');
        btn.title = modeTitle(mode);
        btn.setAttribute('aria-label', btn.title);
        if (mode !== 'unit' && !isModeAvailable(item, mode)) btn.disabled = true;

        var img = DOC.createElement('img');
        img.alt = '';
        img.decoding = 'async';
        img.loading = 'lazy';
        img.src = ZQ_PRICE_MODE_ICONS[mode] || '';
        btn.appendChild(img);

        btn.addEventListener('click', function(){ setLineMode(line.id, mode); });
        modes.appendChild(btn);
      }

      addModeBtn('unit');
      addModeBtn('paleta');
      addModeBtn('tir');

      m.appendChild(tMain);
      m.appendChild(modes);
      var s = DOC.createElement('div');
      s.className = 'sub';
      s.textContent =
        (item.kategoria || '') +
        (item.podkategoria ? (' / ' + item.podkategoria) : '') +
        (item.wymiar ? (' - ' + item.wymiar) : '') +
        (line.ral ? (' - ' + line.ral) : '') +
        (line.ral ? (' - SKU: ' + skuText) : '') +
        ((modeNow !== 'unit') ? (' - ' + (modeNow === 'paleta' ? 'Paleta' : 'TIR')) : '');
      c1.appendChild(m); c1.appendChild(s);

      if (isTransportLine(line) && calc && calc.transport && calc.transport.summary){
        var sTr = DOC.createElement('div');
        sTr.className = 'sub2';
        sTr.textContent = String(calc.transport.summary);
        c1.appendChild(sTr);
      }

      if (line && line.lineComment){
        var s2 = DOC.createElement('div');
        s2.className = 'sub2';
        s2.textContent = 'Komentarz: ' + String(line.lineComment);
        c1.appendChild(s2);
      }

      var c3 = DOC.createElement('div');
      c3.className = 'zq-cell zq-qty';
      c3.setAttribute('data-l', isTransportLine(line) ? 'Ilość (km)' : 'Ilość (szt.)');
      var qtyIn = DOC.createElement('input');
      qtyIn.type='number'; qtyIn.min='1'; qtyIn.step='1';
      qtyIn.className='zq-input';
      qtyIn.value = String(calc.qty);
      qtyIn.addEventListener('change', function(){
        var v = clampNum(parseNumber(qtyIn.value) || 1, 1, 999999);
        qtyIn.value = String(v);
        updateLine(line.id, {qty:v});
        persistOffer();
        renderLines();
      });
      c3.appendChild(qtyIn);

      var c4 = DOC.createElement('div');
      c4.className = 'zq-cell zq-unit hide-md';
      c4.setAttribute('data-l', isTransportLine(line) ? 'Cena za 1 km' : 'Cena jedn.');
      if (state.specialOffer){
        var priceIn = DOC.createElement('input');
        priceIn.type='number'; priceIn.min='0'; priceIn.step='0.01';
        priceIn.className='zq-input';
        priceIn.value = (line.manualUnitNet != null && isFinite(line.manualUnitNet)) ? String(line.manualUnitNet) : String(calc.unitNet);
        priceIn.addEventListener('change', function(){
          var vv = parseNumber(priceIn.value);
          if (vv == null || vv < 0) vv = 0;
          priceIn.value = String(vv);
          updateLine(line.id, {manualUnitNet: vv});
          persistOffer();
          renderLines();
        });
        c4.appendChild(priceIn);
      } else {
        c4.textContent = toMoney(unitShow);
      }

      var c5 = DOC.createElement('div');
      c5.className = 'zq-cell zq-disc';
      c5.setAttribute('data-l','Rabat %');
      var discIn = DOC.createElement('input');
      discIn.type='number'; discIn.min='0'; discIn.max='100'; discIn.step='1';
      discIn.className='zq-input';
      discIn.value = String(calc.disc);
      discIn.addEventListener('change', function(){
        var v2 = clampNum(parseNumber(discIn.value) || 0, 0, 100);
        var maxD = getMaxDiscount();
        if (v2 > maxD) v2 = maxD;
        discIn.value = String(v2);
        updateLine(line.id, {disc:v2});
        persistOffer();
        renderLines();
      });
      c5.appendChild(discIn);

      var cNetBefore = DOC.createElement('div');
      cNetBefore.className = 'zq-cell zq-value zq-before hide-md';
      cNetBefore.setAttribute('data-l','Wartość Netto');
      cNetBefore.textContent = toMoney(calc.netBefore);

      var cNetAfter = DOC.createElement('div');
      cNetAfter.className = 'zq-cell zq-value zq-after';
      cNetAfter.setAttribute('data-l','Netto (po rabacie)');
      cNetAfter.textContent = toMoney(calc.netAfter);

      var cGrossBefore = DOC.createElement('div');
      cGrossBefore.className = 'zq-cell zq-value zq-before hide-md';
      cGrossBefore.setAttribute('data-l','Wartość Brutto');
      cGrossBefore.textContent = toMoney(calc.grossBefore);

      var cGrossAfter = DOC.createElement('div');
      cGrossAfter.className = 'zq-cell zq-value zq-after';
      cGrossAfter.setAttribute('data-l','Brutto (po rabacie)');
      cGrossAfter.textContent = toMoney(calc.grossAfter);

      var c7 = DOC.createElement('div');
      c7.className = 'zq-cell zq-acts';
      var acts = DOC.createElement('div');
      acts.className='zq-actions-mini';

      var dup = DOC.createElement('button');
      dup.type='button';
      dup.className='zq-ico';
      dup.title='Duplikuj pozycję';
      dup.setAttribute('aria-label','Duplikuj pozycję');
      dup.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><rect x="2" y="2" width="13" height="13" rx="2"/></svg>';
      dup.addEventListener('click', function(){ duplicateLine(line.id); });
      acts.appendChild(dup);

      if (isCustomLine(line)){
        var edit = DOC.createElement('button');
        edit.type='button';
        edit.className='zq-ico';
        edit.title='Edytuj pozycję';
        edit.setAttribute('aria-label','Edytuj pozycję');
        edit.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
        edit.addEventListener('click', function(){ if (isTransportLine(line)) { openTransportLineModal('edit', line); } else { openCustomLineModal('edit', line); } });
        acts.appendChild(edit);
      }

      var sig = sigOf(item.sheet, item.kategoria, item.podkategoria || '', item.produkt, item.wymiar || '', line.ral);
      var favOn = isFav(sig);
      var fav = DOC.createElement('button');
      fav.type='button';
      fav.className='zq-ico' + (favOn ? ' is-on' : '');
      fav.title = favOn ? 'Usuń z ulubionych' : 'Dodaj do ulubionych';
      fav.setAttribute('aria-label', fav.title);
      fav.innerHTML = favOn
        ? '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
      fav.addEventListener('click', function(){
        toggleFav(sig, { label: (item.produkt + (item.wymiar ? (' - ' + item.wymiar) : '')), sku: skuRaw || '' });
        renderLines();
      });
      acts.appendChild(fav);

      var del = DOC.createElement('button');
      del.type='button';
      del.className='zq-ico is-danger';
      del.title='Usuń pozycję';
      del.setAttribute('aria-label','Usuń pozycję');
      del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
      del.addEventListener('click', function(){ removeLine(line.id); });
      acts.appendChild(del);

      c7.appendChild(acts);

      row.appendChild(c1);
      row.appendChild(c3);
      row.appendChild(c4);
      row.appendChild(c5);
      row.appendChild(cNetBefore);
      row.appendChild(cNetAfter);
      row.appendChild(cGrossBefore);
      row.appendChild(cGrossAfter);
      row.appendChild(c7);

      list.appendChild(row);
    });

    renderTotals();
  }

  // Sync sheets via proxy (REST)
  var syncInFlight = null;
  function syncAll(forceFresh){
    if (syncInFlight) return syncInFlight;
    setErr('');
    state.lastSyncAttemptAt = Date.now();
    state.lastSyncAttemptErr = null;
    var start = Date.now();
    var url = '/sheets' + (forceFresh ? '?force=1' : '');

    setStatus('Synchronizacja: w toku...');

    syncInFlight = apiFetch(url, {method:'GET'}).then(function(res){
      return res.json().catch(function(){ return null; }).then(function(json){
        if (!res.ok){
          var msg = (json && (json.message || json.error)) ? (json.message || json.error) : ('HTTP ' + res.status);
          throw new Error(msg);
        }
        if (!json) throw new Error('Niepoprawna odpowiedź JSON.');
        // fallback: force sync może zwrócić ok=false + cache
        if (json.ok !== true){
          if (json.cache && json.cache.data){
            setErr((json && json.message) ? String(json.message) : 'Sync błąd - używam ostatniego cache.', 'error');
            json = { ok:true, data: json.cache.data, meta: {
              fetched_at: (json.cache && json.cache.fetched_at) ? json.cache.fetched_at : null,
              data_hash: (json.cache && json.cache.data_hash) ? json.cache.data_hash : null,
              errors: (json.cache && json.cache.errors) ? json.cache.errors : []
            }};
          } else {
            throw new Error((json && json.message) ? String(json.message) : 'Niepoprawna odpowiedź JSON.');
          }
        }
        if (!json.data){
          throw new Error('Brak danych w odpowiedzi.');
        }

        var tabs = getTabs();
        // reset danych, żeby nie mieszać starych zakładek
        state.data = {};
        tabs.forEach(function(name){
          var matrix = json.data[name];
          if (!matrix || !matrix.headers || !matrix.rows){
            // jeśli konto ma ograniczone kategorie, backend zwróci tylko część danych
            return;
          }
          state.data[name] = buildItems(name, matrix);
        });
        // tryb ALL: scalamy do jednego zbioru dla dropdownów
        state.data['__ALL__'] = buildAllMerged();
        state.activeSheet = '__ALL__';

        // indeks wyszukiwania (po sync)
        buildSearchIndexAsync();

        state.syncedAt = new Date();
        var ms = Date.now() - start;
        var meta = json.meta || {};
        var hash = meta.data_hash ? String(meta.data_hash) : '';
        var hashShort = hash ? hash.slice(0, 10) : '';
        var fetched = meta.fetched_at ? String(meta.fetched_at) : '';
        var extra = '';
        if (hashShort) extra += ' | hash: ' + hashShort;
        if (fetched) extra += ' | cache: ' + fetched;
        setStatus('Synchronizacja: OK (' + ms + ' ms) - ' + state.syncedAt.toLocaleString('pl-PL') + extra);
        populateCascades();
        maybeShowDraftBanner();
        if (!state._offerRestored){ restoreOffer(); state._offerRestored = true; }
        renderLines();
      });
    }).catch(function(err){
      state.lastSyncAttemptErr = (err && err.message) ? String(err.message) : 'Błąd synchronizacji.';
      setStatus('Synchronizacja: błąd');
      setErr(state.lastSyncAttemptErr, 'error');
    }).finally(function(){
      syncInFlight = null;
    });

    return syncInFlight;
  }

  // Zapis/Export/Historia
  function buildOfferData(){
    var lines = state.offerLines.map(function(l){
      var c = computeLine(l);
      var isC = isCustomLine(l);
      var mode = isC ? 'unit' : getLineMode(l);
      var tInfo = (!isC && mode !== 'unit' && l && l.item && l.item.tiers && l.item.tiers[mode]) ? l.item.tiers[mode] : null;

      return {
        is_custom: isC,
        custom_kind: isC ? (isTransportLine(l) ? 'transport' : 'custom') : null,
        sheet: isC ? '__CUSTOM__' : (l.item ? l.item.sheet : ''),
        kategoria: (l.item && l.item.kategoria) ? l.item.kategoria : '',
        podkategoria: (l.item && l.item.podkategoria) ? (l.item.podkategoria || '') : '',
        produkt: (l.item && l.item.produkt) ? l.item.produkt : '',
        wymiar: (l.item && l.item.wymiar) ? (l.item.wymiar || '') : '',
        ral: isC ? '' : (l.ral || ''),
        sku: isC ? '' : (getSku(l.item, l.ral) || ''),
        sku_display: isC ? '' : formatSku(getSku(l.item, l.ral)),
        price_mode: mode,
        price_mode_qty: (tInfo && tInfo.qty) ? tInfo.qty : null,
        catalog_unit_net: (l && l.item && typeof l.item.cenaNetto === 'number' && isFinite(l.item.cenaNetto)) ? l.item.cenaNetto : null,
        custom_unit_net: isC ? ((l && l.item && typeof l.item.cenaNetto === 'number' && isFinite(l.item.cenaNetto)) ? l.item.cenaNetto : 0) : null,
        qty: c.qty,
        qty_units: c.units,
        qty_packs: null,
        disc: c.disc,
        unit_net: c.unitNet,
        unit_net_after: c.unitNetAfter,
        net: c.net,
        gross: c.gross,
        // manual prices are only valid in "Oferta specjalna" mode
        manual_unit_net: (state.specialOffer ? l.manualUnitNet : null),
        line_comment: l.lineComment || '',
        transport: isTransportLine(l) ? {
          meta: (l && l.transport) ? l.transport : null,
          summary: (c && c.transport && c.transport.summary) ? c.transport.summary : '',
          km: (c && c.transport && c.transport.km != null) ? c.transport.km : null,
          rate_used: (c && c.transport && c.transport.rate_used != null) ? c.transport.rate_used : null,
          base_net: (c && c.transport && c.transport.base_net != null) ? c.transport.base_net : null,
          extras_total: (c && c.transport && c.transport.extras_total != null) ? c.transport.extras_total : null,
          no_global_disc: (l && l.transport && l.transport.no_global_disc != null) ? !!l.transport.no_global_disc : null
        } : null
      };
    });

    var totals = { net:0, gross:0 };
    lines.forEach(function(x){ totals.net += x.net; totals.gross += x.gross; });

    return {
      vat_rate: getVatRate(),
      special_offer: !!state.specialOffer,
      client: collectClient(),
      lines: lines,
      totals: totals,
      synced_at: state.syncedAt ? state.syncedAt.toISOString() : null,
      validity_days: getValidityDays(),
      seller: getSeller()
    };
  }

function clientFingerprint(c){
  c = (c && typeof c === 'object') ? c : {};
  var id = (c.id != null && c.id !== '') ? String(c.id) : '';
  function t(v){ return (v == null) ? '' : String(v).trim(); }
  return [
    id,
    t(c.full_name),
    t(c.company),
    t(c.nip),
    t(c.phone),
    t(c.email),
    t(c.address)
  ].join('\u001f');
}

function setEditingOfferContext(id, title, clientObj){
  state.editingOffer = {
    id: String(id || ''),
    title: String(title || ''),
    clientFp: clientFingerprint(clientObj || null)
  };
  applyOverwriteUI();
}

function clearEditingOfferContext(){
  state.editingOffer = null;
  applyOverwriteUI();
}

function applyOverwriteUI(){
  var btn = $('zq-overwrite-offer');
  if (!btn) return;
  if (!state.editingOffer || !state.editingOffer.id){
    btn.style.display = 'none';
    btn.disabled = true;
    btn.title = '';
    return;
  }
  btn.style.display = '';
  updateOverwriteAvailability();
}

function updateOverwriteAvailability(){
  var btn = $('zq-overwrite-offer');
  if (!btn) return;
  if (!state.editingOffer || !state.editingOffer.id){
    btn.disabled = true;
    return;
  }

  var titleEl = $('zq-offer-title');
  var curTitle = titleEl && titleEl.value ? titleEl.value.trim() : '';
  var baseTitle = (state.editingOffer.title || '').trim();

  var curClientFp = clientFingerprint(collectClient());
  var baseClientFp = String(state.editingOffer.clientFp || '');

  var okTitle = (curTitle === baseTitle);
  var okClient = (curClientFp === baseClientFp);

  if (okTitle && okClient){
    btn.disabled = false;
    btn.title = 'Nadpisze wybraną ofertę w historii (bez tworzenia nowej).';
  } else {
    btn.disabled = true;
    var why = [];
    if (!okTitle) why.push('zmieniono tytuł');
    if (!okClient) why.push('zmieniono dane klienta');
    btn.title = 'Nie można nadpisać: ' + why.join(' i ') + '. Zapisz jako nową ofertę.';
  }
}

function overwriteOffer(){
  setExportStatus('');
  if (!state.editingOffer || !state.editingOffer.id){
    setErr('Brak wybranej oferty do nadpisania.');
    return;
  }

  var title = ($('zq-offer-title') && $('zq-offer-title').value) ? $('zq-offer-title').value.trim() : '';
  var comment = ($('zq-offer-comment') && $('zq-offer-comment').value) ? $('zq-offer-comment').value.trim() : '';
  if (!title){
    setErr('Nazwa kalkulacji jest wymagana.');
    return;
  }
  if (!state.offerLines.length){
    setErr('Brak pozycji do zapisania.');
    return;
  }

  var st = requireSelectedOfferStatus();
  if (!st) return;

  updateOverwriteAvailability();
  var btn = $('zq-overwrite-offer');
  if (btn && btn.disabled){
    setErr('Nie można nadpisać: zmieniono tytuł lub dane klienta. Zapisz jako nową ofertę.');
    return;
  }

  setErr('');
  setExportStatus('Nadpisuję...');
  rememberOfferStatus(st);

  var id = String(state.editingOffer.id);
  apiFetch('/offers/' + encodeURIComponent(id), { method:'PUT', json: { title: title, comment: comment, status: st, data: buildOfferData() } }).then(function(r){
    return r.json().catch(function(){return null;}).then(function(j){
      if (!r.ok || !j || !j.ok){
        throw new Error((j && j.message) ? j.message : 'Błąd nadpisania.');
      }
      setExportStatus('OK: nadpisano "' + (j.title || title) + '"');
      clearDraft();
      refreshHistory();
    });
  }).catch(function(e){
    setExportStatus('Błąd: ' + (e && e.message ? e.message : 'overwrite'));
  });
}

  function saveOffer(){
    setExportStatus('');
    var title = ($('zq-offer-title') && $('zq-offer-title').value) ? $('zq-offer-title').value.trim() : '';
    var comment = ($('zq-offer-comment') && $('zq-offer-comment').value) ? $('zq-offer-comment').value.trim() : '';
    if (!title){
      setErr('Nazwa kalkulacji jest wymagana.');
      return;
    }
    if (!state.offerLines.length){
      setErr('Brak pozycji do zapisania.');
      return;
    }

    var st = requireSelectedOfferStatus();
    if (!st) return;

    setErr('');
    setExportStatus('Zapisuję...');
    rememberOfferStatus(st);
    apiFetch('/offers', { json: { title: title, comment: comment, status: st, data: buildOfferData() } }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok){
          throw new Error((j && j.message) ? j.message : 'Błąd zapisu.');
        }
        setExportStatus('OK: zapisano "' + j.title + '"');
        clearDraft();
        refreshHistory();
      });
    }).catch(function(e){
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'save'));
    });
  }

  function openOfferStatusModal(offer){
    if (!offer || !offer.id) return;
    var modal = $('zq-offer-status-modal');
    if (!modal) return;

    var idEl = $('zq-osm-offer-id');
    if (idEl) idEl.value = String(offer.id);

    var sub = $('zq-osm-sub');
    if (sub){
      var parts = [];
      if (offer.title) parts.push(String(offer.title));
      if (offer.created_at) parts.push('Utw.: ' + String(offer.created_at));
      if (offer.updated_at) parts.push('Edyt.: ' + String(offer.updated_at));
      sub.textContent = parts.join(' | ');
    }

    var sel = $('zq-osm-status');
    if (sel){
      fillStatusSelect(sel, offer.status, true);
      sel.disabled = false;
    }

    var err = $('zq-osm-err');
    if (err) err.textContent = '';

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    try{ if (sel) sel.focus(); }catch(e){}
  }

  function closeOfferStatusModal(){
    var modal = $('zq-offer-status-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function saveOfferStatusModal(){
    var id = $('zq-osm-offer-id') ? $('zq-osm-offer-id').value : '';
    id = String(id || '').replace(/[^0-9]/g, '');

    var sel = $('zq-osm-status');
    var next = normOfferStatus(sel ? sel.value : '');
    var err = $('zq-osm-err');

    if (!id){
      if (err) err.textContent = 'Błąd: brak ID oferty.';
      return;
    }
    if (!next || next === 'unset'){
      if (err) err.textContent = 'Wybierz status.';
      return;
    }
    if (err) err.textContent = '';

    if (sel) sel.disabled = true;
    updateOfferStatus(id, next).then(function(ok){
      if (sel) sel.disabled = false;
      if (ok){
        closeOfferStatusModal();
        renderHistory();
        renderHistoryMini();
      } else {
        if (err) err.textContent = 'Nie udało się zapisać statusu.';
      }
    });
  }


  function openSalesNoteModal(offer){
    if (!offer || !offer.id) return;
    var modal = $('zq-sales-note-modal');
    if (!modal) return;

    if (!state._snm) state._snm = { open:false, id:null, dirty:false, nonce:0 };
    state._snm.open = true;
    state._snm.id = String(offer.id);
    state._snm.dirty = false;
    state._snm.nonce = (state._snm.nonce || 0) + 1;
    var nonce = state._snm.nonce;

    var idEl = $('zq-snm-offer-id');
    if (idEl) idEl.value = state._snm.id;

    var sub = $('zq-snm-sub');
    if (sub){
      var parts = [];
      if (offer.title) parts.push(String(offer.title));
      if (offer.created_at) parts.push('Utw.: ' + String(offer.created_at));
      if (offer.updated_at) parts.push('Edyt.: ' + String(offer.updated_at));
      sub.textContent = parts.join(' | ');
    }

    var ta = $('zq-snm-note');
    if (ta){
      ta.value = plainFromHtml(offer.sales_note || '');
      ta.disabled = false;
    }

    var err = $('zq-snm-err');
    if (err) err.textContent = '';

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    try{ if (ta) ta.focus(); }catch(e){}

    // Pobierz świeżą wartość (best-effort), bez nadpisywania jeśli użytkownik już edytuje.
    apiFetch('/offers/' + encodeURIComponent(state._snm.id) + '/sales-note', { method:'GET' }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok) return;
        if (!state._snm || !state._snm.open) return;
        if (state._snm.nonce !== nonce) return;
        if (state._snm.dirty) return;
        if (String(j.id) !== String(state._snm.id)) return;
        try{ if (ta) ta.value = String(j.sales_note || ''); }catch(e){}
      });
    }).catch(function(){});
  }

  function closeSalesNoteModal(){
    var modal = $('zq-sales-note-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (state._snm){
      state._snm.open = false;
      state._snm.id = null;
      state._snm.dirty = false;
    }
  }

  function saveSalesNoteModal(){
    var id = $('zq-snm-offer-id') ? $('zq-snm-offer-id').value : '';
    id = String(id || '').replace(/[^0-9]/g, '');

    var ta = $('zq-snm-note');
    var note = ta ? String(ta.value || '') : '';
    var err = $('zq-snm-err');

    if (!id){
      if (err) err.textContent = 'Błąd: brak ID oferty.';
      return;
    }
    if (err) err.textContent = '';

    var saveBtn = $('zq-snm-save');
    if (ta) ta.disabled = true;
    if (saveBtn) saveBtn.disabled = true;

    apiFetch('/offers/' + encodeURIComponent(id) + '/sales-note', { method:'PUT', json:{ sales_note: note } }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (ta) ta.disabled = false;
        if (saveBtn) saveBtn.disabled = false;

        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
          if (err) err.textContent = msg;
          return;
        }

        // update lokalnego cache
        try{
          var arr = Array.isArray(state.offers) ? state.offers : [];
          for (var i=0;i<arr.length;i++){
            if (arr[i] && String(arr[i].id) === String(id)){
              arr[i].sales_note = j.sales_note || '';
              break;
            }
          }
        }catch(e){}

        closeSalesNoteModal();
        renderHistory();
        renderHistoryMini();
      });
    }).catch(function(){
      if (ta) ta.disabled = false;
      if (saveBtn) saveBtn.disabled = false;
      if (err) err.textContent = 'Błąd: network';
    });
  }


  // Modal: potwierdzenie (działa w iframe sandbox - bez confirm())
  function initConfirmModal(){
    if (!state._confirm) state._confirm = { inited:false, open:false, resolve:null, opts:null };
    if (state._confirm.inited) return;
    state._confirm.inited = true;

    var modal = $('zq-confirm-modal');
    if (!modal) return;

    function close(result){
      try{
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
      }catch(e){}
      var r = state._confirm.resolve;
      state._confirm.resolve = null;
      state._confirm.open = false;
      state._confirm.opts = null;
      if (typeof r === 'function'){
        try{ r(!!result); }catch(e){}
      }
    }

    var btnClose = $('zq-confirm-close');
    var btnCancel = $('zq-confirm-cancel');
    var btnOk = $('zq-confirm-ok');

    function onCancel(ev){
      if (ev && ev.preventDefault) ev.preventDefault();
      close(false);
    }
    function onOk(ev){
      if (ev && ev.preventDefault) ev.preventDefault();
      close(true);
    }

    if (btnClose) btnClose.addEventListener('click', onCancel);
    if (btnCancel) btnCancel.addEventListener('click', onCancel);
    if (btnOk) btnOk.addEventListener('click', onOk);

    // klik na tło NIE zamyka (zamknięcie tylko przyciskami/ESC)

    // ESC zamyka, Enter = potwierdza (gdy focus w modalu)
    DOC.addEventListener('keydown', function(ev){
      if (!state._confirm || !state._confirm.open) return;
      if (!ev) return;
      if (ev.key === 'Escape'){
        ev.preventDefault();
        onCancel(ev);
      }
      if (ev.key === 'Enter'){
        // gdy focus nie jest w textarea - zatwierdź
        try{
          var a = DOC.activeElement;
          var tag = a && a.tagName ? String(a.tagName).toUpperCase() : '';
          if (tag !== 'TEXTAREA'){
            ev.preventDefault();
            onOk(ev);
          }
        }catch(e){}
      }
    }, true);
  }

  function zqConfirm(opts){
    opts = (opts && typeof opts === 'object') ? opts : {};
    var title = (opts.title != null) ? String(opts.title) : 'Potwierdź';
    var sub = (opts.sub != null) ? String(opts.sub) : '';
    var msg = (opts.message != null) ? String(opts.message) : 'Czy na pewno?';
    var okText = (opts.okText != null) ? String(opts.okText) : 'OK';
    var cancelText = (opts.cancelText != null) ? String(opts.cancelText) : 'Anuluj';
    var okClass = (opts.okClass != null) ? String(opts.okClass) : 'primary';

    initConfirmModal();

    var modal = $('zq-confirm-modal');
    var titleEl = $('zq-confirm-title');
    var subEl = $('zq-confirm-sub');
    var msgEl = $('zq-confirm-msg');
    var errEl = $('zq-confirm-err');
    var btnOk = $('zq-confirm-ok');
    var btnCancel = $('zq-confirm-cancel');

    if (titleEl) titleEl.textContent = title;
    if (subEl) subEl.textContent = sub;
    if (msgEl) msgEl.textContent = msg;
    if (errEl){ errEl.textContent = ''; errEl.style.display = 'none'; }
    if (btnCancel) btnCancel.textContent = cancelText;
    if (btnOk){
      btnOk.textContent = okText;
      btnOk.className = 'zq-btn ' + okClass;
    }

    return new Promise(function(resolve){
      if (!modal){
        resolve(false);
        return;
      }
      if (!state._confirm) state._confirm = { inited:false, open:false, resolve:null, opts:null };
      state._confirm.open = true;
      state._confirm.resolve = resolve;
      state._confirm.opts = { title:title, sub:sub, message:msg };

      try{
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
      }catch(e){}

      try{
        if (btnOk) btnOk.focus();
        else if (btnCancel) btnCancel.focus();
      }catch(e){}
    });
  }



  // Modal: oferty wymagające aktualizacji (pokazywany przy starcie panelu gdy są takie oferty)
  function initNeedsUpdateModal(){
    if (!state._num) state._num = { inited:false, open:false };
    if (state._num.inited) return;
    state._num.inited = true;

    var modal = $('zq-needs-update-modal');
    if (!modal) return;

    function close(){
      try{
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden','true');
      }catch(e){}
      state._num.open = false;
    }

    function showInHistory(){
      // Zamknij modal i przenieś do historii z filtrem "Wymaga zaktualizowania".
      // Uwaga: render historii opiera się o state.historyStatusFilter ustawiany w handlerze "change".
      // Samo przypisanie hf.value nie wystarcza.

      close();

      var run = function(){
        var hf = $('zq-history-status-filter');
        var det = $('zq-history-details');
        if (det && typeof det.open !== 'undefined') det.open = true;

        if (hf){
          hf.value = 'needs_update';
          try{
            hf.dispatchEvent(new Event('change', { bubbles:true }));
          }catch(e){
            // starsze przeglądarki
            try{ var ev = DOC.createEvent('Event'); ev.initEvent('change', true, true); hf.dispatchEvent(ev); }catch(e2){}
          }
        } else {
          // fallback: ustaw stan bez selekta
          try{ state.historyStatusFilter = normHistoryFilter('needs_update'); persistHistoryPrefs(); renderHistory(); renderHistoryMini(); }catch(e){}
        }

        // przewiń do historii (kotwica w DOM)
        var anchor = $('zq-refresh-history') || $('zq-history-details') || $('zq-history') || $('zq-history-mini');
        if (anchor && anchor.scrollIntoView){
          try{ anchor.scrollIntoView({ behavior:'smooth', block:'start' }); }
          catch(e){ try{ anchor.scrollIntoView(true); }catch(e2){} }
        }

        // fokus na filtr statusu
        if (hf && hf.focus){
          try{ hf.focus({ preventScroll:true }); }catch(e){ try{ hf.focus(); }catch(e2){} }
        }
      };

      // Daj DOMowi chwilę na zdjęcie overlay/scroll-lock po zamknięciu modala
      try{ setTimeout(run, 80); }catch(e){ try{ run(); }catch(e2){} }
    }

    var btnClose = $('zq-num-close');
    var btnOk = $('zq-num-ok');
    var btnShow = $('zq-num-show');

    if (btnClose) btnClose.addEventListener('click', function(ev){ try{ ev.preventDefault(); }catch(e){} close(); });
    if (btnOk) btnOk.addEventListener('click', function(ev){ try{ ev.preventDefault(); }catch(e){} close(); });
    if (btnShow) btnShow.addEventListener('click', function(ev){ try{ ev.preventDefault(); }catch(e){} showInHistory(); });

    // klik na tło NIE zamyka
    DOC.addEventListener('keydown', function(ev){
      if (!state._num || !state._num.open) return;
      if (!ev) return;
      if (ev.key === 'Escape'){
        ev.preventDefault();
        close();
      }
    }, true);
  }

  function openNeedsUpdateModal(offers){
    initNeedsUpdateModal();
    var modal = $('zq-needs-update-modal');
    if (!modal) return;

    offers = Array.isArray(offers) ? offers : [];
    var listEl = $('zq-num-list');
    if (listEl){
      while (listEl.firstChild) listEl.removeChild(listEl.firstChild);

      if (!offers.length){
        listEl.textContent = 'Brak ofert.';
      } else {
        var ul = DOC.createElement('ul');
        ul.style.margin = '0';
        ul.style.paddingLeft = '18px';
        ul.style.lineHeight = '1.45';

        offers.slice(0, 20).forEach(function(o){
          var li = DOC.createElement('li');
          var title = (o && o.title) ? String(o.title) : ('Oferta #' + String(o && o.id ? o.id : ''));
          var ua = (o && o.updated_at) ? String(o.updated_at) : '';
          li.textContent = ua ? (title + ' (ostatnia edycja: ' + ua + ')') : title;
          ul.appendChild(li);
        });

        if (offers.length > 20){
          var liMore = DOC.createElement('li');
          liMore.textContent = '... i jeszcze ' + String(offers.length - 20) + ' ofert';
          ul.appendChild(liMore);
        }

        listEl.appendChild(ul);
      }
    }

    state._num.open = true;
    try{
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden','false');
    }catch(e){}

    try{
      var btn = $('zq-num-ok');
      if (btn) btn.focus();
    }catch(e){}
  }

  function maybeShowNeedsUpdatePopupOnBoot(){
    // pokazuj przy każdym wejściu do panelu (jednorazowo na sesję panelu)
    if (state._numShownThisBoot) return;
    state._numShownThisBoot = true;

    var arr = Array.isArray(state.offers) ? state.offers : [];
    var bad = arr.filter(function(o){ return normOfferStatus(o && o.status) === 'needs_update'; });
    if (bad.length){
      openNeedsUpdateModal(bad);
    }
  }

  function statusSortRank(st){
    st = normOfferStatus(st);
    var order = { new:10, sent:20, in_progress:30, won:40, lost:50, canceled:60, unset:99 };
    return Object.prototype.hasOwnProperty.call(order, st) ? order[st] : 99;
  }

  function getHistoryViewList(){
    var all = Array.isArray(state.offers) ? state.offers.slice() : [];
    var q = (state.historyQuery || '').trim().toLowerCase();
    var filt = normHistoryFilter(state.historyStatusFilter);

    var list = [];
    if (q){
      for (var i=0;i<all.length;i++){
        var t = all[i] && all[i].title ? String(all[i].title) : '';
        if (t.toLowerCase().indexOf(q) >= 0) list.push(all[i]);
      }
    } else {
      list = all.slice();
    }

    if (filt !== 'all'){
      list = list.filter(function(o){
        return normOfferStatus(o && o.status) === filt;
      });
    }

    var sort = normHistorySort(state.historySort);
    list.sort(function(a,b){
      var ad = (a && a.created_at) ? String(a.created_at) : '';
      var bd = (b && b.created_at) ? String(b.created_at) : '';
      var aid = (a && a.id != null) ? parseInt(a.id, 10) : 0;
      var bid = (b && b.id != null) ? parseInt(b.id, 10) : 0;
      var at = (a && a.title) ? String(a.title) : '';
      var bt = (b && b.title) ? String(b.title) : '';
      var asu = (a && a.status_updated_at) ? String(a.status_updated_at) : '';
      var bsu = (b && b.status_updated_at) ? String(b.status_updated_at) : '';

      if (sort === 'oldest'){
        if (ad != bd) return ad < bd ? -1 : 1;
        return aid - bid;
      }
      if (sort === 'title_asc'){
        var c = at.localeCompare(bt, 'pl', { sensitivity:'base' });
        if (c) return c;
        return bid - aid;
      }
      if (sort === 'title_desc'){
        var c2 = bt.localeCompare(at, 'pl', { sensitivity:'base' });
        if (c2) return c2;
        return bid - aid;
      }
      if (sort === 'status'){
        var ar = statusSortRank(a && a.status);
        var br = statusSortRank(b && b.status);
        if (ar != br) return ar - br;
        if (ad != bd) return ad < bd ? 1 : -1;
        return bid - aid;
      }
      if (sort === 'status_updated'){
        if (asu != bsu) return asu < bsu ? 1 : -1;
        if (ad != bd) return ad < bd ? 1 : -1;
        return bid - aid;
      }

      // newest
      if (ad != bd) return ad < bd ? 1 : -1;
      return bid - aid;
    });

    return { all: all, list: list, query: q, filter: filt, sort: sort };
  }

  


  function needsUpdateIcon(){
    return '<span class="zq-needicon" title="Oferta wymaga zaktualizowania" aria-label="Oferta wymaga zaktualizowania"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.3h3.4l8.2 14.2A2 2 0 0 1 20.2 20H3.8a2 2 0 0 1-1.7-2.5l8.2-14.2a2 2 0 0 1 1.8-1z"/></svg></span>';
  }

  function lockSvg(locked){
    if (locked){
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.8-1"/></svg>';
  }

  function makeOfferLockButton(o){
    var locked = isOfferLocked(o);
    var btn = DOC.createElement('button');
    btn.type = 'button';
    btn.className = 'zq-lockbtn ' + (locked ? 'is-locked' : 'is-unlocked');
    btn.innerHTML = lockSvg(locked);
    btn.setAttribute('aria-label', locked ? 'Odblokuj ofertę' : 'Zablokuj ofertę');

    var can = canLockOffers();
    var isFinal = isFinalOfferStatus(o && o.status);
    var isSA = actorIsSuperAdmin();

    if (!can){
      btn.disabled = true;
      btn.title = locked ? 'Oferta zablokowana (brak uprawnień do zmiany)' : 'Oferta odblokowana (brak uprawnień do zmiany)';
      return btn;
    }

    if (locked && isFinal && !isSA){
      btn.disabled = true;
      btn.title = 'Status końcowy - tylko Super Admin może odblokować';
      return btn;
    }

    btn.title = locked ? 'Kliknij, aby odblokować' : 'Kliknij, aby zablokować';

    btn.addEventListener('click', function(ev){
      try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
      toggleOfferLock(o.id, !locked);
    });

    return btn;
  }


  function historySvg(){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v5l3 2"/><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>';
  }

  function makeOfferHistoryButton(o){
    var btn = DOC.createElement('button');
    btn.type = 'button';
    btn.className = 'zq-histbtn';
    btn.innerHTML = historySvg();
    btn.setAttribute('aria-label', 'Historia zmian oferty');
    btn.title = 'Historia zmian oferty';

    btn.addEventListener('click', function(ev){
      try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
      openOfferHistoryModal(o);
    });

    return btn;
  }


function previewSvg(){
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>';
}

function offerNetForList(o){
  if (!o) return null;
  var d = parseNumber(o.total_net_display);
  if (d != null && isFinite(d)) return d;

  var has = (o.has_discount === 1 || o.has_discount === '1' || o.has_discount === true);
  var a = parseNumber(o.total_net_after);
  var b = parseNumber(o.total_net_before);

  if (has){
    if (a != null && isFinite(a)) return a;
    if (b != null && isFinite(b)) return b;
    return null;
  }
  if (b != null && isFinite(b)) return b;
  if (a != null && isFinite(a)) return a;
  return null;
}

function makeOfferQuickPreviewButton(o){
  var btn = DOC.createElement('button');
  btn.type = 'button';
  btn.className = 'zq-qvbtn';
  btn.innerHTML = previewSvg();
  btn.setAttribute('aria-label', 'Szybki podgląd oferty');
  btn.title = 'Szybki podgląd oferty';

  var amt = DOC.createElement('span');
  amt.className = 'zq-qvamt';
  var n = offerNetForList(o);
  if (n == null){
    amt.textContent = '—';
    amt.classList.add('is-muted');
  } else {
    amt.textContent = toMoney(n);
  }
  btn.appendChild(amt);

  btn.addEventListener('click', function(ev){
    try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
    openOfferPreviewModal(o);
  });

  return btn;
}

function openOfferPreviewModal(offer){
  if (!offer || !offer.id) return;
  var modal = $('zq-offer-preview-modal');
  if (!modal) return;

  if (!state._qvm) state._qvm = { open:false, id:null, nonce:0 };
  state._qvm.open = true;
  state._qvm.id = String(offer.id);
  state._qvm.nonce = (state._qvm.nonce || 0) + 1;
  var nonce = state._qvm.nonce;

  var idEl = $('zq-qvm-offer-id');
  if (idEl) idEl.value = state._qvm.id;

  var sub = $('zq-qvm-sub');
  if (sub){
    var parts = [];
    if (offer.title) parts.push(String(offer.title));
    if (offer.created_at) parts.push('Utw.: ' + String(offer.created_at));
    if (offer.updated_at) parts.push('Edyt.: ' + String(offer.updated_at));
    sub.textContent = parts.join(' | ');
  }

  var err = $('zq-qvm-err');
  if (err) err.textContent = '';

  var body = $('zq-qvm-body');
  if (body){
    fastClear(body);
    var p = DOC.createElement('div');
    p.className = 'zq-muted';
    p.textContent = 'Ładuję podgląd...';
    body.appendChild(p);
  }

  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');

  apiFetch('/offers/' + encodeURIComponent(state._qvm.id) + '/preview', { method:'GET' }).then(function(r){
    return r.json().catch(function(){return null;}).then(function(j){
      if (!state._qvm || !state._qvm.open) return;
      if (state._qvm.nonce !== nonce) return;

      if (!r.ok || !j || !j.ok || !j.preview){
        var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
        if (err) err.textContent = msg;
        if (body) fastClear(body);
        return;
      }

      renderOfferPreview(j.preview);
    });
  }).catch(function(){
    if (!state._qvm || !state._qvm.open) return;
    if (state._qvm.nonce !== nonce) return;
    if (err) err.textContent = 'Błąd: network';
    if (body) fastClear(body);
  });

  try{ if ($('zq-qvm-ok')) $('zq-qvm-ok').focus(); }catch(e){}
}

function closeOfferPreviewModal(){
  var modal = $('zq-offer-preview-modal');
  if (!modal) return;
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden', 'true');
  if (state._qvm){
    state._qvm.open = false;
    state._qvm.id = null;
  }
}

function renderOfferPreview(preview){
  var body = $('zq-qvm-body');
  if (!body) return;
  fastClear(body);

  var grid = DOC.createElement('div');
  grid.className = 'zq-qvm-grid';

  function kvText(k, v){
    var box = DOC.createElement('div');
    box.className = 'zq-qvm-kv';
    var kk = DOC.createElement('div');
    kk.className = 'k';
    kk.textContent = k;
    var vv = DOC.createElement('div');
    vv.className = 'v';
    vv.textContent = (v != null && String(v).trim() !== '') ? String(v) : '—';
    box.appendChild(kk);
    box.appendChild(vv);
    return box;
  }

  function kvNode(k, node){
    var box = DOC.createElement('div');
    box.className = 'zq-qvm-kv';
    var kk = DOC.createElement('div');
    kk.className = 'k';
    kk.textContent = k;
    var vv = DOC.createElement('div');
    vv.className = 'v';
    if (node) vv.appendChild(node);
    else vv.textContent = '—';
    box.appendChild(kk);
    box.appendChild(vv);
    return box;
  }

  // Status
  var st = normOfferStatus(preview && preview.status);
  var stBadge = makeStatusBadge(st);
  grid.appendChild(kvNode('Status', stBadge));

  // Klient
  var cl = (preview && preview.client && typeof preview.client === 'object') ? preview.client : null;
  var clLabel = '';
  if (cl){
    clLabel = (cl.company && String(cl.company).trim()) ? String(cl.company).trim() : ((cl.full_name && String(cl.full_name).trim()) ? String(cl.full_name).trim() : '');
    var nip = (cl.nip && String(cl.nip).trim()) ? String(cl.nip).trim() : '';
    if (nip) clLabel += (clLabel ? ' | NIP: ' : 'NIP: ') + nip;
  }
  grid.appendChild(kvText('Klient', clLabel || '—'));

  // Pozycje
  var lc = (preview && preview.lines_count != null) ? String(preview.lines_count) : '0';
  grid.appendChild(kvText('Pozycji', lc));

  // Ważność
  var vd = (preview && preview.validity_days != null) ? (String(preview.validity_days) + ' dni') : '—';
  grid.appendChild(kvText('Ważność', vd));

  // Handlowiec
  var seller = (preview && preview.seller && typeof preview.seller === 'object') ? preview.seller : null;
  var sLabel = '';
  if (seller){
    sLabel = (seller.name && String(seller.name).trim()) ? String(seller.name).trim() : '';
    if (!sLabel && seller.login) sLabel = String(seller.login);
    if (seller.branch) sLabel += (sLabel ? (' | ' + String(seller.branch)) : String(seller.branch));
  }
  grid.appendChild(kvText('Handlowiec', sLabel || '—'));

  // Kwoty netto
  var tot = (preview && preview.totals && typeof preview.totals === 'object') ? preview.totals : null;
  var nb = tot ? parseNumber(tot.net_before) : null;
  var na = tot ? parseNumber(tot.net_after) : null;
  var hd = tot ? !!tot.has_discount : false;

  if (hd){
    grid.appendChild(kvText('Razem netto (po rabacie)', (na != null && isFinite(na)) ? toMoney(na) : ((nb != null && isFinite(nb)) ? toMoney(nb) : '—')));
  } else {
    grid.appendChild(kvText('Razem netto', (nb != null && isFinite(nb)) ? toMoney(nb) : ((na != null && isFinite(na)) ? toMoney(na) : '—')));
  }

  // Daty
  grid.appendChild(kvText('Utworzono', (preview && preview.created_at) ? String(preview.created_at) : '—'));
  grid.appendChild(kvText('Ostatnia edycja', (preview && preview.updated_at) ? String(preview.updated_at) : '—'));

  body.appendChild(grid);

  // Lista pozycji (pierwsze 8)
  var lines = (preview && Array.isArray(preview.lines_preview)) ? preview.lines_preview : [];
  if (lines && lines.length){
    var box2 = DOC.createElement('div');
    box2.className = 'zq-qvm-lines';
    var h = DOC.createElement('div');
    h.className = 'h';
    var more = '';
    try{
      var total = parseInt(preview.lines_count, 10) || lines.length;
      if (total > lines.length) more = ' (+' + String(total - lines.length) + ')';
    }catch(e){}
    h.textContent = 'Pozycje - podgląd' + more;
    box2.appendChild(h);

    lines.forEach(function(L){
      var row = DOC.createElement('div');
      row.className = 'li';
      var nm = DOC.createElement('div');
      nm.className = 'nm';
      nm.textContent = L && L.label ? String(L.label) : 'Pozycja';
      var qt = DOC.createElement('div');
      qt.className = 'qt';

      var qtyLine = DOC.createElement('div');
      var qv = (L && L.qty != null) ? String(L.qty) : '1';
      var disc = (L && L.disc != null && parseNumber(L.disc) > 0) ? (' | rabat ' + String(L.disc) + '%') : '';
      qtyLine.textContent = 'Ilość: ' + qv + disc;
      qt.appendChild(qtyLine);

      var valLine = DOC.createElement('div');
      valLine.className = 'qv';
      var lv = (L && L.net_display != null && isFinite(parseNumber(L.net_display))) ? toMoney(parseNumber(L.net_display)) : '—';
      var lh = !!(L && L.has_discount);
      valLine.textContent = (lh ? 'Wartość netto (po rabacie): ' : 'Wartość netto: ') + lv;
      qt.appendChild(valLine);

      row.appendChild(nm);
      row.appendChild(qt);
      box2.appendChild(row);
    });

    body.appendChild(box2);
  }
}

  function openOfferHistoryModal(offer){
    if (!offer || !offer.id) return;
    var modal = $('zq-offer-history-modal');
    if (!modal) return;

    if (!state._ohm) state._ohm = { open:false, id:null, nonce:0 };
    state._ohm.open = true;
    state._ohm.id = String(offer.id);
    state._ohm.nonce = (state._ohm.nonce || 0) + 1;
    var nonce = state._ohm.nonce;

    var idEl = $('zq-ohm-offer-id');
    if (idEl) idEl.value = state._ohm.id;

    var sub = $('zq-ohm-sub');
    if (sub){
      var parts = [];
      if (offer.title) parts.push(String(offer.title));
      if (offer.created_at) parts.push('Utw.: ' + String(offer.created_at));
      if (offer.updated_at) parts.push('Edyt.: ' + String(offer.updated_at));
      sub.textContent = parts.join(' | ');
    }

    var err = $('zq-ohm-err');
    if (err) err.textContent = '';

    var listEl = $('zq-ohm-list');
    if (listEl){
      while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
      var p = DOC.createElement('div');
      p.className = 'zq-muted';
      p.textContent = 'Ładuję historię...';
      listEl.appendChild(p);
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    // Fetch
    apiFetch('/offers/' + encodeURIComponent(state._ohm.id) + '/history', { method:'GET' }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!state._ohm || !state._ohm.open) return;
        if (state._ohm.nonce !== nonce) return;

        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
          if (err) err.textContent = msg;
          if (listEl){
            while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
          }
          return;
        }

        var hist = Array.isArray(j.history) ? j.history : [];
        renderOfferHistoryList(hist);
      });
    }).catch(function(e){
      if (!state._ohm || !state._ohm.open) return;
      if (state._ohm.nonce !== nonce) return;
      if (err) err.textContent = 'Błąd: network';
      if (listEl){
        while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
      }
    });

    try{ if ($('zq-ohm-ok')) $('zq-ohm-ok').focus(); }catch(e){}
  }

  function closeOfferHistoryModal(){
    var modal = $('zq-offer-history-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (state._ohm){
      state._ohm.open = false;
      state._ohm.id = null;
    }
  }

  function offerStatusLabel(st){
    st = normOfferStatus(st);
    if (OFFER_STATUS_META && OFFER_STATUS_META[st] && OFFER_STATUS_META[st].label) return String(OFFER_STATUS_META[st].label);
    return st || '';
  }

  function historyEventTitle(row){
    var ev = row && row.event ? String(row.event) : '';
    var meta = (row && row.meta && typeof row.meta === 'object') ? row.meta : null;

    if (ev === 'offer_saved') return 'Utworzono ofertę';
    if (ev === 'offer_overwritten') return 'Edytowano ofertę (nadpisanie)';
    if (ev === 'offer_sales_note_updated') return 'Zmieniono notatkę handlową';
    if (ev === 'offer_duplicated') return 'Utworzono kopię oferty';
    if (ev === 'offer_deleted') return 'Usunięto ofertę';
    if (ev === 'offer_exported') return 'Wyeksportowano PDF i zapisano ofertę';
    if (ev === 'offer_pdf_exported') return 'Wyeksportowano PDF';
    if (ev === 'offer_export_deduped') return 'Eksport odrzucony (duplikat)';
    if (ev === 'offer_exported_status_unset') return 'Eksport: status był "Brak statusu"';
    if (ev === 'offer_lock_toggled'){
      var locked = meta && (meta.locked == 1 || meta.locked === true);
      return locked ? 'Zablokowano ofertę' : 'Odblokowano ofertę';
    }
    if (ev === 'offer_status_changed'){
      var oldSt = meta ? meta.old : null;
      var newSt = meta ? meta.new : null;
      return 'Zmieniono status: ' + offerStatusLabel(oldSt) + ' → ' + offerStatusLabel(newSt);
    }
    if (ev === 'offer_marked_needs_update'){
      var o = meta ? meta.old : null;
      return 'System: oznaczono jako "Wymaga zaktualizowania" (brak edycji 72h)' + (o ? (' [poprzednio: ' + offerStatusLabel(o) + ']') : '');
    }

    return 'Zdarzenie: ' + ev;
  }


  function renderHistoryEventTitleEl(el, row){
    if (!el) return;
    while (el.firstChild) el.removeChild(el.firstChild);

    var ev = row && row.event ? String(row.event) : '';
    var meta = (row && row.meta && typeof row.meta === 'object') ? row.meta : null;

    if (ev === 'offer_status_changed'){
      var oldSt = meta ? meta.old : null;
      var newSt = meta ? meta.new : null;
      el.appendChild(DOC.createTextNode('Zmieniono status: '));
      el.appendChild(makeStatusBadge(oldSt));
      el.appendChild(DOC.createTextNode(' → '));
      el.appendChild(makeStatusBadge(newSt));
      return;
    }

    if (ev === 'offer_marked_needs_update'){
      var prev = meta ? meta.old : null;
      el.appendChild(DOC.createTextNode('System: oznaczono jako '));
      el.appendChild(makeStatusBadge('needs_update'));
      el.appendChild(DOC.createTextNode(' (brak edycji 72h)'));
      if (prev){
        el.appendChild(DOC.createTextNode(' [poprzednio: '));
        el.appendChild(makeStatusBadge(prev));
        el.appendChild(DOC.createTextNode(']'));
      }
      return;
    }

    el.textContent = historyEventTitle(row);
  }

  function historyEventSub(row){
    var meta = (row && row.meta && typeof row.meta === 'object') ? row.meta : null;
    var parts = [];

    // kto wykonał
    var who = (row && row.account_login) ? String(row.account_login) : '';
    var actor = meta && meta._actor_login ? String(meta._actor_login) : '';
    if (meta && meta.system) who = 'System';
    if (actor && who && actor !== who){
      parts.push('Aktor: ' + actor + ' (jako: ' + who + ')');
    } else if (actor){
      parts.push('Aktor: ' + actor);
    } else if (who){
      parts.push('Konto: ' + who);
    }

    // detale meta (tylko wybrane)
    if (meta){
      if (meta.len != null) parts.push('Długość notatki: ' + String(meta.len));
      if (meta.src_id != null) parts.push('Źródło: #' + String(meta.src_id));
      if (meta.reason) parts.push('Powód: ' + String(meta.reason));
    }

    return parts.join(' | ');
  }

  function renderOfferHistoryList(hist){
    var listEl = $('zq-ohm-list');
    if (!listEl) return;
    while (listEl.firstChild) listEl.removeChild(listEl.firstChild);

    if (!hist || !hist.length){
      var p = DOC.createElement('div');
      p.className = 'zq-muted';
      p.textContent = 'Brak historii dla tej oferty.';
      listEl.appendChild(p);
      return;
    }

    var rows = hist.slice();
    rows.forEach(function(row, idx){
      var it = DOC.createElement('div');
      it.className = 'zq-tl-item';

      var num = DOC.createElement('div');
      num.className = 'zq-tl-num';
      num.textContent = String(idx + 1);
      it.appendChild(num);

      var time = DOC.createElement('div');
      time.className = 'zq-tl-time';
      time.textContent = row && row.created_at ? String(row.created_at) : '';
      it.appendChild(time);

      var body = DOC.createElement('div');
      body.className = 'zq-tl-body';

      var title = DOC.createElement('div');
      title.className = 'zq-tl-title';
      renderHistoryEventTitleEl(title, row);
      body.appendChild(title);

      var sub = historyEventSub(row);
      if (sub){
        var p2 = DOC.createElement('div');
        p2.className = 'zq-tl-sub';
        p2.textContent = sub;
        body.appendChild(p2);
      }

      // meta debug (opcjonalnie) - tylko jeśli ktoś będzie chciał, można łatwo włączyć
      // var meta = (row && row.meta && typeof row.meta === 'object') ? row.meta : null;

      it.appendChild(body);
      listEl.appendChild(it);
    });
  }

function renderHistory(){
    var wrap = $('zq-history');
    if (!wrap) return;

    var metaEl = $('zq-history-meta');
    var metaTopEl = $('zq-history-meta-top');

    fastClear(wrap);

    var view = getHistoryViewList();
    var all = view.all;
    var list = view.list;

    var frag = DOC.createDocumentFragment();

    var frag = DOC.createDocumentFragment();

    function countStatuses(arr){
      var out = {unset:0,new:0,sent:0,in_progress:0,needs_update:0,won:0,lost:0,canceled:0};
      for (var i=0;i<arr.length;i++){
        var st = normOfferStatus(arr[i] && arr[i].status);
        if (!Object.prototype.hasOwnProperty.call(out, st)) out[st] = 0;
        out[st]++;
      }
      return out;
    }

    var metaText = '';
    if (all.length){
      var c = countStatuses(list);
      var parts = [];
      var filtered = ((view.query && view.query.length) || (view.filter && view.filter !== 'all'));
      parts.push(filtered ? ('Wyniki: ' + list.length + ' / ' + all.length) : ('Łącznie: ' + all.length));
      parts.push('Sukces: ' + c.won);
      parts.push('W trakcie: ' + c.in_progress);
      parts.push('Odrzucone: ' + c.lost);
      if (c.unset > 0) parts.push('Brak statusu: ' + c.unset);
      metaText = parts.join(' | ');
    }
    if (metaEl) metaEl.textContent = metaText;
    if (metaTopEl) metaTopEl.textContent = metaText;


    if (!all.length){
      var p0 = DOC.createElement('div');
      p0.className='zq-muted';
      p0.textContent='Brak zapisanych ofert.';
      wrap.appendChild(p0);
      return;
    }

    if (!list.length){
      var p1 = DOC.createElement('div');
      p1.className='zq-muted';
      var msg = 'Brak wyników.';
      if (view.query) msg += ' Szukano: "' + state.historyQuery + '".';
      if (view.filter && view.filter !== 'all') msg += ' Filtr statusu: ' + (OFFER_STATUS_META[view.filter] ? OFFER_STATUS_META[view.filter].label : view.filter) + '.';
      p1.textContent = msg;
      wrap.appendChild(p1);
      return;
    }

    list.forEach(function(o){
      var item = DOC.createElement('div');
      item.className='zq-hitem';
      if (normOfferStatus(o && o.status) === 'needs_update') item.classList.add('is-needs_update');

      var left = DOC.createElement('div');
      var t = DOC.createElement('div');
      t.className='zq-htitle';
      try{ t.appendChild(makeOfferLockButton(o)); }catch(e){}
      try{ t.appendChild(makeOfferHistoryButton(o)); }catch(e){}
      try{ t.appendChild(makeOfferQuickPreviewButton(o)); }catch(e){}

      // Status obok kłódki (w tytule) - jedna instancja badge, bez duplikowania w meta.
      var badge = makeStatusBadge(o.status, o && o.status_change_count);
      var _locked = isOfferLocked(o);
      var _isSA = actorIsSuperAdmin();
      if (!_locked || _isSA){
        badge.classList.add('is-click');
        badge.title = 'Kliknij, aby zmienić status';
        badge.addEventListener('click', function(ev){
          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
          openOfferStatusModal(o);
        });
      } else {
        badge.title = 'Oferta zablokowana - status nie może być zmieniony';
      }
      t.appendChild(badge);

      if (normOfferStatus(o && o.status) === 'needs_update'){
        var ni = DOC.createElement('span');
        ni.innerHTML = needsUpdateIcon();
        // innerHTML tworzy wrapper - wyciągnij pierwszy element
        try{ if (ni.firstChild) t.appendChild(ni.firstChild); }catch(e){}
      }
      var tTxt = DOC.createElement('span');
      tTxt.className='zq-htitle-text';
      tTxt.textContent = o.title;
      t.appendChild(tTxt);
      var m = DOC.createElement('div');
      m.className='meta';
      var dt = DOC.createElement('span');
      var _ca = (o && o.created_at) ? String(o.created_at) : '';
      var _ua = (o && o.updated_at) ? String(o.updated_at) : '';
      if (_ca && _ua) dt.textContent = 'Utw.: ' + _ca + ' | Edyt.: ' + _ua;
      else if (_ca)   dt.textContent = 'Utw.: ' + _ca;
      else if (_ua)   dt.textContent = 'Edyt.: ' + _ua;
      else            dt.textContent = '';
      m.appendChild(dt);

      left.appendChild(t);
      left.appendChild(m);

      var author = (o && o.account_login) ? String(o.account_login) : (state.me && state.me.account && state.me.account.login ? String(state.me.account.login) : '');
      var cmtRaw = (o && o.comment) ? o.comment : '';
      var cmt = plainFromHtml(cmtRaw);

      var snRaw = (o && o.sales_note) ? o.sales_note : '';
      var sn = plainFromHtml(snRaw);

      if (author || cmt || sn){
        var m2 = DOC.createElement('div');
        m2.className='meta2';
        if (author){
          var by = DOC.createElement('span');
          by.className='zq-by';
          by.textContent = 'Przygotował: ' + author;
          m2.appendChild(by);
        }
        if (cmt){
          var cm = DOC.createElement('span');
          cm.className='zq-cmt';
          cm.textContent = 'Komentarz: ' + cmt;
          cm.title = cmt;
          m2.appendChild(cm);
        }
        if (sn){
          var snEl = DOC.createElement('span');
          snEl.className='zq-sn';
          snEl.textContent = 'Notatka: ' + sn;
          snEl.title = sn;
          m2.appendChild(snEl);
        }
        left.appendChild(m2);
      }

      var right = DOC.createElement('div');
      right.className='zq-actions-mini';

      var __locked = isOfferLocked(o);
      var __isSA = actorIsSuperAdmin();


      var stBtn = DOC.createElement('button');
      stBtn.type='button';
      stBtn.className='zq-mini';
      stBtn.textContent='Zmień status';
      if (__locked && !__isSA){
        stBtn.disabled = true;
        stBtn.title = 'Oferta zablokowana';
      }
      stBtn.addEventListener('click', function(){ openOfferStatusModal(o); });
      right.appendChild(stBtn);

      var edit = DOC.createElement('button');
      edit.type='button'; edit.className='zq-mini';
      edit.textContent='Edytuj';
      if (__locked && !__isSA){
        edit.disabled = true;
        edit.title = 'Oferta zablokowana';
      }
      edit.addEventListener('click', function(){ loadOffer(o.id); });
      right.appendChild(edit);
      var dupOfferBtn = DOC.createElement('button');
      dupOfferBtn.type='button'; dupOfferBtn.className='zq-mini';
      dupOfferBtn.textContent='Duplikuj jako nowy';
      dupOfferBtn.title='Utwórz kopię jako nową ofertę';
      dupOfferBtn.addEventListener('click', function(){ duplicateOffer(o.id); });
      right.appendChild(dupOfferBtn);


      var noteBtn = DOC.createElement('button');
      noteBtn.type='button'; noteBtn.className='zq-mini';
      noteBtn.textContent='Notatka';
      noteBtn.title='Notatka handlowa (wewnętrzna)';
      if (__locked && !__isSA){
        noteBtn.disabled = true;
        noteBtn.title = 'Oferta zablokowana';
      }
      noteBtn.addEventListener('click', function(){ openSalesNoteModal(o); });
      right.appendChild(noteBtn);
      if (o.pdf_path){
        var dl = DOC.createElement('button');
        dl.type='button'; dl.className='zq-mini';
        dl.textContent='Pobierz PDF';
        dl.addEventListener('click', function(){ downloadPdf(o.id, o.title); });
        right.appendChild(dl);
      } else {
        var exp = DOC.createElement('button');
        exp.type='button'; exp.className='zq-mini';
        exp.textContent='Eksportuj PDF';
        exp.title='Generuj PDF i zapisz do tej oferty';
        if ((__locked && !__isSA) || state.exportLock){
          exp.disabled = true;
          exp.title = (__locked && !__isSA) ? 'Oferta zablokowana' : 'Trwa eksport...';
        }
        exp.addEventListener('click', function(){ exportOfferPdfFromHistory(o.id, exp); });
        right.appendChild(exp);
      }



      if (canDeleteOffers()){
        var del = DOC.createElement('button');
        del.type='button';
        del.className='zq-mini danger';
        del.textContent='Usuń';
        del.title = canDeleteOffersAny() ? 'Usuń ofertę (admin)' : 'Usuń ofertę';
        if (__locked && !__isSA){
          del.disabled = true;
          del.title = 'Oferta zablokowana';
        }
        del.addEventListener('click', function(){
          var msg = 'Usunąć ofertę "' + String(o.title || '').replace(/"/g, '\\"') + '"?';
          zqConfirm({ title:'Usuń ofertę', message: msg, okText:'Usuń', cancelText:'Anuluj', okClass:'danger' }).then(function(ok){
            if (!ok) return;

            del.disabled = true;
            var prevTxt = del.textContent;
            del.textContent = 'Usuwam...';

            deleteOffer(o.id).catch(function(){
              try{
                del.disabled = false;
                del.textContent = prevTxt;
              }catch(e){}
            });
          });
        });
        right.appendChild(del);
      }

      item.appendChild(left);
      item.appendChild(right);
      frag.appendChild(item);
    });
    wrap.appendChild(frag);
  

    // Sync UI po renderze pełnej listy.
    try{ syncHistoryDetailsUi(); }catch(e){}
    try{ if (window.__zqosCheckHistoryEnd) window.__zqosCheckHistoryEnd(); }catch(e2){}
}


  
  function syncHistoryDetailsUi(){
    var det = $('zq-history-details');
    var miniWrap = $('zq-history-mini-wrap');
    var miniHead = $('zq-history-mini-head');
    var collapseWrap = $('zq-history-collapse-wrap');
    var label = $('zq-history-toggle-label');

    // Jeśli nie ma detali (lub są ukryte), traktuj jak stan zwinięty.
    var isDetVisible = !!(det && det.style && det.style.display !== 'none');
    var isOpen = !!(det && det.open && isDetVisible);

    // Mini (ostatnie 5 + gradient) znika gdy lista jest rozwinięta.
    if (miniWrap) miniWrap.style.display = isOpen ? 'none' : '';
    if (miniHead) miniHead.style.display = isOpen ? 'none' : '';

    // Przycisk zwijania: widoczny tylko gdy lista jest rozwinięta i użytkownik dojechał do końca listy.
var atEnd = false;
var inView = false;
try{
  if (typeof _zqHistoryDock === 'object' && _zqHistoryDock && _zqHistoryDock.inited){
    atEnd = !!_zqHistoryDock.atEnd;
    inView = !!_zqHistoryDock.inView;
  }
}catch(e){ atEnd = false; inView = false; }

var showCollapse = !!(isOpen && atEnd && inView);
    if (collapseWrap){
      // Upewnij się, że nie trzymamy starego inline display (sterujemy klasą + CSS).
      try{ collapseWrap.style.display = ''; }catch(e0){}
      try{
        if (showCollapse) collapseWrap.classList.add('is-active');
        else collapseWrap.classList.remove('is-active');
      }catch(e2){}
      try{ collapseWrap.setAttribute('aria-hidden', showCollapse ? 'false' : 'true'); }catch(e3){}
    }

    // Legacy: label w <summary> (summary jest ukryty w UI).
    if (label) label.textContent = isOpen ? 'Pokaż mniej' : 'Pokaż więcej';
  }

  // Dokowanie przycisku "Zwiń listę" do modala + wykrywanie czy użytkownik jest na końcu listy historii.
  var _zqHistoryDock = { inited:false, atEnd:false, inView:false };

  function _zqUpdateHistoryDockRect(){
    var modal = DOC.querySelector('.zq-offer-modal');
    if (!modal) return;
    var r = modal.getBoundingClientRect();
    // Dopasowanie do wewnętrznych paddingów modala (head/border)
    var left = Math.round((r.left || 0) + 16);
    var width = Math.max(240, Math.round((r.width || 0) - 32));
    var bottom = Math.round(Math.max((window.innerHeight - (r.bottom || 0)) + 16, 12));
    try{
      DOC.documentElement.style.setProperty('--zq-hcol-left', left + 'px');
      DOC.documentElement.style.setProperty('--zq-hcol-width', width + 'px');
      DOC.documentElement.style.setProperty('--zq-hcol-bottom', bottom + 'px');
    }catch(e){}
  }

  function setupHistoryCollapseDock(){
    if (_zqHistoryDock.inited) return;
    _zqHistoryDock.inited = true;

    var modal = DOC.querySelector('.zq-offer-modal');
    var det = $('zq-history-details');
    var historyCard = $('zq-history-card');
    var sentinel = $('zq-history-end');
    if (!modal) return;

    _zqUpdateHistoryDockRect();

    // resize/orientation -> przelicz pozycję (modal ma stały rect w viewport) + odśwież stan końca listy
    (function(){
      var t = 0;
      function onR(){
        if (t) return;
        t = setTimeout(function(){
          t = 0;
          _zqUpdateHistoryDockRect();
          try{ checkEnd(); }catch(e2){}
        }, 80);
      }
      window.addEventListener('resize', onR, { passive:true });
      window.addEventListener('orientationchange', onR, { passive:true });
    })();

    function setState(nextAtEnd, nextInView){
      nextAtEnd = !!nextAtEnd;
      nextInView = !!nextInView;
      if (_zqHistoryDock.atEnd === nextAtEnd && _zqHistoryDock.inView === nextInView) return;
      _zqHistoryDock.atEnd = nextAtEnd;
      _zqHistoryDock.inView = nextInView;
      try{ syncHistoryDetailsUi(); }catch(e){}
    }

    function isDetOpen(){
      try{
        return !!(det && det.open && det.style && det.style.display !== 'none');
      }catch(e){ return false; }
    }

    function findScrollRoot(){
      var el = (historyCard || det || sentinel || modal);
      while (el && el !== DOC.body && el !== DOC.documentElement){
        try{
          var cs = window.getComputedStyle(el);
          var oy = cs ? cs.overflowY : '';
          if ((oy === 'auto' || oy === 'scroll') && (el.scrollHeight > el.clientHeight + 2)){
            return el;
          }
        }catch(e){}
        el = el.parentElement;
      }
      return DOC.scrollingElement || DOC.documentElement;
    }

    // Sprawdza: (1) czy historia jest w viewport scroll-roota, (2) czy jesteśmy na końcu listy (sentinel).
    function checkEnd(){
      try{
        if (!isDetOpen()){
          setState(false, false);
          return;
        }

        var root = findScrollRoot();
        var rr = root && root.getBoundingClientRect ? root.getBoundingClientRect() : { top:0, bottom: (window.innerHeight || 0) };

        // Historia w widoku? (żeby przycisk nie wisiał, gdy user jest powyżej/poniżej sekcji Historii)
        var target = historyCard || det;
        var inView = false;
        if (target && target.getBoundingClientRect){
          var hr = target.getBoundingClientRect();
          inView = (hr.bottom > (rr.top + 40)) && (hr.top < (rr.bottom - 40));
        }

        // Koniec listy: sentinel widoczny i "dosięga" do dołu viewportu scroll-roota.
        var atEnd = false;
        if (sentinel && sentinel.getBoundingClientRect){
          var sr = sentinel.getBoundingClientRect();
          var intersects = (sr.bottom >= rr.top) && (sr.top <= rr.bottom);
          atEnd = intersects && (sr.bottom <= (rr.bottom + 2));
        }else if (root && typeof root.scrollTop === 'number'){
          atEnd = (root.scrollTop + root.clientHeight) >= (root.scrollHeight - 6);
        }

        setState(atEnd, inView);
      }catch(e2){
        setState(false, false);
      }
    }

    // Upublicznij dla renderów (po zmianie DOM listy).
    try{ window.__zqosCheckHistoryEnd = checkEnd; }catch(e3){}

    // Bulletproof: łap scroll z dowolnego scroll-roota (scroll nie bubluje, ale działa na capture).
    (function(){
      var tt = 0;
      function onAnyScroll(){
        if (tt) return;
        tt = setTimeout(function(){ tt = 0; checkEnd(); }, 60);
      }
      DOC.addEventListener('scroll', onAnyScroll, true);
      window.addEventListener('scroll', onAnyScroll, { passive:true });
    })();

    // Toggle <details> -> przelicz od razu
    if (det){
      det.addEventListener('toggle', function(){
        setTimeout(checkEnd, 0);
      });
    }

    setTimeout(checkEnd, 0);
  }
function renderHistoryMini(){
    var wrap = $('zq-history-mini');
    if (!wrap) return;

    while (wrap.firstChild) wrap.removeChild(wrap.firstChild);

    var view = getHistoryViewList();
    var all = view.all;
    var list = view.list;

    // Meta (podsumowanie) widoczne także gdy lista jest zwinięta.
    (function(){
      var metaTopEl = $('zq-history-meta-top');
      if (!metaTopEl) return;

      var metaText = '';
      if (all.length){
        var out = {unset:0,new:0,sent:0,in_progress:0,needs_update:0,won:0,lost:0,canceled:0};
        for (var i=0;i<list.length;i++){
          var st = normOfferStatus(list[i] && list[i].status);
          if (!Object.prototype.hasOwnProperty.call(out, st)) out[st] = 0;
          out[st]++;
        }
        var parts = [];
        var filtered = ((view.query && view.query.length) || (view.filter && view.filter !== 'all'));
        parts.push(filtered ? ('Wyniki: ' + list.length + ' / ' + all.length) : ('Łącznie: ' + all.length));
        parts.push('Sukces: ' + out.won);
        parts.push('W trakcie: ' + out.in_progress);
        parts.push('Odrzucone: ' + out.lost);
        if (out.unset > 0) parts.push('Brak statusu: ' + out.unset);
        metaText = parts.join(' | ');
      }
      metaTopEl.textContent = metaText;
    })();


    var det = $('zq-history-details');
    var cnt = $('zq-history-more-count');
    if (det){
      if (list.length <= 5){
        det.open = false;
        det.style.display = 'none';
        if (cnt) cnt.textContent = '';
      } else {
        det.style.display = '';
        if (cnt) cnt.textContent = '+' + String(list.length - 5);
      }
    }

    // Ustaw stan mini-wrap (gradient + padding) gdy jest więcej niż 5 wyników.
    (function(){
      var miniWrap = $('zq-history-mini-wrap');
      if (!miniWrap) return;
      var hasMore = (list.length > 5);
      try{
        if (hasMore) miniWrap.classList.add('has-more');
        else miniWrap.classList.remove('has-more');
      }catch(e){}
    })();

    // zsynchronizuj widok mini vs pełna lista + label przycisku
    syncHistoryDetailsUi();

    if (!all.length){
      var p0 = DOC.createElement('div');
      p0.className='zq-muted';
      p0.textContent='Brak zapisanych ofert.';
      wrap.appendChild(p0);
      return;
    }

    if (!list.length){
      var p1 = DOC.createElement('div');
      p1.className='zq-muted';
      p1.textContent='Brak wyników.';
      wrap.appendChild(p1);
      return;
    }

    var top = list.slice(0, 5);

    top.forEach(function(o){
      var item = DOC.createElement('div');
      item.className='zq-hitem';
      if (normOfferStatus(o && o.status) === 'needs_update') item.classList.add('is-needs_update');

      var left = DOC.createElement('div');
      var t = DOC.createElement('div');
      t.className='zq-htitle';
      try{ t.appendChild(makeOfferLockButton(o)); }catch(e){}
      try{ t.appendChild(makeOfferHistoryButton(o)); }catch(e){}
      try{ t.appendChild(makeOfferQuickPreviewButton(o)); }catch(e){}

      // Status obok kłódki (w tytule) - jedna instancja badge, bez duplikowania w meta.
      var badge = makeStatusBadge(o.status, o && o.status_change_count);
      var _locked = isOfferLocked(o);
      var _isSA = actorIsSuperAdmin();
      if (!_locked || _isSA){
        badge.classList.add('is-click');
        badge.title = 'Kliknij, aby zmienić status';
        badge.addEventListener('click', function(ev){
          try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){}
          openOfferStatusModal(o);
        });
      } else {
        badge.title = 'Oferta zablokowana - status nie może być zmieniony';
      }
      t.appendChild(badge);

      if (normOfferStatus(o && o.status) === 'needs_update'){
        var ni = DOC.createElement('span');
        ni.innerHTML = needsUpdateIcon();
        // innerHTML tworzy wrapper - wyciągnij pierwszy element
        try{ if (ni.firstChild) t.appendChild(ni.firstChild); }catch(e){}
      }
      var tTxt = DOC.createElement('span');
      tTxt.className='zq-htitle-text';
      tTxt.textContent = o.title;
      t.appendChild(tTxt);
      var m = DOC.createElement('div');
      m.className='meta';
      var dt = DOC.createElement('span');
      var _ca = (o && o.created_at) ? String(o.created_at) : '';
      var _ua = (o && o.updated_at) ? String(o.updated_at) : '';
      if (_ca && _ua) dt.textContent = 'Utw.: ' + _ca + ' | Edyt.: ' + _ua;
      else if (_ca)   dt.textContent = 'Utw.: ' + _ca;
      else if (_ua)   dt.textContent = 'Edyt.: ' + _ua;
      else            dt.textContent = '';
      m.appendChild(dt);

      left.appendChild(t);
      left.appendChild(m);

      var author = (o && o.account_login) ? String(o.account_login) : (state.me && state.me.account && state.me.account.login ? String(state.me.account.login) : '');
      var cmtRaw = (o && o.comment) ? o.comment : '';
      var cmt = plainFromHtml(cmtRaw);

      var snRaw = (o && o.sales_note) ? o.sales_note : '';
      var sn = plainFromHtml(snRaw);

      if (author || cmt || sn){
        var m2 = DOC.createElement('div');
        m2.className='meta2';
        if (author){
          var by = DOC.createElement('span');
          by.className='zq-by';
          by.textContent = 'Przygotował: ' + author;
          m2.appendChild(by);
        }
        if (cmt){
          var cm = DOC.createElement('span');
          cm.className='zq-cmt';
          cm.textContent = 'Komentarz: ' + cmt;
          cm.title = cmt;
          m2.appendChild(cm);
        }
        if (sn){
          var snEl = DOC.createElement('span');
          snEl.className='zq-sn';
          snEl.textContent = 'Notatka: ' + sn;
          snEl.title = sn;
          m2.appendChild(snEl);
        }
        left.appendChild(m2);
      }

      var right = DOC.createElement('div');
      right.className='zq-actions-mini';

      var __locked = isOfferLocked(o);
      var __isSA = actorIsSuperAdmin();


      var stBtn = DOC.createElement('button');
      stBtn.type='button'; stBtn.className='zq-mini';
      stBtn.textContent='Zmień status';
      if (__locked && !__isSA){
        stBtn.disabled = true;
        stBtn.title = 'Oferta zablokowana';
      }
      stBtn.addEventListener('click', function(){ openOfferStatusModal(o); });
      right.appendChild(stBtn);

      var edit = DOC.createElement('button');
      edit.type='button'; edit.className='zq-mini';
      edit.textContent='Edytuj';
      if (__locked && !__isSA){
        edit.disabled = true;
        edit.title = 'Oferta zablokowana';
      }
      edit.addEventListener('click', function(){ loadOffer(o.id); });
      right.appendChild(edit);
      var dupOfferBtn = DOC.createElement('button');
      dupOfferBtn.type='button'; dupOfferBtn.className='zq-mini';
      dupOfferBtn.textContent='Duplikuj jako nowy';
      dupOfferBtn.title='Utwórz kopię jako nową ofertę';
      dupOfferBtn.addEventListener('click', function(){ duplicateOffer(o.id); });
      right.appendChild(dupOfferBtn);


      var noteBtn = DOC.createElement('button');
      noteBtn.type='button'; noteBtn.className='zq-mini';
      noteBtn.textContent='Notatka';
      noteBtn.title='Notatka handlowa (wewnętrzna)';
      if (__locked && !__isSA){
        noteBtn.disabled = true;
        noteBtn.title = 'Oferta zablokowana';
      }
      noteBtn.addEventListener('click', function(){ openSalesNoteModal(o); });
      right.appendChild(noteBtn);
      if (o.pdf_path){
        var dl = DOC.createElement('button');
        dl.type='button'; dl.className='zq-mini';
        dl.textContent='Pobierz PDF';
        dl.addEventListener('click', function(){ downloadPdf(o.id, o.title); });
        right.appendChild(dl);
      } else {
        var exp = DOC.createElement('button');
        exp.type='button'; exp.className='zq-mini';
        exp.textContent='Eksportuj PDF';
        exp.title='Generuj PDF i zapisz do tej oferty';
        if ((__locked && !__isSA) || state.exportLock){
          exp.disabled = true;
          exp.title = (__locked && !__isSA) ? 'Oferta zablokowana' : 'Trwa eksport...';
        }
        exp.addEventListener('click', function(){ exportOfferPdfFromHistory(o.id, exp); });
        right.appendChild(exp);
      }



      if (canDeleteOffers()){
        var del = DOC.createElement('button');
        del.type='button';
        del.className='zq-mini danger';
        del.textContent='Usuń';
        del.title = canDeleteOffersAny() ? 'Usuń ofertę (admin)' : 'Usuń ofertę';
        if (__locked && !__isSA){
          del.disabled = true;
          del.title = 'Oferta zablokowana';
        }
        del.addEventListener('click', function(){
          var msg = 'Usunąć ofertę "' + String(o.title || '').replace(/"/g, '\\"') + '"?';
          zqConfirm({ title:'Usuń ofertę', message: msg, okText:'Usuń', cancelText:'Anuluj', okClass:'danger' }).then(function(ok){
            if (!ok) return;

            del.disabled = true;
            var prevTxt = del.textContent;
            del.textContent = 'Usuwam...';

            deleteOffer(o.id).catch(function(){
              try{
                del.disabled = false;
                del.textContent = prevTxt;
              }catch(e){}
            });
          });
        });
        right.appendChild(del);
      }

      item.appendChild(left);
      item.appendChild(right);
      wrap.appendChild(item);
    });
  

    // Sync UI (mini vs full + label) po każdym renderze mini.
    try{ syncHistoryDetailsUi(); }catch(e){}
    try{ if (window.__zqosCheckHistoryEnd) window.__zqosCheckHistoryEnd(); }catch(e2){}
}

  function loadOffer(id){
    setExportStatus('Ładuję ofertę...');
    apiFetch('/offers/' + encodeURIComponent(String(id)), {method:'GET'}).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok || !j.offer){
          throw new Error((j && j.message) ? j.message : 'Błąd odczytu oferty.');
        }
        var offer = j.offer;
        // klient
        if (offer.data && offer.data.client) applyClient(offer.data.client);

        // linie
        if (offer.data && Array.isArray(offer.data.lines)){
          var manualFound = false;
          state.offerLines = [];
          offer.data.lines.forEach(function(L){
            var found = findItem(L.sheet, L.kategoria, L.podkategoria || '', L.produkt, L.wymiar || '');
            if (!found) return;
            var ral = L.ral || '';
            var p = (found.ceny && Object.prototype.hasOwnProperty.call(found.ceny, ral)) ? found.ceny[ral] : 0;
            var cloned = JSON.parse(JSON.stringify(found));
            cloned.cenaNetto = p;

            var mu = (L.manual_unit_net != null) ? parseNumber(L.manual_unit_net) : null;
            if (mu != null && isFinite(mu)) manualFound = true;
            state.offerLines.push({
              id: lineId(),
              item: cloned,
              ral: ral,
              qty: clampNum(parseNumber(L.qty) || 1, 1, 999999),
              disc: clampNum(parseNumber(L.disc) || 0, 0, 100),
              manualUnitNet: mu
            });
          });
          // jeżeli w danych są ręczne ceny, a flaga special_offer była false (legacy) - włącz tryb specjalny automatycznie
          var allowed = isSpecialAllowed();
          var wantSpecial = !!(offer.data && offer.data.special_offer);
          if (!wantSpecial && manualFound && allowed) wantSpecial = true;
          state.specialOffer = wantSpecial && allowed;
          if ($('zq-special')) $('zq-special').checked = state.specialOffer;

          // jeśli konto nie ma uprawnień do trybu specjalnego, usuń ręczne ceny z pozycji
          if (!allowed && manualFound){
            state.offerLines.forEach(function(l){ if (l) l.manualUnitNet = null; });
          }

          persistOffer();
          renderLines();
        } else {
          state.specialOffer = false;
          if ($('zq-special')) $('zq-special').checked = false;
        }


// tytuł + komentarz (do edycji / nadpisania)
if ($('zq-offer-title')) $('zq-offer-title').value = offer.title || '';
if ($('zq-offer-comment')) $('zq-offer-comment').value = plainFromHtml(offer.comment || '');
setEditingOfferContext(offer.id || id, offer.title || '', (offer.data && offer.data.client) ? offer.data.client : null);

        // status (do zarządzania)
        var st = normOfferStatus(offer.status);
        if ($('zq-offer-status')){
          if (st && st !== 'unset') $('zq-offer-status').value = st;
          else $('zq-offer-status').value = 'unset';
        }
        setExportStatus('OK: wczytano "' + offer.title + '"');
      });
    }).catch(function(e){
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'load'));
    });
  }

  function downloadPdf(id, title, createdAt){
    setExportStatus('Pobieram PDF...');
    apiFetch('/offers/' + encodeURIComponent(String(id)) + '/pdf', {method:'GET'}).then(function(r){
      if (!r.ok){
        return r.json().catch(function(){return null;}).then(function(j){
          throw new Error((j && j.message) ? j.message : ('HTTP ' + r.status));
        });
      }
      return r.arrayBuffer().then(function(buf){
        var blob = new Blob([buf], {type:'application/pdf'});
        var url = URL.createObjectURL(blob);
        var a = DOC.createElement('a');
        a.href = url;
        var dateTag = '';
        if (createdAt){
          try{ dateTag = String(createdAt).slice(0,10).replace(/\./g,'-').replace(/\//g,'-').replace(/\s.*/,''); }catch(e){ dateTag=''; }
        }
        var safeTitle = title ? String(title) : 'oferta';
        safeTitle = safeTitle.replace(/[^a-zA-Z0-9\-_.\sąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, '').trim();
        var fname = 'Oferta_ZEGGER_' + safeTitle + (dateTag ? ('_' + dateTag) : '') + '.pdf';
        a.download = fname;
        DOC.body.appendChild(a);
        a.click();
        setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1500);
        setExportStatus('');
      });
    }).catch(function(e){
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'pdf'));
    });
  }

  function updateOfferStatus(id, status){
    id = String(id || '').replace(/[^0-9]/g, '');
    if (!id) return Promise.resolve(false);
    var st = normOfferStatus(status);
    if (!st || st === 'unset') return Promise.resolve(false);

    return apiFetch('/offers/' + encodeURIComponent(id) + '/status', { method:'PUT', json:{ status: st } }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
          setExportStatus('Błąd statusu: ' + msg);
          return false;
        }

        // update lokalnego cache
        try{
          var arr = Array.isArray(state.offers) ? state.offers : [];
          for (var i=0;i<arr.length;i++){
            if (arr[i] && String(arr[i].id) === id){
              arr[i].status = st;
              arr[i].status_updated_at = j.status_updated_at || arr[i].status_updated_at || '';
              if (Object.prototype.hasOwnProperty.call(j, 'locked')){
                arr[i].locked = j.locked ? 1 : 0;
                arr[i].locked_at = j.locked_at || arr[i].locked_at || null;
                arr[i].locked_by = j.locked_by || arr[i].locked_by || null;
                arr[i].lock_reason = j.lock_reason || arr[i].lock_reason || null;
              }
              break;
            }
          }
        }catch(e){}

        renderHistory();
        renderHistoryMini();

        // odśwież KPI w profilu (debounce)
        try{
          if (state._profileRefreshTimer){ clearTimeout(state._profileRefreshTimer); }
        }catch(e){}
        state._profileRefreshTimer = setTimeout(function(){ refreshProfile(); }, 350);

        return true;
      });
    }).catch(function(){
      setExportStatus('Błąd statusu: network');
      return false;
    });
  }

  

  function toggleOfferLock(id, wantLocked){
    id = String(id || '').replace(/[^0-9]/g, '');
    if (!id) return Promise.resolve(false);

    if (!canLockOffers()){
      toast('warn', 'Brak uprawnień do blokowania ofert.');
      return Promise.resolve(false);
    }

    return apiFetch('/offers/' + encodeURIComponent(id) + '/lock', { method:'PUT', json:{ locked: !!wantLocked } }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
          toast('error', msg);
          return false;
        }

        // update lokalnego cache
        try{
          var arr = Array.isArray(state.offers) ? state.offers : [];
          for (var i=0;i<arr.length;i++){
            if (arr[i] && String(arr[i].id) === id){
              arr[i].locked = j.locked ? 1 : 0;
              arr[i].locked_at = j.locked_at || null;
              arr[i].locked_by = j.locked_by || null;
              arr[i].lock_reason = j.lock_reason || null;
              break;
            }
          }
        }catch(e){}

        renderHistory();
        renderHistoryMini();

        return true;
      });
    }).catch(function(){
      toast('error', 'Błąd: network');
      return false;
    });
  }

  function duplicateOffer(id){
    id = String(id || '').replace(/[^0-9]/g, '');
    if (!id) return Promise.resolve(null);

    return apiFetch('/offers/' + encodeURIComponent(id) + '/duplicate', { method:'POST', json:{} }).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok){
          var msg = (j && j.message) ? j.message : ('HTTP ' + r.status);
          toast('error', msg);
          return null;
        }
        var newId = j.id ? String(j.id) : '';
        if (!newId) return null;

        // odśwież historię deterministycznie i otwórz nową kopię w edycji
        return refreshHistory({bust:true}).then(function(){
          var has = false;
          try{
            for (var i=0;i<state.offers.length;i++){
              if (state.offers[i] && String(state.offers[i].id) === newId){ has = true; break; }
            }
          }catch(e){}
          if (!has) return refreshHistoryAfterExport(newId);
        }).finally(function(){
          try{ loadOffer(newId); }catch(e){}
        }).then(function(){
          toast('success', 'Utworzono kopię jako nową ofertę.');
          return newId;
        });
      });
    }).catch(function(){
      toast('error', 'Błąd: network');
      return null;
    });
  }


  // Eksport PDF z historii: generuje PDF w hoście (kalkulator) i zapisuje go do TEJ SAMEJ oferty.
  function exportOfferPdfFromHistory(offerId, btnEl){
    offerId = String(offerId || '').replace(/[^0-9]/g, '');
    if (!offerId) return;

    if (state.exportLock){
      setExportStatus('Trwa eksport...');
      return;
    }

    if (!isEmbedded()){
      toast('error', 'Brak hosta do PDF (otwórz w kalkulatorze).');
      return;
    }

    if (!state.authToken){
      toast('error', 'Brak autoryzacji (token). Zaloguj się ponownie.');
      return;
    }

    setErr('');
    setExportStatus('Generuję PDF...');

    setExportLock(true, btnEl);

    state.exportNonce = String(Date.now()) + '-' + String(Math.floor(Math.random()*1e9));
    state.exportStartedAt = Date.now();
    state.exportFromOfferId = offerId;

    if (state.exportTimer){ try{ clearTimeout(state.exportTimer); }catch(e){} }
    state.exportTimer = setTimeout(function(){
      if (state.exportLock && state.exportNonce){
        setExportStatus('Błąd: timeout eksportu PDF (brak odpowiedzi hosta).');
        state.exportNonce = null;
        state.exportFromOfferId = null;
        setExportLock(false);
      }
    }, 60000);

    fetchOfferById(offerId).then(function(off){
      if (!off) throw new Error('Nie można pobrać danych oferty.');
      if (!off.data) throw new Error('Brak danych oferty.');

      var payload = {
        token: state.authToken,
        offer_id: offerId,
        title: off.title || ('Oferta #' + offerId),
        comment: plainFromHtml(off.comment || ''),
        status: normOfferStatus(off.status || 'unset'),
        data: off.data,
        nonce: state.exportNonce
      };

      try{
        if (window.parent && window.parent !== window){
          window.parent.postMessage({ type:'zq:offer:export_pdf', payload: payload }, '*');
          return;
        }
      }catch(e){}
      throw new Error('Brak hosta do PDF (otwórz w kalkulatorze).');
    }).catch(function(e){
      if (state.exportTimer){ try{ clearTimeout(state.exportTimer); }catch(ex){} state.exportTimer = 0; }
      state.exportNonce = null;
      state.exportFromOfferId = null;
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'export'));
      setExportLock(false);
    });
  }


function deleteOffer(id){
    id = String(id || '').replace(/[^0-9]/g, '');
    if (!id) return Promise.reject(new Error('Brak ID oferty.'));

    function pruneLocalHistory(){
      var arr = Array.isArray(state.offers) ? state.offers : [];
      var out = [];
      var removed = false;
      for (var i=0;i<arr.length;i++){
        if (!arr[i]) continue;
        if (String(arr[i].id) === id){ removed = true; continue; }
        out.push(arr[i]);
      }
      if (removed){
        state.offers = out;
        renderHistory();
        renderHistoryMini();
      }
      return removed;
    }

    setExportStatus('Usuwam ofertę...');
    return apiFetch('/offers/' + encodeURIComponent(String(id)), {method:'DELETE'}).then(function(r){
      return r.json().catch(function(){return null;}).then(function(j){
        if (!r.ok || !j || !j.ok){
          throw new Error((j && j.message) ? j.message : ('HTTP ' + r.status));
        }
        setExportStatus('OK: usunięto');
        // Natychmiastowa aktualizacja UI (bez klikania "Odśwież")
        pruneLocalHistory();
        // Synchronizacja z backendem (best-effort)
        return refreshHistory({bust:true});
      });
    }).catch(function(e){
      setExportStatus('Błąd: ' + (e && e.message ? e.message : 'delete'));
      throw e;
    });
  }

  function getPriceView(){
    var v = ($('zq-price-view') && $('zq-price-view').value) ? String($('zq-price-view').value) : 'net';
    return (v === 'gross') ? 'gross' : 'net';
  }


  // Boot
  function boot(){
    initHelpTooltips();
    applyUserSwitchUI();
    buildTabs();
    populateCascades();
    renderLines();

    // profil
    if ($('zq-prof-edit')) $('zq-prof-edit').addEventListener('click', function(){ openProfileModal(); });
    if ($('zq-prof-modal-close')) $('zq-prof-modal-close').addEventListener('click', function(){ closeProfileModal(); });
    if ($('zq-prof-cancel')) $('zq-prof-cancel').addEventListener('click', function(){ closeProfileModal(); });
    if ($('zq-prof-save')) $('zq-prof-save').addEventListener('click', function(){ saveProfile(); });


    // draft banner
    if ($('zq-draft-restore')) $('zq-draft-restore').addEventListener('click', function(){ restoreDraft(); });
    if ($('zq-draft-discard')) $('zq-draft-discard').addEventListener('click', function(){ clearDraft(); setExportStatus('Szkic odrzucony.'); });

    // search (debounce + nieblokujące wyszukiwanie)
    var searchEl = $('zq-search');
    var dropEl = $('zq-search-drop');
    function closest(el, cls){
      while (el && el !== DOC){
        if (el.classList && el.classList.contains(cls)) return el;
        el = el.parentNode;
      }
      return null;
    }

    function scheduleSearch(){
      if (!searchEl) return;
      _zqSearch.lastQ = String(searchEl.value || '');
      if (_zqSearch.debounceT) clearTimeout(_zqSearch.debounceT);
      _zqSearch.debounceT = setTimeout(function(){
        _zqSearch.debounceT = 0;
        performSearch(_zqSearch.lastQ);
      }, 130);
    }

    if (searchEl){
      searchEl.addEventListener('input', scheduleSearch);
      searchEl.addEventListener('focus', function(){ performSearch(searchEl.value || ''); });
      searchEl.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape'){
          hideSearchDrop();
          try{ searchEl.blur(); }catch(e){}
        }
      });
    }

    // Event delegation w dropdownie (bez setek listenerów)
    if (dropEl){
      dropEl.addEventListener('click', function(ev){
        var t = ev && ev.target ? ev.target : null;
        if (!t) return;

        var row = null;
        try{ row = t.closest ? t.closest('.it[data-zq-sig]') : null; }catch(e){ row = null; }
        if (!row || !row.getAttribute) return;

        var sig = String(row.getAttribute('data-zq-sig') || '');
        if (!sig) return;

        var star = null;
        try{
          if (t.getAttribute && t.getAttribute('data-zq-star') === '1') star = t;
          else if (t.closest) star = t.closest('[data-zq-star="1"]');
        }catch(e2){ star = null; }

        if (star){
          ev.preventDefault();
          ev.stopPropagation();
          var label = '';
          try{
            var elT = row.querySelector('.t');
            label = elT ? String(elT.textContent || '') : '';
          }catch(e3){}
          var sku = String(row.getAttribute('data-zq-sku') || '');
          toggleFav(sig, { label: label, sku: sku });

          var on = isFav(sig);
          star.className = 'zq-star' + (on ? ' is-on' : '');
          star.textContent = on ? '★' : '☆';
          return;
        }

        // wybór elementu
        applySelectionFromSig(sig);
        hideSearchDrop();
        if (searchEl) searchEl.value = '';
        try{ if (searchEl) searchEl.blur(); }catch(e4){}
      });
    }

    if ($('zq-fav-open')) $('zq-fav-open').addEventListener('click', function(){
      if (!searchEl) return;
      try{ searchEl.focus(); }catch(e){}
      performSearch('');
    });
    DOC.addEventListener('click', function(ev){
      var t = ev && ev.target ? ev.target : null;
      if (!t) return;
      if (closest(t, 'zq-dropwrap')) return;
      if (t === $('zq-fav-open')) return;
      if (dropEl && dropEl.style.display === 'block') hideSearchDrop();
    });

    // global discount
    if ($('zq-apply-disc-all')) $('zq-apply-disc-all').addEventListener('click', function(){
      setErr('');
      var el = $('zq-disc-all');
      var v = el ? parseNumber(el.value) : null;
      if (v == null) v = 0;
      var maxD = getMaxDiscount();
      v = clampNum(v, 0, maxD);
      if (el) el.value = String(v);
      if (!state.offerLines.length){
        setErr('Brak pozycji na liście.');
        return;
      }
      state.offerLines.forEach(function(l){
        if (isTransportLine(l) && l && l.transport && l.transport.no_global_disc) return;
        l.disc = v;
      });
      persistOffer();
      renderLines();
      setExportStatus('Zastosowano rabat globalny: ' + v + '%.');
    });

    var clearBtn = $('zq-clear-btn');
    if (clearBtn) clearBtn.addEventListener('click', function(){ clearOffer(); });

    if ($('zq-cat')) $('zq-cat').addEventListener('change', function(){ setErr(''); updateSubcats(); });
    if ($('zq-subcat')) $('zq-subcat').addEventListener('change', function(){ setErr(''); updateProducts(); });
    if ($('zq-prod')) $('zq-prod').addEventListener('change', function(){ setErr(''); updateDims(); });
    if ($('zq-dim')) $('zq-dim').addEventListener('change', function(){ setErr(''); updateRAL(); });

    if ($('zq-add')) $('zq-add').addEventListener('click', function(){ addLine(); });

    if ($('zq-price-view')) $('zq-price-view').addEventListener('change', function(){ renderLines(); });
    if ($('zq-price-view')) $('zq-price-view').addEventListener('change', function(){ scheduleDraftSave(); });

    if ($('zq-special')) $('zq-special').addEventListener('change', function(){
      if (!isSpecialAllowed()){
        $('zq-special').checked = false;
        state.specialOffer = false;
        setErr('Brak uprawnień do "Oferta specjalna".', 'error');
        return;
      }
      state.specialOffer = !!$('zq-special').checked;
      // jeśli wyłączono tryb specjalny, usuń ręczne ceny z pozycji (żeby zapis/eksport nie był blokowany)
      if (!state.specialOffer && state.offerLines && state.offerLines.length){
        state.offerLines.forEach(function(l){
          if (!l) return;
          l.manualUnitNet = null;
        });
        persistOffer();
      }
      renderLines();
      scheduleDraftSave();
    });

    if ($('zq-client-select')) $('zq-client-select').addEventListener('change', function(){
      var v = $('zq-client-select').value || '';
      v = String(v);
      state.selectedClientId = v || null;

      if (!v){
        if (isClientManualAllowed()){
          applyClient({full_name:'',company:'',nip:'',phone:'',email:'',address:''});
          scheduleDraftSave();
        }
        applyClientAccessUI();
        updateOverwriteAvailability();
        return;
      }

      for (var i=0;i<state.clients.length;i++){
        if (state.clients[i] && String(state.clients[i].id) === v){
          applyClient(state.clients[i]);
          scheduleDraftSave();
          applyClientAccessUI();
          updateOverwriteAvailability();
          break;
        }
      }
    });


    if ($('zq-client-add')) $('zq-client-add').addEventListener('click', function(){
      saveNewClient();
    });

    if ($('zq-client-edit')) $('zq-client-edit').addEventListener('click', function(){ editSelectedClient(); });
    
        // modal - klient
        if ($('zq-client-modal-close')) $('zq-client-modal-close').addEventListener('click', closeClientModal);
        if ($('zq-client-modal-cancel')) $('zq-client-modal-cancel').addEventListener('click', closeClientModal);
        if ($('zq-client-modal-save')) $('zq-client-modal-save').addEventListener('click', saveClientModal);
    
        // klik na tło NIE zamyka (zamknięcie tylko przyciski / ESC)
    
        // Enter w modalu -> zapisz
        ;['zq-cm-fullname','zq-cm-company','zq-cm-nip','zq-cm-phone','zq-cm-email','zq-cm-address'].forEach(function(id){
          var el = $(id);
          if (!el) return;
          el.addEventListener('keydown', function(e){
            if (!e) return;
            if (e.key === 'Enter'){
              e.preventDefault();
              saveClientModal();
            }
          });
        });


    // custom line: przycisk w Legendzie + modal
    if ($('zq-add-custom-line')) $('zq-add-custom-line').addEventListener('click', function(){
      openCustomLineModal('add', null);
    });

    // transport line: przycisk w Legendzie + modal
    if ($('zq-add-transport-line')) $('zq-add-transport-line').addEventListener('click', function(){
      openTransportLineModal('add', null);
    });

    if ($('zq-transport-line-close')) $('zq-transport-line-close').addEventListener('click', closeTransportLineModal);
    if ($('zq-transport-cancel')) $('zq-transport-cancel').addEventListener('click', closeTransportLineModal);
    if ($('zq-transport-save')) $('zq-transport-save').addEventListener('click', saveTransportLineModal);

    // klik na tło NIE zamyka (zamknięcie tylko przyciski / ESC)

    // Transport: live UI (preview + enable/disable dopłat) + Enter = zapisz (poza textarea)
    ;[
      'zq-transport-km','zq-transport-unit-net','zq-transport-min-net',
      'zq-transport-km1','zq-transport-rate1','zq-transport-km2','zq-transport-rate2','zq-transport-rate3','zq-transport-min-net2',
      'zq-transport-x-hds','zq-transport-x-unload','zq-transport-x-sat',
      'zq-transport-disc'
    ].forEach(function(id){
      var el = $(id);
      if (!el) return;
      el.addEventListener('input', function(){ updateTransportPreview(); scheduleDraftSave(); });
      el.addEventListener('change', function(){ updateTransportPreview(); scheduleDraftSave(); });
      el.addEventListener('keydown', function(e){
        if (!e) return;
        if (e.key === 'Enter'){
          e.preventDefault();
          saveTransportLineModal();
        }
      });
    });

    // tryb wyceny
    ;['zq-transport-mode-flat','zq-transport-mode-tier'].forEach(function(id){
      var el = $(id);
      if (!el) return;
      el.addEventListener('change', function(){ setTransportModeUI(getTransportModeUI()); scheduleDraftSave(); });
    });

    // dopłaty on/off
    ;[
      ['zq-transport-x-hds-on','zq-transport-x-hds'],
      ['zq-transport-x-unload-on','zq-transport-x-unload'],
      ['zq-transport-x-sat-on','zq-transport-x-sat']
    ].forEach(function(pair){
      var c = $(pair[0]);
      if (!c) return;
      c.addEventListener('change', function(){
        setExtraEnabled(pair[0], pair[1]);
        updateTransportPreview();
        scheduleDraftSave();
      });
    });

    if ($('zq-transport-no-global')) $('zq-transport-no-global').addEventListener('change', function(){ scheduleDraftSave(); });
    if ($('zq-transport-comment')) $('zq-transport-comment').addEventListener('input', function(){ scheduleDraftSave(); });

    if ($('zq-custom-line-close')) $('zq-custom-line-close').addEventListener('click', closeCustomLineModal);
    if ($('zq-custom-cancel')) $('zq-custom-cancel').addEventListener('click', closeCustomLineModal);
    if ($('zq-custom-save')) $('zq-custom-save').addEventListener('click', saveCustomLineModal);

    // klik na tło NIE zamyka (zamknięcie tylko przyciski / ESC)

    // Enter w modalu -> zapisz (poza textarea)
    ;['zq-custom-name','zq-custom-qty','zq-custom-unit-net','zq-custom-disc'].forEach(function(id){
      var el = $(id);
      if (!el) return;
      el.addEventListener('keydown', function(e){
        if (!e) return;
        if (e.key === 'Enter'){
          e.preventDefault();
          saveCustomLineModal();
        }
      });
    });


    // autosave draft - pola klienta + pola oferty
    ;['zq-client-fullname','zq-client-company','zq-client-nip','zq-client-phone','zq-client-email','zq-client-address','zq-offer-title','zq-offer-comment','zq-valid-days'].forEach(function(id){
      var el = $(id);
      if (!el) return;
      el.addEventListener('input', function(){ scheduleDraftSave(); updateOverwriteAvailability(); });
      el.addEventListener('change', function(){ scheduleDraftSave(); updateOverwriteAvailability(); });
    });

    if ($('zq-save-offer')) $('zq-save-offer').addEventListener('click', saveOffer);
    if ($('zq-overwrite-offer')) $('zq-overwrite-offer').addEventListener('click', overwriteOffer);
    if ($('zq-refresh-history')) $('zq-refresh-history').addEventListener('click', refreshHistory);

    var hs = $('zq-history-search');
    if (hs) hs.addEventListener('input', function(){
      state.historyQuery = hs.value || '';
      renderHistory();
      renderHistoryMini();
    });

    if ($('zq-history-clear')) $('zq-history-clear').addEventListener('click', function(){
      if (hs) hs.value = '';
      state.historyQuery = '';
      renderHistory();
      renderHistoryMini();
      try{ if (hs) hs.focus(); }catch(e){}
    });

    // Historia: filtr statusu + sortowanie
    var hf = $('zq-history-status-filter');
    if (hf){
      try{ hf.value = normHistoryFilter(state.historyStatusFilter); }catch(e){}
      hf.addEventListener('change', function(){
        state.historyStatusFilter = normHistoryFilter(hf.value);
        persistHistoryPrefs();
        renderHistory();
        renderHistoryMini();
      });
    }
    var hdet = $('zq-history-details');
    if (hdet){
      // toggle odpala się przy otwarciu/zamknięciu <details>
      hdet.addEventListener('toggle', function(){
        syncHistoryDetailsUi();
      });
      // Fallback: część przeglądarek/skinów nie emituje 'toggle' w przewidywalny sposób.
      var hsum = hdet.querySelector('summary');
      if (hsum){
        hsum.addEventListener('click', function(){
          // po zmianie stanu <details>
          setTimeout(function(){ try{ syncHistoryDetailsUi(); }catch(e){} }, 0);
        });
      }
    }
    // Gradientowy przycisk: "Pokaż więcej"
    var hexp = $('zq-history-expand');
    if (hexp){
      hexp.addEventListener('click', function(){
        var det = $('zq-history-details');
        if (det && det.style && det.style.display !== 'none'){
          det.open = true;
        }
        try{ syncHistoryDetailsUi(); }catch(e){}
      });
    }

    // Sticky przycisk: "Zwiń listę"
    var hcol = $('zq-history-collapse');
    if (hcol){
      hcol.addEventListener('click', function(){
        var det = $('zq-history-details');
        if (det) det.open = false;
        try{ syncHistoryDetailsUi(); }catch(e){}
        // Opcjonalnie: utrzymaj użytkownika w sekcji historii
        try{
          var ref = $('zq-refresh-history');
          if (ref && ref.scrollIntoView) ref.scrollIntoView({behavior:'smooth', block:'start'});
        }catch(e){}
      });
    }

    // Dokowanie przycisku "Zwiń listę" do modala (widoczny tylko gdy Historia jest w viewport scroll-container).
    try{ setupHistoryCollapseDock(); }catch(e){}

    var hsrt = $('zq-history-sort');
    if (hsrt){
      try{ hsrt.value = normHistorySort(state.historySort); }catch(e){}
      hsrt.addEventListener('change', function(){
        state.historySort = normHistorySort(hsrt.value);
        persistHistoryPrefs();
        renderHistory();
        renderHistoryMini();
      });
    }

    // Modal: status oferty
    if ($('zq-osm-close')) $('zq-osm-close').addEventListener('click', closeOfferStatusModal);
    if ($('zq-osm-cancel')) $('zq-osm-cancel').addEventListener('click', closeOfferStatusModal);
    if ($('zq-osm-save')) $('zq-osm-save').addEventListener('click', saveOfferStatusModal);
    // klik na tło NIE zamyka (zamknięcie tylko przyciski / ESC)
    if ($('zq-osm-status')) $('zq-osm-status').addEventListener('keydown', function(e){
      if (!e) return;
      if (e.key === 'Enter'){ e.preventDefault(); saveOfferStatusModal(); }
      if (e.key === 'Escape'){ e.preventDefault(); closeOfferStatusModal(); }
    });





    // Modal: historia oferty (audyt zmian)
    if ($('zq-ohm-close')) $('zq-ohm-close').addEventListener('click', closeOfferHistoryModal);
    if ($('zq-ohm-ok')) $('zq-ohm-ok').addEventListener('click', closeOfferHistoryModal);
    DOC.addEventListener('keydown', function(ev){
      if (!state._ohm || !state._ohm.open) return;
      if (!ev) return;
      if (ev.key === 'Escape'){
        ev.preventDefault();
        closeOfferHistoryModal();
      }
    }, true);


    // Modal: podgląd oferty (szybki)
    if ($('zq-qvm-close')) $('zq-qvm-close').addEventListener('click', closeOfferPreviewModal);
    if ($('zq-qvm-ok')) $('zq-qvm-ok').addEventListener('click', closeOfferPreviewModal);
    DOC.addEventListener('keydown', function(ev){
      if (!state._qvm || !state._qvm.open) return;
      if (!ev) return;
      if (ev.key === 'Escape'){
        ev.preventDefault();
        closeOfferPreviewModal();
      }
    }, true);

    // Modal: notatka handlowa
    if ($('zq-snm-close')) $('zq-snm-close').addEventListener('click', closeSalesNoteModal);
    if ($('zq-snm-cancel')) $('zq-snm-cancel').addEventListener('click', closeSalesNoteModal);
    if ($('zq-snm-save')) $('zq-snm-save').addEventListener('click', saveSalesNoteModal);
    // klik na tło NIE zamyka (zamknięcie tylko przyciski / ESC)
    if ($('zq-snm-note')) $('zq-snm-note').addEventListener('input', function(){
      if (state._snm) state._snm.dirty = true;
    });
    if ($('zq-snm-note')) $('zq-snm-note').addEventListener('keydown', function(e){
      if (!e) return;
      if (e.key === 'Escape'){ e.preventDefault(); closeSalesNoteModal(); }
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter'){ e.preventDefault(); saveSalesNoteModal(); }
    });


    // Modal: potwierdzenie (bez confirm() - działa w iframe sandbox)
    initConfirmModal();

var hd = $('zq-history-details');
if (hd) hd.addEventListener('toggle', function(){
  if (hd.open) renderHistory();
});


    // auto open (standalone)
    if (!isEmbedded()){
      openPanel(lastPayload || null);
    }
  }


  function getValidityDays(){
    var el = $('zq-valid-days');
    var v = el ? parseNumber(el.value) : null;
    if (v == null) v = 14;
    v = clampNum(v, 1, 365);
    if (el) el.value = String(v);
    return v;
  }

  function getSeller(){
    var acc = state.account || null;
    var out = { login:'', name:'', phone:'', email:'', branch:'' };
    if (acc && acc.login) out.login = String(acc.login);

    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;
    if (perms && perms.seller && typeof perms.seller === 'object'){
      out.name = perms.seller.name ? String(perms.seller.name) : '';
      out.phone = perms.seller.phone ? String(perms.seller.phone) : '';
      out.email = perms.seller.email ? String(perms.seller.email) : '';
      out.branch = perms.seller.branch ? String(perms.seller.branch) : '';
    }
    return out;
  }

  function applySellerToUI(){
    var el = $('zq-seller');
    if (!el) return;
    var s = getSeller();
    var parts = [];
    if (s.name) parts.push(s.name);
    else if (s.login) parts.push(s.login);
    if (s.phone) parts.push(s.phone);
    if (s.email) parts.push(s.email);
    if (s.branch) parts.push(s.branch);
    el.value = parts.join(' | ');
  }

  function getMaxDiscount(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;
    if (perms && Object.prototype.hasOwnProperty.call(perms, 'max_discount_percent')){
      var v = parseNumber(perms.max_discount_percent);
      if (v == null) v = 100;
      return clampNum(v, 0, 100);
    }
    return 100; // brak limitu dla starych kont
  }

  function isSpecialAllowed(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;
    if (perms && Object.prototype.hasOwnProperty.call(perms, 'allow_special_offer')){
      return !!perms.allow_special_offer;
    }
    return true; // kompatybilność
  }

  function canDeleteOffers(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;

    var caps = (state.actorCaps && typeof state.actorCaps === 'object') ? state.actorCaps : null;
    var isImpersonating = !!(state.actor && state.actor.login && acc && acc.login && state.actor.login !== acc.login);

    // W trybie impersonacji: tylko AKTOR z "can_delete_offers_any" może kasować mimo braku uprawnień konta.
    if (isImpersonating){
      if (caps && caps.can_delete_offers_any) return true;
      return !!(perms && (perms.can_delete_offers_any || perms.can_delete_offers_own));
    }

    // Normalnie: bierzemy uprawnienia konta (oraz - dla spójności - caps z /me).
    if (caps && (caps.can_delete_offers_any || caps.can_delete_offers_own)) return true;
    return !!(perms && (perms.can_delete_offers_any || perms.can_delete_offers_own));
  }

  function canDeleteOffersAny(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;

    var caps = (state.actorCaps && typeof state.actorCaps === 'object') ? state.actorCaps : null;
    var isImpersonating = !!(state.actor && state.actor.login && acc && acc.login && state.actor.login !== acc.login);

    if (isImpersonating){
      return !!(caps && caps.can_delete_offers_any);
    }

    if (caps && caps.can_delete_offers_any) return true;
    return !!(perms && perms.can_delete_offers_any);
  }

  

  // v1.2.15.0 - blokowanie/odblokowywanie ofert
  function actorIsSuperAdmin(){
    var caps = (state.actorCaps && typeof state.actorCaps === 'object') ? state.actorCaps : null;
    if (caps && caps.super_admin) return true;

    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;
    return !!(perms && perms.super_admin);
  }

  function canLockOffers(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;

    var caps = (state.actorCaps && typeof state.actorCaps === 'object') ? state.actorCaps : null;
    var isImpersonating = !!(state.actor && state.actor.login && acc && acc.login && state.actor.login !== acc.login);

    if (isImpersonating){
      return !!(caps && (caps.super_admin || caps.can_lock_offers));
    }

    if (caps && (caps.super_admin || caps.can_lock_offers)) return true;
    return !!(perms && (perms.super_admin || perms.can_lock_offers));
  }

  function isOfferLocked(o){
    if (!o) return false;
    return !!(o.locked === 1 || o.locked === '1' || o.locked === true);
  }

  function isFinalOfferStatus(status){
    var st = normOfferStatus(status);
    return (st === 'sent' || st === 'won' || st === 'lost');
  }

function getAllowedTabs(){
    var acc = state.account || null;
    var perms = (acc && acc.perms && typeof acc.perms === 'object') ? acc.perms : null;
    var tabs = [];
    if (perms && Array.isArray(perms.allowed_tabs)){
      perms.allowed_tabs.forEach(function(x){
        x = String(x || '').trim();
        if (x) tabs.push(x);
      });
    }
    return tabs;
  }

  if (DOC.readyState === 'loading') DOC.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
JS;
  }
}


