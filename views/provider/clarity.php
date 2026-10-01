<?php
/**
 * Provider-view partial — Microsoft Clarity breakdown tables (referrers / pages / browsers / smart events).
 * Included by index.php inside the generic provider view when provider_key = 'clarity'.
 * In scope from index.php: $clarityBreakdowns (allstat_get_clarity_breakdowns) + the h() helper.
 * Presentation only — no DB access here; the shared KPI grid + trend are still rendered by index.php.
 */
?>
<?php if (!empty($clarityBreakdowns)): ?>
<section class="tables-grid clarity-breakdowns" aria-label="Clarity rozpady">
    <?php foreach ($clarityBreakdowns as $breakdown): ?>
        <article class="panel table-panel">
            <div class="panel-header"><h2><?= h($breakdown['title']) ?> <span>(Clarity)</span></h2></div>
            <p class="panel-help"><?= h($breakdown['help']) ?></p>
            <div class="table-scroll">
                <table>
                    <thead><tr><th><?= h($breakdown['title']) ?></th><th>Návštěvy</th><th>Podíl</th></tr></thead>
                    <tbody>
                        <?php foreach ($breakdown['items'] as $item): ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= h($item['sessionsLabel']) ?></td>
                                <td><?= h($item['shareLabel']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$breakdown['items']): ?>
                            <tr><td colspan="3" class="table-muted">Bez dat za toto období, naimportuj denní Clarity CSV nebo počkej na sync.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
