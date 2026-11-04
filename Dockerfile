# Image autonome de sylius-upgrade-analyzer.
#
# Elle embarque son propre PHP : un projet Sylius 1.x en PHP 7.4 ou Symfony 5.4 peut etre
# analyse sans rien installer dans le projet, ni toucher a son composer.json.
#
#   docker run --rm -v "$PWD:/project" ghcr.io/pierrearthurdemengel/sylius-upgrade-analyzer
#
# Images de base epinglees par empreinte : la construction est reproductible.

FROM composer:2.10.2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS composer

FROM php:8.3.36-cli-alpine@sha256:86a7a8f46bbf73120a19cf72246f606b6de9aaa84a00971c25d187fbae44c211 AS build
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /src
COPY . .
RUN sh .github/scripts/prepare-dist.sh /dist

FROM php:8.3.36-cli-alpine@sha256:86a7a8f46bbf73120a19cf72246f606b6de9aaa84a00971c25d187fbae44c211

LABEL org.opencontainers.image.title="Sylius Upgrade Analyzer" \
      org.opencontainers.image.description="Automated Sylius 1.x to 2.x migration audit, independent of the audited project's PHP version" \
      org.opencontainers.image.source="https://github.com/pierrearthurdemengel/sylius-upgrade-analyzer" \
      org.opencontainers.image.licenses="MIT"

COPY --from=build /dist /opt/sylius-upgrade-analyzer

# Utilisateur non privilegie ; le projet monte sur /project n'est lu qu'en lecture,
# sauf pour les rapports ecrits a l'emplacement demande par --output.
RUN adduser -D -u 1000 analyzer
USER analyzer
WORKDIR /project

ENTRYPOINT ["php", "/opt/sylius-upgrade-analyzer/bin/sylius-upgrade-analyzer"]
CMD ["sylius-upgrade:analyze", "/project"]
