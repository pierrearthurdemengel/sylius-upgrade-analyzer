<?php
/**
 * Synthèse d'un projet : indicateurs clés, répartition par catégorie et risques.
 *
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder $this
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport $report
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScore $risk
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimate|null $cost
 */

use PierreArthur\SyliusUpgradeAnalyzer\Model\Category;
use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

$breakingCount = count($report->getIssuesBySeverity(Severity::BREAKING));
$warningCount = count($report->getIssuesBySeverity(Severity::WARNING));
$hoursByCategory = $report->getEstimatedHoursByCategory();
$maxCategoryHours = $hoursByCategory === [] ? 0.0 : max($hoursByCategory);
?>
<table class="kpis">
    <tr>
        <td class="<?= $this->complexityTone($report->getComplexity()) ?>">
            <div class="kpi-label">Complexité</div>
            <div class="kpi-value"><?= $this->e($this->complexityLabel($report->getComplexity())) ?></div>
        </td>
        <td class="tone-neutral">
            <div class="kpi-label">Charge estimée</div>
            <div class="kpi-value"><?= $this->e($this->hours($report->getTotalEstimatedHours())) ?></div>
            <?php if ($cost !== null): ?>
                <div class="kpi-hint"><?= $this->e(str_replace('.', ',', (string) $cost->days)) ?> j · <?= $this->e($this->euros($cost->amount)) ?></div>
            <?php else: ?>
                <div class="kpi-hint">bloquants + avertissements</div>
            <?php endif ?>
        </td>
        <td class="<?= $breakingCount > 0 ? 'tone-bad' : 'tone-ok' ?>">
            <div class="kpi-label">Bloquants</div>
            <div class="kpi-value"><?= $breakingCount ?></div>
            <div class="kpi-hint"><?= $warningCount ?> avertissement(s)</div>
        </td>
        <td class="<?= $this->riskTone($risk) ?>">
            <div class="kpi-label">Risque</div>
            <div class="kpi-value"><?= $this->e($this->riskLabel($risk->level)) ?></div>
            <div class="kpi-hint"><?= count($risk->factors) ?> facteur(s) identifié(s)</div>
        </td>
    </tr>
</table>

<?php if ($hoursByCategory !== []): ?>
    <h3>Répartition de la charge par catégorie</h3>
    <table class="data">
        <thead>
            <tr>
                <th>Catégorie</th>
                <th class="right">Problèmes</th>
                <th class="right">Heures</th>
                <th style="width: 38%"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($hoursByCategory as $categoryValue => $categoryHours): ?>
                <?php $category = Category::from($categoryValue); ?>
                <tr>
                    <td><?= $this->e($this->categoryLabel($category)) ?></td>
                    <td class="right"><?= count($report->getIssuesByCategory($category)) ?></td>
                    <td class="right nowrap"><?= $this->e($this->hours($categoryHours)) ?></td>
                    <td><div class="bar"><span style="width: <?= $maxCategoryHours > 0 ? (int) round($categoryHours / $maxCategoryHours * 100) : 0 ?>%"></span></div></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<?php if ($risk->factors !== []): ?>
    <h3>Facteurs de risque</h3>
    <table class="data">
        <thead>
            <tr><th>Facteur</th><th>Impact</th><th class="right">Poids</th></tr>
        </thead>
        <tbody>
            <?php foreach ($risk->factors as $factor): ?>
                <tr>
                    <td><strong><?= $this->e($factor->name) ?></strong><br><span class="muted small"><?= $this->e($factor->description) ?></span></td>
                    <td><?= $this->e($factor->impact) ?></td>
                    <td class="right"><?= $factor->weight ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<?php if ($risk->recommendations !== []): ?>
    <div class="callout">
        <strong>Recommandations</strong>
        <ul class="plain">
            <?php foreach ($risk->recommendations as $recommendation): ?>
                <li><?= $this->e($recommendation) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>
