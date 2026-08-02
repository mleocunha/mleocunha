# Evolução criptográfica — decisões e backlog

**Status:** Fase 0 concluída; Fase 1 (Feldman VSS + cerimônia) ativa na geração (≥ 1.0.30); Fase 2 é o próximo trabalho de código  
 
**Plugin:** RelataSoft Secure Election Suite  
**Última atualização:** 2026-08-02  
**Branch de trabalho:** `cursor/votador-prova-de-conceito-2eb1`

Este documento congela o que já está acertado e lista pendências contratuais que **não bloqueiam** o início da implementação modular (PHP + Feldman VSS). Pendências marcadas como *backlog TSE* aguardam a equipe do TSE (retorno previsto na segunda-feira).

---

## 1. Princípio de engenharia

| Papel | Função |
|-------|--------|
| **PHP + GMP** | Implementação de referência executável até o protocolo modular completo estabilizar |
| **Rust** | Reimplementação posterior, submetida a testes diferenciais contra a referência PHP |
| **Testes** | Conformidade, regressão, equivalência entre implementações — **não** “certificam” segurança sozinhos |
| **Segurança forte** | Revisão especializada do protocolo + auditoria independente |

Evitar mudar ao mesmo tempo: linguagem, grupo, protocolo de compartilhamento, modelo de apuração, formatos e cerimônia operacional.

---

## 2. Alinhamento com o TSE (formulação correta)

### O que se pode afirmar

> **Perfil criptográfico baseado nas primitivas e nos parâmetros publicamente adotados ou exigidos pelo TSE**

### O que não se deve afirmar

> “O mesmo protocolo criptográfico utilizado pelo TSE.”

Threshold-ElGamal, Shamir/Feldman/Pedersen, DKG e Chaum–Pedersen são **protocolo eleitoral próprio** da suite. Não há demonstração pública de que o TSE use esses esquemas para cifrar/apurar votos; as normas de fiscalização concentram-se em inspeção, auditoria, assinatura, lacração e cadeia de custódia.

### Padrões TSE já garantidos para o perfil da suite

Fonte: especificação pública UE2020 (capacidades dos módulos de segurança) + prática descrita para assinatura/integridade; consolidação jurídica interna do produto.

| Uso na suite | Padrão alinhado ao TSE | Nota |
|--------------|------------------------|------|
| Grupo elíptico (fase ECC) | **secp521r1 / NIST P-521** | Oficial; usado com módulo de segurança (PKCS#11) para assinaturas e integridade |
| Hash principal | **SHA-512** | Conjunto TSE também inclui SHA-256 e SHA-384; a suite fixa SHA-512 como hash principal do perfil |
| Assinatura de artefatos | **ECDSA P-521 + SHA-512** | Mesmo padrão citado para BIOS, bootloader, kernel e RDV |
| Cifração de envelopes | **AES + HMAC-SHA-2** (HMAC-SHA-512 no perfil) | O ECIES-TSE proprietário usa essa combinação (chave simétrica → AES CTR/CBC → HMAC-SHA-512) |

Registro explícito de diferença algorítmica na fase ECC:

```text
TSE:   ECIES-TSE (derivado de ECIES) sobre curva ≥ 521 bits
Suite: threshold EC ElGamal sobre P-521 (+ VSS/provas próprias)
```

A **curva** é alinhada; o **algoritmo/protocolo eleitoral** não é idêntico.

Nível clássico aproximado (NIST SP 800-57): P-521 ≈ **256 bits** de força — não é mera substituição compacta do modular 2048.

---

## 3. O que já está acertado

### 3.1 Prioridade e ameaças

Ordem de prioridade:

1. VSS e integridade da distribuição  
2. Threshold decryption **sem** reconstruir `x`  
3. Reimplementação equivalente em Rust  
4. Migração do grupo modular → P-521  
5. DKG  

Modelo de ameaça declarado: corrupção acidental; dealer defeituoso/malicioso; custodiante defeituoso/malicioso; adversário externo em armazenamento/transporte.

### 3.2 Estados de segurança (flags de perfil)

Transitório (Fase 1):

```text
key_generation_mode = trusted_dealer
private_key_reconstruction = permitted_during_tally
security_generation = transitional
```

Após partial decryption (Fase 2):

```text
private_key_reconstruction = prohibited
```

Após DKG (Fase 5):

```text
key_generation_mode = distributed
full_private_key_exists = false
```

### 3.3 Roadmap de entregas

```text
Fase 0  Congelar baseline atual          scheme = modp-elgamal-shamir-v1
Fase 1  Feldman VSS em PHP               scheme = modp-elgamal-feldman-v1
Fase 2  Partial decrypt + Chaum–Pedersen scheme = modp-elgamal-threshold-cp-v1
Fase 3  Paridade Rust (mesmo scheme modular)
Fase 4  EC ElGamal P-521 em Rust         scheme = ec-elgamal-p521-threshold-cp-v1
Fase 5  Pedersen VSS + DKG               scheme = ec-elgamal-p521-pedersen-dkg-v1
```

Registry durante a transição:

```text
CryptoSchemeRegistry
├── modp-elgamal-shamir-v1          legacy / read-only
├── modp-elgamal-feldman-v1         transitional
├── modp-elgamal-threshold-cp-v1    target modular
└── ec-elgamal-p521-threshold-v1    target final (nome completo na Fase 4)
```

### 3.4 VSS (Fase 1)

- **Feldman** primeiro; **Pedersen** na fase DKG  
- `C₀ = gˣ` = chave pública ElGamal (já pública)  
- Verificação **local/offline** obrigatória  
- Transcript público canônico único; ZIPs de oficiais reproduzem o mesmo transcript (mesmo hash)  
- Falha → **fail-closed**: cerimônia inteira `CEREMONY_INVALID` / `SHARE_VERIFICATION_FAILED`  
- Botão **“Verificar meu share”** obrigatório no MVP  
- Quebra limpa de formato para eleições novas; legado só leitura arquivística; sem mistura de `scheme`

### 3.5 Threshold decryption (Fase 2)

- Estado final: **nunca reconstruir `x` na apuração**  
- Fluxo assíncrono offline por ZIP (partial + prova)  
- Chaum–Pedersen via Fiat–Shamir na Fase 2 (não no MVP Feldman)  
- `reconstructPrivateKey` só em `LegacyPrivateKeyReconstructionAdapter`

### 3.6 ECC e DKG

- Curva definitiva: **P-521**  
- Produção ECC: **Rust** (não PHP puro / não Sodium Ristretto como substituto)  
- Homomorfismo: exponential ElGamal com domínio de tally limitado e documentado  
- DKG: roadmap explícito; rodadas assíncronas; KA = bulletin board autenticado; aborto total no primeiro cut

### 3.7 Operação / UX

- Três modos permanecem: Key Authority / Voting / Tallying  
- ZIP + JSON para metadados; segredo em envelope cifrado (não share “nu” copiável)  
- Transcript entra na hash chain / pacote público / auditoria de apuração

### 3.8 Contrato do módulo (alvo)

Operações permitidas: `describeProfile`, `createCeremony`, `verifyPublicTranscript`, `verifyShare`, `encryptBallot`, `validateCiphertext`, `createPartialDecryption`, `verifyPartialDecryption`, `combinePartialDecryptions`, `verifyTally`, `exportPublicTranscript`.

Proibidas no núcleo: `getPrivateKey`, `exportPrivateKey`, `reconstructPrivateKey`, `decryptWithFullPrivateKey`.

Todo artefato carrega: `format_version`, `scheme_id`, `profile_id`, `election_id`, `ceremony_id`, `key_id`, `participant_id`, `threshold`, `participant_count`, `created_at`, `public_transcript_hash`, `payload_hash`, `issuer_signature`.

### 3.9 Ferramentas

- Aikido (SAST/taint/secrets/deps) na fase PHP, com regras customizadas anti-exposição de segredo / anti-legado indevido  
- GPU fora do escopo até Rust+P-521 estável e profiling

---

## 4. Pendências contratuais — backlog (não bloqueiam Fase 0–1)

Aguardar alinhamento com a equipe do TSE (retorno na segunda-feira). Podem evoluir em paralelo à implementação modular.

| ID | Pendência | Impacto se adiar | Bloqueia Fase 1? |
|----|-----------|------------------|------------------|
| B1 | Âncora normativa formal da matriz “TSE vs Suite” (qual norma/parecer/edital nomeia o perfil) | Nome oficial do `profile_id` / comunicação jurídica | **Não** — usar rótulo provisório `rses-tse-aligned-primitives-draft` |
| B2 | SemVer de `format_version` vs `scheme_id` (nomes finais canônicos) | Cosmético nos artefatos; pode versionar v1 agora | **Não** |
| B3 | Quem assina o transcript na fase dealer (chave de instalação KA / cerimônia / HSM depois) | Detalhe de `issuer_signature`; MVP pode usar chave de instalação KA documentada como transitória | **Não** |
| B4 | Campo Feldman: primo próprio `P > x` vs subgrupo `q` | **Resolvido para Fase 1:** Feldman usa **field = q** (necessário para \(g^{s}=\prod C_k^{i^k}\)); quebra limpa com o baseline | **Não** (fechado) |
| B5 | Escopo UX “Verificar meu share”: só WP vs também CLI offline | MVP = botão no admin KA; CLI depois | **Não** |
| B6 | Unidade da partial decryption (eleição / cargo / opção) | Só Fase 2 | **Não** |
| B7 | Fronteira PHP→Rust (`proc_open` / socket / serviço) | Só Fase 3 | **Não** |
| B8 | Política de corte do legado (versão / flag / por eleição) | Pode ser flag de configuração na Fase 1 | **Não** |

**Decisão de processo:** as 8 ficam em backlog até a reunião com o TSE; a implementação segue com os defaults da coluna “Bloqueia Fase 1?”.

---

## 5. O que a implementação pode fazer já (sem a equipe do TSE)

### Fase 0 — concluída

Artefactos:

| Artefacto | Caminho |
|-----------|---------|
| Spec matemática + formatos + inventário de `x` | `docs/crypto/modp-elgamal-shamir-v1.md` |
| ADR do roadmap | `docs/crypto/ADR-001-crypto-evolution.md` |
| Registry de `scheme_id` | `includes/Crypto/CryptoSchemeRegistry.php` |
| Vetores toy fixos | `tests/vectors/modp-elgamal-shamir-v1-tiny.json` |
| Aceitação Fase 0 | `tests/baseline-scheme-acceptance.php` |
| Matriz TSE provisória | §2 deste documento |

### Fase 1 (ativa na geração)

- Feldman VSS sobre ElGamal modular atual (PHP + GMP) — **ligado**  
- Transcript público + verificação offline — **ligado**  
- `scheme_id` / artefatos sem mistura com legado — **ligado**  
- UI “Verificar meu share” + fail-closed — **ligado**  
- Testes adversariais básicos (bit flip, índice errado) — **ligado**; suite de pacotes ZIP ainda pode expandir  

### Explicitamente fora até as fases seguintes

- Partial decryption / Chaum–Pedersen (Fase 2)  
- Rust (Fase 3)  
- P-521 / EC ElGamal (Fase 4)  
- DKG (Fase 5)  

---

## 6. Comunicação pública recomendada

Permitido:

> A suite adota curva P-521 e SHA-512, parâmetros também presentes nas especificações públicas de segurança da urna eletrônica brasileira, e assina artefatos com ECDSA P-521 + SHA-512. Envelopes usam AES autenticado com HMAC-SHA-512, na mesma família do esquema ECIES-TSE. O protocolo de custódia e apuração (threshold ElGamal + VSS + provas) é próprio da suite.

Evitar:

> A suite usa a mesma criptografia / o mesmo protocolo do TSE.
