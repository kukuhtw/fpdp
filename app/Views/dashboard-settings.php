<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <?= \App\Core\View::themeStylesheetTag() ?>
</head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Settings</h1>
        <p id="auth-summary" class="muted">Sign in to manage settings.</p>
        <form id="login-form" class="stack" method="post">
            <label>Email<input name="email" type="email" autocomplete="email" required></label>
            <label class="password-field">Password
                <span class="password-wrapper">
                    <input name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" aria-label="Show password" data-show="Show" data-hide="Hide">Show</button>
                </span>
            </label>
            <button type="submit">Sign in</button>
        </form>
    </section>

    <section id="settings-content" class="hidden stack">
        <div class="two-col">
            <section class="panel">
                <h2>Payment Gateways</h2>
                <p class="muted">Configure payment gateway credentials. Secrets are encrypted at rest.</p>
                <div id="gateway-list"></div>
            </section>
            <section class="panel">
                <h2>Gateway Configuration</h2>
                <p class="muted" id="config-subtitle">Select a gateway to configure.</p>
                <form id="gateway-form" class="stack hidden">
                    <input name="code" type="hidden">
                    <div class="field-row">
                        <label>Environment<select name="environment"><option value="SANDBOX">Sandbox</option><option value="LIVE">Live</option></select></label>
                    </div>
                    <p id="config-existing-note" class="muted"></p>
                    <div id="gateway-fields"></div>
                    <button type="submit">Save Configuration</button>
                </form>
                <p id="settings-status" class="status" role="status" aria-live="polite"></p>
            </section>
        </div>

        <section class="panel">
            <h2>AI / LLM Provider</h2>
            <p class="muted">Configure an LLM provider for AI features (e.g. generating a product description from a photo). The API key is encrypted at rest and never shown in full again.</p>
            <p id="llm-key-hint" class="muted hidden"></p>
            <form id="llm-form" class="stack">
                <label>Provider<select name="provider_code">
                    <option value="OPENAI">OpenAI</option>
                    <option value="ANTHROPIC">Anthropic</option>
                    <option value="OPENROUTER">OpenRouter</option>
                </select></label>
                <label>Model<input name="model" placeholder="gpt-4o-mini" required></label>
                <label>API Key<input name="api_key" type="password" placeholder="Enter to set or replace the stored key" autocomplete="off"></label>
                <label class="check"><input name="supports_vision" type="checkbox"> This model can read images (vision)</label>
                <button type="submit">Save LLM Settings</button>
            </form>
            <p id="llm-status" class="status" role="status" aria-live="polite"></p>
        </section>

        <section class="panel">
            <h2>Install another gateway (plugin)</h2>
            <p class="muted">Beyond the built-in gateways above, you can add your own by copying a folder to <code>/gateways/&lt;slug&gt;/</code> on the server — the same no-upload-from-browser model as Template. No restart needed; reload this page and it appears in the list above, ready to configure and activate. Full guide: <code>documentation/PAYMENT-GATEWAY-PLUGIN-GUIDE.id.md</code>. A working example ships at <code>gateways/manual-transfer/</code>.</p>
        </section>
    </section>
</main>
<script src="/assets/dashboard-settings.js" defer></script>
</body>
</html>