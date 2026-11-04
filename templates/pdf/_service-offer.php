<?php
/**
 * Encart de fin de rapport presentant l'offre d'accompagnement a la migration.
 *
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\Pdf\HtmlReportBuilder $this
 * @var \PierreArthur\SyliusUpgradeAnalyzer\Report\MigrationServiceOffer $offer
 */
$offerUrl = $offer->url('pdf');
?>
<div class="section service-offer">
    <h2>Need help with this migration?</h2>
    <p>
        This report was produced by Sylius Upgrade Analyzer, an open-source tool developed by
        <strong><?= $this->e($offer::AUTHOR) ?></strong>, freelance developer specialised in Sylius 1.x to 2.x migrations.
    </p>
    <ul class="plain">
        <li><strong>Audit</strong>: review of this report with your team and prioritisation of the work.</li>
        <li><strong>Fixed-price migration</strong>: the upgrade carried out end to end, from dependencies to production.</li>
        <li><strong>Team support</strong>: guidance for your developers on Twig Hooks, Symfony Workflow and Payment Requests.</li>
    </ul>
    <p>Free diagnosis: <a href="<?= $this->e($offerUrl) ?>"><?= $this->e($offerUrl) ?></a></p>
</div>
