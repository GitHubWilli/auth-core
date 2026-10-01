<?php
declare(strict_types=1);

/**
 * HTML des Tabs "Logo" fuer users.php (nur Administratoren): Vorschau, Upload, Alt-Text/Link, Entfernen.
 * Verwendet die vorhandenen Klassen der Verwaltungsseiten (panel, button, dialog-actions).
 */
function authRenderBrandingPanel(): string
{
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $logo = authBrandLogo();
    $meta = authBrandMeta();
    $action = $h(authRoute('api_admin_update_logo'));

    $preview = $logo !== null
        ? '<div style="background:#1f2937;height:40px;display:inline-block;border-radius:6px;margin:6px 0 12px;">'
            . '<img src="' . $h($logo['src']) . '" alt="Aktuelles Logo" style="display:block;height:40px;width:auto;max-width:70vw;"></div>'
            . '<p class="meta">So erscheint das Logo links in der oberen Leiste (Höhe = Leistenhöhe).</p>'
        : '<p class="meta">Es ist noch kein Logo hinterlegt. Die obere Leiste zeigt dann nur die Anwendungsfunktionen.</p>';

    $remove = '';
    $removeScript = '';
    if ($logo !== null) {
        $remove = '<form action="' . $action . '" method="post" style="margin-top:12px;" data-brand-remove>'
            . csrfInput()
            . '<input type="hidden" name="action" value="remove">'
            . '<button type="submit" class="button button-danger">Logo entfernen</button></form>';

        // Rueckfrage ueber den gemeinsamen Dialog des Design-Systems (confirmDestructive)
        $removeScript = <<<'JS'
<script>
(function () {
    var form = document.querySelector('form[data-brand-remove]');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        if (form.dataset.confirmed === '1') return;
        event.preventDefault();
        var go = function () { form.dataset.confirmed = '1'; form.submit(); };
        var text = 'Das Logo wird entfernt. Die obere Leiste zeigt danach wieder nur die Anwendungsfunktionen.';
        if (typeof confirmDestructive === 'function') {
            confirmDestructive({ title: 'Logo entfernen?', message: text, confirmLabel: 'Entfernen', onConfirm: go });
        } else if (window.confirm(text)) {
            go();
        }
    });
})();
</script>
JS;
    }

    return '<div class="panel">'
        . '<h2>Logo</h2>'
        . '<p class="meta">Das Logo steht ganz links in der oberen Leiste. Alle Text-Buttons (z. B. „Zurück zur Startseite“) stehen direkt rechts daneben. '
        . 'Erlaubt: SVG, PNG, JPG, WebP (höchstens 2 MB). Rasterbilder werden auf 160 px Höhe verkleinert.</p>'
        . $preview
        . '<form action="' . $action . '" method="post" enctype="multipart/form-data">'
        . csrfInput()
        . '<input type="hidden" name="action" value="save">'
        . '<label>Logo-Datei' . ($logo !== null ? ' (leer lassen = bisheriges behalten)' : '')
        . '<input type="file" name="logo" accept="image/svg+xml,image/png,image/jpeg,image/webp"></label>'
        . '<label>Alternativtext<input type="text" name="alt" maxlength="120" value="' . $h($meta['alt']) . '" placeholder="z. B. DAS DA Theater"></label>'
        . '<label>Link beim Klick (intern, optional)<input type="text" name="href" value="' . $h($meta['href']) . '" placeholder="z. B. /index.php"></label>'
        . '<div class="dialog-actions"><button type="submit">Speichern</button></div>'
        . '</form>'
        . $remove
        . $removeScript
        . '</div>';
}
