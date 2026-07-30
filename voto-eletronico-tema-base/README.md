# Voto Eletrônico — Tema Base

Tema WordPress standalone (slug `voto-eletronico-tema-base`) para o **RelataSoft Secure Election Suite**.

## Objetivo

- Evidenciar o processo eleitoral (shortcodes RSES)
- Ocultar menus, sidebars, rodapés e widgets do WordPress no front
- Branding RelataSoft oficial (sem regeneração), com white-label via o plugin

## Requisitos

- WordPress 6.0+
- PHP 8.1+
- Plugin **RelataSoft Secure Election Suite** (recomendado; logos e páginas da jornada vêm de `rses_settings` por **ID**)

## Instalação

1. Copie a pasta `voto-eletronico-tema-base` para `wp-content/themes/`
2. Ative em **Aparência → Temas**
3. No plugin: **Election Suite → Redirections** — crie/apointe welcome, cabina e thank-you
4. Opcional: **Settings → Admin logo** e logo de login para white-label

## Logos (defaults oficiais)

| Arquivo | Uso |
|---------|-----|
| `pinwheel.svg` | Favicon / marcador / cabina (baixa poluição visual) |
| `lockup-horizontal-on-dark.png` | Expressão comum da marca no topo |
| `lockup-vertical-light-text.png` | Tom oficial (ex.: thank-you) |

Tamanho ajustável via CSS; **aspect-ratio nunca é distorcido**. Animação da roda de fogo = apenas `transform: rotate` CSS no asset oficial.

## Idioma

`pt_BR` é o padrão do tema quando o site está em `en_US`/vazio. Catálogos adicionais em `languages/catalogs/`.
