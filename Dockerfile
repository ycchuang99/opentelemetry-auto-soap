ARG PHP_VERSION=8.1
FROM ghcr.io/open-telemetry/opentelemetry-php/opentelemetry-php-base:${PHP_VERSION}

USER root

RUN install-php-extensions \
        opentelemetry \
        soap

USER php
