(() => {
  'use strict';

  const state = {
    registry: null,
    fallback: {},
    messages: {},
    locale: 'id'
  };

  const localePath = document.documentElement.dataset.localePath || 'locales/';

  async function loadJson(path) {
    const response = await fetch(path, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error(`Unable to load ${path}: ${response.status}`);
    }
    return response.json();
  }

  async function initializeI18n() {
    state.registry = await loadJson(`${localePath}languages.json`);
    const enabled = state.registry.languages.filter((language) => language.enabled);
    const stored = localStorage.getItem(state.registry.storageKey);
    const browserLocale = navigator.language?.split('-')[0];
    const allowed = new Set(enabled.map((language) => language.code));

    state.locale = allowed.has(stored)
      ? stored
      : allowed.has(browserLocale)
        ? browserLocale
        : state.registry.default;

    const fallbackEntry = enabled.find((language) => language.code === state.registry.fallback);
    if (fallbackEntry) {
      state.fallback = await loadJson(`${localePath}${fallbackEntry.file}`);
    }

    populateLocaleSelectors(enabled);
    await setLocale(state.locale, false);
  }

  function populateLocaleSelectors(languages) {
    document.querySelectorAll('[data-language-select]').forEach((select) => {
      select.replaceChildren();
      languages.forEach((language) => {
        const option = document.createElement('option');
        option.value = language.code;
        option.textContent = language.name;
        select.append(option);
      });
      select.addEventListener('change', () => setLocale(select.value));
    });
  }

  async function setLocale(code, persist = true) {
    const language = state.registry.languages.find(
      (entry) => entry.code === code && entry.enabled
    );
    if (!language) return;

    try {
      state.messages = await loadJson(`${localePath}${language.file}`);
      state.locale = language.code;
      document.documentElement.lang = language.code;
      document.documentElement.dir = language.direction || 'ltr';
      if (persist) localStorage.setItem(state.registry.storageKey, language.code);

      document.querySelectorAll('[data-language-select]').forEach((select) => {
        select.value = language.code;
      });
      translateDocument();
      document.dispatchEvent(new CustomEvent('fpdp:locale-changed', {
        detail: { locale: language.code }
      }));
    } catch (error) {
      console.error(error);
    }
  }

  function translate(key) {
    return state.messages[key] ?? state.fallback[key] ?? key;
  }

  function translateDocument() {
    document.querySelectorAll('[data-i18n]').forEach((element) => {
      element.textContent = translate(element.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach((element) => {
      element.placeholder = translate(element.dataset.i18nPlaceholder);
    });
    document.querySelectorAll('[data-i18n-aria-label]').forEach((element) => {
      element.setAttribute('aria-label', translate(element.dataset.i18nAriaLabel));
    });
  }

  function initializeDashboardNavigation() {
    const navItems = document.querySelectorAll('[data-page-target]');
    const pages = document.querySelectorAll('[data-page]');
    if (!navItems.length || !pages.length) return;

    const showPage = (pageName, updateHash = true) => {
      const selectedPage = document.querySelector(`[data-page="${pageName}"]`);
      if (!selectedPage) return;

      pages.forEach((page) => page.classList.toggle('active', page === selectedPage));
      navItems.forEach((item) => {
        const active = item.dataset.pageTarget === pageName;
        item.classList.toggle('active', active);
        if (active) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
      });
      if (updateHash) history.replaceState(null, '', `#${pageName}`);
      document.querySelector('.sidebar')?.classList.remove('open');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    navItems.forEach((item) => item.addEventListener('click', (event) => {
      if (item.tagName === 'A' && item.getAttribute('href')?.endsWith('.html')) return;
      event.preventDefault();
      showPage(item.dataset.pageTarget);
    }));

    const initial = location.hash.slice(1) || 'overview';
    showPage(initial, false);

    document.querySelectorAll('[data-go-page]').forEach((button) => {
      button.addEventListener('click', () => showPage(button.dataset.goPage));
    });
  }

  function initializeSettingsTabs() {
    const tabs = document.querySelectorAll('[data-settings-target]');
    const panels = document.querySelectorAll('[data-settings-panel]');
    tabs.forEach((tab) => tab.addEventListener('click', () => {
      tabs.forEach((item) => item.classList.toggle('active', item === tab));
      panels.forEach((panel) => {
        panel.classList.toggle('active', panel.dataset.settingsPanel === tab.dataset.settingsTarget);
      });
    }));
  }

  function initializeInteractions() {
    document.querySelector('[data-mobile-toggle]')?.addEventListener('click', () => {
      document.querySelector('.sidebar')?.classList.toggle('open');
    });

    document.querySelectorAll('.toggle').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        toggle.classList.toggle('on');
        toggle.setAttribute('aria-pressed', String(toggle.classList.contains('on')));
      });
    });

    document.querySelectorAll('[data-save-settings]').forEach((button) => {
      button.addEventListener('click', () => showToast(translate('settings.saved')));
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        event.preventDefault();
        document.querySelector('.search-box input')?.focus();
      }
      if (event.key === 'Escape') document.querySelector('.sidebar')?.classList.remove('open');
    });
  }

  function showToast(message) {
    const toast = document.querySelector('[data-toast]');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove('show'), 2200);
  }

  document.addEventListener('DOMContentLoaded', async () => {
    initializeDashboardNavigation();
    initializeSettingsTabs();
    initializeInteractions();
    try {
      await initializeI18n();
    } catch (error) {
      console.error('FPDP i18n initialization failed.', error);
      document.documentElement.lang = 'en';
    }
  });
})();
