# Identidade visual do Cerne

Documento para levar a outra conversa. Reúne logo, cores, tipografia, estilo dos componentes e tom de voz, como estão hoje no app (Laravel 13, Tailwind 4, Livewire 4).

**Conceito.** O nome vem do "cerne" da madeira, a parte central e mais densa do tronco. A marca é a seção transversal de um tronco: anéis de crescimento ao redor de um núcleo esmeralda. A ideia é crescimento ao longo do tempo, com o patrimônio no centro.

**Produtos da família.** Cerne Finanças, Cerne Seguros, Cerne Documentos e Cerne Saúde. Cada um tem uma cor própria (ver "Cor por produto").

---

## 1. Logo

Arquivos nesta pasta:

- `cerne-icon.svg`: ícone principal, o que está em uso no app e nos ícones do PWA.
- `cerne-c-corte.svg`: versão alternativa, existe no projeto mas não é a usada no app.

Os dois são SVG 512×512, com fundo navy de cantos arredondados (`rx=112`, cerca de 22% do lado).

### 1.1 Ícone principal (`cerne-icon.svg`)

Quatro anéis brancos que ficam mais fortes e mais grossos em direção ao centro (opacidade 30%, 50%, 70%, 90%; traço de 7 a 13), um núcleo esmeralda e um ponto branco central.

```svg
<svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
  <!-- Cerne: ícone de seção transversal (anéis de crescimento + núcleo esmeralda) -->
  <rect x="0" y="0" width="512" height="512" rx="112" fill="#0B1D3A"/>
  <circle cx="256" cy="256" r="225" fill="#0D2240"/>
  <circle cx="256" cy="256" r="225" stroke="#FFFFFF" stroke-width="7"  fill="none" opacity="0.30"/>
  <circle cx="256" cy="256" r="182" stroke="#FFFFFF" stroke-width="9"  fill="none" opacity="0.50"/>
  <circle cx="256" cy="256" r="138" stroke="#FFFFFF" stroke-width="11" fill="none" opacity="0.70"/>
  <circle cx="256" cy="256" r="97"  stroke="#FFFFFF" stroke-width="13" fill="none" opacity="0.90"/>
  <circle cx="256" cy="256" r="72"  fill="#10B981"/>
  <circle cx="256" cy="256" r="15"  fill="#FFFFFF"/>
</svg>
```

Cores usadas: fundo `#0B1D3A`, miolo `#0D2240`, anéis `#FFFFFF`, núcleo `#10B981`.

### 1.2 Versão "C Corte" (`cerne-c-corte.svg`)

Um "C" branco aberto à direita, cujas duas pontas são faces de corte de madeira. Cada ponta é um círculo branco com um anel esmeralda e um miolo navy, como um galho serrado.

```svg
<svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
  <rect width="512" height="512" rx="112" fill="#0B1D3A"/>
  <path d="M 372.9 141.0 A 194 194 0 1 0 372.9 371.0 L 316.6 322.9 A 112 112 0 1 1 316.6 189.1 Z" fill="#FFFFFF"/>
  <circle cx="359.6" cy="176.5" r="49" fill="#FFFFFF"/>
  <circle cx="359.6" cy="176.5" r="34" fill="#10B981"/>
  <circle cx="359.6" cy="176.5" r="14" fill="#0B1D3A" opacity="0.9"/>
  <circle cx="359.6" cy="335.5" r="49" fill="#FFFFFF"/>
  <circle cx="359.6" cy="335.5" r="34" fill="#10B981"/>
  <circle cx="359.6" cy="335.5" r="14" fill="#0B1D3A" opacity="0.9"/>
</svg>
```

### 1.3 Logotipo (a palavra)

Não existe um arquivo de logotipo separado. Na interface, o ícone aparece pequeno (24 a 28 px, cantos arredondados) ao lado da palavra "Cerne" escrita em texto:

- Fonte: Fraunces, peso semibold (600).
- Tamanho: 24 px (`text-2xl`), com espaçamento levemente fechado (`tracking-tight`).
- Cor: branca sobre fundo navy ou escuro; navy `#0B1D3A` sobre fundo claro.

### 1.4 Ícones do app (PWA)

PNG em `public/icons/`: 180, 192, 512 e uma versão maskable. São gerados a partir do SVG principal. Cor do tema do navegador e do PWA: `#0B1D3A`.

---

## 2. Cores

### 2.1 Marca: navy institucional

Âncora em 800 (`#0B1D3A`), a cor do ícone, mais usada em cabeçalhos e barra lateral.

| Token | Hex |
|---|---|
| brand-50 | `#F5F6F7` |
| brand-100 | `#E7E8EB` |
| brand-200 | `#CED2D8` |
| brand-300 | `#B1B6C0` |
| brand-400 | `#9199A6` |
| brand-500 | `#727C8D` |
| brand-600 | `#546175` |
| brand-700 | `#37465D` |
| **brand-800** | **`#0B1D3A`** |
| brand-900 | `#091830` |
| brand-950 | `#071123` |

### 2.2 Destaque: esmeralda

Núcleo do ícone, botões principais e indicadores de ganho. É a escala emerald padrão do Tailwind, nomeada como "accent".

| Token | Hex |
|---|---|
| accent-50 | `#ECFDF5` |
| accent-100 | `#D1FAE5` |
| accent-200 | `#A7F3D0` |
| accent-300 | `#6EE7B7` |
| accent-400 | `#34D399` |
| **accent-500** | **`#10B981`** (núcleo do ícone) |
| **accent-600** | **`#059669`** (botão primário) |
| accent-700 | `#047857` (hover) |
| accent-800 | `#065F46` |
| accent-900 | `#064E3B` |
| accent-950 | `#022C22` |

### 2.3 Neutros

Escala `slate` do Tailwind, que é fria e combina com o navy (um cinza quente destoaria).

- Fundo de superfície: `#F8FAFC` no claro, `#0F172A` no escuro.
- Superfície secundária: `#E2E8F0` no claro, `#1E293B` no escuro.
- Cartões: brancos no claro, `slate-800` (`#1E293B`) no escuro.

### 2.4 Cor por produto

A barra lateral (e a barra inferior no celular) muda de cor conforme o módulo em que a pessoa está.

| Produto | Cor da barra (tom 800) | Tom claro (200) |
|---|---|---|
| Cerne Finanças | navy `#0B1D3A` | (usa a escala da marca) |
| Cerne Seguros | verde-floresta `#173404` | `#97C459` |
| Cerne Documentos | marrom-âmbar `#633806` | `#EF9F27` |
| Cerne Saúde | roxo `#3C3489` | `#AFA9EC` |

Regra: quem usa só um produto vê a cor do módulo; quem usa vários vê o navy. No modo escuro a barra lateral volta a ser neutra (`slate-900`) e a cor do módulo vira acento no item ativo.

### 2.5 Sombras

Suaves e azuladas, sem borda dura.

- Claro: `0 1px 2px rgb(11 29 58 / .05), 0 4px 16px -4px rgb(11 29 58 / .08)`
- Claro, hover: `0 1px 2px rgb(11 29 58 / .07), 0 12px 32px -8px rgb(11 29 58 / .14)`
- Escuro: sombras pretas (`rgb(0 0 0 / .3)` e `.4`).

---

## 3. Tipografia

- **Fraunces** (serifada) é a voz da marca. É usada na palavra "Cerne", nos nomes de clientes, nos títulos de página e de seção, e nos números grandes de destaque (patrimônio, totais). A ideia é que o patrimônio do cliente seja lido como manchete, não como célula de planilha. Pesos: 400, 500 e 600. Títulos de página costumam usar 600 com `tracking-tight`. Alternativas de reserva: Charter, Georgia.
- **Inter** (sem serifa) é o texto funcional: corpo, rótulos, tabelas, botões e formulários. Pesos: 400, 500, 600 e 700. Reserva: fonte de sistema.
- **Números** em colunas e valores usam algarismos tabulares (`tabular-nums`), para alinhar.
- **Rótulo pequeno** ("eyebrow"): Inter 11 px, semibold, caixa alta, espaçamento largo (0,08em), cor slate-500. Fica acima de números e títulos de cartão.
- As fontes são servidas pelo próprio app, baixadas no build, nunca de CDN em tempo real.

---

## 4. Estilo dos componentes

- **Cantos:** generosos. Cartões `rounded-2xl` (16 px), botões e campos `rounded-xl` (12 px), selos totalmente arredondados (pílula).
- **Cartão:** branco, sombra suave e um anel de 1 px muito discreto (navy a 5%). No escuro, `slate-800` com anel branco a 10%.
- **Botão primário:** esmeralda 600, texto branco, `rounded-xl`, hover em 700. Esmeralda é a cor de ação; o navy é a cor institucional.
- **Botão secundário:** branco com anel cinza de 1 px. **Botão discreto:** só texto cinza, com fundo no hover.
- **Campos de formulário:** fundo branco, anel cinza de 1 px e, no foco, anel de 2 px na cor da marca. Sem borda tradicional.
- **Item de menu ativo:** fundo `brand-50` com texto navy no claro; no escuro, fundo esmeralda translúcido com texto esmeralda 400.
- **Selos de status:** pílula pequena (12 px), peso médio, com fundo suave da cor (por exemplo, âmbar claro com texto âmbar escuro para avisos).
- **Modo escuro:** escolhido pela pessoa (claro, sistema ou escuro).

---

## 5. Tom de voz

Português do Brasil, formal e direto. Sem coloquialismos como "num" ou "pra" (usar "em um" e "para"), sem travessão em textos públicos e sem exclamações em excesso.

---

## 6. Código-fonte dos tokens (Tailwind 4)

Trecho de `resources/css/app.css`, para quem for reproduzir a paleta em código:

```css
@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
    --font-display: 'Fraunces', 'Charter', 'Georgia', 'Iowan Old Style', 'Source Serif Pro', serif;

    --color-brand-50: #f5f6f7;  --color-brand-100: #e7e8eb; --color-brand-200: #ced2d8;
    --color-brand-300: #b1b6c0; --color-brand-400: #9199a6; --color-brand-500: #727c8d;
    --color-brand-600: #546175; --color-brand-700: #37465d; --color-brand-800: #0b1d3a;
    --color-brand-900: #091830; --color-brand-950: #071123;

    --color-accent-50: #ecfdf5;  --color-accent-100: #d1fae5; --color-accent-200: #a7f3d0;
    --color-accent-300: #6ee7b7; --color-accent-400: #34d399; --color-accent-500: #10b981;
    --color-accent-600: #059669; --color-accent-700: #047857; --color-accent-800: #065f46;
    --color-accent-900: #064e3b; --color-accent-950: #022c22;

    --color-seguros-200: #97c459;    --color-seguros-800: #173404;
    --color-documentos-200: #ef9f27; --color-documentos-800: #633806;
    --color-saude-200: #afa9ec;      --color-saude-800: #3c3489;

    --color-paper: #f8fafc;
    --color-paper-dark: #e2e8f0;

    --shadow-card: 0 1px 2px rgb(11 29 58 / 0.05), 0 4px 16px -4px rgb(11 29 58 / 0.08);
    --shadow-card-hover: 0 1px 2px rgb(11 29 58 / 0.07), 0 12px 32px -8px rgb(11 29 58 / 0.14);
}

.dark {
    --color-paper: #0f172a;
    --color-paper-dark: #1e293b;
    --shadow-card: 0 1px 2px rgb(0 0 0 / 0.3), 0 4px 16px -4px rgb(0 0 0 / 0.4);
    --shadow-card-hover: 0 1px 2px rgb(0 0 0 / 0.35), 0 12px 32px -8px rgb(0 0 0 / 0.5);
}
```

Origem no projeto: `resources/css/app.css` (tokens e componentes), `resources/branding/*.svg` (logos), `resources/views/components/brand-mark.blade.php` (uso do ícone) e `vite.config.js` (fontes e pesos).
