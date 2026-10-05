.PHONY: release

## ---- Release ----

# Pousse le tag vX.Y.Z : .github/workflows/docker.yml teste puis publie l'image (X.Y.Z, et latest si version finale).
release: ## Publie une version : make release v=1.2.3 (ou 1.2.3-rc.1)
	@[[ "$(v)" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$$ ]] || { echo "Version invalide : make release v=1.2.3"; exit 1; }
	@[[ "$$(git branch --show-current)" == main ]] || { echo "À lancer depuis main"; exit 1; }
	@git diff --quiet && git diff --cached --quiet || { echo "Modifications non commitées"; exit 1; }
	@git fetch -q --tags origin main
	@[[ "$$(git rev-parse HEAD)" == "$$(git rev-parse origin/main)" ]] || { echo "main n'est pas à jour avec origin/main"; exit 1; }
	@! git rev-parse -q --verify "refs/tags/v$(v)" >/dev/null || { echo "Le tag v$(v) existe déjà"; exit 1; }
	git tag -a "v$(v)" -m "v$(v)"
	git push origin "v$(v)"
