.DEFAULT_GOAL := help
DC := docker compose
EXEC := $(DC) exec -T php

.PHONY: help
help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: build
build: ## Construye la imagen Docker
	$(DC) build

.PHONY: up
up: ## Levanta el contenedor
	$(DC) up -d

.PHONY: down
down: ## Para el contenedor
	$(DC) down

.PHONY: install
install: ## Instala dependencias con Composer
	$(EXEC) composer install

.PHONY: shell
shell: ## Abre una shell dentro del contenedor
	$(DC) exec php sh

.PHONY: test
test: ## Ejecuta los tests unitarios
	$(EXEC) vendor/bin/phpunit --testsuite=unit

.PHONY: test-all
test-all: ## Ejecuta todos los tests
	$(EXEC) vendor/bin/phpunit

.PHONY: stan
stan: ## Análisis estático PHPStan nivel 9
	$(EXEC) vendor/bin/phpstan analyse --no-progress

.PHONY: check
check: stan test ## Análisis estático + tests (lo que corre CI)

.PHONY: demo
demo: ## Demo con plataforma simulada, sin API key
	$(EXEC) php examples/demo.php

.PHONY: record
record: ## Graba cassettes reales contra Groq (necesita GROQ_API_KEY)
	$(EXEC) php examples/groq_record.php

.PHONY: drift
drift: ## Comprueba deriva del modelo contra las cassettes grabadas
	$(EXEC) php bin/llm-vcr drift
