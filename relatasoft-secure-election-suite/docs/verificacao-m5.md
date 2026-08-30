# Verificação M5 — UI / jornada do eleitor

Gate M5: eleitor completa o itinerário **sem shortcode obrigatório** e sem páginas do sítio.

## Critérios

| | |
|--|--|
| **Go** | Rotas nativas `/voto`, `/voto/cabina`, `/voto/obrigado` via `JourneyGateway` + front controller |
| **No-go** | Jornada só em páginas do host / shortcodes como caminho principal |

## Evidência

| Peça | Estado |
|------|--------|
| Contratos | `JourneyUrlGenerator` / `JourneyRouteResolver` / `JourneyPresenter` + `JourneySteps` |
| Application | `JourneyGateway` |
| Adapter #1 | `WordPressJourneyBootstrap`, `WordPressJourneyFrontController`, `WordPressJourneyPresenter` |
| URLs operacionais | `JourneySettings::rses_page_url` prefere gateway quando booted |
| Shortcodes | Facades finas → `JourneyGateway::render(WELCOME\|BOOTH\|THANK_YOU)` |
| Provisionamento | Páginas do sítio **opt-in** (Redirecionamentos); **não** na activação |
| UI operador | Eleição + “URLs da jornada” lideram com URL nativa; shortcode em detalhes/legado |
| Testes | `tests/Unit/Journey/JourneyPortsTest.php` |
| CI | `.github/workflows/phpunit.yml` |

## Corrigido nesta verificação

- Invertida a propriedade de apresentação: conteúdo em `VoterJourney::rses_render_{welcome,thank_you}`; shortcodes e presenter sem ciclo “presenter → shortcode”
- `Activator` deixa de chamar `rses_provision_pages()` (M5: rotas nativas por defeito)
- `VotingViews` (editor + gerador) prioriza `/voto/cabina/?election_id=&round_id=`
- `data-rses-journey="thank_you"` alinhado a `JourneySteps::THANK_YOU`
- Testes: paths canónicos, recibo em query, `isBooted`/reset, step inválido

## Residual aceitável (Adapter #1)

- Shortcodes e páginas do sítio continuam disponíveis como adaptadores opt-in
- Markup HTML da boas-vindas/obrigado ainda vive em `includes/Frontend/VoterJourney.php` (usa APIs do sítio: login, open elections)
- Assets em páginas legado via `rses_enqueue_journey_assets`; rotas nativas via front controller
- Textos EN residuales nalguns ecrãs admin (fora do caminho crítico M5)

**Veredicto M5:** PASS

```bash
./vendor/bin/phpunit --filter JourneyPortsTest
```
