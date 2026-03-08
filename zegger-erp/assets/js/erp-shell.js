(function () {
  'use strict';

  const CFG = window.ZEGGER_ERP || {};
  const DEFAULT_AUTH_LOGO_URL = 'https://zegger.pl/wp-content/uploads/2025/05/Logo-black-negative-scaled.jpg';
  const STORE_KEY = 'zerp_token';

  const state = {
    token: localStorage.getItem(STORE_KEY) || '',
    me: null,
    modules: { active: [], future: [] },
    currentModule: null,
    moduleInstance: null,
    summary: null,
    viewTransitionToken: 0,
  };

  const el = {
    app: document.getElementById('zerp-app'),
    shellWindow: document.getElementById('zerp-shell-window'),
    authView: document.getElementById('zerp-auth-view'),
    moduleView: document.getElementById('zerp-module-view'),
    sidebar: document.getElementById('zerp-sidebar'),
    modalRoot: document.getElementById('zerp-modal-root'),
    moduleLabel: document.getElementById('zerp-current-module-label'),
    notifier: document.getElementById('zerp-notification-count'),
    btnNotifications: document.getElementById('zerp-open-notifications'),
    btnCommunicator: document.getElementById('zerp-open-communicator'),
    btnNav: document.getElementById('zerp-open-nav'),
    impersonationBanner: document.getElementById('zerp-impersonation-banner'),
    userBtn: document.getElementById('zerp-user-menu-btn'),
  };
  function setToken(token) {
    state.token = token || '';
    if (state.token) {
      localStorage.setItem(STORE_KEY, state.token);
    } else {
      localStorage.removeItem(STORE_KEY);
    }
  }

  function splitPathAndQuery(path) {
    const raw = String(path || '').replace(/^\/+/, '');
    const idx = raw.indexOf('?');
    if (idx === -1) {
      return { routePath: raw, query: '' };
    }
    return {
      routePath: raw.slice(0, idx),
      query: raw.slice(idx + 1),
    };
  }

  function buildApiUrl(path) {
    const baseRaw = (CFG.rest_base || '/wp-json/' + (CFG.rest_ns || 'zegger-erp/v1') + '/').replace(/\/+$/, '/');
    const parts = splitPathAndQuery(path);
    const urlObj = new URL(baseRaw, window.location.origin);

    if (urlObj.searchParams.has('rest_route')) {
      let restRoute = String(urlObj.searchParams.get('rest_route') || '/');
      if (restRoute.charAt(0) !== '/') {
        restRoute = '/' + restRoute;
      }
      restRoute = restRoute.replace(/\/+$/, '/') + parts.routePath;
      urlObj.searchParams.set('rest_route', restRoute);
    } else {
      urlObj.pathname = urlObj.pathname.replace(/\/+$/, '/') + parts.routePath;
    }

    if (parts.query) {
      const qp = new URLSearchParams(parts.query);
      qp.forEach(function (value, key) {
        urlObj.searchParams.set(key, value);
      });
    }

    return urlObj.toString();
  }

  async function api(path, opts) {
    const cfg = Object.assign({ method: 'GET', body: null }, opts || {});
    const url = buildApiUrl(path);

    const headers = { 'Accept': 'application/json' };
    if (CFG.nonce) {
      headers['X-WP-Nonce'] = CFG.nonce;
    }
    if (state.token) {
      headers['Authorization'] = 'Bearer ' + state.token;
      headers['X-ZERP-Token'] = state.token;
    }
    if (cfg.body !== null) {
      headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, {
      method: cfg.method,
      credentials: 'include',
      headers,
      body: cfg.body !== null ? JSON.stringify(cfg.body) : null,
    });

    const isJson = (res.headers.get('content-type') || '').indexOf('application/json') !== -1;
    const payload = isJson ? await res.json() : null;

    if (!res.ok) {
      const msg = payload && payload.message ? payload.message : 'Błąd HTTP ' + res.status;
      const err = new Error(msg);
      err.status = res.status;
      err.payload = payload;
      throw err;
    }

    return payload || {};
  }

  function showInfo(container, message, isError) {
    if (!container) {
      return;
    }
    container.innerHTML = '<div class="' + (isError ? 'zerp-error' : 'zerp-info') + '">' + escapeHtml(message) + '</div>';
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function stableHash(input) {
    const source = String(input || '');
    let hash = 2166136261;
    for (let i = 0; i < source.length; i += 1) {
      hash ^= source.charCodeAt(i);
      hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
    }
    return ('h' + (hash >>> 0).toString(16));
  }

  function moduleMeta(moduleId) {
    const map = {
      dashboard: { icon: 'DS', hint: 'Launcher' },
      offers: { icon: 'OF', hint: 'Oferty' },
      communicator: { icon: 'KO', hint: 'Komunikator' },
      company_users: { icon: 'FU', hint: 'Firma' },
      catalog: { icon: 'PR', hint: 'Produkty' },
      notifications: { icon: 'NO', hint: 'Alerty' }
    };
    return map[moduleId] || { icon: 'MD', hint: 'Modul' };
  }

  function playShellEnterAnimation() {
    document.body.classList.add('zerp-shell-enter');
    window.setTimeout(function () {
      document.body.classList.remove('zerp-shell-enter');
    }, 460);
  }

  function runModuleTransition(renderFn) {
    state.viewTransitionToken += 1;
    const token = state.viewTransitionToken;
    el.moduleView.classList.add('is-view-leaving');

    window.setTimeout(function () {
      if (token !== state.viewTransitionToken) {
        return;
      }

      renderFn();
      el.moduleView.classList.remove('is-view-leaving');
      el.moduleView.classList.add('is-view-enter');
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          el.moduleView.classList.remove('is-view-enter');
        });
      });
    }, 120);
  }

  function closeNavDrawer() {
    document.body.classList.remove('zerp-nav-open');
  }

  function toggleNavDrawer(forceOpen) {
    if (document.body.classList.contains('zerp-prelogin')) {
      return;
    }

    if (typeof forceOpen === 'boolean') {
      document.body.classList.toggle('zerp-nav-open', forceOpen);
      return;
    }

    document.body.classList.toggle('zerp-nav-open');
  }

  function authLogoMarkup() {
    const src = escapeHtml(String(CFG.auth_logo_url || DEFAULT_AUTH_LOGO_URL));
    return '<div class="zerp-auth-brand-mark"><img src="' + src + '" alt="Zegger"></div>';
  }
  function mountModal(title, bodyHtml, actions) {
    const back = document.createElement('div');
    back.className = 'zerp-modal-backdrop';

    const buttons = (actions || []).map(function (a, idx) {
      return '<button data-idx="' + idx + '" class="zerp-btn ' + (a.className || '') + '">' + escapeHtml(a.label) + '</button>';
    }).join('');

    back.innerHTML = '' +
      '<div class="zerp-modal">' +
      '  <h3 style="margin:0 0 8px">' + escapeHtml(title) + '</h3>' +
      '  <div style="margin-bottom:10px">' + bodyHtml + '</div>' +
      '  <div class="zerp-actions">' + buttons + '</div>' +
      '</div>';

    back.addEventListener('click', function (ev) {
      const btn = ev.target.closest('button[data-idx]');
      if (!btn) {
        return;
      }
      const idx = parseInt(btn.getAttribute('data-idx') || '-1', 10);
      if (idx < 0 || !actions[idx]) {
        return;
      }
      const action = actions[idx];
      if (typeof action.onClick === 'function') {
        action.onClick();
      }
      if (!action.keepOpen) {
        back.remove();
      }
    });

    back.addEventListener('click', function (ev) {
      if (ev.target === back) {
        back.remove();
      }
    });

    el.modalRoot.innerHTML = '';
    el.modalRoot.appendChild(back);
  }

  function setPreLoginMode(enabled) {
    const on = !!enabled;
    document.body.classList.toggle('zerp-prelogin', on);
    if (on) {
      closeNavDrawer();
    }

    if (el.app) {
      el.app.classList.toggle('zerp-prelogin', on);
    }

    if (el.shellWindow) {
      el.shellWindow.classList.toggle('is-prelogin', on);
      el.shellWindow.classList.toggle('is-app', !on);
    }

    if (on) {
      el.modalRoot.innerHTML = '';
      el.sidebar.innerHTML = '';
      el.moduleView.hidden = true;
      el.moduleView.classList.remove('is-view-enter', 'is-view-leaving');
      el.moduleView.innerHTML = '';
      el.impersonationBanner.hidden = true;
      el.impersonationBanner.innerHTML = '';
      el.moduleLabel.textContent = 'Zegger ERP';
      return;
    }

    el.moduleView.classList.remove('is-view-enter', 'is-view-leaving');
  }
  function clearViews() {
    el.authView.hidden = true;
    el.moduleView.hidden = true;
    el.authView.innerHTML = '';
    el.moduleView.innerHTML = '';
  }

  function renderAuthLoading() {
    clearViews();
    setPreLoginMode(true);
    el.authView.hidden = false;
    el.authView.innerHTML = '<div class="zerp-auth-scene"><div class="zerp-auth-backdrop"></div><div class="zerp-auth-overlay"></div><section class="zerp-auth-card"><header class="zerp-auth-head">' + authLogoMarkup() + '<p class="zerp-auth-kicker">Zegger ERP</p><h1>Weryfikacja sesji</h1><p class="zerp-auth-lead">Sprawdzamy Twoje konto. To potrwa chwilę.</p></header><div class="zerp-info">Ładowanie...</div></section></div>';
  }

  function wirePasswordToggles(scope) {
    if (!scope) {
      return;
    }

    scope.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const wrap = btn.closest('.zerp-input-wrap');
        const input = wrap ? wrap.querySelector('input') : null;
        if (!input) {
          return;
        }

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło');
        btn.classList.toggle('is-active', show);
      });
    });
  }

  function renderAuth() {
    clearViews();
    setPreLoginMode(true);
    el.authView.hidden = false;

    el.authView.innerHTML = `
      <div class="zerp-auth-scene">
        <div class="zerp-auth-backdrop"></div>
        <div class="zerp-auth-overlay"></div>
        <section class="zerp-auth-card" aria-label="Ekran logowania Zegger ERP">
          <header class="zerp-auth-head">
            ${authLogoMarkup()}
            <p class="zerp-auth-kicker">Strefa dostępu</p>
            <h1>Logowanie do Zegger ERP</h1>
            <p class="zerp-auth-lead">Nowoczesne środowisko ofert, relacji i komunikacji w jednym miejscu.</p>
          </header>

          <div class="zerp-auth-segment" role="tablist" aria-label="Sekcje konta">
            <button class="zerp-auth-segment-btn is-active" type="button" role="tab" aria-selected="true" data-auth-tab="login">Logowanie</button>
            <button class="zerp-auth-segment-btn" type="button" role="tab" aria-selected="false" data-auth-tab="register-company">Nowa firma</button>
            <button class="zerp-auth-segment-btn" type="button" role="tab" aria-selected="false" data-auth-tab="register-member">Dołącz do firmy</button>
          </div>

          <div id="zerp-auth-feedback" class="zerp-auth-feedback" aria-live="polite"></div>
          <div id="zerp-auth-body"></div>
        </section>
      </div>`;

    const feedback = document.getElementById('zerp-auth-feedback');
    const body = document.getElementById('zerp-auth-body');

    function setTab(tab) {
      el.authView.querySelectorAll('[data-auth-tab]').forEach(function (btn) {
        const active = btn.getAttribute('data-auth-tab') === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      if (tab === 'login') {
        body.innerHTML = `
          <form id="zerp-login-form" class="zerp-form-grid">
            <div class="zerp-field">
              <label>Login lub e-mail</label>
              <input class="zerp-input" name="login" autocomplete="username" required>
            </div>
            <div class="zerp-field">
              <label>Hasło</label>
              <div class="zerp-input-wrap">
                <input class="zerp-input" name="password" type="password" autocomplete="current-password" required>
                <button class="zerp-input-toggle" type="button" data-toggle-password aria-label="Pokaż hasło">&#128065;</button>
              </div>
            </div>
            <div class="zerp-actions">
              <button class="zerp-btn zerp-btn-primary" type="submit">Zaloguj</button>
            </div>
          </form>`;

        wirePasswordToggles(body);

        document.getElementById('zerp-login-form').addEventListener('submit', async function (ev) {
          ev.preventDefault();
          showInfo(feedback, 'Logowanie...', false);
          const form = new FormData(ev.currentTarget);
          try {
            const result = await api('auth/login', {
              method: 'POST',
              body: {
                login: String(form.get('login') || ''),
                password: String(form.get('password') || ''),
              },
            });
            setToken(result.token || '');
            await afterAuth();
          } catch (err) {
            showInfo(feedback, err.message || 'Błąd logowania.', true);
          }
        });
        return;
      }

      if (tab === 'register-company') {
        body.innerHTML = `
          <form id="zerp-register-company" class="zerp-form-grid cols-2 zerp-auth-form-complex">
            <div class="zerp-auth-progress" style="grid-column:1/-1">
              <span class="zerp-auth-step is-active">1. Dane konta</span>
              <span class="zerp-auth-step">2. Dane firmy</span>
            </div>

            <div class="zerp-auth-section-label" style="grid-column:1/-1">Krok 1 - Konto użytkownika</div>
            <div class="zerp-field"><label>Imię</label><input class="zerp-input" name="first_name" required></div>
            <div class="zerp-field"><label>Nazwisko</label><input class="zerp-input" name="last_name" required></div>
            <div class="zerp-field"><label>E-mail</label><input class="zerp-input" name="email" type="email" autocomplete="email" required></div>
            <div class="zerp-field"><label>Telefon</label><input class="zerp-input" name="phone" autocomplete="tel"></div>
            <div class="zerp-field" style="grid-column:1/-1">
              <label>Hasło</label>
              <div class="zerp-input-wrap">
                <input class="zerp-input" name="password" type="password" minlength="8" autocomplete="new-password" required>
                <button class="zerp-input-toggle" type="button" data-toggle-password aria-label="Pokaż hasło">&#128065;</button>
              </div>
            </div>

            <div class="zerp-auth-section-label" style="grid-column:1/-1">Krok 2 - Profil firmy</div>
            <div class="zerp-field"><label>Nazwa firmy</label><input class="zerp-input" name="company_name" required></div>
            <div class="zerp-field"><label>NIP</label><input class="zerp-input" name="company_nip" required></div>
            <div class="zerp-field"><label>E-mail firmy</label><input class="zerp-input" name="company_email" type="email" required></div>
            <div class="zerp-field"><label>Telefon firmy</label><input class="zerp-input" name="company_phone"></div>
            <div class="zerp-field" style="grid-column:1/-1"><label>Adres firmy</label><textarea name="company_address"></textarea></div>

            <div class="zerp-actions" style="grid-column:1/-1">
              <button class="zerp-btn zerp-btn-primary" type="submit">Utwórz firmę</button>
            </div>
          </form>`;

        wirePasswordToggles(body);

        document.getElementById('zerp-register-company').addEventListener('submit', async function (ev) {
          ev.preventDefault();
          showInfo(feedback, 'Rejestracja firmy...', false);
          const form = new FormData(ev.currentTarget);
          const bodyPayload = Object.fromEntries(form.entries());
          try {
            const result = await api('auth/register/company', { method: 'POST', body: bodyPayload });
            if (result.session && result.session.token) {
              setToken(result.session.token);
              await afterAuth();
              return;
            }
            showInfo(feedback, 'Firma utworzona. Zaloguj się.', false);
            setTab('login');
          } catch (err) {
            showInfo(feedback, err.message || 'Błąd rejestracji.', true);
          }
        });
        return;
      }

      body.innerHTML = `
        <form id="zerp-register-member" class="zerp-form-grid cols-2 zerp-auth-form-complex">
          <div class="zerp-auth-section-label" style="grid-column:1/-1">Konto użytkownika</div>
          <div class="zerp-field"><label>Imię</label><input class="zerp-input" name="first_name" required></div>
          <div class="zerp-field"><label>Nazwisko</label><input class="zerp-input" name="last_name" required></div>
          <div class="zerp-field"><label>E-mail</label><input class="zerp-input" name="email" type="email" required></div>
          <div class="zerp-field"><label>Telefon</label><input class="zerp-input" name="phone"></div>
          <div class="zerp-field" style="grid-column:1/-1">
            <label>Hasło</label>
            <div class="zerp-input-wrap">
              <input class="zerp-input" name="password" type="password" minlength="8" autocomplete="new-password" required>
              <button class="zerp-input-toggle" type="button" data-toggle-password aria-label="Pokaż hasło">&#128065;</button>
            </div>
          </div>

          <div class="zerp-auth-section-label" style="grid-column:1/-1">Dane dołączenia</div>
          <div class="zerp-field"><label>Nazwa firmy (opcjonalnie)</label><input class="zerp-input" name="company_query"></div>
          <div class="zerp-field"><label>Join code (opcjonalnie)</label><input class="zerp-input" name="join_code"></div>
          <p class="zerp-auth-help" style="grid-column:1/-1">Jeśli masz kod zaproszenia firmy, wpisz go. Wniosek szybciej trafi do właściwego ownera.</p>

          <div class="zerp-actions" style="grid-column:1/-1">
            <button class="zerp-btn zerp-btn-primary" type="submit">Wyślij prośbę o dołączenie</button>
          </div>
        </form>`;

      wirePasswordToggles(body);

      document.getElementById('zerp-register-member').addEventListener('submit', async function (ev) {
        ev.preventDefault();
        showInfo(feedback, 'Wysyłanie prośby...', false);
        const form = new FormData(ev.currentTarget);
        const bodyPayload = Object.fromEntries(form.entries());
        try {
          await api('auth/register/member', { method: 'POST', body: bodyPayload });
          showInfo(feedback, 'Wniosek wysłany. Poczekaj na akceptację.', false);
          setTab('login');
        } catch (err) {
          showInfo(feedback, err.message || 'Błąd rejestracji.', true);
        }
      });
    }

    el.authView.querySelectorAll('[data-auth-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setTab(btn.getAttribute('data-auth-tab') || 'login');
      });
    });

    setTab('login');
  }

  function updateTopbar() {
    const firstName = state.me && state.me.first_name ? state.me.first_name : 'Konto';
    const company = state.me && state.me.company && state.me.company.name ? ' - ' + state.me.company.name : '';
    el.userBtn.textContent = firstName + company;

    if (state.me && state.me.actor_member_id && state.me.actor_member_id !== state.me.id) {
      el.impersonationBanner.hidden = false;
      el.impersonationBanner.innerHTML = '<span>Tryb impersonacji jest aktywny.</span><button class="zerp-btn" id="zerp-stop-impersonation">Wróć do swojego konta</button>';
      document.getElementById('zerp-stop-impersonation').addEventListener('click', async function () {
        try {
          const result = await api('auth/restore-self', { method: 'POST', body: {} });
          setToken(result.token || '');
          await afterAuth();
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zakończyć impersonacji.') + '</p>', [{ label: 'OK' }]);
        }
      });
    } else {
      el.impersonationBanner.hidden = true;
      el.impersonationBanner.innerHTML = '';
    }
  }

  async function afterAuth() {
    try {
      const me = await api('auth/me');
      state.me = me.member || null;
      state.modules = me.modules || { active: [], future: [] };
      setPreLoginMode(false);
      updateTopbar();
      renderApp();
      playShellEnterAnimation();
      await refreshUnreadCount();
    } catch (err) {
      setToken('');
      state.me = null;
      setPreLoginMode(true);
      renderAuth();
    }
  }

  function renderApp() {
    setPreLoginMode(false);
    clearViews();
    el.moduleView.hidden = false;
    renderSidebar();

    let target = 'dashboard';
    if (!state.modules.active.some(function (m) { return m.id === target; })) {
      target = state.modules.active.length ? state.modules.active[0].id : null;
    }

    if (!target) {
      el.moduleView.innerHTML = '<div class="zerp-card"><h3>Brak dostępnych modułów</h3><p class="zerp-muted">Skontaktuj się z ownerem firmy, aby nadać widoczność modułów.</p></div>';
      return;
    }

    switchModule(target);
  }

  function renderSidebar() {
    const active = state.modules.active || [];
    const future = state.modules.future || [];
    const desktop = [];
    const mobile = [];

    active.forEach(function (mod) {
      const meta = moduleMeta(mod.id);
      const node = '' +
        '<button class="zerp-nav-pill zerp-sidebar-item" data-module="' + escapeHtml(mod.id) + '">' +
        '  <span class="zerp-nav-pill-icon" aria-hidden="true">' + escapeHtml(meta.icon) + '</span>' +
        '  <span class="zerp-sidebar-item-label">' + escapeHtml(mod.label) + '</span>' +
        '</button>';
      desktop.push(node);
      mobile.push(node);
    });

    if (future.length) {
      desktop.push('<div class="zerp-nav-divider">W produkcji</div>');
      mobile.push('<div class="zerp-nav-divider">W produkcji</div>');

      future.forEach(function (mod) {
        const meta = moduleMeta(mod.id);
        const node = '' +
          '<button class="zerp-nav-pill zerp-sidebar-item is-disabled" data-disabled="1" title="W produkcji">' +
          '  <span class="zerp-nav-pill-icon" aria-hidden="true">' + escapeHtml(meta.icon) + '</span>' +
          '  <span class="zerp-sidebar-item-label">' + escapeHtml(mod.label) + '</span>' +
          '</button>';
        desktop.push(node);
        mobile.push(node);
      });
    }

    el.sidebar.innerHTML = '' +
      '<div class="zerp-nav-track zerp-nav-track-desktop">' + desktop.join('') + '</div>' +
      '<div class="zerp-nav-mobile-backdrop" data-nav-close="1"></div>' +
      '<div class="zerp-nav-drawer-panel" role="dialog" aria-label="Nawigacja modulow">' +
      '  <div class="zerp-nav-drawer-head">' +
      '    <strong>Moduly ERP</strong>' +
      '    <button type="button" class="zerp-btn" data-nav-close="1">Zamknij</button>' +
      '  </div>' +
      '  <div class="zerp-nav-track zerp-nav-track-mobile">' + mobile.join('') + '</div>' +
      '</div>';

    el.sidebar.querySelectorAll('[data-module]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchModule(btn.getAttribute('data-module') || 'dashboard');
      });
    });

    el.sidebar.querySelectorAll('[data-nav-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeNavDrawer();
      });
    });
  }
  async function switchModule(targetModule) {
    if (!targetModule || targetModule === state.currentModule) {
      return;
    }

    if (state.moduleInstance && typeof state.moduleInstance.hasUnsavedChanges === 'function' && state.moduleInstance.hasUnsavedChanges()) {
      mountModal('Niezapisane zmiany', '<p>Wykryto niezapisane zmiany. Co chcesz zrobić?</p>', [
        {
          label: 'Zapisz',
          className: 'zerp-btn-primary',
          onClick: async function () {
            if (state.moduleInstance && typeof state.moduleInstance.saveBeforeLeave === 'function') {
              await state.moduleInstance.saveBeforeLeave();
            }
            doSwitch(targetModule);
          }
        },
        {
          label: 'Odrzuć',
          className: 'zerp-btn-danger',
          onClick: function () {
            if (state.moduleInstance && typeof state.moduleInstance.discardChanges === 'function') {
              state.moduleInstance.discardChanges();
            }
            doSwitch(targetModule);
          }
        },
        { label: 'Anuluj' }
      ]);
      return;
    }

    doSwitch(targetModule);
  }

  function doSwitch(targetModule) {
    const previousModule = state.currentModule;
    state.currentModule = targetModule;

    if (state.moduleInstance && typeof state.moduleInstance.unmount === 'function') {
      state.moduleInstance.unmount();
    }

    el.sidebar.querySelectorAll('.zerp-sidebar-item[data-module]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-module') === targetModule);
    });

    const labelMap = {};
    (state.modules.active || []).forEach(function (mod) { labelMap[mod.id] = mod.label; });
    el.moduleLabel.textContent = labelMap[targetModule] || 'Modul';

    const renderTarget = function () {
      if (targetModule === 'dashboard') {
        state.moduleInstance = moduleDashboard();
        return;
      }
      if (targetModule === 'offers') {
        state.moduleInstance = moduleOffers();
        return;
      }
      if (targetModule === 'communicator') {
        state.moduleInstance = moduleCommunicator();
        return;
      }
      if (targetModule === 'company_users') {
        state.moduleInstance = moduleCompanyUsers();
        return;
      }
      if (targetModule === 'catalog') {
        state.moduleInstance = moduleCatalog();
        return;
      }
      if (targetModule === 'notifications') {
        state.moduleInstance = moduleNotifications();
        return;
      }

      el.moduleView.innerHTML = '<div class="zerp-card"><h3>Modul niedostepny</h3></div>';
      state.moduleInstance = { hasUnsavedChanges: function () { return false; } };
    };

    if (!previousModule) {
      renderTarget();
      closeNavDrawer();
      return;
    }

    runModuleTransition(renderTarget);
    closeNavDrawer();
  }
  function moduleDashboard() {
    const wrap = document.createElement('div');
    wrap.className = 'zerp-workspace zerp-workspace-dashboard zerp-module-shell';
    wrap.innerHTML = '' +
      '<section class="zerp-launcher-hero zerp-window-hero">' +
      '  <div class="zerp-launcher-hero-copy">' +
      '    <p class="zerp-kicker">Zegger ERP</p>' +
      '    <h2>Centrum pracy</h2>' +
      '    <p class="zerp-launcher-lead">Wybierz obszar roboczy i przechodz miedzy modulami w jednym, kompaktowym oknie aplikacji.</p>' +
      '  </div>' +
      '  <div class="zerp-summary-strip zerp-summary-strip--launcher" id="zerp-summary-strip"></div>' +
      '</section>' +
      '<section class="zerp-launcher-grid" id="zerp-dashboard-grid"></section>' +
      '<section class="zerp-launcher-future" id="zerp-dashboard-future"></section>';

    el.moduleView.innerHTML = '';
    el.moduleView.appendChild(wrap);

    const tileContainer = wrap.querySelector('#zerp-dashboard-grid');
    const summaryContainer = wrap.querySelector('#zerp-summary-strip');
    const futureContainer = wrap.querySelector('#zerp-dashboard-future');

    (state.modules.active || []).forEach(function (mod) {
      const meta = moduleMeta(mod.id);
      const tile = document.createElement('button');
      tile.className = 'zerp-launcher-tile';
      tile.type = 'button';
      tile.innerHTML = '' +
        '<div class="zerp-launcher-tile-top">' +
        '  <span class="zerp-launcher-icon" aria-hidden="true">' + escapeHtml(meta.icon) + '</span>' +
        '  <span class="zerp-launcher-chip">' + escapeHtml(meta.hint) + '</span>' +
        '</div>' +
        '<div class="zerp-launcher-tile-title">' + escapeHtml(mod.label) + '</div>' +
        '<div class="zerp-launcher-tile-desc">' + escapeHtml(mod.description || '') + '</div>' +
        '<div class="zerp-launcher-tile-cta">Przejdz do modulu</div>';
      tile.addEventListener('click', function () { switchModule(mod.id); });
      tileContainer.appendChild(tile);
    });

    const future = state.modules.future || [];
    if (future.length) {
      const title = document.createElement('h3');
      title.className = 'zerp-launcher-future-title';
      title.textContent = 'Moduly w produkcji';
      futureContainer.appendChild(title);

      const grid = document.createElement('div');
      grid.className = 'zerp-launcher-future-grid';

      future.forEach(function (mod) {
        const meta = moduleMeta(mod.id);
        const tile = document.createElement('div');
        tile.className = 'zerp-launcher-tile is-disabled';
        tile.innerHTML = '' +
          '<div class="zerp-launcher-tile-top">' +
          '  <span class="zerp-launcher-icon" aria-hidden="true">' + escapeHtml(meta.icon) + '</span>' +
          '  <span class="zerp-launcher-chip">W produkcji</span>' +
          '</div>' +
          '<div class="zerp-launcher-tile-title">' + escapeHtml(mod.label) + '</div>' +
          '<div class="zerp-launcher-tile-desc">Modul jest przygotowywany do uruchomienia.</div>';
        grid.appendChild(tile);
      });

      futureContainer.appendChild(grid);
    }

    api('app/summary').then(function (result) {
      const s = (result && result.summary) || {};
      summaryContainer.innerHTML = '' +
        '<div class="zerp-summary-item"><div class="zerp-muted">Powiadomienia</div><div class="zerp-summary-value">' + (s.unread_notifications || 0) + '</div></div>' +
        '<div class="zerp-summary-item"><div class="zerp-muted">Rozmowy</div><div class="zerp-summary-value">' + (s.unread_threads || 0) + '</div></div>' +
        '<div class="zerp-summary-item"><div class="zerp-muted">Wnioski</div><div class="zerp-summary-value">' + (s.pending_join_requests || 0) + '</div></div>';
    }).catch(function () {
      summaryContainer.innerHTML = '<div class="zerp-summary-item">Brak danych podsumowania.</div>';
    });

    return {
      unmount: function () {},
      hasUnsavedChanges: function () { return false; },
      saveBeforeLeave: function () {},
      discardChanges: function () {}
    };
  }
  function moduleOffers() {
    const cardHtml = '' +
      '<div class="zerp-card">' +
      '  <div class="zerp-row" style="margin-bottom:8px">' +
      '    <strong>Panel Ofertowy</strong>' +
      '    <a class="zerp-btn" target="_blank" rel="noopener" href="' + escapeHtml(CFG.legacy_offer_panel_url || '#') + '">Otwórz w nowej karcie</a>' +
      '  </div>' +
      '  <div id="zerp-offers-mount-point" class="zerp-offers-inline-host"><div class="zerp-muted">Ładowanie Panelu Ofertowego...</div></div>' +
      '</div>';

    el.moduleView.innerHTML = cardHtml;

    const mountPoint = document.getElementById('zerp-offers-mount-point');
    if (!mountPoint) {
      return {
        unmount: function () {},
        hasUnsavedChanges: function () { return false; },
        saveBeforeLeave: function () {},
        discardChanges: function () {}
      };
    }

    let globalHost = document.getElementById('zerp-offers-global-host');
    if (!globalHost) {
      globalHost = document.createElement('div');
      globalHost.id = 'zerp-offers-global-host';
      globalHost.className = 'zerp-offers-inline-host';
      globalHost.hidden = true;
      document.body.appendChild(globalHost);
    }

    const moveHostToMount = function () {
      if (mountPoint && globalHost && globalHost.parentNode !== mountPoint) {
        mountPoint.innerHTML = '';
        mountPoint.appendChild(globalHost);
      }
      globalHost.hidden = false;
    };

    const hideHost = function () {
      if (!globalHost) {
        return;
      }
      if (globalHost.parentNode !== document.body) {
        document.body.appendChild(globalHost);
      }
      globalHost.hidden = true;
    };

    const executeInlineScripts = async function (doc, legacyBaseUrl) {
      const scripts = Array.prototype.slice.call(doc.querySelectorAll('script'));
      for (let i = 0; i < scripts.length; i += 1) {
        const srcRaw = scripts[i].getAttribute('src');
        const code = scripts[i].textContent || '';

        await new Promise(function (resolve, reject) {
          const s = document.createElement('script');
          s.setAttribute('data-zerp-offers-runtime', '1');

          if (srcRaw) {
            let resolvedSrc = srcRaw;
            try {
              resolvedSrc = new URL(srcRaw, legacyBaseUrl).toString();
            } catch (e) {
              // fallback to original src
            }
            s.src = resolvedSrc;
            s.async = false;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('Nie udało się załadować skryptu legacy: ' + resolvedSrc)); };
          } else {
            s.text = code;
            resolve();
          }

          document.body.appendChild(s);
        });
      }
    };
    const injectLegacyMarkup = async function (html, legacyBaseUrl) {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      const headStyles = Array.prototype.slice.call(doc.head.querySelectorAll('style,link[rel="stylesheet"]'));
      headStyles.forEach(function (node) {
        if (node.tagName === 'LINK') {
          const hrefRaw = node.getAttribute('href') || '';
          if (!hrefRaw) {
            return;
          }

          let hrefAbs = hrefRaw;
          try {
            hrefAbs = new URL(hrefRaw, legacyBaseUrl).toString();
          } catch (e) {
            // fallback to raw href
          }

          const alreadyLinked = Array.prototype.some.call(
            document.head.querySelectorAll('link[data-zerp-offers-style]'),
            function (elNode) { return elNode.getAttribute('data-zerp-offers-style') === hrefAbs; }
          );

          if (alreadyLinked) {
            return;
          }

          const l = document.createElement('link');
          l.rel = 'stylesheet';
          l.href = hrefAbs;
          l.setAttribute('data-zerp-offers-style', hrefAbs);
          document.head.appendChild(l);
          return;
        }

        const cssText = node.textContent || '';
        if (!cssText) {
          return;
        }

        const key = stableHash(cssText);
        const hasStyle = Array.prototype.some.call(
          document.head.querySelectorAll('style[data-zerp-offers-style-key]'),
          function (elNode) { return elNode.getAttribute('data-zerp-offers-style-key') === key; }
        );

        if (hasStyle) {
          return;
        }

        const s = document.createElement('style');
        s.setAttribute('data-zerp-offers-style-key', key);
        s.textContent = cssText;
        document.head.appendChild(s);
      });

      const bodyNodes = Array.prototype.slice.call(doc.body.childNodes);
      const scriptNodes = [];

      globalHost.innerHTML = '';
      bodyNodes.forEach(function (node) {
        if (node.nodeType === 1 && node.tagName === 'SCRIPT') {
          scriptNodes.push(node);
          return;
        }
        const clone = node.cloneNode(true);
        globalHost.appendChild(clone);
      });

      const scriptDoc = document.implementation.createHTMLDocument('zerp-offers-script-doc');
      scriptNodes.forEach(function (n) {
        scriptDoc.body.appendChild(n.cloneNode(true));
      });

      window.ZQOS = window.ZQOS || {};
      window.ZQOS.forceEmbed = true;

      await executeInlineScripts(scriptDoc, legacyBaseUrl);
      window.postMessage({ type: 'zq:offer:open', payload: null }, window.location.origin);
    };
    const mountOrLoad = async function () {
      moveHostToMount();

      if (window.__ZERP_OFFERS_LEGACY_READY) {
        window.ZQOS = window.ZQOS || {};
        window.ZQOS.forceEmbed = true;
        window.postMessage({ type: 'zq:offer:open', payload: null }, window.location.origin);
        return;
      }

      const url = CFG.legacy_offer_panel_url || '';
      if (!url) {
        mountPoint.innerHTML = '<div class="zerp-error">Brak URL modułu ofertowego.</div>';
        return;
      }

      const response = await fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Accept': 'text/html' }
      });

      if (!response.ok) {
        throw new Error('Nie udało się załadować Panelu Ofertowego. HTTP ' + response.status);
      }

      const html = await response.text();
      const resolvedLegacyUrl = response.url || url;
      await injectLegacyMarkup(html, resolvedLegacyUrl);
      window.__ZERP_OFFERS_LEGACY_READY = true;
    };

    mountOrLoad().catch(function (err) {
      mountPoint.innerHTML = '<div class="zerp-error">' + escapeHtml(err.message || 'Błąd ładowania modułu ofertowego.') + '</div>';
    });

    return {
      unmount: function () {
        hideHost();
      },
      hasUnsavedChanges: function () { return false; },
      saveBeforeLeave: function () {},
      discardChanges: function () {}
    };
  }

  function permission(key) {
    return !!(state.me && state.me.permissions && state.me.permissions[key]);
  }

  function findActiveModule(id) {
    return (state.modules.active || []).find(function (m) { return m.id === id; }) || null;
  }

  function moduleUnavailable(label) {
    el.moduleView.innerHTML = '<div class="zerp-card"><h3>' + escapeHtml(label || 'Moduł niedostępny') + '</h3><p class="zerp-muted">Brak wymaganych uprawnień lub moduł nie został aktywowany.</p></div>';
    return {
      unmount: function () {},
      hasUnsavedChanges: function () { return false; },
      saveBeforeLeave: function () {},
      discardChanges: function () {}
    };
  }

  async function refreshUnreadCount() {
    if (!state.me) {
      el.notifier.textContent = '0';
      return;
    }

    try {
      const result = await api('notifications/unread-count');
      const count = result && typeof result.count === 'number' ? result.count : 0;
      el.notifier.textContent = String(count);
      el.notifier.hidden = count <= 0;
    } catch (err) {
      el.notifier.textContent = '0';
      el.notifier.hidden = false;
    }
  }

  function showNotificationsModal() {
    if (!state.me || !permission('can_view_notifications')) {
      mountModal('Powiadomienia', '<p>Brak uprawnień do centrum powiadomień.</p>', [{ label: 'OK' }]);
      return;
    }

    api('notifications?limit=50').then(function (result) {
      const items = result && Array.isArray(result.items) ? result.items : [];
      const unread = items.filter(function (item) { return !item.is_read; });
      const body = [];

      body.push('<div class="zerp-list" style="max-height:60dvh;overflow:auto">');
      if (!items.length) {
        body.push('<div class="zerp-list-item">Brak powiadomień.</div>');
      }

      items.forEach(function (item) {
        body.push(
          '<div class="zerp-list-item">' +
          '  <div class="zerp-row"><strong>' + escapeHtml(item.title || '') + '</strong><span class="zerp-muted">' + escapeHtml(item.created_at || '') + '</span></div>' +
          '  <div class="zerp-muted" style="margin-top:4px">' + escapeHtml(item.notification_type || '') + '</div>' +
          '  <div style="margin-top:6px">' + escapeHtml(item.body || '') + '</div>' +
          (item.is_read ? '' : '<div class="zerp-muted" style="margin-top:6px">Nieprzeczytane</div>') +
          '</div>'
        );
      });
      body.push('</div>');

      mountModal('Powiadomienia', body.join(''), [
        {
          label: unread.length ? 'Oznacz nieprzeczytane jako przeczytane' : 'Odśwież',
          className: 'zerp-btn-primary',
          onClick: function () {
            if (!unread.length) {
              showNotificationsModal();
              return;
            }
            api('notifications/read', {
              method: 'POST',
              body: { ids: unread.map(function (x) { return x.id; }) }
            }).then(function () {
              refreshUnreadCount();
              showNotificationsModal();
            }).catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się oznaczyć powiadomień.') + '</p>', [{ label: 'OK' }]);
            });
          }
        },
        { label: 'Zamknij' }
      ]);
    }).catch(function (err) {
      mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się pobrać powiadomień.') + '</p>', [{ label: 'OK' }]);
    });
  }

  function openUserMenu() {
    if (!state.me) {
      return;
    }

    const content = '' +
      '<div class="zerp-list">' +
      '  <div class="zerp-list-item"><strong>' + escapeHtml((state.me.first_name || '') + ' ' + (state.me.last_name || '')) + '</strong><div class="zerp-muted">' + escapeHtml(state.me.login || '') + '</div></div>' +
      '  <div class="zerp-list-item"><div class="zerp-muted">Firma</div><strong>' + escapeHtml((state.me.company && state.me.company.name) || '-') + '</strong></div>' +
      '  <div class="zerp-list-item"><div class="zerp-muted">Rola</div><strong>' + escapeHtml(state.me.role || '-') + '</strong></div>' +
      '</div>';

    mountModal('Konto', content, [
      {
        label: 'Wyloguj',
        className: 'zerp-btn-danger',
        onClick: async function () {
          try {
            await api('auth/logout', { method: 'POST', body: {} });
          } catch (err) {
            // silent
          }
          setToken('');
          state.me = null;
          state.moduleInstance = null;
          state.currentModule = null;
          renderAuth();
        }
      },
      { label: 'Zamknij' }
    ]);
  }

  function moduleCommunicator() {
    if (!permission('can_view_communicator')) {
      return moduleUnavailable('Komunikator niedostępny');
    }

    const local = {
      selectedThreadId: null,
      threads: [],
      relations: [],
      categories: [],
      dirty: false,
      pollTimer: null,
    };

    const wrap = document.createElement('div');
    wrap.className = 'zerp-module-shell zerp-module-shell-chat';
    wrap.innerHTML = '' +
      '<section class="zerp-module-head">' +
      '  <p class="zerp-kicker">Komunikator</p>' +
      '  <h2>Rozmowy operacyjne</h2>' +
      '  <p class="zerp-module-head-lead">Prowadz rozmowy handlowe i ofertowe w jednym roboczym oknie komunikacji.</p>' +
      '</section>' +
      '<div class="zerp-chat-wrap">' +
      '<div class="zerp-card zerp-chat-panel zerp-chat-left">' +
      '  <div class="zerp-row"><strong>Rozmowy</strong><button type="button" class="zerp-btn" id="zerp-chat-refresh">Odśwież</button></div>' +
      '  <div class="zerp-field" style="margin-top:8px"><label>Filtr statusu</label><select class="zerp-select" id="zerp-chat-filter-closed"><option value="0">Aktywne</option><option value="1">Zamknięte</option><option value="all">Wszystkie</option></select></div>' +
      '  <div class="zerp-list zerp-chat-list" id="zerp-chat-thread-list"></div>' +
      '</div>' +
      '<div class="zerp-card zerp-chat-panel zerp-chat-right">' +
      '  <div id="zerp-chat-detail"></div>' +
      '</div>' +
      '</div>';

    el.moduleView.innerHTML = '';
    el.moduleView.appendChild(wrap);

    const listEl = wrap.querySelector('#zerp-chat-thread-list');
    const detailEl = wrap.querySelector('#zerp-chat-detail');
    const filterClosed = wrap.querySelector('#zerp-chat-filter-closed');

    function stopPolling() {
      if (local.pollTimer) {
        clearInterval(local.pollTimer);
        local.pollTimer = null;
      }
    }

    async function loadLookups() {
      const relationRes = await api('relations');
      local.relations = relationRes && Array.isArray(relationRes.items) ? relationRes.items : [];

      const catRes = await api('chat/categories');
      local.categories = catRes && Array.isArray(catRes.items) ? catRes.items : [];
    }

    function relationNameById(id) {
      const row = local.relations.find(function (r) { return Number(r.id) === Number(id); });
      if (!row) {
        return 'Relacja #' + id;
      }
      return row.other_company_name || ('Relacja #' + id);
    }

    function renderCreateForm() {
      const relationOptions = ['<option value="">- wybierz relację -</option>'];
      local.relations.forEach(function (r) {
        if (String(r.status || '') !== 'active') {
          return;
        }
        relationOptions.push('<option value="' + Number(r.id) + '">' + escapeHtml((r.other_company_name || '') + ' (ID ' + r.id + ')') + '</option>');
      });

      const catOptions = ['<option value="">(brak)</option>'];
      local.categories.forEach(function (c) {
        catOptions.push('<option value="' + Number(c.id) + '">' + escapeHtml(c.name || ('Kategoria ' + c.id)) + '</option>');
      });

      return '' +
        '<div class="zerp-card" style="margin-bottom:12px">' +
        '  <h3 style="margin:0 0 8px">Nowa rozmowa</h3>' +
        '  <form id="zerp-chat-create" class="zerp-form-grid cols-2">' +
        '    <div class="zerp-field"><label>Relacja A↔B</label><select class="zerp-select" name="relation_id" required>' + relationOptions.join('') + '</select></div>' +
        '    <div class="zerp-field"><label>Kategoria</label><select class="zerp-select" name="category_id">' + catOptions.join('') + '</select></div>' +
        '    <div class="zerp-field"><label>Typ</label><select class="zerp-select" name="type"><option value="general">Ogólna</option><option value="offer">Ofertowa</option></select></div>' +
        '    <div class="zerp-field"><label>Oferta ID (opcjonalnie)</label><input class="zerp-input" name="linked_offer_id" inputmode="numeric"></div>' +
        '    <div class="zerp-field" style="grid-column:1/-1"><label>Tytuł</label><input class="zerp-input" name="title" required></div>' +
        '    <div class="zerp-field" style="grid-column:1/-1"><label>Pierwsza wiadomość</label><textarea name="first_message" required></textarea></div>' +
        '    <div class="zerp-actions" style="grid-column:1/-1"><button type="submit" class="zerp-btn zerp-btn-primary">Utwórz rozmowę</button></div>' +
        '  </form>' +
        '</div>';
    }

    function renderList() {
      listEl.innerHTML = '';

      if (!local.threads.length) {
        listEl.innerHTML = '<div class="zerp-list-item">Brak rozmów w filtrze.</div>';
        return;
      }

      local.threads.forEach(function (thread) {
        const btn = document.createElement('button');
        btn.className = 'zerp-list-item zerp-chat-thread-btn' + (local.selectedThreadId === thread.id ? ' is-active' : '');
        btn.type = 'button';
        btn.innerHTML = '' +
          '<div class="zerp-row"><strong>' + escapeHtml(thread.title || ('Rozmowa #' + thread.id)) + '</strong><span class="zerp-muted">' + escapeHtml(thread.type || '') + '</span></div>' +
          '<div class="zerp-muted">' + escapeHtml(relationNameById(thread.relation_id)) + '</div>' +
          '<div class="zerp-row"><span class="zerp-muted">ID ' + thread.id + '</span><span class="zerp-badge" ' + ((thread.unread_count || 0) ? '' : 'style="visibility:hidden"') + '>' + Number(thread.unread_count || 0) + '</span></div>' +
          (thread.is_closed ? '<div class="zerp-muted">Zamknięta</div>' : '');

        btn.addEventListener('click', function () {
          local.selectedThreadId = thread.id;
          renderList();
          loadThread(thread.id);
        });

        listEl.appendChild(btn);
      });
    }

    function renderDetailSkeleton() {
      detailEl.innerHTML = renderCreateForm() + '<div class="zerp-card"><div class="zerp-muted">Wybierz rozmowę z listy po lewej stronie.</div></div>';
      bindCreateForm();
    }

    function bindCreateForm() {
      const form = detailEl.querySelector('#zerp-chat-create');
      if (!form) {
        return;
      }

      form.addEventListener('input', function () {
        local.dirty = true;
      });

      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const data = new FormData(form);
        const payload = {
          relation_id: Number(data.get('relation_id') || 0),
          category_id: Number(data.get('category_id') || 0) || null,
          type: String(data.get('type') || 'general'),
          title: String(data.get('title') || ''),
          first_message: String(data.get('first_message') || ''),
        };

        const linked = Number(data.get('linked_offer_id') || 0);
        if (linked > 0) {
          payload.linked_offer_id = linked;
        }

        try {
          const result = await api('chat/threads', { method: 'POST', body: payload });
          local.dirty = false;
          await loadThreads();
          if (result && result.thread && result.thread.id) {
            local.selectedThreadId = Number(result.thread.id);
            renderList();
            await loadThread(local.selectedThreadId);
          }
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się utworzyć rozmowy.') + '</p>', [{ label: 'OK' }]);
        }
      });
    }

    function renderThreadDetail(thread) {
      const participants = Array.isArray(thread.participants) ? thread.participants : [];
      const messages = Array.isArray(thread.messages) ? thread.messages : [];

      const participantsHtml = participants.map(function (p) {
        return '<span class="zerp-pill">' + escapeHtml(p.display_name || p.login || ('Użytkownik ' + p.member_id)) + (p.is_muted ? ' (wyciszony)' : '') + '</span>';
      }).join(' ');

      const messagesHtml = messages.map(function (m) {
        const atts = Array.isArray(m.attachments) ? m.attachments : [];
        const attHtml = atts.map(function (a) {
          if (a.is_expired) {
            return '<div class="zerp-muted">Plik wygasł: ' + escapeHtml(a.file_name || '') + '</div>';
          }
          return '<div><a class="zerp-link" href="' + escapeHtml(a.url || '#') + '" target="_blank" rel="noopener">' + escapeHtml(a.file_name || 'Załącznik') + '</a></div>';
        }).join('');

        return '' +
          '<div class="zerp-list-item ' + (m.message_type === 'system' ? 'is-system' : '') + '">' +
          '  <div class="zerp-row"><strong>' + escapeHtml(m.sender_name || 'System') + '</strong><span class="zerp-muted">' + escapeHtml(m.created_at || '') + '</span></div>' +
          '  <div style="margin-top:6px">' + (m.body ? m.body : '<span class="zerp-muted">Wydarzenie systemowe</span>') + '</div>' +
          '  ' + attHtml +
          '</div>';
      }).join('');

      detailEl.innerHTML = '' +
        renderCreateForm() +
        '<div class="zerp-card">' +
        '  <div class="zerp-row" style="margin-bottom:8px">' +
        '    <div><h3 style="margin:0">' + escapeHtml(thread.title || ('Rozmowa #' + thread.id)) + '</h3><div class="zerp-muted">' + escapeHtml(relationNameById(thread.relation_id)) + ' | typ: ' + escapeHtml(thread.type || '') + '</div></div>' +
        '    <div class="zerp-actions">' +
        '      <button type="button" class="zerp-btn" id="zerp-thread-mute">' + (thread.viewer_muted ? 'Włącz powiadomienia' : 'Wycisz') + '</button>' +
        '      <button type="button" class="zerp-btn" id="zerp-thread-ping">Ping</button>' +
        '      <button type="button" class="zerp-btn ' + (thread.is_closed ? '' : 'zerp-btn-danger') + '" id="zerp-thread-toggle-close">' + (thread.is_closed ? 'Wznów' : 'Zamknij') + '</button>' +
        '    </div>' +
        '  </div>' +
        '  <div class="zerp-muted" style="margin-bottom:8px">Uczestnicy: ' + (participantsHtml || 'brak') + '</div>' +
        '  <div class="zerp-list zerp-chat-messages" id="zerp-thread-messages">' + (messagesHtml || '<div class="zerp-list-item">Brak wiadomości.</div>') + '</div>' +
        '  <form id="zerp-thread-send" class="zerp-form-grid" style="margin-top:10px">' +
        '    <div class="zerp-field"><label>Nowa wiadomość</label><textarea name="body" required placeholder="Wpisz wiadomość..."></textarea></div>' +
        '    <div class="zerp-actions"><button class="zerp-btn zerp-btn-primary" type="submit" ' + (thread.is_closed ? 'disabled' : '') + '>Wyślij</button></div>' +
        '  </form>' +
        '</div>';

      bindCreateForm();

      const sendForm = detailEl.querySelector('#zerp-thread-send');
      if (sendForm) {
        sendForm.addEventListener('input', function () {
          local.dirty = true;
        });

        sendForm.addEventListener('submit', async function (ev) {
          ev.preventDefault();
          const data = new FormData(sendForm);
          const body = String(data.get('body') || '').trim();
          if (!body) {
            return;
          }

          try {
            await api('chat/threads/' + thread.id + '/messages', { method: 'POST', body: { body: body } });
            local.dirty = false;
            await loadThread(thread.id);
            await loadThreads();
            await refreshUnreadCount();
          } catch (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się wysłać wiadomości.') + '</p>', [{ label: 'OK' }]);
          }
        });
      }

      const muteBtn = detailEl.querySelector('#zerp-thread-mute');
      if (muteBtn) {
        muteBtn.addEventListener('click', async function () {
          const mute = !thread.viewer_muted;
          try {
            await api('chat/threads/' + thread.id + '/mute', { method: 'POST', body: { mute: mute } });
            await loadThread(thread.id);
            await loadThreads();
          } catch (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zmienić wyciszenia.') + '</p>', [{ label: 'OK' }]);
          }
        });
      }

      const pingBtn = detailEl.querySelector('#zerp-thread-ping');
      if (pingBtn) {
        pingBtn.addEventListener('click', function () {
          const ids = prompt('Podaj ID użytkowników do ping (oddzielone przecinkami):', '');
          if (ids === null) {
            return;
          }
          const parsed = String(ids).split(',').map(function (v) { return Number(String(v).trim()); }).filter(function (v) { return v > 0; });
          if (!parsed.length) {
            return;
          }
          api('chat/threads/' + thread.id + '/ping', { method: 'POST', body: { target_member_ids: parsed } })
            .then(function () { loadThread(thread.id); })
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się wysłać pingu.') + '</p>', [{ label: 'OK' }]);
            });
        });
      }

      const toggleCloseBtn = detailEl.querySelector('#zerp-thread-toggle-close');
      if (toggleCloseBtn) {
        toggleCloseBtn.addEventListener('click', function () {
          const action = thread.is_closed ? 'reopen' : 'close';
          const reason = prompt(thread.is_closed ? 'Powód wznowienia rozmowy:' : 'Powód zamknięcia rozmowy:', '');
          if (reason === null) {
            return;
          }
          api('chat/threads/' + thread.id + '/' + action, { method: 'POST', body: { reason: reason } })
            .then(function () { return loadThread(thread.id); })
            .then(function () { return loadThreads(); })
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zmienić statusu rozmowy.') + '</p>', [{ label: 'OK' }]);
            });
        });
      }
    }

    async function loadThread(threadId) {
      try {
        const result = await api('chat/threads/' + threadId);
        const thread = result && result.thread ? result.thread : null;
        if (!thread) {
          detailEl.innerHTML = renderCreateForm() + '<div class="zerp-card">Nie znaleziono rozmowy.</div>';
          bindCreateForm();
          return;
        }

        const participant = (thread.participants || []).find(function (p) { return Number(p.member_id) === Number(state.me.id); }) || null;
        thread.viewer_muted = !!(participant && participant.is_muted);

        renderThreadDetail(thread);
        await api('chat/threads/' + threadId + '/read', { method: 'POST', body: {} });
        await refreshUnreadCount();
      } catch (err) {
        detailEl.innerHTML = renderCreateForm() + '<div class="zerp-card"><div class="zerp-error">' + escapeHtml(err.message || 'Nie udało się pobrać rozmowy.') + '</div></div>';
        bindCreateForm();
      }
    }

    async function loadThreads() {
      const filterVal = filterClosed.value;
      const query = [];
      if (filterVal === '0') {
        query.push('is_closed=0');
      } else if (filterVal === '1') {
        query.push('is_closed=1');
      }

      const path = 'chat/threads' + (query.length ? '?' + query.join('&') : '');
      const result = await api(path);
      local.threads = result && Array.isArray(result.items) ? result.items : [];
      renderList();

      if (local.selectedThreadId && local.threads.some(function (t) { return t.id === local.selectedThreadId; })) {
        await loadThread(local.selectedThreadId);
      }
    }

    wrap.querySelector('#zerp-chat-refresh').addEventListener('click', function () {
      loadThreads().catch(function (err) {
        mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się odświeżyć rozmów.') + '</p>', [{ label: 'OK' }]);
      });
    });

    filterClosed.addEventListener('change', function () {
      loadThreads().catch(function () {});
    });

    renderDetailSkeleton();

    Promise.all([loadLookups(), loadThreads(), refreshUnreadCount()]).catch(function (err) {
      detailEl.innerHTML = '<div class="zerp-error">' + escapeHtml(err.message || 'Nie udało się uruchomić komunikatora.') + '</div>';
    });

    local.pollTimer = setInterval(function () {
      loadThreads().catch(function () {});
      refreshUnreadCount();
    }, 20000);

    return {
      unmount: function () {
        stopPolling();
      },
      hasUnsavedChanges: function () {
        return !!local.dirty;
      },
      saveBeforeLeave: async function () {
        local.dirty = false;
      },
      discardChanges: function () {
        local.dirty = false;
      }
    };
  }

  function moduleCompanyUsers() {
    if (!permission('can_view_company_profile') && !permission('can_view_company_members')) {
      return moduleUnavailable('Firma i Użytkownicy niedostępne');
    }

    const local = { dirty: false };

    el.moduleView.innerHTML = '' +
      '<div class="zerp-module-shell zerp-module-shell-company">' +
      '  <section class="zerp-module-head">' +
      '    <p class="zerp-kicker">Firma i Uzytkownicy</p>' +
      '    <h2>Struktura firmy i relacje</h2>' +
      '    <p class="zerp-module-head-lead">Zarzadzaj profilem firmy, zespolem i relacjami biznesowymi w jednym widoku roboczym.</p>' +
      '  </section>' +
      '<div class="zerp-grid-two">' +
      '  <div class="zerp-card" id="zerp-company-card"><h3 style="margin-top:0">Dane firmy</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '  <div class="zerp-card" id="zerp-relations-card"><h3 style="margin-top:0">Relacje A↔B</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '</div>' +
      '<div class="zerp-grid-two zerp-grid-two-stack">' +
      '  <div class="zerp-card" id="zerp-members-card"><h3 style="margin-top:0">Uzytkownicy firmy</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '  <div class="zerp-card" id="zerp-joins-card"><h3 style="margin-top:0">Wnioski o dolaczenie</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '</div>' +
      '</div>';
    const companyEl = document.getElementById('zerp-company-card');
    const membersEl = document.getElementById('zerp-members-card');
    const relationsEl = document.getElementById('zerp-relations-card');
    const joinsEl = document.getElementById('zerp-joins-card');

    function markDirty() {
      local.dirty = true;
    }

    async function loadCompany() {
      const result = await api('companies/me');
      const company = result && result.company ? result.company : null;

      if (!company) {
        companyEl.innerHTML = '<h3 style="margin-top:0">Dane firmy</h3><div class="zerp-error">Nie znaleziono danych firmy.</div>';
        return;
      }

      companyEl.innerHTML = '' +
        '<h3 style="margin-top:0">Dane firmy</h3>' +
        '<form id="zerp-company-form" class="zerp-form-grid cols-2">' +
        '  <div class="zerp-field"><label>Nazwa</label><input class="zerp-input" name="name" value="' + escapeHtml(company.name || '') + '" required></div>' +
        '  <div class="zerp-field"><label>NIP</label><input class="zerp-input" name="nip" value="' + escapeHtml(company.nip || '') + '" disabled></div>' +
        '  <div class="zerp-field"><label>E-mail firmy</label><input class="zerp-input" name="company_email" value="' + escapeHtml(company.company_email || '') + '"></div>' +
        '  <div class="zerp-field"><label>Telefon firmy</label><input class="zerp-input" name="company_phone" value="' + escapeHtml(company.company_phone || '') + '"></div>' +
        '  <div class="zerp-field" style="grid-column:1/-1"><label>Adres</label><textarea name="address">' + escapeHtml(company.address || '') + '</textarea></div>' +
        '  <div class="zerp-field"><label>WWW</label><input class="zerp-input" name="www" value="' + escapeHtml(company.www || '') + '"></div>' +
        '  <div class="zerp-field"><label>Status</label><select class="zerp-select" name="status"><option value="active" ' + (company.status === 'active' ? 'selected' : '') + '>Aktywna</option><option value="inactive" ' + (company.status === 'inactive' ? 'selected' : '') + '>Nieaktywna</option></select></div>' +
        '  <div class="zerp-row" style="grid-column:1/-1"><div class="zerp-muted">Join code: <strong>' + escapeHtml(company.join_code || '-') + '</strong></div><button type="button" class="zerp-btn" id="zerp-regenerate-join">Regeneruj</button></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button type="submit" class="zerp-btn zerp-btn-primary">Zapisz firmę</button></div>' +
        '</form>';

      const form = companyEl.querySelector('#zerp-company-form');
      form.addEventListener('input', markDirty);

      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
          await api('companies/me', { method: 'POST', body: data });
          local.dirty = false;
          await loadCompany();
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zapisać danych firmy.') + '</p>', [{ label: 'OK' }]);
        }
      });

      const regenBtn = companyEl.querySelector('#zerp-regenerate-join');
      regenBtn.addEventListener('click', async function () {
        try {
          await api('companies/me/join-code/regenerate', { method: 'POST', body: {} });
          await loadCompany();
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zregenerować join code.') + '</p>', [{ label: 'OK' }]);
        }
      });
    }

    async function loadMembers() {
      if (!permission('can_view_company_members')) {
        membersEl.innerHTML = '<h3 style="margin-top:0">Użytkownicy firmy</h3><div class="zerp-muted">Brak uprawnień do podglądu użytkowników.</div>';
        return;
      }

      const result = await api('members');
      const members = result && Array.isArray(result.items) ? result.items : [];

      const rows = members.map(function (m) {
        return '' +
          '<div class="zerp-list-item">' +
          '  <div class="zerp-row"><strong>' + escapeHtml((m.first_name || '') + ' ' + (m.last_name || '')) + '</strong><span class="zerp-muted">' + escapeHtml(m.role || '') + (m.is_owner ? ' (owner)' : '') + '</span></div>' +
          '  <div class="zerp-muted">' + escapeHtml(m.login || '') + ' | ' + escapeHtml(m.email || '') + ' | status: ' + escapeHtml(m.status || '') + '</div>' +
          '  <div class="zerp-actions" style="margin-top:8px">' +
          (m.status === 'active' && !m.is_owner ? '<button class="zerp-btn" data-member-suspend="' + m.id + '">Zawieś</button>' : '') +
          (m.status !== 'active' && !m.is_owner ? '<button class="zerp-btn" data-member-reactivate="' + m.id + '">Aktywuj</button>' : '') +
          (!m.is_owner ? '<button class="zerp-btn" data-member-edit="' + m.id + '">Edytuj</button>' : '') +
          '  </div>' +
          '</div>';
      }).join('');

      membersEl.innerHTML = '' +
        '<h3 style="margin-top:0">Użytkownicy firmy</h3>' +
        '<form id="zerp-member-create" class="zerp-form-grid cols-2" style="margin-bottom:10px">' +
        '  <div class="zerp-field"><label>Imię</label><input class="zerp-input" name="first_name" required></div>' +
        '  <div class="zerp-field"><label>Nazwisko</label><input class="zerp-input" name="last_name" required></div>' +
        '  <div class="zerp-field"><label>E-mail</label><input class="zerp-input" name="email" type="email" required></div>' +
        '  <div class="zerp-field"><label>Telefon</label><input class="zerp-input" name="phone"></div>' +
        '  <div class="zerp-field"><label>Rola</label><select class="zerp-select" name="role"><option value="user">user</option><option value="manager">manager</option></select></div>' +
        '  <div class="zerp-field"><label>Hasło</label><input class="zerp-input" name="password" type="password" minlength="8" required></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button class="zerp-btn zerp-btn-primary" type="submit">Dodaj użytkownika</button></div>' +
        '</form>' +
        '<div class="zerp-list">' + (rows || '<div class="zerp-list-item">Brak użytkowników.</div>') + '</div>';

      const createForm = membersEl.querySelector('#zerp-member-create');
      createForm.addEventListener('input', markDirty);
      createForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        try {
          const payload = Object.fromEntries(new FormData(createForm).entries());
          const res = await api('members', { method: 'POST', body: payload });
          local.dirty = false;
          await loadMembers();
          if (res && res.generated_login) {
            mountModal('Utworzono użytkownika', '<p>Login: <strong>' + escapeHtml(res.generated_login) + '</strong></p><p>Hasło: <strong>' + escapeHtml(res.generated_password || '') + '</strong></p>', [{ label: 'OK' }]);
          }
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się utworzyć użytkownika.') + '</p>', [{ label: 'OK' }]);
        }
      });

      membersEl.querySelectorAll('[data-member-suspend]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-member-suspend'));
          api('members/' + id + '/suspend', { method: 'POST', body: {} }).then(loadMembers).catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zawiesić użytkownika.') + '</p>', [{ label: 'OK' }]);
          });
        });
      });

      membersEl.querySelectorAll('[data-member-reactivate]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-member-reactivate'));
          api('members/' + id + '/reactivate', { method: 'POST', body: {} }).then(loadMembers).catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się aktywować użytkownika.') + '</p>', [{ label: 'OK' }]);
          });
        });
      });

      membersEl.querySelectorAll('[data-member-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-member-edit'));
          const target = members.find(function (m) { return Number(m.id) === id; });
          if (!target) {
            return;
          }

          const html = '' +
            '<form id="zerp-member-edit-form" class="zerp-form-grid cols-2">' +
            '  <input type="hidden" name="id" value="' + id + '">' +
            '  <div class="zerp-field"><label>Imię</label><input class="zerp-input" name="first_name" value="' + escapeHtml(target.first_name || '') + '"></div>' +
            '  <div class="zerp-field"><label>Nazwisko</label><input class="zerp-input" name="last_name" value="' + escapeHtml(target.last_name || '') + '"></div>' +
            '  <div class="zerp-field"><label>E-mail</label><input class="zerp-input" name="email" type="email" value="' + escapeHtml(target.email || '') + '"></div>' +
            '  <div class="zerp-field"><label>Telefon</label><input class="zerp-input" name="phone" value="' + escapeHtml(target.phone || '') + '"></div>' +
            '  <div class="zerp-field"><label>Rola</label><select class="zerp-select" name="role"><option value="user" ' + (target.role === 'user' ? 'selected' : '') + '>user</option><option value="manager" ' + (target.role === 'manager' ? 'selected' : '') + '>manager</option></select></div>' +
            '  <div class="zerp-field"><label>Status</label><select class="zerp-select" name="status"><option value="active" ' + (target.status === 'active' ? 'selected' : '') + '>active</option><option value="suspended" ' + (target.status === 'suspended' ? 'selected' : '') + '>suspended</option></select></div>' +
            '  <div class="zerp-field" style="grid-column:1/-1"><label>Nowe hasło (opcjonalnie)</label><input class="zerp-input" name="password" type="password"></div>' +
            '</form>';

          mountModal('Edycja użytkownika', html, [
            {
              label: 'Zapisz',
              className: 'zerp-btn-primary',
              onClick: function () {
                const form = document.getElementById('zerp-member-edit-form');
                if (!form) {
                  return;
                }
                const payload = Object.fromEntries(new FormData(form).entries());
                api('members/' + id, { method: 'POST', body: payload })
                  .then(function () { return loadMembers(); })
                  .catch(function (err) {
                    mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zaktualizować użytkownika.') + '</p>', [{ label: 'OK' }]);
                  });
              }
            },
            { label: 'Anuluj' }
          ]);
        });
      });
    }

    async function loadJoinRequests() {
      if (!permission('can_view_company_members')) {
        joinsEl.innerHTML = '<h3 style="margin-top:0">Wnioski o dołączenie</h3><div class="zerp-muted">Brak uprawnień.</div>';
        return;
      }

      const result = await api('auth/join-requests');
      const items = result && Array.isArray(result.items) ? result.items : [];

      joinsEl.innerHTML = '' +
        '<h3 style="margin-top:0">Wnioski o dołączenie</h3>' +
        '<div class="zerp-list">' +
        (items.map(function (it) {
          return '<div class="zerp-list-item">' +
            '<div class="zerp-row"><strong>' + escapeHtml((it.first_name || '') + ' ' + (it.last_name || '')) + '</strong><span class="zerp-muted">' + escapeHtml(it.status || '') + '</span></div>' +
            '<div class="zerp-muted">' + escapeHtml(it.email || '') + '</div>' +
            '<div class="zerp-actions" style="margin-top:8px">' +
            (it.status === 'pending' ? '<button class="zerp-btn zerp-btn-primary" data-join-approve="' + it.id + '">Akceptuj</button><button class="zerp-btn" data-join-reject="' + it.id + '">Odrzuć</button>' : '') +
            '</div>' +
          '</div>';
        }).join('') || '<div class="zerp-list-item">Brak wniosków.</div>') +
        '</div>';

      joinsEl.querySelectorAll('[data-join-approve]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-join-approve'));
          api('auth/join-requests/' + id + '/approve', { method: 'POST', body: {} })
            .then(function () { return Promise.all([loadJoinRequests(), loadMembers()]); })
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zaakceptować wniosku.') + '</p>', [{ label: 'OK' }]);
            });
        });
      });

      joinsEl.querySelectorAll('[data-join-reject]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-join-reject'));
          const reason = prompt('Powód odrzucenia:', '');
          if (reason === null) {
            return;
          }
          api('auth/join-requests/' + id + '/reject', { method: 'POST', body: { reason: reason } })
            .then(loadJoinRequests)
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się odrzucić wniosku.') + '</p>', [{ label: 'OK' }]);
            });
        });
      });
    }

    async function loadRelations() {
      if (!permission('can_manage_company_relations')) {
        relationsEl.innerHTML = '<h3 style="margin-top:0">Relacje A↔B</h3><div class="zerp-muted">Brak uprawnień do relacji.</div>';
        return;
      }

      const relRes = await api('relations');
      const rels = relRes && Array.isArray(relRes.items) ? relRes.items : [];

      relationsEl.innerHTML = '' +
        '<h3 style="margin-top:0">Relacje A↔B</h3>' +
        '<form id="zerp-relation-invite" class="zerp-form-grid cols-2" style="margin-bottom:10px">' +
        '  <div class="zerp-field"><label>ID firmy docelowej</label><input class="zerp-input" name="target_company_id" inputmode="numeric" required></div>' +
        '  <div class="zerp-field"><label>Limit rabatu strony docelowej [%]</label><input class="zerp-input" name="max_discount_from_target" value="0" inputmode="decimal"></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button class="zerp-btn zerp-btn-primary" type="submit">Wyślij zaproszenie</button></div>' +
        '</form>' +
        '<div class="zerp-list">' +
        (rels.map(function (r) {
          return '<div class="zerp-list-item">' +
            '<div class="zerp-row"><strong>' + escapeHtml(r.other_company_name || ('Firma #' + r.other_company_id)) + '</strong><span class="zerp-muted">' + escapeHtml(r.status || '') + '</span></div>' +
            '<div class="zerp-muted">Relacja #' + r.id + ' | limity: A→B ' + escapeHtml(String(r.max_discount_a_to_b || 0)) + '% | B→A ' + escapeHtml(String(r.max_discount_b_to_a || 0)) + '%</div>' +
            '<div class="zerp-actions" style="margin-top:8px">' +
            (r.status === 'pending' ? '<button class="zerp-btn zerp-btn-primary" data-rel-accept="' + r.id + '">Akceptuj</button><button class="zerp-btn" data-rel-reject="' + r.id + '">Odrzuć</button>' : '') +
            '</div>' +
          '</div>';
        }).join('') || '<div class="zerp-list-item">Brak relacji.</div>') +
        '</div>';

      const inviteForm = relationsEl.querySelector('#zerp-relation-invite');
      inviteForm.addEventListener('input', markDirty);
      inviteForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const payload = Object.fromEntries(new FormData(inviteForm).entries());
        try {
          await api('relations/invite', { method: 'POST', body: payload });
          local.dirty = false;
          await loadRelations();
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się wysłać zaproszenia.') + '</p>', [{ label: 'OK' }]);
        }
      });

      relationsEl.querySelectorAll('[data-rel-accept]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-rel-accept'));
          const maxD = prompt('Limit rabatu dla drugiej strony [%]:', '0');
          if (maxD === null) {
            return;
          }
          api('relations/' + id + '/accept', { method: 'POST', body: { max_discount_for_other_side: Number(maxD) || 0 } })
            .then(loadRelations)
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zaakceptować relacji.') + '</p>', [{ label: 'OK' }]);
            });
        });
      });

      relationsEl.querySelectorAll('[data-rel-reject]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id = Number(btn.getAttribute('data-rel-reject'));
          const reason = prompt('Powód odrzucenia relacji:', '');
          if (reason === null) {
            return;
          }
          api('relations/' + id + '/reject', { method: 'POST', body: { reason: reason } })
            .then(loadRelations)
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się odrzucić relacji.') + '</p>', [{ label: 'OK' }]);
            });
        });
      });
    }

    Promise.all([loadCompany(), loadMembers(), loadJoinRequests(), loadRelations()]).catch(function (err) {
      el.moduleView.insertAdjacentHTML('afterbegin', '<div class="zerp-error" style="margin-bottom:10px">' + escapeHtml(err.message || 'Nie udało się załadować modułu.') + '</div>');
    });

    return {
      unmount: function () {},
      hasUnsavedChanges: function () { return !!local.dirty; },
      saveBeforeLeave: async function () { local.dirty = false; },
      discardChanges: function () { local.dirty = false; }
    };
  }

  function moduleCatalog() {
    if (!permission('can_view_sources')) {
      return moduleUnavailable('Biblioteka Produktów niedostępna');
    }

    const local = { dirty: false, relations: [] };

    el.moduleView.innerHTML = '' +
      '<div class="zerp-module-shell zerp-module-shell-catalog">' +
      '  <section class="zerp-module-head">' +
      '    <p class="zerp-kicker">Biblioteka produktow</p>' +
      '    <h2>Zrodla i katalog firmowy</h2>' +
      '    <p class="zerp-module-head-lead">Polacz dane Google Sheets i lokalna biblioteke produktow w jednym widoku roboczym.</p>' +
      '  </section>' +
      '  <div class="zerp-grid-two">' +
      '    <div class="zerp-card" id="zerp-source-card"><h3 style="margin-top:0">Zrodlo Google Sheets</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '    <div class="zerp-card" id="zerp-local-catalog-card"><h3 style="margin-top:0">Katalog lokalny</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '  </div>' +
      '  <div class="zerp-card" id="zerp-merged-products-card"><h3 style="margin-top:0">Scalona lista produktow</h3><div class="zerp-muted">Ladowanie...</div></div>' +
      '</div>';
    const sourceEl = document.getElementById('zerp-source-card');
    const localEl = document.getElementById('zerp-local-catalog-card');
    const mergedEl = document.getElementById('zerp-merged-products-card');

    function markDirty() { local.dirty = true; }

    async function loadSource() {
      const result = await api('sources/google');
      const source = result && result.source ? result.source : null;
      const tabs = source && Array.isArray(source.tabs) ? source.tabs : [];
      const tabsText = tabs.map(function (t) {
        return String(t.name || '') + ':' + String(t.gid || '');
      }).join('\n');

      sourceEl.innerHTML = '' +
        '<h3 style="margin-top:0">Źródło Google Sheets</h3>' +
        '<form id="zerp-source-form" class="zerp-form-grid">' +
        '  <div class="zerp-field"><label>Sheet public ID</label><input class="zerp-input" name="sheet_pub_id" value="' + escapeHtml((source && source.sheet_pub_id) || '') + '" required></div>' +
        '  <div class="zerp-field"><label>Taby (format: nazwa:gid, jeden wiersz)</label><textarea name="tabs">' + escapeHtml(tabsText) + '</textarea></div>' +
        '  <div class="zerp-field"><label>Interwał sync [min]</label><select class="zerp-select" name="sync_interval_minutes">' +
        '    <option value="1" ' + (source && Number(source.sync_interval_minutes) === 1 ? 'selected' : '') + '>1</option>' +
        '    <option value="5" ' + (source && Number(source.sync_interval_minutes) === 5 ? 'selected' : '') + '>5</option>' +
        '    <option value="10" ' + ((source && Number(source.sync_interval_minutes) === 10) || !source ? 'selected' : '') + '>10</option>' +
        '    <option value="15" ' + (source && Number(source.sync_interval_minutes) === 15 ? 'selected' : '') + '>15</option>' +
        '  </select></div>' +
        '  <div class="zerp-field"><label>Sync aktywny</label><select class="zerp-select" name="sync_enabled"><option value="1" ' + ((source && Number(source.sync_enabled) === 1) || !source ? 'selected' : '') + '>Tak</option><option value="0" ' + (source && Number(source.sync_enabled) === 0 ? 'selected' : '') + '>Nie</option></select></div>' +
        '  <div class="zerp-actions"><button type="submit" class="zerp-btn zerp-btn-primary">Zapisz źródło</button><button type="button" class="zerp-btn" id="zerp-source-force-sync">Wymuś sync</button></div>' +
        '</form>' +
        '<div class="zerp-muted" style="margin-top:8px">Ostatni sync: ' + escapeHtml((source && source.last_sync_at) || '-') + ' | status: ' + ((source && source.last_sync_ok) ? 'OK' : 'brak/err') + '</div>';

      const form = sourceEl.querySelector('#zerp-source-form');
      form.addEventListener('input', markDirty);
      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const fd = new FormData(form);
        const tabsRaw = String(fd.get('tabs') || '');
        const tabsParsed = tabsRaw.split(/\r?\n/).map(function (line) {
          const p = line.split(':');
          const name = String((p[0] || '')).trim();
          const gid = String((p.slice(1).join(':') || '')).trim();
          if (!name || !gid) {
            return null;
          }
          return { name: name, gid: gid };
        }).filter(Boolean);

        const payload = {
          sheet_pub_id: String(fd.get('sheet_pub_id') || '').trim(),
          tabs: tabsParsed,
          sync_interval_minutes: Number(fd.get('sync_interval_minutes') || 10),
          sync_enabled: Number(fd.get('sync_enabled') || 1) === 1,
        };

        try {
          await api('sources/google', { method: 'POST', body: payload });
          local.dirty = false;
          await loadSource();
          await loadMerged();
        } catch (err) {
          mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zapisać źródła Google.') + '</p>', [{ label: 'OK' }]);
        }
      });

      sourceEl.querySelector('#zerp-source-force-sync').addEventListener('click', function () {
        api('sources/google/sync', { method: 'POST', body: {} })
          .then(function () { return Promise.all([loadSource(), loadMerged()]); })
          .catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się uruchomić synchronizacji.') + '</p>', [{ label: 'OK' }]);
          });
      });
    }

    async function loadLocalCatalog() {
      const [catRes, itemRes, varRes] = await Promise.all([
        api('catalog/categories'),
        api('catalog/items?only_active=0'),
        api('catalog/variants?only_active=0')
      ]);

      const categories = catRes && Array.isArray(catRes.items) ? catRes.items : [];
      const items = itemRes && Array.isArray(itemRes.items) ? itemRes.items : [];
      const variants = varRes && Array.isArray(varRes.items) ? varRes.items : [];

      const categoryOptions = ['<option value="">(brak)</option>'];
      categories.forEach(function (c) {
        categoryOptions.push('<option value="' + Number(c.id) + '">' + escapeHtml(c.name || ('Kategoria ' + c.id)) + '</option>');
      });

      localEl.innerHTML = '' +
        '<h3 style="margin-top:0">Katalog lokalny</h3>' +
        '<form id="zerp-category-form" class="zerp-form-grid cols-2">' +
        '  <div class="zerp-field"><label>Nowa kategoria</label><input class="zerp-input" name="name" required></div>' +
        '  <div class="zerp-field"><label>Sortowanie</label><input class="zerp-input" name="sort_order" value="0" inputmode="numeric"></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button class="zerp-btn" type="submit">Dodaj kategorię</button></div>' +
        '</form>' +
        '<form id="zerp-item-form" class="zerp-form-grid cols-2" style="margin-top:10px">' +
        '  <div class="zerp-field"><label>Nazwa produktu</label><input class="zerp-input" name="name" required></div>' +
        '  <div class="zerp-field"><label>Kategoria</label><select class="zerp-select" name="category_id">' + categoryOptions.join('') + '</select></div>' +
        '  <div class="zerp-field"><label>SKU bazowe</label><input class="zerp-input" name="sku"></div>' +
        '  <div class="zerp-field"><label>Jednostka</label><input class="zerp-input" name="unit" value="szt"></div>' +
        '  <div class="zerp-field" style="grid-column:1/-1"><label>Opis</label><textarea name="description"></textarea></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button class="zerp-btn" type="submit">Dodaj produkt</button></div>' +
        '</form>' +
        '<form id="zerp-variant-form" class="zerp-form-grid cols-2" style="margin-top:10px">' +
        '  <div class="zerp-field"><label>Produkt</label><select class="zerp-select" name="item_id" required>' +
        items.map(function (it) {
          return '<option value="' + Number(it.id) + '">' + escapeHtml((it.name || '') + ' (#' + it.id + ')') + '</option>';
        }).join('') +
        '  </select></div>' +
        '  <div class="zerp-field"><label>Kolor (Brak / RAL ...)</label><input class="zerp-input" name="color_label" value="Brak" required></div>' +
        '  <div class="zerp-field"><label>SKU wariantu</label><input class="zerp-input" name="sku" required></div>' +
        '  <div class="zerp-field"><label>Cena netto</label><input class="zerp-input" name="unit_net" inputmode="decimal"></div>' +
        '  <div class="zerp-actions" style="grid-column:1/-1"><button class="zerp-btn" type="submit">Dodaj wariant</button></div>' +
        '</form>' +
        '<div class="zerp-list" style="margin-top:10px">' +
        items.map(function (it) {
          const varCount = variants.filter(function (v) { return Number(v.item_id) === Number(it.id); }).length;
          return '<div class="zerp-list-item"><div class="zerp-row"><strong>' + escapeHtml(it.name || '') + '</strong><span class="zerp-muted">#' + it.id + '</span></div><div class="zerp-muted">SKU: ' + escapeHtml(it.sku || '-') + ' | warianty: ' + varCount + ' | status: ' + (Number(it.is_active) ? 'aktywny' : 'archiwalny') + '</div><div class="zerp-actions" style="margin-top:8px"><button class="zerp-btn" data-archive-item="' + it.id + '">Archiwizuj</button></div></div>';
        }).join('') +
        '</div>';

      const catForm = localEl.querySelector('#zerp-category-form');
      catForm.addEventListener('input', markDirty);
      catForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        const payload = Object.fromEntries(new FormData(catForm).entries());
        api('catalog/categories', { method: 'POST', body: payload })
          .then(function () { local.dirty = false; return loadLocalCatalog(); })
          .then(loadMerged)
          .catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się dodać kategorii.') + '</p>', [{ label: 'OK' }]);
          });
      });

      const itemForm = localEl.querySelector('#zerp-item-form');
      itemForm.addEventListener('input', markDirty);
      itemForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        const payload = Object.fromEntries(new FormData(itemForm).entries());
        api('catalog/items', { method: 'POST', body: payload })
          .then(function () { local.dirty = false; return loadLocalCatalog(); })
          .then(loadMerged)
          .catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się dodać produktu.') + '</p>', [{ label: 'OK' }]);
          });
      });

      const varForm = localEl.querySelector('#zerp-variant-form');
      varForm.addEventListener('input', markDirty);
      varForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        const payload = Object.fromEntries(new FormData(varForm).entries());
        api('catalog/variants', { method: 'POST', body: payload })
          .then(function () { local.dirty = false; return loadLocalCatalog(); })
          .then(loadMerged)
          .catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się dodać wariantu.') + '</p>', [{ label: 'OK' }]);
          });
      });

      localEl.querySelectorAll('[data-archive-item]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const itemId = Number(btn.getAttribute('data-archive-item'));
          api('catalog/items/' + itemId + '/archive', { method: 'POST', body: {} })
            .then(function () { return loadLocalCatalog(); })
            .then(loadMerged)
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się zarchiwizować produktu.') + '</p>', [{ label: 'OK' }]);
            });
        });
      });
    }

    async function ensureRelations() {
      if (local.relations.length) {
        return;
      }
      const relRes = await api('relations');
      local.relations = relRes && Array.isArray(relRes.items) ? relRes.items : [];
    }

    async function loadMerged() {
      await ensureRelations();

      const contextOptions = ['<option value="self">Moja firma</option>'];
      local.relations.forEach(function (r) {
        if (String(r.status || '') !== 'active') {
          return;
        }
        contextOptions.push('<option value="' + Number(r.other_company_id) + '">' + escapeHtml(r.other_company_name || ('Firma #' + r.other_company_id)) + '</option>');
      });

      mergedEl.innerHTML = '' +
        '<h3 style="margin-top:0">Scalona lista produktów</h3>' +
        '<div class="zerp-row" style="margin-bottom:8px"><div class="zerp-field" style="min-width:260px"><label>Kontekst danych</label><select class="zerp-select" id="zerp-merged-context">' + contextOptions.join('') + '</select></div><button class="zerp-btn" id="zerp-merged-refresh">Odśwież</button></div>' +
        '<div id="zerp-merged-table-wrap" class="zerp-list"><div class="zerp-list-item">Wybierz kontekst i odśwież.</div></div>';

      async function fetchMerged() {
        const sel = mergedEl.querySelector('#zerp-merged-context');
        const v = sel ? String(sel.value || 'self') : 'self';
        const query = v === 'self' ? '' : ('?context_company_id=' + encodeURIComponent(v));

        const result = await api('catalog/merged' + query);
        const items = result && Array.isArray(result.items) ? result.items : [];

        const rows = items.map(function (it) {
          return '<div class="zerp-list-item">' +
            '<div class="zerp-row"><strong>' + escapeHtml(it.item_name || '') + '</strong><span class="zerp-muted">' + escapeHtml(it.source || '') + '</span></div>' +
            '<div class="zerp-muted">SKU: ' + escapeHtml(it.sku || '-') + ' | Kolor: ' + escapeHtml(it.color || 'Brak') + ' | Jednostka: ' + escapeHtml(it.unit || 'szt') + '</div>' +
            '<div class="zerp-muted">Netto: ' + escapeHtml(String(it.unit_net == null ? '-' : it.unit_net)) + ' | Kategoria: ' + escapeHtml(it.category || it.category_id || '-') + '</div>' +
          '</div>';
        }).join('');

        mergedEl.querySelector('#zerp-merged-table-wrap').innerHTML = rows || '<div class="zerp-list-item">Brak produktów dla wybranego kontekstu.</div>';
      }

      mergedEl.querySelector('#zerp-merged-refresh').addEventListener('click', function () {
        fetchMerged().catch(function (err) {
          mergedEl.querySelector('#zerp-merged-table-wrap').innerHTML = '<div class="zerp-error">' + escapeHtml(err.message || 'Nie udało się pobrać listy produktów.') + '</div>';
        });
      });

      mergedEl.querySelector('#zerp-merged-context').addEventListener('change', function () {
        fetchMerged().catch(function () {});
      });

      await fetchMerged();
    }

    Promise.all([loadSource(), loadLocalCatalog(), loadMerged()]).catch(function (err) {
      el.moduleView.insertAdjacentHTML('afterbegin', '<div class="zerp-error" style="margin-bottom:10px">' + escapeHtml(err.message || 'Nie udało się załadować modułu katalogu.') + '</div>');
    });

    return {
      unmount: function () {},
      hasUnsavedChanges: function () { return !!local.dirty; },
      saveBeforeLeave: async function () { local.dirty = false; },
      discardChanges: function () { local.dirty = false; }
    };
  }
  function moduleNotifications() {
    if (!permission('can_view_notifications')) {
      return moduleUnavailable('Powiadomienia niedostępne');
    }

    const local = { dirty: false };

    async function render() {
      const result = await api('notifications?limit=100');
      const items = result && Array.isArray(result.items) ? result.items : [];
      const unread = items.filter(function (x) { return !x.is_read; });

      el.moduleView.innerHTML = '' +
        '<div class="zerp-module-shell zerp-module-shell-notifications">' +
        '  <section class="zerp-module-head">' +
        '    <p class="zerp-kicker">Powiadomienia</p>' +
        '    <h2>Centrum zdarzen</h2>' +
        '    <p class="zerp-module-head-lead">Sledz najwazniejsze zdarzenia i reaguj na nowe wiadomosci, pingi i zmiany statusow.</p>' +
        '  </section>' +
        '  <div class="zerp-card zerp-notification-card">' +
        '    <div class="zerp-row zerp-notification-toolbar" style="margin-bottom:10px"><h3 style="margin:0">Powiadomienia</h3><div class="zerp-actions"><button class="zerp-btn" id="zerp-notif-refresh">Odswiez</button><button class="zerp-btn zerp-btn-primary" id="zerp-notif-mark-read" ' + (unread.length ? '' : 'disabled') + '>Oznacz nieprzeczytane jako przeczytane</button></div></div>' +
        '    <div class="zerp-list zerp-notification-list">' +
        (items.map(function (item) {
          return '<div class="zerp-list-item">' +
            '<div class="zerp-row"><strong>' + escapeHtml(item.title || '') + '</strong><span class="zerp-muted">' + escapeHtml(item.created_at || '') + '</span></div>' +
            '<div class="zerp-muted">' + escapeHtml(item.notification_type || '') + (item.is_read ? '' : ' | nieprzeczytane') + '</div>' +
            '<div style="margin-top:6px">' + escapeHtml(item.body || '') + '</div>' +
          '</div>';
        }).join('') || '<div class="zerp-list-item">Brak powiadomien.</div>') +
        '    </div>' +
        '  </div>' +
        '</div>';
      const refreshBtn = document.getElementById('zerp-notif-refresh');
      if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
          render().catch(function (err) {
            mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się odświeżyć powiadomień.') + '</p>', [{ label: 'OK' }]);
          });
        });
      }

      const markBtn = document.getElementById('zerp-notif-mark-read');
      if (markBtn) {
        markBtn.addEventListener('click', function () {
          if (!unread.length) {
            return;
          }
          api('notifications/read', { method: 'POST', body: { ids: unread.map(function (x) { return x.id; }) } })
            .then(function () { return Promise.all([render(), refreshUnreadCount()]); })
            .catch(function (err) {
              mountModal('Błąd', '<p>' + escapeHtml(err.message || 'Nie udało się oznaczyć powiadomień.') + '</p>', [{ label: 'OK' }]);
            });
        });
      }

      refreshUnreadCount();
    }

    render().catch(function (err) {
      el.moduleView.innerHTML = '<div class="zerp-card"><div class="zerp-error">' + escapeHtml(err.message || 'Nie udało się załadować powiadomień.') + '</div></div>';
    });

    return {
      unmount: function () {},
      hasUnsavedChanges: function () { return !!local.dirty; },
      saveBeforeLeave: async function () { local.dirty = false; },
      discardChanges: function () { local.dirty = false; }
    };
  }
  function bindTopbarActions() {
    if (el.btnNotifications) {
      el.btnNotifications.addEventListener('click', function () {
        showNotificationsModal();
      });
    }

    if (el.btnCommunicator) {
      el.btnCommunicator.addEventListener('click', function () {
        const has = !!findActiveModule('communicator');
        if (!has) {
          mountModal('Komunikator', '<p>Modul komunikatora nie jest dostepny dla Twojego konta.</p>', [{ label: 'OK' }]);
          return;
        }
        switchModule('communicator');
      });
    }

    if (el.btnNav) {
      el.btnNav.addEventListener('click', function () {
        toggleNavDrawer();
      });
    }

    if (el.userBtn) {
      el.userBtn.addEventListener('click', function () {
        openUserMenu();
      });
    }
  }
  function boot() {
    bindTopbarActions();
    if (CFG.auth_bg_url) {
      document.documentElement.style.setProperty('--zerp-auth-bg-url', 'url("' + String(CFG.auth_bg_url) + '")');
    }
    setPreLoginMode(true);

    window.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') {
        closeNavDrawer();
      }
    });

    window.addEventListener('beforeunload', function (ev) {
      if (state.moduleInstance && typeof state.moduleInstance.hasUnsavedChanges === 'function' && state.moduleInstance.hasUnsavedChanges()) {
        ev.preventDefault();
        ev.returnValue = '';
      }
    });

    if (state.token) {
      renderAuthLoading();
      afterAuth();
    } else {
      renderAuth();
    }

    setInterval(function () {
      if (!state.me) {
        return;
      }
      refreshUnreadCount();
    }, 30000);
  }

  boot();
})();


