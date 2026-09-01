# Voto Eletrônico (monorepo)

Pacote principal (activação standalone):  
[`relatasoft-secure-election-suite/README.md`](relatasoft-secure-election-suite/README.md)

```bash
cd relatasoft-secure-election-suite
composer install
php bin/ve-http --mode=voting --data=/tmp/ve/voting
```

Documentação de activação:  
[`relatasoft-secure-election-suite/docs/activar-standalone.md`](relatasoft-secure-election-suite/docs/activar-standalone.md)

Outros directórios neste repositório (`votador-prova-de-conceito`, `voto-eletronico-tema-base`)
são componentes auxiliares ou legado de integração — não substituem o arranque standalone acima.
