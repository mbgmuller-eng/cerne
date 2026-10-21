# Plano de resposta a incidente de segurança com dado pessoal

Vale para qualquer suspeita de vazamento, acesso indevido, perda ou alteração de dado pessoal no Cerne. A LGPD (art. 48) e a
Resolução CD/ANPD nº 15/2024 mandam **comunicar a ANPD e os titulares em até 3 dias úteis** do conhecimento, quando o incidente
puder causar risco ou dano relevante, e **manter o registro** do incidente. Este roteiro existe para ninguém perder o prazo
decidindo o que fazer. Não é parecer jurídico: na dúvida, chame o advogado já no primeiro dia.

O registro mora em **/admin/incidentes** (tela só do administrador). Abra o incidente lá no primeiro minuto: a tela mostra o prazo
de 3 dias úteis e marca em vermelho quando vence sem a ANPD comunicada.

## 1. Conter (primeiras horas)

- Descubra o que está aberto e feche: troque a senha do banco, a `APP_KEY` só se souber que vazou (trocá-la sem plano **perde** os
  campos cifrados: ver o item 5), as chaves da Asaas e da Anthropic, o token do webhook, a senha SSH.
- Se foi uma conta específica: troque a senha dela, encerre as sessões (`DELETE FROM sessions WHERE user_id = ...`) e confira o
  registro em **/admin/acessos** para ver se houve "Entrar como" que não devia.
- Guarde as provas (logs do servidor, `storage/logs`, o que a Hostinger oferecer) **antes** de limpar qualquer coisa.

## 2. Avaliar

Responda por escrito e anote em /admin/incidentes:

1. O que aconteceu e quando (atingiu o quê, desde quando)?
2. Quando o Cerne ficou sabendo? (**o prazo de 3 dias úteis conta daqui**)
3. Que dados foram atingidos? Dado de saúde, CPF, dado financeiro e senha pesam mais.
4. Quantas pessoas?
5. Os dados estavam cifrados ou eram legíveis? (CPF/CNPJ e o conteúdo da ficha e da agenda de saúde são cifrados no banco, mas
   quem pega o banco **e** a `APP_KEY` lê tudo.)
6. Risco: baixo (sem dano provável), médio ou alto (fraude, discriminação, exposição de saúde). Risco **baixo e documentado**
   dispensa a comunicação, mas o registro continua obrigatório.

## 3. Comunicar (se o risco for relevante)

- **ANPD**, em até 3 dias úteis, pelo formulário do site da ANPD (gov.br/anpd). Conteúdo: natureza do incidente, dados e titulares
  atingidos, medidas técnicas, riscos, o que foi feito para reverter e os dados do encarregado. Se faltar informação, comunique o
  que já se sabe e complemente depois.
- **Titulares**, também em até 3 dias úteis, por e-mail, em linguagem simples: o que houve, que dados, o que o Cerne já fez e o que a
  pessoa pode fazer (trocar a senha, desconfiar de contato em nome do Cerne).
- Marque no /admin/incidentes quando cada comunicação saiu.
- Se o incidente atingiu dados que um **consultor ou corretor** tratava como controlador independente, avise-o também.

## 4. Corrigir e aprender

- Corrija a causa. Registre as medidas no incidente.
- Rode `php artisan cerne:check --strict` e confira que nada ficou aberto.
- Marque o incidente como **Contido** e, depois da correção definitiva, **Encerrado**. Mantenha o registro por pelo menos 5 anos.

## 5. Lembretes técnicos

- **Cifragem de campo** (`App\Casts\Sensitive`): usa a `APP_KEY`. Perder a chave é perder esses campos. Ela deve estar no cofre de
  senhas, junto com o backup do banco. Nunca troque a chave sem antes decifrar e recifrar (não há comando pronto para isso: abra
  uma tarefa).
- **Quem entrou em quê**: `/admin/acessos` (cada "Entrar como", com motivo, IP e horário).
- **Apagar para um titular**: a exclusão de conta (Minha conta, ou o administrador em /admin) apaga dados e arquivos e deixa só um
  registro sem dado pessoal em `account_deletions`.
