<?php
/**
 * Rapport PDF consolidé de plusieurs projets.
 *
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder $this
 * @var list<array{report: \PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport, risk: \PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScore, cost: \PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimate|null}> $projects
 * @var float $totalHours
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimate|null $totalCost
 * @var \DateTimeImmutable $generatedAt
 */

use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

$totalIssues = 0;
$totalBreaking = 0;
foreach ($projects as $project) {
    $totalIssues += count($project['report']->getIssues());
    $totalBreaking += count($project['report']->getIssuesBySeverity(Severity::BREAKING));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de migration Sylius — <?= count($projects) ?> projets</title>
    <?= $this->partial('_styles.php', []) ?>
</head>
<body>

<div class="cover">
    <div class="eyebrow">Audit de migration consolidé</div>
    <h1>Migration Sylius 2.x</h1>
    <div class="project"><?= count($projects) ?> projet(s) analysé(s)</div>
    <table class="meta">
        <tr><td class="label">Charge totale</td><td><?= $this->e($this->hours($totalHours)) ?></td></tr>
        <?php if ($totalCost !== null): ?>
            <tr><td class="label">Budget estimé</td><td><?= $this->e($this->euros($totalCost->amount)) ?> (TJM <?= $this->e($this->euros($totalCost->dailyRate)) ?>)</td></tr>
        <?php endif ?>
        <tr><td class="label">Problèmes détectés</td><td><?= $totalIssues ?>, dont <?= $totalBreaking ?> bloquant(s)</td></tr>
        <tr><td class="label">Généré le</td><td><?= $this->e($generatedAt->format('d/m/Y à H:i')) ?></td></tr>
    </table>
</div>

<div class="page-break section">
    <h2>Vue d'ensemble</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Projet</th>
                <th>Version</th>
                <th>Complexité</th>
                <th>Risque</th>
                <th class="right">Bloquants</th>
                <th class="right">Problèmes</th>
                <th class="right">Charge</th>
                <?php if ($totalCost !== null): ?><th class="right">Budget</th><?php endif ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($projects as $project): ?>
                <?php $report = $project['report']; ?>
                <tr>
                    <td><strong><?= $this->e($this->projectName($report)) ?></strong></td>
                    <td class="nowrap"><?= $this->e($report->getDetectedSyliusVersion() ?? '?') ?> → <?= $this->e($report->getTargetVersion()) ?></td>
                    <td><?= $this->e($this->complexityLabel($report->getComplexity())) ?></td>
                    <td><?= $this->e($this->riskLabel($project['risk']->level)) ?></td>
                    <td class="right"><?= count($report->getIssuesBySeverity(Severity::BREAKING)) ?></td>
                    <td class="right"><?= count($report->getIssues()) ?></td>
                    <td class="right nowrap"><?= $this->e($this->hours($report->getTotalEstimatedHours())) ?></td>
                    <?php if ($project['cost'] !== null): ?><td class="right nowrap"><?= $this->e($this->euros($project['cost']->amount)) ?></td><?php endif ?>
                </tr>
            <?php endforeach ?>
            <tr class="total">
                <td colspan="4">Total</td>
                <td class="right"><?= $totalBreaking ?></td>
                <td class="right"><?= $totalIssues ?></td>
                <td class="right nowrap"><?= $this->e($this->hours($totalHours)) ?></td>
                <?php if ($totalCost !== null): ?><td class="right nowrap"><?= $this->e($this->euros($totalCost->amount)) ?></td><?php endif ?>
            </tr>
        </tbody>
    </table>
    <?php if ($totalCost !== null): ?>
        <p class="muted small">Budget calculé sur une base de 7 h par jour, chaque projet étant arrondi à la demi-journée supérieure.</p>
    <?php endif ?>
</div>

<?php foreach ($projects as $project): ?>
    <?php
    $report = $project['report'];
    $breakingIssues = $report->getIssuesBySeverity(Severity::BREAKING);
    ?>
    <div class="page-break section">
        <h2><?= $this->e($this->projectName($report)) ?></h2>
        <p class="muted mono"><?= $this->e($report->getProjectPath()) ?></p>
        <?= $this->partial('_project-summary.php', ['report' => $report, 'risk' => $project['risk'], 'cost' => $project['cost']]) ?>
        <?php if ($breakingIssues !== []): ?>
            <h3>Problèmes bloquants (<?= count($breakingIssues) ?>)</h3>
            <?= $this->partial('_issues-table.php', ['issues' => $breakingIssues]) ?>
        <?php endif ?>
    </div>
<?php endforeach ?>

</body>
</html>
