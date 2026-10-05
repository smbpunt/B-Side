.DEFAULT_GOAL := help

SHELL := /bin/bash
.SHELLFLAGS := -o pipefail -c

# --- Variables ---
# Les commandes PHP tournent dans le conteneur php (répertoire /app = api/).
# En CI sans Docker : make ci EXEC_PHP= (depuis api/, avec php et composer installés)
COMPOSE     ?= docker compose
EXEC_PHP    ?= $(COMPOSE) exec php
EXEC_FRONT  ?= $(COMPOSE) exec front
PHP          = $(EXEC_PHP) php
COMPOSER     = $(EXEC_PHP) composer
SYMFONY      = $(PHP) bin/console
REPORTS_DIR ?= var/reports

# --- Includes ---
include make/docker.mk
include make/symfony.mk
include make/quality.mk
include make/ci.mk
include make/front.mk
include make/release.mk

# --- Help ---
.PHONY: help
help: ## Affiche cette aide
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
