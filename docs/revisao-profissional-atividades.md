# Cerne Atividades: pontos para revisão profissional

Para um fisioterapeuta ou profissional de educação física. O Cerne **não avalia nem prescreve**: ele sugere por precaução, a pessoa decide, e a tela
avisa para conferir com um profissional. Mesmo assim, as listas e os passos abaixo foram escritos pela equipe e ainda **não foram revisados por um
profissional**.

## 1. Como o Cerne marca um exercício

Quando a pessoa registra uma limitação, ela escolhe o que evitar. O Cerne compara essas escolhas com **palavras no nome do exercício**. Ele não
conhece a execução nem a carga usada.

| O que evitar | Nomes que são marcados |
|---|---|
| Impacto e saltos | salto, pular corda, jump, burpee, pliométrico, arremesso, polichinelo, corrida, sprint |
| Carga sobre a coluna | agachamento livre, frontal e com barra; levantamento terra; terra romeno; stiff; good morning; desenvolvimento; militar; remada curvada; cavalinho; T-bar; panturrilha em pé; encolhimento; kettlebell swing; farmer; thruster; snatch; clean |
| Rotação do tronco com carga | rotação do tronco, russian twist, woodchop, lenhador, arremesso, abdominal oblíquo, twist |
| Inclinar o tronco com carga | levantamento terra, terra romeno, stiff, good morning, remada curvada, cavalinho, T-bar, hiperextensão, kettlebell swing |
| Peso acima da cabeça | desenvolvimento, militar, arnold, tríceps francês, elevação frontal, puxada atrás, thruster, snatch, push press, pullover |

Para joelho, ombro e lombar vale também a lista do treino automático (`app/Support/GymExerciseClassifier.php`).

Perguntas:

1. Falta algum exercício que deveria ser marcado? Algum está sobrando?
2. Pescoço, coluna torácica, quadril, coxa, tornozelo, cotovelo e punho **não têm lista própria**: valem só as escolhas de "o que evitar". Que
   exercícios cada região deveria marcar?

## 2. O que vem marcado ao escolher a região

É um ponto de partida que a pessoa ajusta.

| Região | Vem marcado |
|---|---|
| Pescoço | peso acima da cabeça; carga sobre a coluna |
| Coluna torácica | carga sobre a coluna; rotação; peso acima da cabeça |
| Lombar | impacto; carga sobre a coluna; rotação; inclinar o tronco com carga |
| Quadril, coxa, joelho, tornozelo | impacto |
| Ombro | peso acima da cabeça |
| Cotovelo, punho | nada |

## 3. Programa de caminhada e corrida

Cada sessão começa com 5 minutos de caminhada.

| Passo | Sessão |
|---|---|
| 1 | 25 min de caminhada |
| 2 | 6 vezes: 1 min de trote e 2 min de caminhada |
| 3 | 8 vezes: 1 min de trote e 2 min de caminhada |
| 4 | 6 vezes: 2 min de trote e 2 min de caminhada |
| 5 | 6 vezes: 3 min de trote e 2 min de caminhada |
| 6 | 4 vezes: 5 min de trote e 2 min de caminhada |
| 7 | 3 vezes: 8 min de trote e 2 min de caminhada |
| 8 | 2 vezes: 12 min de trote e 2 min de caminhada |
| 9 | 25 min de trote seguidos |

Onde começa, conforme o trote confortável e sem dor que a pessoa faz hoje: nenhum, passo 1; 1 a 2 min, passo 2; 3 a 5 min, passo 4; 10 min ou
mais, passo 6.

Como anda, uma vez por semana e com confirmação da pessoa:

- **Avança** com todas as sessões feitas, alguma nota de dor informada e nenhuma acima da dor que ela tinha ao começar.
- **Repete** se faltou sessão ou se não informou dor.
- **Volta um passo** se a dor passou da inicial, com a orientação de procurar o profissional se ela continuar.

Até 3 sessões por semana.

## 4. Avisos da semana

- Treino de pernas (metade ou mais dos exercícios de pernas e glúteos) na véspera de um esporte.
- Semana sem nenhum dia de descanso.
- Corrida no dia seguinte ao treino de pernas, ou corridas em dias seguidos.
- Carga da semana (minutos × esforço de 0 a 10) mais de 30% acima da média das 4 semanas anteriores. A tela diz que é um sinal para observar e
  que **não prevê lesão**. O limite de 30% é uma escolha de produto, não uma referência clínica.

## Onde mudar

| Assunto | Arquivo |
|---|---|
| Listas de "o que evitar" | `app/Enums/MovementLoad.php` |
| Sugestão por região | `app/Enums/BodyRegion.php` |
| Passos da corrida | `app/Support/Activities/RunningLadder.php` |
| Regras de encaixe e alerta de carga | `app/Services/Activities/ActivityWeekService.php` |
