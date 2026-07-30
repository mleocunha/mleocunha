# Voto Eletrônico — Tema Base

Tema WordPress standalone (slug `voto-eletronico-tema-base`) para o **RelataSoft Secure Election Suite**.

## Objetivo

- Evidenciar o processo eleitoral (shortcodes RSES exclusivamente)
- Ocultar menus, sidebars, rodapés e widgets do WordPress no front
- Branding RelataSoft oficial (sem regeneração), com white-label via o plugin
- Páginas da jornada resolvidas por **ID** (`rses_settings`) — mudança de slug não quebra o tema

## Requisitos

- WordPress 6.0+
- PHP 8.1+
- Plugin **RelataSoft Secure Election Suite** (recomendado; logos e páginas da jornada vêm de `rses_settings` por **ID**)

## Instalação

1. Copie a pasta `voto-eletronico-tema-base` para `wp-content/themes/`
2. Ative em **Aparência → Temas**
3. No plugin: **Election Suite → Redirections** — crie/apointe welcome, cabina e thank-you
4. Opcional: **Settings → Admin logo** e logo de login para white-label
5. Opcional: **Aparência → Personalizar → Front eleitoral** — extras do tema (login continua no plugin)

## Logos (defaults oficiais, aprovados por marketing)

| Arquivo | Uso |
|---------|-----|
| `pinwheel.svg` | Favicon / marcador de URL / cabina (baixa poluição visual) |
| `lockup-horizontal-on-dark.png` | Expressão comum: roda à esquerda do nome, slogan abaixo |
| `lockup-vertical-light-text.png` | Tom oficial / PDF: roda centralizada acima do nome e slogan |

Tamanho ajustável via CSS; **aspect-ratio nunca é distorcido**. Animação da roda de fogo = apenas `transform: rotate` CSS no asset oficial (útil em telas longas sem movimento, como espera / tallying no front).

White-label: `admin_logo_attachment_id` (lockup) e `login_logo_attachment_id` (pinwheel/favicon) do plugin; fallback Custom Logo; depois assets do tema.

## Idioma

`pt_BR` é o padrão do tema quando o site está em `en_US`/vazio. Catálogos adicionais em `languages/catalogs/`.
