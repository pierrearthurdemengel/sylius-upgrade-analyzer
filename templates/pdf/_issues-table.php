<?php
/**
 * Tableau compact d'issues (avertissements, suggestions, ou bloquants en vue consolidée).
 *
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder $this
 * @var list<\PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationIssue> $issues
 */
?>
<table class="data">
    <thead>
        <tr>
            <th>Problème</th>
            <th>Emplacement</th>
            <th class="right">Estim.</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($issues as $issue): ?>
            <tr>
                <td>
                    <span class="badge badge-<?= $this->e($issue->getSeverity()->value) ?>"><?= $this->e($this->severityLabel($issue->getSeverity())) ?></span>
                    <?= $this->e($issue->getMessage()) ?>
                    <br><span class="muted small"><?= $this->e($this->categoryLabel($issue->getCategory())) ?> · <?= $this->e($issue->getAnalyzer()) ?></span>
                </td>
                <td class="mono"><?= $this->e($issue->getFile() !== null ? $issue->getFile() . ($issue->getLine() !== null ? ':' . $issue->getLine() : '') : '—') ?></td>
                <td class="right nowrap"><?= $issue->getEstimatedMinutes() ?> min</td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
