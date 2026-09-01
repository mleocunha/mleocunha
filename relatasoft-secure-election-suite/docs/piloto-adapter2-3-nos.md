# LEGADO — Piloto Adapter #2 (gate M6 / CLI)

> **Procedimento actual de activação e piloto HTTP:**  
> [`activar-standalone.md`](activar-standalone.md) · [`piloto-3-nos.md`](piloto-3-nos.md) · [`operacao-standalone.md`](operacao-standalone.md)

Este ficheiro conserva o guião **histórico** do gate M6 (`bin/ve-node pilot`,
persistência JSON, courier). A superfície suportada para operadores e eleitores
é o **standalone HTTP** (`index.php` / `bin/ve-http`).

## Referência rápida (CLI)

```bash
cd relatasoft-secure-election-suite
composer install
php bin/ve-node pilot --root=/tmp/ve-piloto --cliente=piloto --votes=2
./vendor/bin/phpunit --filter ThreeNodePilotTest
```

Verificação histórica: `docs/verificacao-m6.md`, `docs/verificacao-a61-persistencia.md`.
