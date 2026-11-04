<?php
/**
 * Rapport PDF d'un projet.
 *
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder $this
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Model\MigrationReport $report
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Estimation\RiskScore $risk
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Estimation\CostEstimate|null $cost
 * @var list<\PierreArthur\SyliusUpgradeAnalyzer\Estimation\RoadmapStep> $roadmap
 * @var \DateTimeImmutable $generatedAt
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\MigrationServiceOffer|null $offer
 */

use PierreArthur\SyliusUpgradeAnalyzer\Model\Severity;

$breakingIssues = $report->getIssuesBySeverity(Severity::BREAKING);
$warningIssues = $report->getIssuesBySeverity(Severity::WARNING);
$suggestionIssues = $report->getIssuesBySeverity(Severity::SUGGESTION);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de migration Sylius — <?= $this->e($this->projectName($report)) ?></title>
    <?= $this->partial('_styles.php', []) ?>
</head>
<body>

<div class="cover">
    <div class="eyebrow">Audit de migration</div>
    <h1>Sylius <?= $this->e($report->getDetectedSyliusVersion() ?? '1.x') ?> → <?= $this->e($report->getTargetVersion()) ?></h1>
    <div class="project"><?= $this->e($this->projectName($report)) ?></div>
    <table class="meta">
        <tr><td class="label">Version détectée</td><td><?= $this->e($report->getDetectedSyliusVersion() ?? 'Non détectée') ?></td></tr>
        <tr><td class="label">Version cible</td><td><?= $this->e($report->getTargetVersion()) ?></td></tr>
        <tr><td class="label">Problèmes détectés</td><td><?= count($report->getIssues()) ?></td></tr>
        <?php if ($cost !== null): ?>
            <tr><td class="label">TJM retenu</td><td><?= $this->e($this->euros($cost->dailyRate)) ?></td></tr>
        <?php endif ?>
        <tr><td class="label">Généré le</td><td><?= $this->e($generatedAt->format('d/m/Y à H:i')) ?></td></tr>
    </table>
</div>

<div class="page-break section">
    <h2>Synthèse</h2>
    <?= $this->partial('_project-summary.php', ['report' => $report, 'risk' => $risk, 'cost' => $cost]) ?>
</div>

<?php if ($roadmap !== []): ?>
    <div class="section">
        <h2>Feuille de route</h2>
        <table class="data">
            <thead>
                <tr><th>Phase</th><th>Étape</th><th class="right">Heures</th><th>Parallélisable</th></tr>
            </thead>
            <tbody>
                <?php foreach ($roadmap as $step): ?>
                    <tr>
                        <td class="nowrap"><?= $this->e($step->stage) ?></td>
                        <td><strong><?= $this->e($step->name) ?></strong><br><span class="muted small"><?= $this->e($step->description) ?></span></td>
                        <td class="right nowrap"><?= $this->e($this->hours($step->estimatedHours)) ?></td>
                        <td><?= $step->canParallelize ? 'Oui' : 'Non' ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php if ($breakingIssues !== []): ?>
    <div class="page-break section">
        <h2>Problèmes bloquants (<?= count($breakingIssues) ?>)</h2>
        <p class="muted">Ces points empêchent le fonctionnement du projet sous Sylius <?= $this->e($report->getTargetVersion()) ?> et doivent être traités avant la mise en production.</p>
        <?php foreach ($breakingIssues as $index => $issue): ?>
            <div class="issue">
                <div class="title"><?= $index + 1 ?>. <?= $this->e($issue->getMessage()) ?></div>
                <div class="meta">
                    <?= $this->e($this->categoryLabel($issue->getCategory())) ?> · <?= $this->e($issue->getAnalyzer()) ?> · <?= $issue->getEstimatedMinutes() ?> min
                    <?php if ($issue->getFile() !== null): ?>
                        · <span class="mono"><?= $this->e($issue->getFile() . ($issue->getLine() !== null ? ':' . $issue->getLine() : '')) ?></span>
                    <?php endif ?>
                </div>
                <?php if ($issue->getDetail() !== ''): ?>
                    <div><?= $this->e($issue->getDetail()) ?></div>
                <?php endif ?>
                <?php if ($issue->getSuggestion() !== ''): ?>
                    <div class="fix"><strong>Correction :</strong> <?= $this->e($issue->getSuggestion()) ?></div>
                <?php endif ?>
                <?php if ($issue->getDocUrl() !== null): ?>
                    <div class="small"><a href="<?= $this->e($issue->getDocUrl()) ?>"><?= $this->e($issue->getDocUrl()) ?></a></div>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>

<?php if ($warningIssues !== []): ?>
    <div class="section">
        <h2>Avertissements (<?= count($warningIssues) ?>)</h2>
        <?= $this->partial('_issues-table.php', ['issues' => $warningIssues]) ?>
    </div>
<?php endif ?>

<?php if ($suggestionIssues !== []): ?>
    <div class="section">
        <h2>Suggestions (<?= count($suggestionIssues) ?>)</h2>
        <p class="muted">Non comptées dans l'estimation : améliorations recommandées mais non bloquantes.</p>
        <?= $this->partial('_issues-table.php', ['issues' => $suggestionIssues]) ?>
    </div>
<?php endif ?>

<?php if ($report->getIssues() === []): ?>
    <div class="section">
        <h2>Aucun problème détecté</h2>
        <p>Aucun des analyseurs n'a relevé de point de migration pour ce projet.</p>
    </div>
<?php endif ?>

<?php if ($offer !== null): ?>
    <?= $this->partial('_service-offer.php', ['offer' => $offer]) ?>
<?php endif ?>

</body>
</html>
