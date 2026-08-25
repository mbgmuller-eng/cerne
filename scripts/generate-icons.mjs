// Gera os ícones do PWA a partir de resources/branding/cerne-icon.svg.
//
// Existe como script (não como PNGs versionados "à mão") porque a
// identidade visual ainda pode mudar — trocar o SVG de origem e rodar
// `npm run icons` de novo é a forma de propagar isso sem depender de um
// editor de imagem. Ver DEPLOY.md, seção PWA.
//
// Uso: npm run icons

import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';
import pngToIco from 'png-to-ico';

const root = process.cwd();
const svgPath = path.join(root, 'resources/branding/cerne-icon.svg');
const outDir = path.join(root, 'public/icons');

mkdirSync(outDir, { recursive: true });

const svg = readFileSync(svgPath, 'utf8');

// Ícone "any": o SVG de origem já vem com fundo navy de ponta a ponta e
// cantos arredondados — serve como está para as três resoluções comuns.
const sizes = [
    ['icon-180.png', 180],
    ['icon-192.png', 192],
    ['icon-512.png', 512],
];

// Ícone "maskable": o sistema operacional aplica sua PRÓPRIA máscara
// (círculo, squircle etc.) por cima do ícone, então cantos arredondados
// do SVG de origem só atrapalham, e conteúdo perto da borda pode ser
// cortado. A convenção (maskable.app) é manter o conteúdo essencial
// dentro de ~80% do centro — aqui o conteúdo é encolhido para 70% e
// o fundo vira um quadrado cheio para preencher a área que a máscara
// do SO deixar visível.
const maskableSvg = svg
    .replace(/<rect([^>]*?)rx="112"([^>]*?)\/>/, '<rect$1rx="0"$2/>')
    .replace(
        /(<!-- sapwood fill -->)/,
        '<g transform="translate(256 256) scale(0.7) translate(-256 -256)">\n  $1',
    )
    .replace('</svg>', '</g>\n</svg>');

async function renderPng(source, size) {
    return sharp(Buffer.from(source), { density: 384 })
        .resize(size, size)
        .png()
        .toBuffer();
}

for (const [filename, size] of sizes) {
    const buffer = await renderPng(svg, size);
    writeFileSync(path.join(outDir, filename), buffer);
    console.log(`✓ public/icons/${filename} (${size}x${size})`);
}

const maskableBuffer = await renderPng(maskableSvg, 512);
writeFileSync(path.join(outDir, 'icon-maskable.png'), maskableBuffer);
console.log('✓ public/icons/icon-maskable.png (512x512, maskable)');

// favicon.ico: multi-resolução (16/32/48), a partir de renders próprios —
// reduzir o PNG de 512 direto para 16px perde definição nos anéis.
const faviconSizes = await Promise.all([16, 32, 48].map((size) => renderPng(svg, size)));
const icoBuffer = await pngToIco(faviconSizes);
writeFileSync(path.join(root, 'public/favicon.ico'), icoBuffer);
console.log('✓ public/favicon.ico (16/32/48)');
