# Verificação C* — Operação (isolamento, máscara, becape) — LEGADO Adapter #1

> Activação standalone actual: [`activar-standalone.md`](activar-standalone.md).  
> C2/C3 abaixo referem a máscara `/painel` no hospedeiro antigo.

Pacote §3.3 do roadmap: C1 isolamento E3, C2 máscara `/painel`, C3 becape/módulos/autoteste.

## Estado

| ID | Veredicto | Evidência |
|----|-----------|-----------|
| **C1** Isolamento 3 sítios | PASS | `ModeLock` ↔ `SiteModes`; UI “Modo do sítio” em PT; sem sync; doc E3 |
| **C2** Máscara `/painel` + 404 | PASS | `UrlMaskConfig::publicAccessDecision`; `UrlMask404PolicyTest`; `bin/ve-url-mask-smoke` |
| **C3** Becape / módulos / autoteste | PASS | Guards `BecapeService`; núcleo protegido; nav + PT no autoteste; doc ops |

## Corrigido neste fecho

- Labels de modo via `SiteModes` (PT-BR); ModeSetup alinhado a E3
- Matriz R4 de 404 como API de domínio + smoke CLI
- Validação de manifesto/basename de becape; delete do núcleo via `isCorePluginBasename`
- Autoteste no `NavigationService`; cópia PT; Conhecimento `ops-becape-modulos`

## Checklist manual (sítio vivo)

```bash
# CI / local sem HTTP
php bin/ve-url-mask-smoke
./vendor/bin/phpunit --filter 'UrlMask404PolicyTest|ModeIsolationTest|BecapeOpsGuardTest'

# Após deploy (substituir HOST)
curl -sI https://HOST/wp-admin/ | head -1          # esperar 404
curl -sI https://HOST/wp-login.php | head -1       # esperar 404
curl -sI https://HOST/painel/admin.php | head -1   # esperar 302/200 (auth)
curl -sI https://HOST/painel/plugins.php | head -1 # esperar 404
```

## Residual

- Apache `.htaccess` ainda reescreve `/painel`→`wp-admin` (PHP aplica 404); Nginx 404 na borda — documentado em `wordpress-dependencies.md`
- `CryptoSelfTest` UI ainda usa facade `includes/Crypto` (Domain coberto por PHPUnit)

**Veredicto C*:** PASS
