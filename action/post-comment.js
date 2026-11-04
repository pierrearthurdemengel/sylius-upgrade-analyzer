// Script Node.js pour poster un commentaire de resume sur une PR GitHub
// Lit le rapport JSON et genere un commentaire formate en Markdown

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

// GitHub refuse les commentaires de plus de 65 536 caracteres : un gros projet
// Sylius 1.x peut compter plusieurs centaines d'issues bloquantes.
const MAX_LISTED_ISSUES = 50;

function buildComment(report) {
    const summary = report.summary;

    let comment = `## Sylius Upgrade Analyzer\n\n`;
    comment += `| Metrique | Valeur |\n`;
    comment += `|---|---|\n`;
    comment += `| Complexite | **${String(summary.complexity).toUpperCase()}** |\n`;
    comment += `| Heures estimees | ${summary.total_hours}h |\n`;
    comment += `| Issues BREAKING | ${summary.breaking_count} |\n`;
    comment += `| Issues WARNING | ${summary.warning_count} |\n`;
    comment += `| Issues SUGGESTION | ${summary.suggestion_count} |\n\n`;

    if (summary.breaking_count > 0) {
        const breaking = [];
        for (const category of Object.keys(report.issues || {})) {
            for (const issue of report.issues[category]) {
                if (issue.severity === 'breaking') {
                    breaking.push(`- **[${category.toUpperCase()}]** ${issue.message}`);
                }
            }
        }

        comment += `### Issues BREAKING\n\n`;
        comment += breaking.slice(0, MAX_LISTED_ISSUES).join('\n') + '\n';
        if (breaking.length > MAX_LISTED_ISSUES) {
            comment += `\n_… et ${breaking.length - MAX_LISTED_ISSUES} autre(s) : voir le rapport complet._\n`;
        }
        comment += `\n`;
    }

    comment += `---\n`;
    comment += `*Rapport de [sylius-upgrade-analyzer](https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer)*\n`;

    return comment;
}

function main() {
    const reportFile = process.env.REPORT_FILE || 'sylius-upgrade-report.json';

    if (!fs.existsSync(reportFile)) {
        console.error(`Rapport introuvable : ${reportFile}`);
        process.exitCode = 1;
        return;
    }

    const comment = buildComment(JSON.parse(fs.readFileSync(reportFile, 'utf-8')));

    const prNumber = process.env.GITHUB_REF?.match(/^refs\/pull\/(\d+)\//)?.[1];
    if (!prNumber) {
        console.log('Pas de PR detectee. Commentaire non poste.');
        console.log(comment);
        return;
    }

    const bodyFile = path.join(process.env.RUNNER_TEMP || os.tmpdir(), 'sylius-upgrade-pr-comment.md');
    fs.writeFileSync(bodyFile, comment);

    try {
        // execFileSync sans shell : aucun argument n'est interprete par un interpreteur de commandes.
        execFileSync('gh', ['pr', 'comment', prNumber, '--repo', process.env.GITHUB_REPOSITORY, '--body-file', bodyFile], {
            env: { ...process.env, GH_TOKEN: process.env.GITHUB_TOKEN },
            stdio: 'inherit',
        });
        console.log(`Commentaire poste sur la PR #${prNumber}`);
    } catch (error) {
        // Sur une PR issue d'un fork, le jeton est en lecture seule : le commentaire est un
        // complement, son echec ne doit pas faire echouer l'audit.
        console.log(`::warning::Commentaire de PR non poste : ${error.message}`);
    }
}

module.exports = { buildComment, MAX_LISTED_ISSUES };

if (require.main === module) {
    main();
}
